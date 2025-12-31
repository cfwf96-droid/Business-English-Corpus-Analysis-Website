import sys
import json
import re
import gpflib  # 导入gpflib模块

def get_pattern_mappings():
    """
    中文组块结构检索模式映射表
    包含15种常见中文语法结构的检索规则
    """
    return {
        # 1. 词组的离合用法
        r'离合用法\{\w+-\w+\}': {
            'type': 'discontinuous_word',
            'pattern': r'(\w+)[^\w]+(\w+)',  # 匹配"吃...饭"等离合结构
            'description': '词组的离合用法（如"吃-饭、洗-澡"）',
            'example': '吃了一顿饭 → 吃-饭'
        },
        # 2. 状中结构
        r'状中结构\{\}': {
            'type': 'adverbial_center',
            'pattern': r'(很|非常|特别|太|十分|格外)\s*(\w+)',  # 程度副词+中心语
            'description': '状中结构（如"很好、非常漂亮、特别开心"）',
            'example': '非常漂亮 → 非常(状语)+漂亮(中心语)'
        },
        # 3. 动词重叠式"v一v"
        r'v一v\{\}': {
            'type': 'verb_repetition_v1v',
            'pattern': r'(\w+)一\1',  # 匹配"v一v"结构
            'description': '动词重叠式"v一v"（如"看一看、听一听、试一试"）',
            'example': '看一看 → 看(动词)+一+看(动词)'
        },
        # 4. 动词重叠式"v一v"其后宾语
        r'v一v宾语\{\}': {
            'type': 'verb_repetition_v1v_object',
            'pattern': r'(\w+)一\1\s+(\w+)',  # 匹配"v一v+宾语"
            'description': '动词重叠式"v一v"其后宾语（如"看一看书、听一听音乐、试一试方法"）',
            'example': '看一看书 → 看一看(动词重叠)+书(宾语)'
        },
        # 5. 述补结构（通用）
        r'述补结构\{\}': {
            'type': 'predicate_complement',
            'pattern': r'(\w+)(得|不)(\w+)',  # 匹配"v得c"或"v不c"
            'description': '述补结构（如"跑得快、看不见、吃得香、拿不动"）',
            'example': '跑得快 → 跑(述语)+得+快(补语)'
        },
        # 6. 述补结构-动词作补语
        r'述补结构-动词补语\{\}': {
            'type': 'predicate_complement_verb',
            'pattern': r'(打|看|听|说|学|做|写|读|想|吃)(败|见|懂|会|成|完|好|透)',  # 动词+动词补语
            'description': '述补结构-动词作补语（如"打败、看见、听懂、学会、做成"）',
            'example': '打败 → 打(述语)+败(动词补语)'
        },
        # 7. 述补结构-趋向动词作补语
        r'述补结构-趋向补语\{\}': {
            'type': 'predicate_complement_directional',
            'pattern': r'(\w+)(上|下|来|去|进|出|回|过|起|开|上来|下去|进来|出去)',  # 动词+趋向补语
            'description': '述补结构-趋向动词作补语（如"站起来、走出去、拿过来、放下去"）',
            'example': '站起来 → 站(述语)+起来(趋向补语)'
        },
        # 8. 主谓谓语句（通用）
        r'主谓谓语句\{\}': {
            'type': 'subject_predicate_predicate',
            'pattern': r'(\w+)\s+(\w+\s+\w+)',  # 大主语+小主谓结构
            'description': '主谓谓语句（如"他学习努力、这本书内容很好、小明身体很棒"）',
            'example': '他学习努力 → 他(大主语)+学习努力(小主谓结构)'
        },
        # 9. 主谓谓语句--限制谓语
        r'主谓谓语句-限制谓语\{\}': {
            'type': 'subject_predicate_predicate_limited',
            'pattern': r'(\w+)\s+(很|非常|特别|太|十分)\s*(\w+)',  # 大主语+程度副词+谓语
            'description': '主谓谓语句--限制谓语（如"他很努力、这本书非常好、小明特别棒"）',
            'example': '他很努力 → 他(大主语)+很(限制词)+努力(谓语)'
        },
        # 10. 动词并列式
        r'动词并列式\{\}': {
            'type': 'verb_coordinate',
            'pattern': r'(\w+)\s+和\s+(\w+)',  # 动词+和+动词
            'description': '动词并列式（如"学习和工作、吃饭和睡觉、看书和写字、唱歌和跳舞"）',
            'example': '学习和工作 → 学习(动词)+和(连词)+工作(动词)'
        },
        # 11. 代词做主语的主谓结构
        r'代词主语主谓结构\{\}': {
            'type': 'pronoun_subject',
            'pattern': r'(我|你|他|她|它|我们|你们|他们|她们|它们|这|那|这些|那些)\s+(\w+)',  # 代词+谓语
            'description': '代词做主语的主谓结构（如"我吃饭、你学习、他工作、这很好"）',
            'example': '我吃饭 → 我(代词主语)+吃(谓语)+饭(宾语)'
        },
        # 12. 代词做主语的主谓结构，但代词不为"我"
        r'代词主语主谓结构-非我\{\}': {
            'type': 'pronoun_subject_not_wo',
            'pattern': r'(你|他|她|它|我们|你们|他们|她们|它们|这|那|这些|那些)\s+(\w+)',  # 非"我"代词+谓语
            'description': '代词做主语的主谓结构，但代词不为"我"（如"你吃饭、他学习、这很好"）',
            'example': '你学习 → 你(代词主语)+学习(谓语)'
        },
        # 13. 动词性宾语的动宾结构
        r'动词宾语动宾结构\{\}': {
            'type': 'verb_object_verb',
            'pattern': r'(想|要|喜欢|希望|打算|计划|开始|继续|停止)\s+(\w+)',  # 动词+动词宾语
            'description': '动词性宾语的动宾结构（如"想吃饭、喜欢学习、希望成功、打算工作"）',
            'example': '想吃饭 → 想(谓语动词)+吃饭(动词性宾语)'
        },
        # 14. 双宾句
        r'双宾句\{\}': {
            'type': 'double_object',
            'pattern': r'(给|送|教|告诉|还|借|还|问|欠)\s+(\w+)\s+(\w+)',  # 动词+间接宾语+直接宾语
            'description': '双宾句（如"给他一本书、教我英语、告诉她消息、还我钱"）',
            'example': '给他一本书 → 给(动词)+他(间接宾语)+一本书(直接宾语)'
        },
        # 15. 主谓结构（通用）
        r'主谓结构\{\}': {
            'type': 'subject_predicate',
            'pattern': r'(\w+)\s+(\w+)',  # 主语+谓语
            'description': '主谓结构（如"小明吃饭、太阳升起、花儿开放、小鸟飞翔"）',
            'example': '小明吃饭 → 小明(主语)+吃(谓语)+饭(宾语)'
        }
    }

def parse_query_pattern(query):
    """
    解析用户输入的检索式，识别对应的中文组块结构类型
    """
    mappings = get_pattern_mappings()
    
    # 遍历所有模式进行匹配
    for regex_pattern, config in mappings.items():
        if re.match(regex_pattern, query, re.IGNORECASE):
            return config
    
    # 默认模式：精确匹配
    return {
        'type': 'default',
        'pattern': re.escape(query),
        'description': f'精确匹配检索式：{query}',
        'example': f'匹配包含"{query}"的文本内容'
    }

def highlight_matches(text, pattern):
    """
    高亮显示匹配的内容（标红处理）
    """
    # 使用正则替换实现高亮
    def replace_match(match):
        return f'<span class="highlight">{match.group()}</span>'
    
    return re.sub(pattern, replace_match, text)

def extract_context(text, pattern, context_length=60):
    """
    提取所有匹配内容及其上下文
    返回包含上下文和高亮匹配的结果列表
    """
    matches = []
    # 查找所有匹配位置
    for match in re.finditer(pattern, text):
        match_text = match.group()
        start_pos = match.start()
        end_pos = match.end()
        
        # 计算上下文范围
        context_start = max(0, start_pos - context_length)
        context_end = min(len(text), end_pos + context_length)
        
        # 提取上下文文本
        context_text = text[context_start:context_end]
        
        # 高亮匹配部分
        highlighted_text = highlight_matches(context_text, re.escape(match_text))
        
        # 添加上下文标识
        prefix = "..." if context_start > 0 else ""
        suffix = "..." if context_end < len(text) else ""
        
        # 构建最终结果
        full_text = f"{prefix}{highlighted_text}{suffix}"
        matches.append(full_text)
    
    return matches

def paginate_results(results, page=1, per_page=10):
    """
    分页处理结果集
    返回包含分页信息和当前页数据的字典
    """
    total = len(results)
    pages = (total + per_page - 1) // per_page  # 向上取整计算总页数
    start = (page - 1) * per_page
    end = start + per_page
    
    return {
        'total': total,
        'pages': pages,
        'current_page': page,
        'per_page': per_page,
        'data': results[start:end],
        'all_data': results  # 返回所有数据用于PHP后续处理
    }

def main():
    # 检查参数数量
    if len(sys.argv) != 3:
        print("用法: python gpflib_search.py <输入JSON文件路径> <页码>")
        sys.exit(1)
    
    input_file = sys.argv[1]
    # 处理页码参数
    try:
        current_page = int(sys.argv[2])
        if current_page < 1:
            current_page = 1
    except ValueError:
        current_page = 1  # 默认第一页
    
    output_file = input_file.replace('input', 'output')
    
    try:
        # 读取输入数据（PHP传递的语料和检索参数）
        with open(input_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        query = data.get('query', '')
        parsed_pattern = data.get('parsed_pattern', parse_query_pattern(query))
        entries = data.get('entries', [])
        all_results = []
        
        # 遍历每条语料记录进行检索
        for entry in entries:
            entry_id = entry.get('id', '未知ID')
            corpus_name = entry.get('corpus_name', '未知名称')
            source = entry.get('source', '未知来源')
            content = entry.get('content', '')
            
            try:
                # 获取检索模式
                pattern = parsed_pattern['pattern']
                # 查找所有匹配
                match_list = re.findall(pattern, content)
                frequency = len(match_list) if match_list else 0
                
                if frequency > 0:
                    # 提取匹配内容及上下文
                    examples = extract_context(content, pattern)
                    
                    # 构建结果条目
                    result_item = {
                        'id': entry_id,
                        'corpus_name': corpus_name,
                        'source': source,
                        'frequency': frequency,
                        'examples': examples,
                        'pattern_info': parsed_pattern
                    }
                    all_results.append(result_item)
            
            except Exception as e:
                # 记录错误但不中断执行
                print(f"处理语料ID {entry_id} 时出错: {str(e)}", file=sys.stderr)
                continue
        
        # 执行分页处理
        paginated_result = paginate_results(all_results, current_page, 10)
        
        # 保存结果到输出文件
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(paginated_result, f, ensure_ascii=False, indent=2)
            
        print(f"检索完成：共找到 {len(all_results)} 条匹配记录，当前显示第 {current_page} 页", file=sys.stdout)
        
    except Exception as e:
        print(f"系统错误: {str(e)}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
