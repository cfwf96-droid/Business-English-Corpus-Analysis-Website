<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/analysis_errors.log');

// 创建必要的目录
$requiredDirs = ['uploads', 'results', 'logs'];
foreach ($requiredDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        chmod($dir, 0755);
    }
}

// 初始化变量
$message = '';
$messageType = '';
$analysisResults = [];
$pythonPath = '';
$libraryCheck = [];

// 检查Python环境
function findPython() {
    $possiblePaths = [
        'python3', 'python', 
        '/usr/bin/python3', '/usr/local/bin/python3',
        'C:/Python39/python.exe', 'C:/Python310/python.exe',
        'C:/Program Files/Python39/python.exe', 'C:/Program Files/Python310/python.exe',
        'C:/Program Files (x86)/Python39/python.exe', 'C:/Program Files (x86)/Python310/python.exe'
    ];
    
    foreach ($possiblePaths as $path) {
        $output = [];
        $returnVar = 0;
        exec("$path --version 2>&1", $output, $returnVar);
        if ($returnVar === 0) {
            return $path;
        }
    }
    return false;
}

// 检查Python库
function checkPythonLibrary($pythonPath, $library) {
    $command = escapeshellcmd("$pythonPath -c \"import $library\" 2>&1");
    exec($command, $output, $returnVar);
    return $returnVar === 0;
}

// 查找Python路径
$pythonPath = findPython();

// 检查必要的Python库
if ($pythonPath) {
    $libraryCheck = [
        'jieba' => checkPythonLibrary($pythonPath, 'jieba'),
        'matplotlib' => checkPythonLibrary($pythonPath, 'matplotlib'),
        'wordcloud' => checkPythonLibrary($pythonPath, 'wordcloud'),
        'numpy' => checkPythonLibrary($pythonPath, 'numpy'),
        'PIL' => checkPythonLibrary($pythonPath, 'PIL'),
        'docx' => checkPythonLibrary($pythonPath, 'docx'),
        'textract' => checkPythonLibrary($pythonPath, 'textract')
    ];
}

// 处理文件上传和分析
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['text_file'])) {
    // 检查Python是否可用
    if (!$pythonPath) {
        $message = "错误：未找到Python环境。请确保Python已安装并添加到系统PATH中。";
        $messageType = 'error';
    } else {
        // 检查必要的库
        $missingLibraries = [];
        foreach ($libraryCheck as $lib => $installed) {
            if (!$installed) {
                $missingLibraries[] = $lib;
            }
        }
        
        if (!empty($missingLibraries)) {
            $message = "错误：缺少必要的Python库：" . implode(', ', $missingLibraries) . "。请使用pip安装这些库。";
            $messageType = 'error';
        } else {
            // 处理文件上传
            $uploadedFile = $_FILES['text_file'];
            
            // 检查上传错误
            if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => "上传的文件超过了php.ini中upload_max_filesize选项限制的值。",
                    UPLOAD_ERR_FORM_SIZE => "上传文件的大小超过了HTML表单中指定的MAX_FILE_SIZE选项的值。",
                    UPLOAD_ERR_PARTIAL => "文件只有部分被上传。",
                    UPLOAD_ERR_NO_FILE => "没有文件被上传。",
                    UPLOAD_ERR_NO_TMP_DIR => "缺少临时文件夹。",
                    UPLOAD_ERR_CANT_WRITE => "文件写入失败。",
                    UPLOAD_ERR_EXTENSION => "PHP扩展阻止了文件的上传。"
                ];
                
                $message = "文件上传失败：" . ($errorMessages[$uploadedFile['error']] ?? "未知错误（错误代码：{$uploadedFile['error']}）");
                $messageType = 'error';
            } else {
                // 验证文件类型
                $allowedTypes = [
                    'text/plain' => 'txt',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/msword' => 'doc'
                ];
                
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($uploadedFile['tmp_name']);
                
                if (!isset($allowedTypes[$mimeType])) {
                    $message = "不支持的文件类型。请上传TXT、DOC或DOCX格式的文件。";
                    $messageType = 'error';
                } else {
                    // 生成唯一文件名
                    $baseName = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
                    $extension = $allowedTypes[$mimeType];
                    $uniqueId = uniqid();
                    $uploadPath = "uploads/{$baseName}_{$uniqueId}.{$extension}";
                    
                    // 移动上传的文件
                    if (!move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
                        $message = "无法保存上传的文件。请检查服务器权限。";
                        $messageType = 'error';
                    } else {
                        // 准备分析命令
                        $outputPrefix = "results/{$baseName}_{$uniqueId}";
                        $pythonScript = "text_analyzer.py";
                        
                        // 验证Python脚本是否存在
                        if (!file_exists($pythonScript)) {
                            $message = "找不到Python分析脚本：{$pythonScript}";
                            $messageType = 'error';
                        } else {
                            // 执行Python分析脚本
                            $command = escapeshellcmd("{$pythonPath} {$pythonScript} " . escapeshellarg($uploadPath) . " " . escapeshellarg($outputPrefix));
                            
                            // 执行命令并捕获输出和错误
                            $output = [];
                            $returnVar = 0;
                            exec($command . " 2>&1", $output, $returnVar);
                            
                            // 记录命令执行结果
                            error_log("Python命令: {$command}");
                            error_log("返回代码: {$returnVar}");
                            error_log("输出: " . implode("\n", $output));
                            
                            if ($returnVar !== 0) {
                                $message = "分析失败: " . implode("<br>", $output);
                                $messageType = 'error';
                            } else {
                                // 检查分析结果
                                $wordFreqFile = "{$outputPrefix}_word_freq.txt";
                                $charFreqFile = "{$outputPrefix}_char_freq.txt";
                                $wordcloudPng = "{$outputPrefix}_wordcloud.png";
                                $wordcloudJpg = "{$outputPrefix}_wordcloud.jpg";
                                $wordcloudSvg = "{$outputPrefix}_wordcloud.svg";
                                
                                if (file_exists($wordFreqFile) && file_exists($charFreqFile)) {
                                    $analysisResults = [
                                        'base_name' => $baseName,
                                        'word_freq' => $wordFreqFile,
                                        'char_freq' => $charFreqFile,
                                        'wordcloud_png' => file_exists($wordcloudPng) ? $wordcloudPng : null,
                                        'wordcloud_jpg' => file_exists($wordcloudJpg) ? $wordcloudJpg : null,
                                        'wordcloud_svg' => file_exists($wordcloudSvg) ? $wordcloudSvg : null
                                    ];
                                    
                                    $message = "文本分析完成！";
                                    $messageType = 'success';
                                } else {
                                    $message = "分析失败：未能生成分析结果文件。";
                                    $messageType = 'error';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文本分析与词云生成系统</title>
    <style>
        body {
            font-family: "Microsoft YaHei", sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
            line-height: 1.6;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        input[type="file"],
        input[type="text"],
        button {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 16px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            padding: 12px 20px;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #45a049;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-weight: bold;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        .warning {
            background-color: #fcf8e3;
            color: #8a6d3b;
            border: 1px solid #faebcc;
        }
        .results {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .result-section {
            margin-bottom: 30px;
        }
        .download-link {
            display: inline-block;
            margin: 10px 0;
            padding: 8px 15px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .download-link:hover {
            background-color: #0b7dda;
        }
        .wordcloud-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        .wordcloud-item {
            flex: 1;
            min-width: 300px;
            text-align: center;
        }
        .wordcloud-img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
        }
        .system-check {
            margin-bottom: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .check-item {
            margin: 8px 0;
        }
        .check-success {
            color: #3c763d;
        }
        .check-error {
            color: #a94442;
        }
        .python-path-config {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed #ccc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>文本分析与词云生成系统</h1>
        
        <div class="system-check">
            <h3>系统环境检查</h3>
            <div class="check-item">
                Python 环境: 
                <?php if ($pythonPath): ?>
                    <span class="check-success">已找到 (<?php echo htmlspecialchars($pythonPath); ?>)</span>
                <?php else: ?>
                    <span class="check-error">未找到 - 请安装Python并添加到系统PATH</span>
                <?php endif; ?>
            </div>
            
            <?php if ($pythonPath): ?>
                <div class="check-item">jieba 库: <?php echo $libraryCheck['jieba'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install jieba</span>'; ?></div>
                <div class="check-item">matplotlib 库: <?php echo $libraryCheck['matplotlib'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install matplotlib</span>'; ?></div>
                <div class="check-item">wordcloud 库: <?php echo $libraryCheck['wordcloud'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install wordcloud</span>'; ?></div>
                <div class="check-item">numpy 库: <?php echo $libraryCheck['numpy'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install numpy</span>'; ?></div>
                <div class="check-item">Pillow 库: <?php echo $libraryCheck['PIL'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install pillow</span>'; ?></div>
                <div class="check-item">python-docx 库: <?php echo $libraryCheck['docx'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install python-docx</span>'; ?></div>
                <div class="check-item">textract 库: <?php echo $libraryCheck['textract'] ? '<span class="check-success">已安装</span>' : '<span class="check-error">未安装 - 请运行 pip install textract</span>'; ?></div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="text_file">上传文本文件（支持TXT、DOC、DOCX）</label>
                <input type="file" id="text_file" name="text_file" accept=".txt,.doc,.docx" required>
            </div>
            
            <button type="submit">开始分析</button>
        </form>
        
        <?php if (!empty($analysisResults)): ?>
            <div class="results">
                <h2>分析结果: <?php echo htmlspecialchars($analysisResults['base_name']); ?></h2>
                
                <div class="result-section">
                    <h3>频率统计报告</h3>
                    <p>词频统计: <a href="<?php echo htmlspecialchars($analysisResults['word_freq']); ?>" class="download-link" download>下载词频报告</a></p>
                    <p>字频统计: <a href="<?php echo htmlspecialchars($analysisResults['char_freq']); ?>" class="download-link" download>下载字频报告</a></p>
                </div>
                
                <div class="result-section">
                    <h3>词云图</h3>
                    <div class="wordcloud-container">
                        <?php if ($analysisResults['wordcloud_png']): ?>
                            <div class="wordcloud-item">
                                <h4>PNG格式</h4>
                                <img src="<?php echo htmlspecialchars($analysisResults['wordcloud_png']); ?>" alt="词云图PNG版" class="wordcloud-img">
                                <br>
                                <a href="<?php echo htmlspecialchars($analysisResults['wordcloud_png']); ?>" class="download-link" download>下载PNG</a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($analysisResults['wordcloud_jpg']): ?>
                            <div class="wordcloud-item">
                                <h4>JPG格式</h4>
                                <img src="<?php echo htmlspecialchars($analysisResults['wordcloud_jpg']); ?>" alt="词云图JPG版" class="wordcloud-img">
                                <br>
                                <a href="<?php echo htmlspecialchars($analysisResults['wordcloud_jpg']); ?>" class="download-link" download>下载JPG</a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($analysisResults['wordcloud_svg']): ?>
                            <div class="wordcloud-item">
                                <h4>SVG格式</h4>
                                <img src="<?php echo htmlspecialchars($analysisResults['wordcloud_svg']); ?>" alt="词云图SVG版" class="wordcloud-img">
                                <br>
                                <a href="<?php echo htmlspecialchars($analysisResults['wordcloud_svg']); ?>" class="download-link" download>下载SVG</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
    