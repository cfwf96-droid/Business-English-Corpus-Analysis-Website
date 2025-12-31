<?php
session_start();

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

// 确保上传和结果目录存在并可写
$uploadDir = 'uploads/';
$resultDir = 'results/';
foreach ([$uploadDir, $resultDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        die("目录 {$dir} 不可写，请检查权限设置");
    }
}

// 初始化变量
$message = '';
$downloadFile = '';

/**
 * 读取不同类型文件的内容并处理编码
 */
function readFileContent($filePath, $extension) {
    try {
        switch ($extension) {
            case 'txt':
                $content = file_get_contents($filePath);
                if ($content === false) return false;
                
                // 检测并转换编码为UTF-8
                $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1']);
                if ($encoding && $encoding != 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }
                return trim($content);
                
            case 'doc':
            case 'docx':
                $phpWord = IOFactory::load($filePath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
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
                return trim($text);
                
            default:
                return false;
        }
    } catch (Exception $e) {
        error_log("文件读取错误: " . $e->getMessage());
        return false;
    }
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && empty($missingExtensions)) {
    $file = $_FILES['file'];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => '文件超过PHP配置限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传',
            UPLOAD_ERR_NO_FILE => '未上传文件',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '上传被扩展阻止'
        ];
        $message = "上传错误: " . ($uploadErrors[$file['error']] ?? "未知错误 ({$file['error']})");
    } else {
        // 验证文件类型
        $allowedTypes = [
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        
        // 如果没有ZipArchive扩展，只允许TXT文件
        if (!class_exists('ZipArchive')) {
            $allowedTypes = ['text/plain' => 'txt'];
        }
        
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($file['tmp_name']);
        
        if (!isset($allowedTypes[$mimeType])) {
            $allowedFiles = class_exists('ZipArchive') ? 'TXT、DOC和DOCX' : 'TXT';
            $message = "不支持的文件类型 ({$mimeType})。请上传{$allowedFiles}文件。";
        } else {
            // 处理文件名
            $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
            $extension = $allowedTypes[$mimeType];
            $filename = uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;
            
            // 移动上传文件
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // 读取文件内容
                $content = readFileContent($targetPath, $extension);
                
                if ($content !== false && $content !== '') {
                    try {
                        // 初始化Jieba分词
                        Jieba::init();
                        Posseg::init();
                        
                        // 进行词性标注
                        $segments = Posseg::cut($content);
                        
                        // 构建标注结果
                        $result = [];
                        foreach ($segments as $segment) {
                            if (!empty($segment['word'])) { // 过滤空字符
                                $result[] = $segment['word'] . "/" . $segment['tag'];
                            }
                        }
                        
                        if (empty($result)) {
                            throw new Exception("未识别到可标注的内容");
                        }
                        
                        $resultContent = implode(' ', $result);
                        
                        // 保存结果文件
                        $resultFilename = $originalName . '_标注结果.txt';
                        $resultPath = $resultDir . $resultFilename;
                        
                        if (file_put_contents($resultPath, $resultContent) === false) {
                            throw new Exception("无法写入结果文件");
                        }
                        
                        // 清理上传的文件
                        unlink($targetPath);
                        
                        $message = "词性标注完成！";
                        $downloadFile = $resultFilename;
                        $_SESSION['last_download'] = $resultFilename;
                        
                    } catch (Exception $e) {
                        $message = "标注过程出错: " . $e->getMessage();
                        error_log("标注错误: " . $e->getMessage());
                    }
                } else {
                    $message = $content === '' ? "文件内容为空" : "无法读取文件内容";
                }
            } else {
                $message = "文件上传失败，请检查上传目录权限";
            }
        }
    }
}

// 下载文件处理
if (isset($_GET['download']) && isset($_SESSION['last_download'])) {
    $filename = $_SESSION['last_download'];
    $filePath = $resultDir . $filename;
    
    if (file_exists($filePath) && is_readable($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $message = "下载文件不存在或无法读取";
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
            max-width: 800px;
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
            display: inline-block;
            margin-top: 15px;
            padding: 10px 15px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .download-link:hover {
            background-color: #0b7dda;
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
                <p>当前仅支持TXT文件上传。</p>
            </div>
        <?php endif; ?>
        
        <p>上传文本文件，系统将对文本内容进行词性标注，并生成可下载的结果文件。</p>
        
        <div class="upload-form">
            <form method="post" enctype="multipart/form-data">
                <label for="file">选择文件：</label>
                <input type="file" name="file" id="file" 
                    accept="<?php echo class_exists('ZipArchive') ? '.txt,.doc,.docx' : '.txt'; ?>" required>
                <br>
                <button type="submit">开始标注</button>
            </form>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo strpos($message, '失败') !== false || strpos($message, '错误') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($downloadFile)): ?>
            <a href="?download=1" class="download-link">下载标注结果：<?php echo $downloadFile; ?></a>
        <?php endif; ?>
    </div>
</body>
</html>
