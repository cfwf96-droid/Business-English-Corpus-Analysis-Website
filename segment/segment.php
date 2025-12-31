<?php
session_start();

// 检查必要的库是否安装
if (!file_exists('vendor/autoload.php')) {
    die('请先通过Composer安装依赖：composer require fukuball/jieba-php phpoffice/phpword');
}

require 'vendor/autoload.php';

use Fukuball\Jieba\Jieba;
use Fukuball\Jieba\Finalseg;
use PhpOffice\PhpWord\IOFactory;

// 初始化Jieba分词
Jieba::init();
Finalseg::init();

// 确保上传目录存在
$uploadDir = 'uploads/';
$outputDir = 'outputs/';
foreach ([$uploadDir, $outputDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "文件上传错误: ";
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $error .= "文件大小超过了php.ini中的限制";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $error .= "文件大小超过了表单中的限制";
                break;
            case UPLOAD_ERR_PARTIAL:
                $error .= "文件只上传了一部分";
                break;
            case UPLOAD_ERR_NO_FILE:
                $error .= "没有文件被上传";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $error .= "缺少临时文件夹";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $error .= "文件写入失败";
                break;
            default:
                $error .= "未知错误";
        }
        $_SESSION['message'] = ['type' => 'error', 'text' => $error];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 验证文件类型
    $allowedTypes = [
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];
    
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file['tmp_name']);
    
    if (!isset($allowedTypes[$mimeType])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '不支持的文件类型，请上传TXT、DOC或DOCX文件'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 生成唯一文件名并移动到上传目录
    $extension = $allowedTypes[$mimeType];
    $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
    $filename = uniqid() . '.' . $extension;
    $uploadPath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '文件移动失败'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 读取文件内容
    $content = '';
    try {
        if ($extension === 'txt') {
            // 读取TXT文件
            $content = file_get_contents($uploadPath);
            // 尝试转换编码为UTF-8
            $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ASCII']);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
        } else {
            // 读取Word文件
            $phpWord = IOFactory::load($uploadPath);
            $sections = $phpWord->getSections();
            foreach ($sections as $section) {
                $elements = $section->getElements();
                foreach ($elements as $element) {
                    if (method_exists($element, 'getText')) {
                        $content .= $element->getText() . "\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '文件读取失败: ' . $e->getMessage()];
        unlink($uploadPath); // 清理上传的文件
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 执行分词
    $segments = Jieba::cut($content);
    $segmentedContent = implode(' ', $segments);
    
    // 生成输出文件
    $outputFilename = $originalName . '分词.txt';
    $outputPath = $outputDir . $outputFilename;
    
    if (file_put_contents($outputPath, $segmentedContent) === false) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '分词结果写入失败'];
        unlink($uploadPath);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 清理上传的文件
    unlink($uploadPath);
    
    // 存储输出文件信息供下载
    $_SESSION['downloadFile'] = [
        'path' => $outputPath,
        'name' => $outputFilename
    ];
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?download=1');
    exit;
}

// 处理文件下载
if (isset($_GET['download']) && $_GET['download'] == 1 && isset($_SESSION['downloadFile'])) {
    $fileInfo = $_SESSION['downloadFile'];
    $filePath = $fileInfo['path'];
    $fileName = $fileInfo['name'];
    
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        
        // 清理输出文件
        unlink($filePath);
        unset($_SESSION['downloadFile']);
        exit;
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => '下载文件不存在'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// 显示消息
$message = '';
if (isset($_SESSION['message'])) {
    $msgType = $_SESSION['message']['type'];
    $msgText = $_SESSION['message']['text'];
    $message = "<div class='message $msgType'>$msgText</div>";
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>中文分词工具</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            background-color: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
        }
        .upload-form {
            margin-top: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="file"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
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
            margin-bottom: 15px;
            border-radius: 4px;
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
        .instructions {
            background-color: #e8f4fd;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>中文分词工具</h1>
        <?php echo $message; ?>
        <div class="upload-form">
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="file">选择要分词的文件（支持TXT、DOC、DOCX）：</label>
                    <input type="file" id="file" name="file" accept=".txt,.doc,.docx" required>
                </div>
                <button type="submit">开始分词</button>
            </form>
        </div>
        
        <div class="instructions">
            <h3>使用说明</h3>
            <ul>
                <li>支持上传TXT、DOC和DOCX格式的文件</li>
                <li>系统会对文件内容进行中文分词处理</li>
                <li>处理完成后，将生成带有"分词"后缀的TXT文件供下载</li>
                <li>所有文件编码将转换为UTF-8格式</li>
            </ul>
        </div>
    </div>
</body>
</html>
