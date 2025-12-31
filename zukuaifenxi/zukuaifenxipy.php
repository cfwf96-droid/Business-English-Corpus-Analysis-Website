<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=UTF-8');

$uploadDir = 'uploads/';
$resultDir = 'results/';
$logDir = 'logs/';

// 创建目录并确保可写
foreach ([$uploadDir, $resultDir, $logDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        chmod($dir, 0755);
    }
}

// 分页配置
$itemsPerPage = 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage);

$results = [];
$resultFile = '';
$totalPages = 1;
$totalItems = 0;
$errorMessage = '';

// 处理页码跳转
if (isset($_POST['jump_to_page']) && isset($_POST['target_page']) && isset($_GET['file'])) {
    $targetPage = (int)$_POST['target_page'];
    $targetPage = max(1, $targetPage);
    header("Location: zukuaifenxipy.php?file=" . basename($_GET['file']) . "&page=$targetPage");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['jump_to_page'])) {
    if (empty($_FILES['file'])) {
        $errorMessage = "错误：未选择文件";
    } else {
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = "错误：文件上传失败（错误码：{$file['error']}）";
        } else {
            $allowedExts = ['txt', 'docx', 'doc'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                $errorMessage = "错误：仅支持上传 txt、docx、doc 格式文件";
            } else {
                $targetFile = $uploadDir . uniqid() . ".{$ext}";
                if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
                    $errorMessage = "错误：文件保存失败";
                } else {
                    // 检测 Python 解释器
                    $pythonCmd = 'python3';
                    if (!exec('which python3', $output, $returnCode) || $returnCode !== 0) {
                        $pythonCmd = 'python';
                    }
                    
                    // 调用 Python 脚本，仅捕获标准输出，错误输出重定向到日志
                    $logFile = $logDir . 'python_error_' . date('Ymd') . '.log';
                    $command = "$pythonCmd analyze.py " . escapeshellarg($targetFile) . " 2>> " . escapeshellarg($logFile);
                    exec($command, $output, $returnCode);
                    
                    if ($returnCode !== 0) {
                        $errorMessage = "Python 脚本执行失败（返回码：{$returnCode}）";
                        $errorDetails = file_get_contents($logFile);
                        error_log($errorMessage . "\n" . $errorDetails);
                    } else {
                        // 保存结果
                        $resultContent = implode("\n", $output);
                        $resultFile = $resultDir . uniqid() . '.txt';
                        file_put_contents($resultFile, $resultContent);
                        
                        // 重定向到结果页面
                        header("Location: zukuaifenxipy.php?file=" . basename($resultFile));
                        exit;
                    }
                }
            }
        }
    }
}

// 加载已保存的结果
if (isset($_GET['file'])) {
    $resultFile = $resultDir . basename($_GET['file']);
    if (file_exists($resultFile) && is_readable($resultFile)) {
        // 读取结果并分页
        $content = file_get_contents($resultFile);
        if ($content !== false) {
            $results = explode("\n", $content);
            // 仅过滤完全空白的行（保留 "序号. 内容" 格式的行）
            $results = array_filter($results, function($item) {
                return trim($item) !== '';
            });
            $results = array_values($results);
            
            $totalItems = count($results);
            $totalPages = max(1, ceil($totalItems / $itemsPerPage));
            $currentPage = min($currentPage, $totalPages);
            
            // 获取当前页的结果
            $startIndex = ($currentPage - 1) * $itemsPerPage;
            $results = array_slice($results, $startIndex, $itemsPerPage);
        } else {
            $errorMessage = "错误：无法读取结果文件内容";
        }
    } else {
        $errorMessage = "错误：结果文件不存在或无法读取";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>文档组块分析工具</title>
    <style>
        body { 
            font-family: 'Microsoft YaHei', sans-serif; 
            padding: 20px; 
            max-width: 1200px;
            margin: 0 auto;
            line-height: 1.8;
            color: #333;
        }
        .container {
            background-color: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        form {
            margin-bottom: 30px;
            padding: 20px;
            background-color: white;
            border-radius: 6px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        input[type="file"] {
            margin: 10px 0;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #45a049;
        }
        .results {
            background-color: white;
            padding: 30px;
            border-radius: 6px;
            margin-bottom: 20px;
            white-space: pre-wrap;
            word-break: break-all;
            box-shadow: 0 1px 5px rgba(0,0,0,0.05);
        }
        .pagination {
            margin: 20px 0;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pagination a {
            display: inline-block;
            padding: 8px 16px;
            text-decoration: none;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .pagination a.active {
            background-color: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .pagination a:hover:not(.active) {
            background-color: #f1f1f1;
        }
        .pagination a.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .page-info {
            margin: 0 10px;
            color: #666;
        }
        .jump-form {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 10px;
        }
        .jump-form input {
            width: 50px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }
        .jump-form button {
            padding: 6px 12px;
            font-size: 14px;
        }
        .download-link {
            display: inline-block;
            margin: 20px 0;
            padding: 10px 20px;
            background-color: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .download-link:hover {
            background-color: #0b7dda;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .info {
            color: #0c5460;
            background-color: #d1ecf1;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #bee5eb;
        }
        .result-item {
            margin-bottom: 20px;
            padding: 15px;
            border-left: 4px solid #4CAF50;
            background-color: #f9f9f9;
            border-radius: 0 4px 4px 0;
            position: relative;
        }
        .result-number {
            font-weight: bold;
            color: #4CAF50;
            margin-right: 10px;
            display: inline-block;
            min-width: 30px;
        }
        .result-content {
            display: inline;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .page-stats {
            color: #666;
            margin-bottom: 15px;
            font-style: italic;
            padding-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>中文文档组块分析工具（JIEBA-PYTHON）</h1>
        <form enctype="multipart/form-data" method="post">
            <p>请上传需要分析的文档（支持 txt、docx、doc 格式）：</p>
            <input type="file" name="file" accept=".txt,.docx,.doc" required>
            <br>
            <button type="submit">上传并分析</button>
        </form>

        <?php if (!empty($errorMessage)): ?>
            <div class="error"><?php echo $errorMessage; ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['file']) && file_exists($resultFile)): ?>
            <h2>分析结果</h2>
            
            <?php if (empty($results)): ?>
                <div class="info">没有找到分析结果数据。可能文件内容为空或分析过程中出现问题。</div>
            <?php else: ?>
                <div class="page-stats">
                    共 <?php echo $totalItems; ?> 条记录，每页显示 <?php echo $itemsPerPage; ?> 条，当前第 <?php echo $currentPage; ?> / <?php echo $totalPages; ?> 页
                </div>
                
                <div class="results">
                    <?php foreach ($results as $index => $item): ?>
                        <?php
                        // 提取序号和内容（兼容 "数字. 内容" 格式）
                        $parts = preg_split('/^(\d+)\.\s*/', $item, 2, PREG_SPLIT_DELIM_CAPTURE);
                        
                        if (count($parts) >= 3) {
                            $number = $parts[1];
                            $content = $parts[2];
                        } else {
                            // 格式异常时，使用全局序号和原始内容
                            $number = ($currentPage - 1) * $itemsPerPage + $index + 1;
                            $content = $item;
                        }
                        ?>
                        <div class="result-item">
                            <span class="result-number"><?php echo $number; ?>.</span>
                            <span class="result-content"><?php echo htmlspecialchars($content, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <a href="zukuaifenxipy.php?file=<?php echo htmlspecialchars(basename($resultFile)) ?>&page=1" 
                           class="<?php echo $currentPage == 1 ? 'disabled' : ''; ?>">首页</a>
                        
                        <?php if ($currentPage > 1): ?>
                            <a href="zukuaifenxipy.php?file=<?php echo htmlspecialchars(basename($resultFile)) ?>&page=<?php echo $currentPage - 1; ?>">上一页</a>
                        <?php else: ?>
                            <a href="#" class="disabled">上一页</a>
                        <?php endif; ?>
                        
                        <?php
                        $visiblePages = 5;
                        $halfVisible = floor($visiblePages / 2);
                        $startPage = max(1, $currentPage - $halfVisible);
                        $endPage = min($totalPages, $startPage + $visiblePages - 1);
                        
                        if ($endPage - $startPage + 1 < $visiblePages) {
                            $startPage = max(1, $endPage - $visiblePages + 1);
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="zukuaifenxipy.php?file=<?php echo htmlspecialchars(basename($resultFile)) ?>&page=<?php echo $i; ?>" 
                               class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="zukuaifenxipy.php?file=<?php echo htmlspecialchars(basename($resultFile)) ?>&page=<?php echo $currentPage + 1; ?>">下一页</a>
                        <?php else: ?>
                            <a href="#" class="disabled">下一页</a>
                        <?php endif; ?>
                        
                        <a href="zukuaifenxipy.php?file=<?php echo htmlspecialchars(basename($resultFile)) ?>&page=<?php echo $totalPages; ?>" 
                           class="<?php echo $currentPage == $totalPages ? 'disabled' : ''; ?>">尾页</a>
                        
                        <span class="page-info">共 <?php echo $totalPages; ?> 页</span>
                        
                        <form method="post" class="jump-form">
                            <input type="number" name="target_page" value="<?php echo $currentPage; ?>" 
                                   min="1" max="<?php echo $totalPages; ?>" required>
                            <button type="submit" name="jump_to_page">跳转</button>
                        </form>
                    </div>
                <?php endif; ?>

                <a href="<?php echo htmlspecialchars($resultFile); ?>" class="download-link" download>下载完整分析结果</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
