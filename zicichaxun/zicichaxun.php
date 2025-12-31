<?php
session_start();
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '512M');

// 定义目录常量
define('BASE_DIR', __DIR__);
define('UPLOAD_DIR', BASE_DIR . '/uploads/');
define('RESULTS_DIR', BASE_DIR . '/results/');

// 确保必要的目录存在并具有正确权限
foreach ([UPLOAD_DIR, RESULTS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        die("错误：目录 $dir 不可写，请检查权限设置。");
    }
}

// 处理文件下载
handleDownload();

// 处理搜索请求
$searchResults = [];
$searchTerm = '';
$fileStats = [];
$totalResults = 0;
$currentPage = 1;
$resultsPerPage = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    // 验证并获取搜索词
    if (!empty($_POST['search_term'])) {
        $searchTerm = trim($_POST['search_term']);
        $_SESSION['search_term'] = $searchTerm;
    } else {
        $_SESSION['error'] = "请输入搜索内容";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 处理上传的文件
    $files = [];
    
    // 处理单个文件上传
    if (!empty($_FILES['single_files']['name'][0])) {
        foreach ($_FILES['single_files']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['single_files']['error'][$key] === UPLOAD_ERR_OK) {
                $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($_FILES['single_files']['name'][$key]));
                $destination = UPLOAD_DIR . $fileName;
                
                if (move_uploaded_file($tmpName, $destination)) {
                    $files[] = [
                        'path' => $destination,
                        'original_name' => $_FILES['single_files']['name'][$key]
                    ];
                } else {
                    $_SESSION['warning'][] = "无法上传文件: " . $_FILES['single_files']['name'][$key];
                }
            }
        }
    }
    
    // 处理文件夹上传（通过zip文件）
    if (!empty($_FILES['folder_file']['tmp_name']) && $_FILES['folder_file']['error'] === UPLOAD_ERR_OK) {
        $zipFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($_FILES['folder_file']['name']));
        $zipDestination = UPLOAD_DIR . $zipFileName;
        
        if (move_uploaded_file($_FILES['folder_file']['tmp_name'], $zipDestination)) {
            // 创建临时文件夹
            $extractDir = UPLOAD_DIR . uniqid('folder_') . '/';
            mkdir($extractDir, 0755, true);
            
            // 解压zip文件
            $zip = new ZipArchive;
            if ($zip->open($zipDestination) === TRUE) {
                $zip->extractTo($extractDir);
                $zip->close();
                
                // 遍历解压后的文件
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extractDir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    if (!$file->isDir()) {
                        $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                        if (in_array($extension, ['txt', 'doc', 'docx'])) {
                            $newFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '', $file->getFilename());
                            $newFilePath = UPLOAD_DIR . $newFileName;
                            
                            if (copy($file->getPathname(), $newFilePath)) {
                                $files[] = [
                                    'path' => $newFilePath,
                                    'original_name' => $file->getFilename()
                                ];
                            }
                        }
                    }
                }
            } else {
                $_SESSION['warning'][] = "无法解压文件夹";
            }
            
            // 清理临时文件
            unlink($zipDestination);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($extractDir);
        } else {
            $_SESSION['warning'][] = "无法上传文件夹";
        }
    }
    
    if (empty($files)) {
        $_SESSION['error'] = "请上传至少一个文件或文件夹";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // 处理每个文件并搜索
    foreach ($files as $file) {
        $content = extractTextFromFile($file['path']);
        if ($content !== false) {
            $results = searchText($content, $searchTerm, $file['original_name']);
            $searchResults = array_merge($searchResults, $results);
            
            // 更新文件统计
            $count = count(array_filter($results, function($item) use ($file) {
                return $item['file_name'] === $file['original_name'];
            }));
            
            if ($count > 0) {
                $fileStats[$file['original_name']] = $count;
            }
        } else {
            $_SESSION['warning'][] = "无法处理文件: " . $file['original_name'];
        }
    }
    
    // 保存结果到会话
    $_SESSION['search_results'] = $searchResults;
    $_SESSION['file_stats'] = $fileStats;
    $_SESSION['total_results'] = count($searchResults);
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 从会话中恢复搜索状态
if (isset($_SESSION['search_results'])) {
    $searchResults = $_SESSION['search_results'];
    $fileStats = $_SESSION['file_stats'];
    $totalResults = $_SESSION['total_results'];
    $searchTerm = $_SESSION['search_term'];
    
    // 处理分页
    if (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) {
        $currentPage = (int)$_GET['page'];
    }
    
    // 生成报告
    if (isset($_GET['generate_report'])) {
        generateReport($searchResults, $fileStats, $searchTerm);
    }
}

// 清除搜索结果
if (isset($_GET['clear'])) {
    unset($_SESSION['search_results'], $_SESSION['file_stats'], 
          $_SESSION['total_results'], $_SESSION['search_term'], $_SESSION['report_file']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 计算分页
$totalPages = max(1, ceil($totalResults / $resultsPerPage));
$startIndex = ($currentPage - 1) * $resultsPerPage;
$paginatedResults = array_slice($searchResults, $startIndex, $resultsPerPage);

// 处理文件下载的函数
function handleDownload() {
    if (isset($_GET['download']) && isset($_GET['file'])) {
        // 清除所有输出缓冲
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $file = basename($_GET['file']);
        $filePath = RESULTS_DIR . $file;
        
        if (file_exists($filePath) && is_readable($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode($file) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            $_SESSION['error'] = "下载文件不存在或无法访问";
        }
    }
}

// 从不同类型的文件中提取文本
function extractTextFromFile($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'txt':
            return extractTextFromTxt($filePath);
        case 'doc':
            return extractTextFromDoc($filePath);
        case 'docx':
            return extractTextFromDocx($filePath);
        default:
            return false;
    }
}

// 从TXT文件提取文本
function extractTextFromTxt($filePath) {
    $content = file_get_contents($filePath);
    if ($content === false) return false;
    
    // 尝试检测并转换编码
    $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1']);
    if ($encoding && $encoding != 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    
    return $content;
}

// 从DOC文件提取文本
function extractTextFromDoc($filePath) {
    // 方法1: 使用catdoc命令行工具 (需要服务器支持)
    if (is_executable('/usr/bin/catdoc') || is_executable('/usr/local/bin/catdoc')) {
        $command = (is_executable('/usr/bin/catdoc') ? '/usr/bin/catdoc' : '/usr/local/bin/catdoc') . ' ' . escapeshellarg($filePath);
        $output = shell_exec($command);
        if ($output !== null) return $output;
    }
    
    // 方法2: 尝试使用PHP读取(准确率较低)
    $fileHandle = fopen($filePath, "r");
    $line = @fread($fileHandle, filesize($filePath));   
    $lines = explode(chr(0x0D), $line);
    $content = "";
    foreach ($lines as $thisline) {
        $pos = strpos($thisline, chr(0x00));
        if (($pos !== false)||(strlen($thisline) == 0)) {
            continue;
        }
        $content .= $thisline . " ";
    }
    fclose($fileHandle);
    
    return trim($content);
}

// 从DOCX文件提取文本
function extractTextFromDocx($filePath) {
    // DOCX是一个zip文件，里面包含XML格式的内容
    $zip = new ZipArchive;
    if ($zip->open($filePath) === TRUE) {
        // 主文档内容通常在word/document.xml中
        if (($index = $zip->locateName('word/document.xml')) !== false) {
            $content = $zip->getFromIndex($index);
            $zip->close();
            
            // 移除XML标签并提取文本
            $content = strip_tags($content);
            // 处理特殊字符
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
            
            return $content;
        }
        $zip->close();
    }
    
    return false;
}

// 在文本中搜索并提取包含搜索词的句子
function searchText($content, $searchTerm, $fileName) {
    $results = [];
    if (empty($content) || empty($searchTerm)) return $results;
    
    // 将文本分割成句子（使用中文标点符号）
    $sentences = preg_split('/(?<=[。！？；,.!?;])\s*/u', $content);
    
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (empty($sentence)) continue;
        
        // 检查句子中是否包含搜索词
        if (stripos($sentence, $searchTerm) !== false) {
            // 高亮搜索词
            $highlighted = preg_replace(
                '/' . preg_quote($searchTerm, '/') . '/iu',
                '<span style="color: red; font-weight: bold;">$0</span>',
                $sentence
            );
            
            $results[] = [
                'file_name' => $fileName,
                'sentence' => $sentence,
                'highlighted_sentence' => $highlighted,
                'count' => substr_count(strtolower($sentence), strtolower($searchTerm))
            ];
        }
    }
    
    return $results;
}

// 生成报告
function generateReport($results, $fileStats, $searchTerm) {
    $reportContent = "搜索报告: " . $searchTerm . "\n";
    $reportContent .= "生成时间: " . date('Y-m-d H:i:s') . "\n\n";
    
    $reportContent .= "文件统计:\n";
    foreach ($fileStats as $fileName => $count) {
        $reportContent .= "- {$fileName}: 出现 {$count} 次\n";
    }
    
    $reportContent .= "\n搜索结果:\n";
    foreach ($results as $index => $result) {
        $reportContent .= ($index + 1) . ". [{$result['file_name']}] {$result['sentence']}\n";
    }
    
    $reportFileName = 'search_report_' . date('YmdHis') . '.txt';
    $reportPath = RESULTS_DIR . $reportFileName;
    
    file_put_contents($reportPath, $reportContent);
    
    $_SESSION['report_file'] = $reportFileName;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文本内容查询工具</title>
    <style>
        body {
            font-family: "Microsoft YaHei", "SimSun", sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
            background-color: #f9f9f9;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2, h3 {
            color: #2c3e50;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        h1 {
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #34495e;
        }
        input[type="text"], input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
        }
        button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #2980b9;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error {
            background-color: #fadbd8;
            color: #e74c3c;
            border: 1px solid #f5b7b1;
        }
        .success {
            background-color: #d5f5e3;
            color: #27ae60;
            border: 1px solid #a9dfbf;
        }
        .warning {
            background-color: #fef5d4;
            color: #f39c12;
            border: 1px solid #fde3a7;
        }
        .results-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .result-item {
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #eee;
            border-radius: 4px;
            background-color: #f9f9f9;
            transition: box-shadow 0.3s;
        }
        .result-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .result-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .result-number {
            font-weight: bold;
            color: #3498db;
        }
        .file-name {
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .pagination {
            margin-top: 20px;
            text-align: center;
        }
        .pagination a {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            text-decoration: none;
            color: #3498db;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .pagination a.active {
            background-color: #3498db;
            color: white;
            border-color: #3498db;
        }
        .pagination a:hover:not(.active) {
            background-color: #f1f1f1;
        }
        .stats {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .stats h3 {
            margin-top: 0;
        }
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .file-upload {
            margin-bottom: 15px;
            padding: 15px;
            border: 1px dashed #ccc;
            border-radius: 4px;
            background-color: #f8f9fa;
        }
        .file-upload-info {
            font-size: 0.9em;
            color: #7f8c8d;
            margin-top: 5px;
        }
        .search-term {
            font-weight: bold;
            color: #e74c3c;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>文本内容查询工具</h1>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning']) && !empty($_SESSION['warning'])): ?>
            <div class="message warning">
                <?php foreach ($_SESSION['warning'] as $msg): ?>
                    <p><?php echo $msg; ?></p>
                <?php endforeach; unset($_SESSION['warning']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['report_file'])): ?>
            <div class="message success">
                报告已生成: <a href="?download=1&file=<?php echo urlencode($_SESSION['report_file']); ?>">
                点击下载报告</a>
            </div>
            <?php unset($_SESSION['report_file']); ?>
        <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="search_term">查询内容：</label>
                <input type="text" id="search_term" name="search_term" 
                       value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       placeholder="请输入要查询的内容" required>
            </div>
            
            <div class="form-group">
                <label>文本来源（可选择多个）：</label>
                
                <div class="file-upload">
                    <label for="single_files">选择单个或多个文件（支持txt, doc, docx）：</label>
                    <input type="file" id="single_files" name="single_files[]" multiple 
                           accept=".txt,.doc,.docx">
                    <div class="file-upload-info">
                        提示：按住Ctrl键可选择多个文件
                    </div>
                </div>
                
                <div class="file-upload">
                    <label for="folder_file">选择文件夹（请上传ZIP压缩包）：</label>
                    <input type="file" id="folder_file" name="folder_file" accept=".zip">
                    <div class="file-upload-info">
                        提示：要上传文件夹，请先将文件夹压缩为ZIP格式，系统会自动解压并处理其中的文件
                    </div>
                </div>
            </div>
            
            <div class="actions">
                <button type="submit" name="search">开始查询</button>
                <?php if (!empty($searchResults)): ?>
                    <button type="button" onclick="if(confirm('确定要清除当前结果吗？')) window.location='?clear=1'">清除结果</button>
                    <a href="?generate_report=1">
                        <button type="button">生成查询报告</button>
                    </a>
                <?php endif; ?>
            </div>
        </form>
        
        <?php if (!empty($searchResults)): ?>
            <div class="results-section">
                <h2>查询结果：<span class="search-term">"<?php echo htmlspecialchars($searchTerm); ?>"</span></h2>
                
                <div class="stats">
                    <h3>统计信息</h3>
                    <p>总匹配结果: <strong><?php echo $totalResults; ?></strong></p>
                    
                    <h4>文件分布：</h4>
                    <ul>
                        <?php foreach ($fileStats as $fileName => $count): ?>
                            <li><?php echo htmlspecialchars($fileName); ?>: <?php echo $count; ?> 次出现</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <h3>第 <?php echo $currentPage; ?> 页，共 <?php echo $totalPages; ?> 页</h3>
                
                <?php if (count($paginatedResults) > 0): ?>
                    <?php foreach ($paginatedResults as $index => $result): ?>
                        <?php $globalIndex = $startIndex + $index + 1; ?>
                        <div class="result-item">
                            <div class="result-header">
                                <div class="result-number">结果 #<?php echo $globalIndex; ?></div>
                                <div class="file-name"><?php echo htmlspecialchars($result['file_name']); ?></div>
                            </div>
                            <div class="sentence">
                                <?php echo $result['highlighted_sentence']; ?>
                            </div>
                            <div>本句中出现次数: <?php echo $result['count']; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="message warning">
                        当前页没有查询结果
                    </div>
                <?php endif; ?>
                
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($currentPage > 1): ?>
                            <a href="?page=<?php echo $currentPage - 1; ?>">上一页</a>
                        <?php endif; ?>
                        
                        <?php 
                        // 显示合理数量的页码
                        $startPage = max(1, $currentPage - 3);
                        $endPage = min($totalPages, $currentPage + 3);
                        
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" <?php echo $i == $currentPage ? 'class="active"' : ''; ?>>
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="?page=<?php echo $currentPage + 1; ?>">下一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
    
