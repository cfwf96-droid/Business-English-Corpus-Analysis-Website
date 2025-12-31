<?php
// 检查必需扩展
if (!extension_loaded('mbstring')) {
    die('<div style="color:red; padding:20px;">❌ 请先启用PHP的mbstring扩展！<br>
        操作步骤：<br>
        1. 打开XAMPP控制面板→Apache→Config→PHP(php.ini)<br>
        2. 搜索 "extension=mbstring"，删除前面的分号(;)<br>
        3. 重启Apache服务</div>');
}
if (!extension_loaded('curl')) {
    die('<div style="color:red; padding:20px;">❌ 请先启用PHP的CURL扩展！<br>
        操作步骤：<br>
        1. 打开XAMPP控制面板→Apache→Config→PHP(php.ini)<br>
        2. 搜索 "extension=curl"，删除前面的分号(;)<br>
        3. 重启Apache服务</div>');
}

// 初始化变量
$results = [];
$full_chunks = []; // 存储完整组块内容
$error = "";
$success = "";
$user_query = "";
$top_n = 10;
$need_report = false;
$query_info = [];
$uploaded_corpus_path = __DIR__ . DIRECTORY_SEPARATOR . "upload" . DIRECTORY_SEPARATOR . "corpus_processed.txt";
$allowed_ext = ["txt", "docx", "doc"];

// 确保上传目录存在
if (!file_exists(dirname($uploaded_corpus_path))) {
    mkdir(dirname($uploaded_corpus_path), 0755, true);
    chmod(dirname($uploaded_corpus_path), 0755);
}

// Python服务状态检测函数
function check_python_service($host, $port, $timeout = 3) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

// 工具函数
function read_docx($file_path) {
    $content = "";
    $zip = new ZipArchive();
    if ($zip->open($file_path) === true) {
        $xml_path = $zip->locateName('word/document.xml') !== false ? 'word/document.xml' : '';
        if ($xml_path) {
            $xml = $zip->getFromName($xml_path);
            preg_match_all('/<w:t>(.*?)<\/w:t>/s', $xml, $matches);
            if (!empty($matches[1])) {
                $content = implode(' ', $matches[1]) . "\n";
            }
        }
        $zip->close();
    }
    $content = mb_convert_encoding($content, "UTF-8", "UTF-16LE");
    return $content;
}

function read_txt($file_path) {
    $content = file_get_contents($file_path);
    if (empty($content)) return "";
    $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1'], true);
    if ($encoding && $encoding !== "UTF-8") {
        $content = mb_convert_encoding($content, "UTF-8", $encoding);
    }
    $lines = explode("\n", $content);
    $clean_lines = [];
    foreach ($lines as $line) {
        $clean_lines[] = preg_replace('/\s+/', ' ', trim($line));
    }
    return implode("\n", $clean_lines);
}

function read_doc($file_path) {
    return "【DOC格式需手动转换为TXT/DOCX后上传（推荐用记事本另存为UTF-8格式）】";
}

function has_chinese($content) {
    $len = mb_strlen($content, "UTF-8");
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($content, $i, 1, "UTF-8");
        $ord = mb_ord($char, "UTF-8");
        if (($ord >= 0x4E00 && $ord <= 0x9FFF) || 
            ($ord >= 0x3400 && $ord <= 0x4DBF) || 
            ($ord >= 0xF900 && $ord <= 0xFAFF)) {
            return true;
        }
    }
    return false;
}

function has_chunk_mark($content) {
    $lower_content = strtolower($content);
    return strpos($lower_content, '(root(') !== false;
}

// 高亮匹配内容
function highlight_match($chunk_content, $match_content) {
    if (empty($match_content) || strpos($chunk_content, $match_content) === false) {
        return $chunk_content;
    }
    // 转义特殊字符
    $match_escaped = preg_quote($match_content, '/');
    // 不区分大小写高亮
    return preg_replace(
        '/(' . $match_escaped . ')/iu',
        '<span style="color:red; font-weight:bold;">\1</span>',
        $chunk_content
    );
}

// 文件上传与验证
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["corpus_file"])) {
    $file = $_FILES["corpus_file"];
    
    if ($file["error"] !== UPLOAD_ERR_OK) {
        $error = [
            UPLOAD_ERR_INI_SIZE => "文件超过服务器限制（php.ini中upload_max_filesize）",
            UPLOAD_ERR_FORM_SIZE => "文件超过表单限制（HTML中maxlength）",
            UPLOAD_ERR_PARTIAL => "文件仅部分上传（网络中断）",
            UPLOAD_ERR_NO_FILE => "未选择文件（请点击选择按钮）",
            UPLOAD_ERR_NO_TMP_DIR => "服务器缺少临时目录（联系管理员）",
            UPLOAD_ERR_CANT_WRITE => "磁盘不可写（检查服务器权限）"
        ][$file["error"]] ?? "未知上传错误（错误码：{$file["error"]}）";
        goto retrieve_flow;
    }

    $file_ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_ext)) {
        $error = "仅支持TXT/DOCX/DOC格式！当前文件：{$file["name"]}（格式：{$file_ext}）";
        goto retrieve_flow;
    }

    $file_content = "";
    switch ($file_ext) {
        case "txt":
            $file_content = read_txt($file["tmp_name"]);
            break;
        case "docx":
            $file_content = read_docx($file["tmp_name"]);
            break;
        case "doc":
            $file_content = read_doc($file["tmp_name"]);
            if (strpos($file_content, "【DOC格式需手动转换】") !== false) {
                $error = $file_content;
                goto retrieve_flow;
            }
            break;
    }

    $lines = explode("\n", $file_content);
    $preview_lines = array_slice($lines, 0, 5);
    $content_preview = implode("\n", $preview_lines) . (count($lines) > 5 ? "\n..." : "");

    if (empty($file_content)) {
        $error = "文件内容为空！\n内容预览：{$content_preview}";
        goto retrieve_flow;
    }
    if (!has_chunk_mark($file_content)) {
        $error = "非中文组块文件！需包含组块标识：(ROOT(（允许前后有内容）\n内容预览：{$content_preview}";
        goto retrieve_flow;
    }
    if (!has_chinese($file_content)) {
        $error = "非中文组块文件！需包含中文内容\n内容预览：{$content_preview}";
        goto retrieve_flow;
    }

    if (file_put_contents($uploaded_corpus_path, $file_content) === false) {
        $error = "文件保存失败！请检查upload目录权限。";
        goto retrieve_flow;
    }

    if (!file_exists($uploaded_corpus_path)) {
        $error = "文件保存后消失！请重新上传。";
        goto retrieve_flow;
    }

    $success = "✅ 文件上传成功！\n"
             . "保存路径：" . $uploaded_corpus_path . "\n"
             . "内容预览（前5行）：\n{$content_preview}";
}

// 检索逻辑
retrieve_flow:
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $user_query = trim($_POST["query"]);
    $top_n = intval($_POST["top_n"]) ?: 10;
    $need_report = isset($_POST["need_report"]) ? true : false;
    $action = $_POST["action"];
    $python_port = intval($_POST["python_port"] ?? 8000);
    $python_host = "127.0.0.1";

    if (!check_python_service($python_host, $python_port)) {
        $error = "❌ Python服务未启动或端口不可访问！\n"
               . "当前检测：http://{$python_host}:{$python_port}\n"
               . "解决方案：\n"
               . "1. 确认Python服务已启动（命令：uvicorn chunk_analyzer:app --reload --port {$python_port}）\n"
               . "2. 检查端口是否被占用（Windows命令：netstat -ano | findstr :{$python_port}）\n"
               . "3. 关闭防火墙或添加端口例外（允许{$python_port}端口入站）\n"
               . "4. 尝试更换端口（如8001，需同时修改Python启动命令）";
        goto display_page;
    }

    if (!file_exists($uploaded_corpus_path)) {
        $error = "❌ 语料文件不存在！请先上传中文组块文件。";
        goto display_page;
    }
    $saved_content = file_get_contents($uploaded_corpus_path);
    if (!has_chunk_mark($saved_content)) {
        $error = "❌ 语料文件中无'(ROOT('标识！请重新上传正确的组块文件。";
        goto display_page;
    }

    if (empty($user_query)) {
        $error = "❌ 请输入检索式（示例：VP-PRD[v一v]、NULL-MOD[d]VP-PRD[a]）";
        goto display_page;
    }

    $api_url = $action === "download" 
        ? "http://{$python_host}:{$python_port}/download_report" 
        : "http://{$python_host}:{$python_port}/retrieve_chunk";
    
    $post_data = json_encode([
        "query" => $user_query,
        "top_n" => $top_n,
        "need_report" => true,
        "corpus_path" => $uploaded_corpus_path
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($post_data)
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $curl_err = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 处理下载（直接返回TXT文件）
    if ($action === "download") {
        if ($curl_err) {
            $error = get_curl_error_msg($curl_err, $curl_errno, $python_host, $python_port);
        } elseif ($http_code != 200) {
            $error = "❌ 下载失败（HTTP状态码：{$http_code}）\n服务器响应：" . mb_substr($response, 0, 200);
        } else {
            // 直接输出文件内容（Python已确保UTF-8编码）
            header("Content-Type: text/plain; charset=utf-8");
            header("Content-Disposition: attachment; filename=组块检索报告_".date('YmdHis').".txt");
            echo $response;
            exit;
        }
        goto display_page;
    }

    // 处理检索响应
    if ($curl_err) {
        $error = get_curl_error_msg($curl_err, $curl_errno, $python_host, $python_port);
    } elseif ($http_code != 200) {
        $error = "❌ API请求失败（HTTP状态码：{$http_code}）\n服务器响应：" . mb_substr($response, 0, 200);
    } else {
        $response_data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = "❌ API响应解析错误：" . json_last_error_msg() . "\n响应预览：" . mb_substr($response, 0, 200, "UTF-8") . "...";
        } elseif ($response_data["status"] !== "success") {
            $error = "❌ 检索失败：" . ($response_data["detail"] ?? "未知错误");
        } else {
            $results = $response_data["results"];
            $full_chunks = $response_data["full_chunks"] ?? []; // 获取完整组块内容
            $query_info = $response_data["query_info"];
        }
    }
}

// 错误提示函数
function get_curl_error_msg($curl_err, $curl_errno, $host, $port) {
    $base_msg = "❌ API调用失败：{$curl_err}\n";
    $solution = "";

    switch ($curl_errno) {
        case CURLE_COULDNT_CONNECT:
            $solution = "解决方案：\n"
                      . "1. 确认Python服务启动命令包含端口：uvicorn chunk_analyzer:app --reload --port {$port}\n"
                      . "2. 检查端口占用：在命令提示符中执行 netstat -ano | findstr :{$port}\n"
                      . "3. 若端口被占用，更换端口号（如8001）并同步修改PHP页面中的端口设置\n"
                      . "4. 测试服务：在浏览器中访问 http://{$host}:{$port}/docs 看是否能打开";
            break;
        case CURLE_OPERATION_TIMEDOUT:
            $solution = "解决方案：\n"
                      . "1. 确认Python服务在运行（命令窗口有进度提示）\n"
                      . "2. 语料过大时拆分文件（建议≤200MB）\n"
                      . "3. 延长PHP超时：修改zkfs2.php中CURLOPT_TIMEOUT为60";
            break;
        default:
            $solution = "解决方案：\n"
                      . "1. 重启Python服务和Apache\n"
                      . "2. 查看Python命令窗口的错误信息";
    }

    return $base_msg . $solution;
}

// 检索式示例
$query_examples = [
    ["VP-PRD[v一v]", "动词重叠式（如：走一走、听一听）"],
    ["NP-SBJ[n]", "主语名词短语中的名词（如：商务、汉语）"],
    ["NULL-MOD[d]VP-PRD[a]", "状中结构（如：很好、挺合适）"],
    ["NP-SBJ[nr]", "主语中的人名（如：王经理、李总）"],
    ["VP-PRD[NULL-MOD[d]VP-PRD[v]]", "VP内嵌套副词+动词（如：才走、又说）"]
];

display_page:
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>中文组块检索工具</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: #f8f9fa; padding: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); margin-bottom: 25px; }
        .upload-card { border-left: 4px solid #2196F3; }
        .retrieve-card { border-left: 4px solid #4CAF50; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        input[type="text"], input[type="number"], input[type="file"] {
            width: 70%; padding: 10px; font-size: 15px; border: 1px solid #ddd;
            border-radius: 4px; transition: border 0.3s;
        }
        input:focus { border-color: #2196F3; outline: none; box-shadow: 0 0 0 2px rgba(33,150,243,0.2); }
        .btn {
            padding: 10px 22px; font-size: 15px; border: none; border-radius: 4px;
            cursor: pointer; color: white; transition: background 0.3s;
        }
        .btn-upload { background: #2196F3; }
        .btn-upload:hover { background: #1976D2; }
        .btn-retrieve { background: #4CAF50; }
        .btn-retrieve:hover { background: #388E3C; }
        .btn-download { background: #FF9800; margin-left: 10px; }
        .btn-download:hover { background: #F57C00; }
        .alert { padding: 12px 15px; border-radius: 4px; margin: 15px 0; font-size: 15px; line-height: 1.6; white-space: pre-wrap; }
        .alert-error { background: #FFF8F8; color: #D32F2F; border: 1px solid #FFCDD2; }
        .alert-success { background: #F3FFF8; color: #2E7D32; border: 1px solid #C8E6C9; }
        .examples { margin-top: 10px; padding: 12px; background: #F1F8E9; border-radius: 4px; font-size: 14px; }
        .example-item { margin: 6px 0; color: #2E7D32; }
        .result-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .result-table th, .result-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .result-table th { background: #F5F5F5; color: #555; }
        .intention-card { background: #E3F2FD; padding: 12px; border-radius: 4px; margin: 15px 0; color: #1565C0; font-size: 15px; }
        .file-note { margin-top: 8px; color: #666; font-size: 14px; line-height: 1.5; }
        .preview { margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px; font-size: 14px; color: #666; white-space: pre-wrap; }
        .service-tip { margin: 10px 0; padding: 12px; background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 4px; color: #1565c0; }
        .port-config { margin: 15px 0; padding: 12px; background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px; }
        .chunk-content { margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; overflow-x: auto; }
        .highlight { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>用户上传组块分析文件检索</h1>
        
        <!-- Python服务启动提示 -->
        <div class="service-tip">
            ⚠️ 提醒：检索前必须启动Python服务！
        </div>

        <!-- 1. 文件上传区域 -->
        <div class="card upload-card">
            <h2>第一步：上传中文组块文件</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="corpus_file">选择文件（推荐TXT，支持DOCX）</label>
                    <input type="file" id="corpus_file" name="corpus_file" accept=".txt,.docx,.doc" required>
                    <div class="file-note">
                        ✅ 格式要求：每行包含一个组块（如：1. (ROOT(IP(NP-SBJ(n 商务)...))）
                    </div>
                </div>
                <button type="submit" class="btn btn-upload">上传并验证</button>
                <?php if (file_exists($uploaded_corpus_path)): ?>
                    <?php 
                    $saved_lines = explode("\n", file_get_contents($uploaded_corpus_path));
                    $saved_preview = implode("\n", array_slice($saved_lines, 0, 5)) . (count($saved_lines) > 5 ? "\n..." : "");
                    $file_size = round(filesize($uploaded_corpus_path) / 1024 / 1024, 2);
                    ?>
                    <div class="preview">
                        当前使用语料：<?php echo $uploaded_corpus_path; ?><br>
                        文件大小：<?php echo $file_size; ?> MB<br>
                        内容预览（前5行）：<br><?php echo $saved_preview; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- 提示信息 -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- 2. 检索区域 -->
        <?php if (file_exists($uploaded_corpus_path)): ?>
            <div class="card retrieve-card">
                <h2>第二步：输入检索式进行检索</h2>
                <div class="port-config">
                    <strong>Python服务端口配置</strong>（默认8000，若被占用可修改为8001、8002等）<br>
                    请确保与Python启动命令中的--port参数一致！
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="retrieve">
                    <div class="form-group">
                        <label for="python_port">Python服务端口</label>
                        <input type="number" id="python_port" name="python_port" 
                               value="<?php echo isset($_POST["python_port"]) ? $_POST["python_port"] : 8000; ?>" 
                               min="1024" max="65535" required>
                    </div>
                    <div class="form-group">
                        <label for="query">检索式（支持单/多/嵌套标签）</label>
                        <input type="text" id="query" name="query" value="<?php echo htmlspecialchars($user_query); ?>" 
                               placeholder="示例：VP-PRD[v一v]（动词重叠）、NULL-MOD[d]VP-PRD[a]（状中结构）">
                        <div class="examples">
                            <strong>常用检索式示例：</strong><br>
                            <?php foreach ($query_examples as $ex): ?>
                                <div class="example-item">
                                    <code><?php echo $ex[0]; ?></code> → <?php echo $ex[1]; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="top_n">显示前N条结果（1-100）</label>
                        <input type="number" id="top_n" name="top_n" value="<?php echo $top_n; ?>" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <input type="checkbox" id="need_report" name="need_report" <?php echo $need_report ? "checked" : ""; ?>>
                        <label for="need_report" style="display:inline; font-weight:normal; margin-left:5px;">生成UTF-8报告（支持下载）</label>
                    </div>
                    <button type="submit" class="btn btn-retrieve">开始检索</button>
                    <?php if (!empty($results) && $need_report): ?>
                        <button type="submit" class="btn btn-download" onclick="this.form.action.value='download'">下载报告</button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 检索需求分析 -->
            <?php if (!empty($query_info)): ?>
                <div class="intention-card">
                    <strong>检索需求：</strong> <?php echo htmlspecialchars($query_info["intention"] ?? "未知需求"); ?><br>
                    <strong>检索式：</strong> <?php echo htmlspecialchars($query_info["raw_query"] ?? $user_query); ?><br>
                    <strong>匹配结果：</strong> <?php echo $query_info["result_count"] ?? 0; ?> 条（当前显示前 <?php echo count($results); ?> 条）
                </div>
            <?php endif; ?>

            <!-- 结果展示（包含完整组块内容和标红） -->
            <?php if (!empty($results)): ?>
                <div style="margin-top:25px;">
                    <h3>检索结果列表</h3>
                    <table class="result-table">
                        <thead>
                            <tr>
                                <th>序号</th>
                                <th>匹配内容</th>
                                <th>组块记录号</th>
                                <th>完整组块内容（匹配部分标红）</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $idx => $res): ?>
                                <?php
                                $chunk_content = $full_chunks[$res["line_num"]] ?? "未找到组块内容";
                                $highlighted_chunk = highlight_match($chunk_content, $res["content"]);
                                ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><?php echo htmlspecialchars($res["content"]); ?></td>
                                    <td><?php echo $res["line_num"]; ?></td>
                                    <td class="chunk-content"><?php echo $highlighted_chunk; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)): ?>
                <div class="alert alert-error">❌ 未找到匹配结果，建议简化检索式测试。</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        const portInput = document.getElementById('python_port');
        const portDisplay = document.getElementById('port-display');
        const portDisplay2 = document.getElementById('port-display-2');
        
        portInput.addEventListener('input', function() {
            const port = this.value || 8000;
            portDisplay.textContent = port;
            portDisplay2.textContent = port;
        });
    </script>
</body>
</html>
