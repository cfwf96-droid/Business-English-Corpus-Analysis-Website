<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 延长执行时间

// 检查必要的扩展
$missingExtensions = [];
if (!class_exists('ZipArchive')) {
    $missingExtensions[] = 'ZipArchive（处理Word文件需要）';
}

// 引入必要的类
require_once 'vendor/autoload.php';
use Fukuball\Jieba\Jieba;
use Fukuball\Jieba\Posseg;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Text;

// 确保目录存在
$tmpDir = 'tmp/';
$resultDir = 'results/';
$logDir = 'logs/';

foreach ([$tmpDir, $resultDir, $logDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 初始化变量
$message = '';
$processedFiles = [];
$errorFiles = [];

// 记录日志
function logMessage($message) {
    global $logDir;
    $logFile = $logDir . date('Ymd') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

/**
 * 读取不同类型文件的内容
 */
function readFileContent($filePath, $extension, &$error = '') {
    try {
        switch (strtolower($extension)) {
            case 'txt':
                // 尝试不同编码读取TXT文件
                $content = file_get_contents($filePath);
                if ($content === false) {
                    $error = "无法读取TXT文件内容";
                    return false;
                }
                
                // 处理编码问题
                $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1']);
                if ($encoding && $encoding != 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }
                return trim($content);
                
            case 'doc':
                // 处理.doc文件 - 使用IOFactory尝试加载
                try {
                    // 尝试使用Word2003阅读器
                    $phpWord = IOFactory::load($filePath, 'Word2003');
                } catch (Exception $e) {
                    $error = "不支持的DOC文件格式: " . $e->getMessage();
                    return false;
                }
                break;
                
            case 'docx':
                // 处理.docx文件
                $phpWord = IOFactory::load($filePath);
                break;
                
            default:
                $error = "不支持的文件类型: $extension";
                return false;
        }
        
        // 提取Word内容
        $text = '';
        $sections = $phpWord->getSections();
        foreach ($sections as $section) {
            $elements = $section->getElements();
            foreach ($elements as $element) {
                if ($element instanceof TextRun) {
                    foreach ($element->getElements() as $textElement) {
                        if ($textElement instanceof Text) {
                            $text .= $textElement->getText() . ' ';
                        }
                    }
                } elseif ($element instanceof Text) {
                    $text .= $element->getText() . ' ';
                }
            }
        }
        
        $text = trim($text);
        if (empty($text)) {
            $error = "文件内容为空";
            return false;
        }
        
        return $text;
    } catch (Exception $e) {
        $error = "读取文件时出错: " . $e->getMessage();
        return false;
    }
}

/**
 * 处理单个文件
 */
function processFile($filePath, $originalName, $resultDir, &$processedFiles, &$errorFiles) {
    $pathInfo = pathinfo($filePath);
    $extension = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';
    $fileName = $pathInfo['filename'];
    
    // 检查文件类型
    $allowedExtensions = ['txt', 'doc', 'docx'];
    if (!in_array(strtolower($extension), $allowedExtensions)) {
        $errorFiles[] = [
            'file' => $originalName . '.' . $extension,
            'error' => '不支持的文件类型'
        ];
        logMessage("不支持的文件类型: $originalName.$extension");
        return false;
    }
    
    // 读取文件内容
    $error = '';
    $content = readFileContent($filePath, $extension, $error);
    if ($content === false) {
        $errorFiles[] = [
            'file' => $originalName . '.' . $extension,
            'error' => $error
        ];
        logMessage("读取文件失败 $originalName.$extension: $error");
        return false;
    }
    
    // 初始化Jieba（确保只初始化一次）
    static $jiebaInitialized = false;
    if (!$jiebaInitialized) {
        try {
            Jieba::init();
            Posseg::init();
            $jiebaInitialized = true;
        } catch (Exception $e) {
            $errorFiles[] = [
                'file' => $originalName . '.' . $extension,
                'error' => 'Jieba初始化失败: ' . $e->getMessage()
            ];
            logMessage("Jieba初始化失败: " . $e->getMessage());
            return false;
        }
    }
    
    // 进行词性标注
    try {
        $segments = Posseg::cut($content);
        
        // 构建标注结果
        $result = [];
        foreach ($segments as $segment) {
            if (!empty($segment['word'])) { // 跳过空词
                $result[] = $segment['word'] . "/" . $segment['tag'];
            }
        }
        
        if (empty($result)) {
            $errorFiles[] = [
                'file' => $originalName . '.' . $extension,
                'error' => '词性标注结果为空'
            ];
            logMessage("词性标注结果为空: $originalName.$extension");
            return false;
        }
        
        $resultContent = implode(' ', $result);
    } catch (Exception $e) {
        $errorFiles[] = [
            'file' => $originalName . '.' . $extension,
            'error' => '词性标注失败: ' . $e->getMessage()
        ];
        logMessage("词性标注失败 $originalName.$extension: " . $e->getMessage());
        return false;
    }
    
    // 创建与源文件相同的目录结构
    $relativeDir = dirname($originalName);
    $targetDir = $resultDir . $relativeDir . '/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true, true);
    }
    
    // 保存结果文件
    $resultFilename = $fileName . '_标注结果.txt';
    $resultPath = $targetDir . $resultFilename;
    
    if (file_put_contents($resultPath, $resultContent) === false) {
        $errorFiles[] = [
            'file' => $originalName . '.' . $extension,
            'error' => '无法写入结果文件'
        ];
        logMessage("无法写入结果文件: $resultPath");
        return false;
    }
    
    $processedFiles[] = [
        'original' => $originalName . '.' . $extension,
        'result' => $relativeDir . '/' . $resultFilename,
        'resultName' => $resultFilename
    ];
    
    logMessage("成功处理文件: $originalName.$extension");
    return true;
}

/**
 * 递归遍历文件夹并处理所有文件
 */
function processDirectory($dir, $resultDir, &$processedFiles, &$errorFiles, $parentDir = '') {
    $items = scandir($dir);
    if ($items === false) {
        logMessage("无法扫描目录: $dir");
        return;
    }
    
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        $itemPath = $dir . '/' . $item;
        $relativePath = $parentDir ? $parentDir . '/' . $item : $item;
        
        if (is_dir($itemPath)) {
            // 递归处理子文件夹
            processDirectory($itemPath, $resultDir, $processedFiles, $errorFiles, $relativePath);
        } else {
            // 处理文件
            $pathInfo = pathinfo($item);
            $originalName = $parentDir ? $parentDir . '/' . $pathInfo['filename'] : $pathInfo['filename'];
            processFile($itemPath, $originalName, $resultDir, $processedFiles, $errorFiles);
        }
    }
}

// 处理文件夹上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($missingExtensions)) {
    logMessage("开始处理文件夹上传");
    
    if (isset($_FILES['folder']) && is_array($_FILES['folder']['error']) && $_FILES['folder']['error'][0] !== UPLOAD_ERR_NO_FILE) {
        $files = $_FILES['folder'];
        $tmpFolder = $tmpDir . uniqid() . '/';
        mkdir($tmpFolder, 0755, true);
        logMessage("创建临时文件夹: $tmpFolder");
        
        // 保存上传的文件夹内容
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $nameParts = explode('/', $files['name'][$i]);
                $fileName = array_pop($nameParts);
                $relativePath = implode('/', $nameParts);
                
                // 创建目录结构
                if (!empty($relativePath)) {
                    $targetDir = $tmpFolder . $relativePath . '/';
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                        logMessage("创建目录: $targetDir");
                    }
                }
                
                // 移动文件
                $targetFile = $tmpFolder . $files['name'][$i];
                if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                    logMessage("上传文件: " . $files['name'][$i] . " 到 " . $targetFile);
                } else {
                    logMessage("无法移动文件: " . $files['name'][$i]);
                }
            } else if ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                logMessage("文件上传错误 ({$files['error'][$i]}): " . $files['name'][$i]);
            }
        }
        
        // 处理文件夹中的所有文件
        processDirectory($tmpFolder, $resultDir, $processedFiles, $errorFiles);
        
        // 清理临时文件
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpFolder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($tmpFolder);
        logMessage("清理临时文件夹: $tmpFolder");
        
        // 生成结果消息
        $totalFiles = count($processedFiles) + count($errorFiles);
        if ($totalFiles > 0) {
            $message = "共处理 $totalFiles 个文件，成功 " . count($processedFiles) . " 个，失败 " . count($errorFiles) . " 个。";
        } else {
            $message = "文件夹中未找到可处理的文件（支持TXT、DOC、DOCX）";
        }
    } else {
        $message = "请选择要上传的文件夹";
        logMessage("未选择文件夹");
    }
}

// 下载单个结果文件
if (isset($_GET['download']) && !empty($_GET['file'])) {
    $filePath = $resultDir . $_GET['file'];
    $fileName = basename($filePath);
    
    // 安全检查，确保只能下载结果目录内的文件
    if (file_exists($filePath) && strpos(realpath($filePath), realpath($resultDir)) === 0) {
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $message = "下载文件不存在或无权访问";
        logMessage("下载失败: " . $_GET['file']);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>中文文本词性标注工具</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            background-color: #f5f5f5;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
        }
        .upload-form {
            margin: 20px 0;
            padding: 20px;
            background-color: #fff;
            border-radius: 6px;
        }
        input[type="file"] {
            margin: 10px 0;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .message {
            padding: 10px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
        }
        .warning {
            background-color: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .download-link {
            color: #2196F3;
            text-decoration: none;
        }
        .download-link:hover {
            text-decoration: underline;
        }
        .results-section {
            margin-top: 20px;
        }
        .section-title {
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        .file-list-container {
            margin: 15px 0;
            padding: 10px;
            background-color: #fff;
            border-radius: 4px;
            max-height: 300px;
            overflow-y: auto;
        }
        .file-list {
            list-style-type: none;
            padding: 0;
        }
        .file-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .file-list li:last-child {
            border-bottom: none;
        }
        .folder-indicator {
            color: #666;
            font-style: italic;
            margin: 5px 0 5px 20px;
        }
        .error-details {
            font-size: 0.9em;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>中文文本词性标注工具</h1>
        
        <?php if (!empty($missingExtensions)): ?>
            <div class="warning">
                <strong>注意：</strong>检测到缺少必要的PHP扩展：
                <ul>
                    <?php foreach ($missingExtensions as $ext): ?>
                        <li><?php echo $ext; ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>请按照以下步骤启用：</p>
                <ol>
                    <li>打开XAMPP控制面板</li>
                    <li>点击Apache的"Config"按钮，选择"php.ini"</li>
                    <li>在文件中找到";extension=zip"，去掉前面的分号</li>
                    <li>保存文件并重启Apache服务器</li>
                </ol>
                <p>当前仅支持TXT文件处理。</p>
            </div>
        <?php endif; ?>
        
        <div class="warning">
            <strong>注意：</strong>对于DOC文件（.doc）的支持有限，建议使用DOCX或TXT格式以获得更好的处理效果。
        </div>
        
        <p>上传文件夹，系统将对文件夹内所有Word文件（.doc, .docx）和文本文件（.txt）进行词性标注，并为每个文件生成对应的标注结果。</p>
        
        <div class="upload-form">
            <form method="post" enctype="multipart/form-data">
                <label for="folder">选择文件夹：</label>
                <input type="file" name="folder[]" id="folder" webkitdirectory directory multiple
                    accept="<?php echo class_exists('ZipArchive') ? '.txt,.doc,.docx' : '.txt'; ?>" required>
                <p><small>提示：选择文件夹后，系统将处理其中所有支持的文件类型</small></p>
                <br>
                <button type="submit">开始标注</button>
            </form>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo (count($errorFiles) > 0 && count($processedFiles) == 0) ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="results-section">
            <?php if (!empty($processedFiles)): ?>
                <h3 class="section-title">成功处理的文件：</h3>
                <div class="file-list-container">
                    <ul class="file-list">
                        <?php 
                        $currentDir = '';
                        foreach ($processedFiles as $file): 
                            $fileDir = dirname($file['original']);
                            if ($fileDir !== $currentDir) {
                                $currentDir = $fileDir;
                                ?>
                                <li class="folder-indicator">📂 <?php echo $currentDir ?: '根目录'; ?></li>
                                <?php
                            }
                        ?>
                            <li>
                                <?php echo basename($file['original']); ?> → 
                                <a href="?download=1&file=<?php echo urlencode($file['result']); ?>" class="download-link">
                                    <?php echo $file['resultName']; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errorFiles)): ?>
                <h3 class="section-title">处理失败的文件：</h3>
                <div class="file-list-container">
                    <ul class="file-list">
                        <?php foreach ($errorFiles as $file): ?>
                            <li>
                                <?php echo $file['file']; ?>
                                <div class="error-details">原因：<?php echo $file['error']; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
