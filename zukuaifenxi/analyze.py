import sys
import re
import logging
import os
import warnings
import codecs
from contextlib import contextmanager
from datetime import datetime
from jieba.posseg import cut as pos_cut  # 词性标注

# 抑制所有警告
warnings.filterwarnings("ignore")

# 确保标准输出使用UTF-8编码
sys.stdout = codecs.getwriter("utf-8")(sys.stdout.detach())

# 日志配置
log_dir = 'logs/'
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, f"python_{datetime.now().strftime('%Y-%m-%d')}.log")
logging.basicConfig(
    filename=log_file,
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s',
    encoding='utf-8'  # 确保日志文件使用UTF-8编码
)

# 增加递归深度和内存限制，处理大文件
sys.setrecursionlimit(1000000)

# 上下文管理器：捕获并屏蔽所有输出
@contextmanager
def suppress_output():
    with open(os.devnull, 'w', encoding='utf-8') as devnull:
        old_stdout = sys.stdout
        old_stderr = sys.stderr
        sys.stdout = devnull
        sys.stderr = devnull
        try:
            yield
        finally:
            sys.stdout = old_stdout
            sys.stderr = old_stderr

# 彻底屏蔽jieba的所有输出和警告
with suppress_output():
    import jieba
    # 强制重新初始化jieba，避免使用缓存，防止任何输出
    jieba.initialize()
    # 预加载词典，确保后续操作无输出
    jieba.load_userdict(os.devnull)

# 模拟 GPF 类（实现语法树生成逻辑）
class GPF:
    def Parse(self, sent, Structure="Tree"):
        """生成符合要求的组块分析树"""
        try:
            # 分词+词性标注，确保无输出
            with suppress_output():
                words = list(pos_cut(sent.strip()))
            if not words:
                return "[空句子，跳过分析]"  # 关键修复：空句子返回提示
            # 分离标点（识别句尾标点）
            punc = None
            content_words = []
            for word in words:
                if word.flag == 'x':  # 标点符号
                    punc = word
                else:
                    content_words.append(word)
            
            # 构建语法树节点：NP-SBJ（主语）、VP-PRD（谓语）、标点
            np_sbj = []  # 体词性主语组块
            vp_prd = []  # 谓词性述语组块
            
            for word in content_words:
                flag = word.flag
                word_str = word.word
                # 语法规则
                if flag in ['t', 'n', 'uj', 'r']:  # 时间、名词、助词、代词 归为主语
                    np_sbj.append(f"({flag} {word_str})")
                elif flag == 'd':  # 副词 归为修饰组块
                    vp_prd.append(f"NULL-MOD({flag} {word_str})")
                elif flag in ['v', 'a']:  # 动词、形容词 归为谓词
                    vp_prd.append(f"VP-PRD({flag} {word_str})")
                else:
                    # 其他词性暂归主语（可扩展语法规则）
                    np_sbj.append(f"({flag} {word_str})")
            
            # 组装各部分
            np_sbj_str = f"(NP-SBJ{''.join(np_sbj)})" if np_sbj else ""
            vp_prd_str = f"(VP-PRD{''.join(vp_prd)})" if vp_prd else ""
            punc_str = f"(w(x {punc.word}))" if punc else ""
            
            # 根节点组装
            ip_str = f"(IP{np_sbj_str}{vp_prd_str}{punc_str})"
            root_str = f"(ROOT{ip_str})"
            
            return root_str
        except Exception as e:
            logging.error(f"GPF.Parse 失败: {str(e)}", exc_info=True)
            return f"[分析错误: {str(e)}]"

# 读取文件内容（支持 txt/docx/doc），大文件分块读取
def read_file(file_path):
    try:
        file_size = os.path.getsize(file_path)
        logging.info(f"文件大小: {file_size} 字节")
        
        # 对于大文件，使用流式读取
        max_file_size = 10 * 1024 * 1024  # 10MB
        if file_size > max_file_size:
            logging.info(f"处理大文件，采用分块读取: {file_path}")
        
        ext = os.path.splitext(file_path)[-1].lower()
        content = ""
        
        if ext == '.txt':
            # 大文件分块读取
            with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
                if file_size <= max_file_size:
                    content = f.read()
                else:
                    # 大文件处理，每次读取1MB
                    chunk_size = 1024 * 1024
                    while True:
                        chunk = f.read(chunk_size)
                        if not chunk:
                            break
                        content += chunk
                        # 防止内存溢出，处理后清空部分内容
                        if len(content) > 2 * chunk_size:
                            last_punctuation = max(
                                content.rfind('。'),
                                content.rfind('？'),
                                content.rfind('！'),
                                content.rfind('…'),
                                content.rfind('\n')
                            )
                            if last_punctuation != -1:
                                content = content[last_punctuation:]
        
        elif ext == '.docx':
            from docx import Document
            doc = Document(file_path)
            # 分段落读取，避免大文件内存问题
            paragraphs = []
            for para in doc.paragraphs:
                paragraphs.append(para.text)
                # 定期清理内存
                if len(paragraphs) % 100 == 0:
                    content += '\n'.join(paragraphs)
                    paragraphs = []
            content += '\n'.join(paragraphs)
        
        elif ext == '.doc':
            import subprocess
            # 对于大的doc文件，使用管道处理
            process = subprocess.Popen(
                ['antiword', file_path],
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                encoding='utf-8',
                errors='ignore'
            )
            
            # 分块读取输出
            chunk_size = 1024 * 1024
            while True:
                chunk = process.stdout.read(chunk_size)
                if not chunk:
                    break
                content += chunk
            process.wait()
        
        else:
            return "不支持的文件格式"
            
        if not content.strip():
            return "文件内容为空或无法识别"
            
        logging.info(f"成功读取文件，内容长度: {len(content)}字符")
        return content
        
    except Exception as e:
        logging.error(f"读取文件失败: {str(e)}", exc_info=True)
        return f"文件读取错误: {str(e)}"

# 分句（中文标点分割），优化大文本处理
def split_sentences(text):
    try:
        # 处理所有中文句尾标点：。？！…以及它们的全角形式
        # 增加对省略号(...)和中英文标点的支持
        sentence_endings = r'([。？！…．？！．\.\?\!]{1,3})'
        pattern = re.compile(sentence_endings)
        
        # 处理大文本时，分批分句
        sentences = []
        start = 0
        text_length = len(text)
        batch_size = 10000  # 每批处理10000字符
        
        while start < text_length:
            end = min(start + batch_size, text_length)
            batch = text[start:end]
            
            # 确保批次结束在标点后，避免句子被截断
            if end < text_length:
                # 查找最后一个句尾标点
                last_pos = -1
                for ending in re.findall(sentence_endings, batch):
                    pos = batch.rfind(ending)
                    if pos > last_pos:
                        last_pos = pos
                
                if last_pos != -1:
                    end = start + last_pos + len(batch[last_pos:])
                    batch = text[start:end]
            
            # 对当前批次进行分句
            parts = pattern.split(batch)
            
            for i in range(0, len(parts), 2):
                sent_part = parts[i].strip()
                if sent_part:
                    punc = parts[i+1] if i+1 < len(parts) else ''
                    sentences.append(sent_part + punc)
            
            start = end
        
        logging.info(f"成功分句，共 {len(sentences)} 句")
        return sentences
    except Exception as e:
        logging.error(f"分句失败: {str(e)}", exc_info=True)
        return [text.strip()]

def main():
    try:
        if len(sys.argv) < 2:
            print("请传入文件路径")
            return
        
        file_path = sys.argv[1]
        logging.info(f"开始处理文件: {file_path}")
        
        # 读取文件内容
        text = read_file(file_path)
        if not text or text.startswith(("不支持", "文件读取", "文件内容为空")):
            print(text)
            return
        
        # 分句处理
        sentences = split_sentences(text)
        if not sentences:
            print("未能从文本中提取出句子")
            return
        
        # 初始化GPF
        gpf = GPF()
        
        # 处理每个句子，支持大文件的流式输出
        for idx, sent in enumerate(sentences, 1):
            if not sent.strip():
                continue
            try:
                seg_result = gpf.Parse(sent, Structure="Tree")
                # 逐句输出，避免大文件内存问题
                print(f"{idx}. {seg_result}")
                # 刷新输出缓冲区
                sys.stdout.flush()
            except Exception as e:
                print(f"{idx}. [错误: {str(e)}]")
                sys.stdout.flush()
                
    except Exception as e:
        error_msg = f"程序执行出错: {str(e)}"
        logging.error(error_msg, exc_info=True)
        print(error_msg)
        sys.exit(1)

if __name__ == "__main__":
    main()
