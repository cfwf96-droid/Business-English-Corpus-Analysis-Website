from fastapi import FastAPI, Response
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import re
import os
import sys
from datetime import datetime

# 正则表达式模式
chunk_mark_pattern = re.compile(r'\(\s*ROOT\s*\(', re.IGNORECASE)
tag_pattern = re.compile(r"([A-Za-z\-]+)\((.*?)\)", re.DOTALL | re.IGNORECASE)
content_part_pattern = re.compile(r"([A-Za-z]+)\s+([^\(\)\s]+)", re.DOTALL)
chinese_overlap_pattern = re.compile(r"([\u4e00-\u9fa5])一\1")

# 支持命令行指定端口
def get_port():
    for arg in sys.argv:
        if arg.startswith("--port"):
            try:
                return int(arg.split("=")[1])
            except:
                pass
    return 8000

APP_PORT = get_port()

# 初始化FastAPI服务
app = FastAPI(
    title=f"中文组块检索API（端口：{APP_PORT}）",
    version="4.3",
    description="彻底解决中文下载编码问题"
)

# CORS中间件
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# 数据模型
class QueryRequest(BaseModel):
    query: str
    top_n: int = 10
    need_report: bool = False
    corpus_path: str = "68f850c1927c8.txt"

# 服务健康检查接口
@app.get("/health", summary="服务健康检查")
def health_check():
    return {
        "status": "healthy",
        "port": APP_PORT,
        "time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "message": "Python组块检索服务运行正常"
    }

# 语料读取与解析（返回完整组块字典）
def read_corpus(corpus_path: str) -> tuple[list, dict]:
    """读取语料并返回(处理后的语料列表, 完整组块字典{行号: 组块内容})"""
    try:
        corpus_path = os.path.abspath(corpus_path)
        if not os.path.exists(corpus_path):
            raise FileNotFoundError(f"语料文件不存在！路径：{corpus_path}")
        
        file_size_mb = round(os.path.getsize(corpus_path) / 1024 / 1024, 2)
        print(f"\n=== 语料文件信息 ===")
        print(f"路径：{corpus_path}")
        print(f"大小：{file_size_mb} MB")
        print("==================\n")

        corpus = []
        full_chunks = {}  # 存储完整组块内容（行号: 原始内容）
        line_count = 0
        valid_count = 0

        with open(corpus_path, 'r', encoding='utf-8', errors='replace') as f:
            for line_num, line in enumerate(f, 1):
                line_count += 1
                line_strip = line.strip()
                full_chunks[line_num] = line_strip  # 保存原始组块内容
                
                # 每1000行显示一次进度
                if line_count % 1000 == 0:
                    print(f"🔄 正在处理第{line_count}行，已找到{valid_count}条有效组块")
                
                if not line_strip:
                    continue
                
                if not chunk_mark_pattern.search(line_strip):
                    continue
                
                # 提取纯文本内容（移除标签）
                pure_text = re.sub(
                    r"\([A-Za-z\-]+\(.*?\)\)",
                    lambda m: m.group(0).split("(")[-1].split(")")[0] + " ",
                    line_strip
                )
                pure_text = re.sub(r"\s+", " ", pure_text).strip()
                
                corpus.append((line_num, line_strip, pure_text))
                valid_count += 1
        
        print(f"\n✅ 语料处理完成！总行数：{line_count}，有效组块行数：{valid_count}\n")

        if not corpus:
            raise ValueError(f"无有效组块！请确认包含'(ROOT('标识")

        return corpus, full_chunks
    
    except Exception as e:
        print(f"语料读取错误：{str(e)}")
        raise

def parse_chunk(chunk_str: str) -> dict:
    """解析组块字符串，提取标签信息"""
    chunk_info = {"tags": {}, "full_text": chunk_str}
    try:
        tags = tag_pattern.findall(chunk_str)
        
        for match in tags:
            if len(match) != 2:
                print(f"⚠️  过滤无效标签匹配：{match}（组块：{chunk_str[:50]}...）")
                continue
            
            tag_name, tag_content = match
            tag_name = tag_name.strip()
            tag_content = tag_content.strip()
            
            if not tag_name or not tag_content:
                continue
            
            content_parts = content_part_pattern.findall(tag_content)
            if not content_parts:
                continue
            
            if tag_name not in chunk_info["tags"]:
                chunk_info["tags"][tag_name] = []
            for pos, word in content_parts:
                chunk_info["tags"][tag_name].append({
                    "pos": pos,
                    "word": word,
                    "full_subchunk": f"({tag_name}({pos} {word}))"
                })
    
    except Exception as e:
        print(f"组块解析错误（{chunk_str[:50]}...）：{str(e)}")
    
    return chunk_info

def parse_query(query: str) -> dict:
    """解析检索式，生成检索条件"""
    query = query.strip()
    if not query:
        raise ValueError("检索式不能为空")
    
    query_info = {
        "raw_query": query,
        "type": "",
        "tags": [],
        "conditions": [],
        "intention": ""
    }

    # 单标签模式（如：VP-PRD[v一v]）
    single_tag_pattern = re.compile(r"^([A-Za-z\-]+)\[([^\]]*)\]$")
    single_match = single_tag_pattern.match(query)
    if single_match:
        tag, cond_str = single_match.groups()
        query_info["type"] = "single_tag"
        query_info["tags"] = [tag]
        query_info["conditions"] = [_parse_condition(cond_str)]
        query_info["intention"] = _analyze_intention(query_info)
        return query_info

    # 多标签模式（如：NP-SBJ[n]VP-PRD[v]）
    multi_tag_pattern = re.compile(r"^([A-Za-z\-]+\[.*?\])+$")
    if multi_tag_pattern.match(query):
        tag_cond_pairs = re.findall(r"([A-Za-z\-]+)\[([^\]]*)\]", query)
        if tag_cond_pairs:
            query_info["type"] = "multi_tag"
            query_info["tags"] = [tag for tag, _ in tag_cond_pairs]
            query_info["conditions"] = [_parse_condition(cond) for _, cond in tag_cond_pairs]
            query_info["intention"] = _analyze_intention(query_info)
            return query_info

    # 嵌套标签模式（如：VP-PRD[NULL-MOD[d]VP-PRD[v]]）
    nested_tag_pattern = re.compile(r"^([A-Za-z\-]+)\[(.*)\]$")
    nested_match = nested_tag_pattern.match(query)
    if nested_match and "[" in nested_match.group(2) and "]" in nested_match.group(2):
        outer_tag, inner_content = nested_match.groups()
        inner_query_info = parse_query(inner_content)
        query_info["type"] = "nested_tag"
        query_info["tags"] = [outer_tag] + inner_query_info["tags"]
        query_info["conditions"] = [_parse_condition("*")] + inner_query_info["conditions"]
        query_info["intention"] = _analyze_intention(query_info)
        return query_info

    raise ValueError(f"检索式格式错误！输入：{query}，请参考示例格式")

def _parse_condition(cond_str: str) -> dict:
    """解析检索条件"""
    cond_str = cond_str.strip()
    if not cond_str or cond_str == "*":
        return {"type": "any", "value": "*", "desc": "任意内容"}
    
    # 重叠模式（如：v一v）
    pattern_match = re.match(r"^([a-z])一([a-z])$", cond_str)
    if pattern_match and pattern_match.group(1) == pattern_match.group(2):
        pos_type = pattern_match.group(1)
        pattern_desc_map = {
            "v": "动词重叠式（如：走一走、听一听）",
            "n": "名词重叠式（如：人一人、天一天）",
            "a": "形容词重叠式（如：红一红、亮一亮）"
        }
        return {
            "type": "pattern",
            "value": cond_str,
            "pos": pos_type,
            "pattern_regex": chinese_overlap_pattern,
            "desc": pattern_desc_map.get(pos_type, f"{pos_type}类词重叠式")
        }
    
    # 词性条件（如：n、v、d）
    if len(cond_str) <= 2 and cond_str.islower():
        pos_desc_map = {
            "d": "副词（如：很、都、也）",
            "v": "动词（如：走、听、说）",
            "n": "名词（如：天气、经理）",
            "a": "形容词（如：好、合适）",
            "r": "代词（如：我、你）",
            "nr": "人名（如：王经理）",
            "ns": "地名（如：北京）"
        }
        return {
            "type": "pos",
            "value": cond_str,
            "desc": pos_desc_map.get(cond_str, f"词性({cond_str})")
        }
    
    # 词语条件
    return {"type": "word", "value": cond_str, "desc": f"具体词（{cond_str}）"}

def _analyze_intention(query_info: dict) -> str:
    """分析检索意图"""
    tag_desc_map = {
        "ROOT": "根节点",
        "IP": "独立分句",
        "NP-SBJ": "主语名词短语",
        "VP-PRD": "谓语动词短语",
        "NULL-MOD": "副词修饰块",
        "NP-OBJ": "宾语名词短语",
        "PP": "介词短语"
    }
    if query_info["type"] == "single_tag":
        tag = query_info["tags"][0]
        cond = query_info["conditions"][0]
        tag_desc = tag_desc_map.get(tag, tag)
        return f"检索{tag_desc}标签下，{cond['desc']}的组块内容"
    elif query_info["type"] == "multi_tag":
        tag_cond_pairs = list(zip(query_info["tags"], query_info["conditions"]))
        pairs_desc = "→".join([f"{tag_desc_map.get(tag, tag)}（{cond['desc']}）" for tag, cond in tag_cond_pairs])
        return f"检索{ pairs_desc }的标签序列对应的组块内容"
    elif query_info["type"] == "nested_tag":
        outer_tag = query_info["tags"][0]
        inner_tags = query_info["tags"][1:]
        outer_desc = tag_desc_map.get(outer_tag, outer_tag)
        inner_desc = "→".join([tag_desc_map.get(tag, tag) for tag in inner_tags])
        return f"检索{outer_desc}标签下嵌套{inner_desc}标签序列的组块内容"
    return "检索符合条件的组块内容"

def match_structure(chunk_info: dict, query_info: dict) -> list:
    """匹配组块结构与检索条件"""
    matched = []
    try:
        if query_info["type"] == "single_tag":
            tag = query_info["tags"][0]
            cond = query_info["conditions"][0]
            if tag not in chunk_info["tags"]:
                return []
            
            for item in chunk_info["tags"][tag]:
                pos, word, full_subchunk = item["pos"], item["word"], item["full_subchunk"]
                if (cond["type"] == "any") or \
                   (cond["type"] == "pos" and pos == cond["value"]) or \
                   (cond["type"] == "word" and word == cond["value"]) or \
                   (cond["type"] == "pattern" and pos == cond["pos"] and cond["pattern_regex"].search(word)):
                    matched.append({
                        "content": word,
                        "full_subchunk": full_subchunk
                    })
        
        elif query_info["type"] == "multi_tag":
            tag_cond_pairs = list(zip(query_info["tags"], query_info["conditions"]))
            if not all(tag in chunk_info["tags"] for tag, _ in tag_cond_pairs):
                return []
            
            tag_contents = {tag: chunk_info["tags"][tag] for tag, _ in tag_cond_pairs}
            max_len = min([len(contents) for contents in tag_contents.values()])
            
            for i in range(max_len):
                sequence_content = []
                sequence_subchunk = []
                valid = True
                
                for tag, cond in tag_cond_pairs:
                    item = tag_contents[tag][i]
                    pos, word, full_subchunk = item["pos"], item["word"], item["full_subchunk"]
                    
                    if not (cond["type"] == "any" or \
                           (cond["type"] == "pos" and pos == cond["value"]) or \
                           (cond["type"] == "word" and word == cond["value"]) or \
                           (cond["type"] == "pattern" and pos == cond["pos"] and cond["pattern_regex"].search(word))):
                        valid = False
                        break
                    
                    sequence_content.append(word)
                    sequence_subchunk.append(full_subchunk)
                
                if valid:
                    matched.append({
                        "content": "".join(sequence_content),
                        "full_subchunk": "".join(sequence_subchunk)
                    })
        
        elif query_info["type"] == "nested_tag":
            outer_tag = query_info["tags"][0]
            inner_query_info = {
                "type": "multi_tag" if len(query_info["tags"][1:]) > 1 else "single_tag",
                "tags": query_info["tags"][1:],
                "conditions": query_info["conditions"][1:]
            }
            
            if outer_tag not in chunk_info["tags"]:
                return []
            
            for item in chunk_info["tags"][outer_tag]:
                inner_chunk_str = item["full_subchunk"]
                inner_chunk_info = parse_chunk(inner_chunk_str)
                inner_matched = match_structure(inner_chunk_info, inner_query_info)
                matched.extend(inner_matched)
    
    except Exception as e:
        print(f"结构匹配错误：{str(e)}")
    
    return matched

def generate_report(results: list, full_chunks: dict, query_info: dict, corpus_path: str) -> str:
    """生成检索报告（确保兼容UTF-8）"""
    try:
        # 确保所有字符串都是Unicode（防止字节串导致编码问题）
        def ensure_str(s):
            if isinstance(s, bytes):
                return s.decode('utf-8', errors='replace')
            return str(s)
        
        report_lines = [
            "# 中文组块检索报告",
            f"检索时间：{ensure_str(datetime.now().strftime('%Y-%m-%d %H:%M:%S'))}",
            f"语料路径：{ensure_str(corpus_path)}",
            f"检索式：{ensure_str(query_info['raw_query'])}",
            f"检索需求：{ensure_str(query_info['intention'])}",
            f"匹配结果总数：{len(results)}",
            "-" * 80,
            f"{'序号':<6}{'匹配内容':<15}{'组块记录号':<12}{'匹配部分标红的组块内容'}",
            "-" * 80
        ]
        
        for idx, res in enumerate(results, 1):
            content = ensure_str(res["content"])
            line_num = res["line_num"]
            chunk_content = ensure_str(full_chunks.get(line_num, "未找到组块内容"))
            
            # 报告中用【】标红匹配内容
            highlighted_content = chunk_content.replace(content, f"【{content}】")
            
            # 格式化输出
            idx_str = f"{idx}.".ljust(6)
            content_str = content.ljust(15)
            line_num_str = f"第{line_num}行".ljust(12)
            report_lines.append(f"{idx_str}{content_str}{line_num_str}{highlighted_content}")
        
        report_lines.append("-" * 80)
        return "\n".join(report_lines)
    except Exception as e:
        print(f"报告生成错误：{str(e)}")
        return f"报告生成失败：{str(e)}"

# API接口
@app.post("/retrieve_chunk", summary="组块检索")
def retrieve_chunk(request: QueryRequest):
    try:
        print(f"\n📥 收到检索请求：{request.query}")
        
        # 1. 读取语料（同时获取完整组块字典）
        corpus, full_chunks = read_corpus(request.corpus_path)
        
        # 2. 解析检索式
        query_info = parse_query(request.query)
        print(f"🔍 检索需求：{query_info['intention']}")
        
        # 3. 匹配组块
        results = []
        total_chunks = len(corpus)
        for idx, (line_num, chunk_str, pure_text) in enumerate(corpus, 1):
            # 进度提示（每100行）
            if idx % 100 == 0:
                print(f"🔄 匹配第{idx}/{total_chunks}个组块，已找到{len(results)}条结果")
            
            # 解析组块
            chunk_info = parse_chunk(chunk_str)
            
            # 匹配结构
            matched_items = match_structure(chunk_info, query_info)
            
            # 去重并添加结果
            for item in matched_items:
                content = item["content"]
                if not any(r["content"] == content and r["line_num"] == line_num for r in results):
                    results.append({
                        "content": content,
                        "line_num": line_num,
                        "full_subchunk": item["full_subchunk"],
                        "pure_text": pure_text
                    })
        
        # 4. 准备返回结果
        display_results = results[:request.top_n]
        query_info["result_count"] = len(results)
        
        # 筛选需要返回的完整组块
        display_line_nums = [res["line_num"] for res in display_results]
        display_full_chunks = {
            line_num: full_chunks[line_num] 
            for line_num in display_line_nums 
            if line_num in full_chunks
        }
        
        print(f"✅ 检索完成！总匹配：{len(results)}条\n")
        
        return {
            "status": "success",
            "query_info": query_info,
            "result_count": len(results),
            "display_count": len(display_results),
            "results": [{"content": r["content"], "line_num": r["line_num"]} for r in display_results],
            "full_chunks": display_full_chunks,
            "corpus_path": request.corpus_path,
            "report": generate_report(results, full_chunks, query_info, request.corpus_path) if request.need_report else ""
        }
    
    except Exception as e:
        error_msg = str(e)
        print(f"\n❌ 检索错误：{error_msg}\n")
        return {
            "status": "error",
            "detail": error_msg
        }

@app.post("/download_report", summary="下载报告")
def download_report(request: QueryRequest, response: Response):
    try:
        # 获取检索结果
        retrieve_result = retrieve_chunk(request)
        if retrieve_result["status"] != "success" or not retrieve_result.get("report"):
            raise ValueError("无检索结果，无法生成报告")
        
        # 关键修复：确保报告内容是UTF-8编码的字节流
        report_content = retrieve_result["report"]
        if isinstance(report_content, str):
            report_bytes = report_content.encode('utf-8')  # 显式编码为UTF-8
        else:
            report_bytes = report_content  # 已为字节流
        
        filename = f"中文组块检索报告_{datetime.now().strftime('%YmdHis')}.txt"
        
        # 设置响应头，确保中文文件名和UTF-8编码
        response.headers["Content-Type"] = "text/plain; charset=utf-8"
        response.headers["Content-Length"] = str(len(report_bytes))
        # 处理中文文件名（URL编码）
        from urllib.parse import quote
        response.headers["Content-Disposition"] = f"attachment; filename*=UTF-8''{quote(filename)}"
        
        return Response(content=report_bytes, media_type="text/plain; charset=utf-8")
    
    except Exception as e:
        error_msg = str(e)
        print(f"\n❌ 下载错误：{error_msg}\n")
        return {
            "status": "error",
            "detail": error_msg
        }

# 启动服务
if __name__ == "__main__":
    import uvicorn
    print("=" * 60)
    print(f"📌 中文组块检索API（V4.3）启动成功")
    print(f"服务端口：{APP_PORT}")
    print(f"健康检查：http://127.0.0.1:{APP_PORT}/health")
    print(f"API文档：http://127.0.0.1:{APP_PORT}/docs")
    print("提示：修改端口需同时更新PHP页面中的端口配置")
    print("=" * 60)
    uvicorn.run(
        app="chunk_analyzer:app",
        host="0.0.0.0",
        port=APP_PORT,
        reload=True,
        timeout_keep_alive=60
    )
