<?php
session_start();
header("Content-Type: text/html; charset=UTF-8");

// 数据库配置
$dbHost = 'localhost';
$dbName = 'business_chinese_corpus';
$dbUser = 'root';
$dbPass = 'whyylw_666666';

// 连接数据库
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 处理下载请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_report']) && !empty($_SESSION['all_search_results'])) {
    $results = $_SESSION['all_search_results'];
    $query = $_SESSION['search_query'];
    $patternDesc = $_SESSION['pattern_description'] ?? '未知检索类型';
    
    // UTF-8 BOM确保中文显示正常
    $reportContent = "\xEF\xBB\xBF";
    $reportContent .= "中文组块结构检索报告\n";
    $reportContent .= "========================================\n";
    $reportContent .= "检索式: " . $query . "\n";
    $reportContent .= "检索类型: " . $patternDesc . "\n";
    $reportContent .= "匹配记录总数: " . count($results) . "\n";
    $reportContent .= "检索时间: " . date('Y-m-d H:i:s') . "\n";
    $reportContent .= "========================================\n\n";
    
    foreach ($results as $idx => $result) {
        $id = $result['id'] ?? '未知ID';
        $corpusName = $result['corpus_name'] ?? '未知名称';
        $source = $result['source'] ?? '未知来源';
        $frequency = $result['frequency'] ?? 0;
        $examples = $result['examples'] ?? [];
        
        $reportContent .= "【记录 " . ($idx + 1) . "】\n";
        $reportContent .= "记录ID: " . $id . "\n";
        $reportContent .= "语料名称: " . $corpusName . "\n";
        $reportContent .= "来源: " . $source . "\n";
        $reportContent .= "匹配频次: " . $frequency . "\n";
        $reportContent .= "匹配内容:\n";
        
        foreach ($examples as $i => $example) {
            $cleanExample = strip_tags($example);
            $reportContent .= "  " . ($i + 1) . ". " . $cleanExample . "\n";
        }
        $reportContent .= "----------------------------------------\n\n";
    }
    
    $filename = '中文组块检索报告_' . date('YmdHis') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($reportContent));
    echo $reportContent;
    exit;
}

// 处理清除条件请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_conditions'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 中文组块结构检索式映射表
function getPatternMappings() {
    return [
        // 1. 词组的离合用法
        '/离合用法\{\w+-\w+\}/i' => [
            'type' => 'discontinuous_word',
            'pattern' => '/(\w+)[^\\w]+(\w+)/',
            'description' => '词组的离合用法（如"吃-饭"）'
        ],
        // 2. 状中结构
        '/状中结构\{\}/i' => [
            'type' => 'adverbial_center',
            'pattern' => '/(很|非常|特别|太)\w+/',
            'description' => '状中结构（如"很好、非常漂亮"）'
        ],
        // 3. 动词重叠式"v一v"
        '/v一v\{\}/i' => [
            'type' => 'verb_repetition_v1v',
            'pattern' => '/(\w+)一\1/',
            'description' => '动词重叠式"v一v"（如"看一看、听一听"）'
        ],
        // 4. 动词重叠式"v一v"其后宾语
        '/v一v宾语\{\}/i' => [
            'type' => 'verb_repetition_v1v_object',
            'pattern' => '/(\w+)一\1\s+(\w+)/',
            'description' => '动词重叠式"v一v"其后宾语（如"看一看书、听一听音乐"）'
        ],
        // 5. 述补结构（通用）
        '/述补结构\{\}/i' => [
            'type' => 'predicate_complement',
            'pattern' => '/\w+(得|不)\w+/',
            'description' => '述补结构（如"跑得快、看不见"）'
        ],
        // 6. 述补结构-动词作补语
        '/述补结构-动词补语\{\}/i' => [
            'type' => 'predicate_complement_verb',
            'pattern' => '/(打|看|听|说)\w+/',
            'description' => '述补结构-动词作补语（如"打败、看见、听懂"）'
        ],
        // 7. 述补结构-趋向动词作补语
        '/述补结构-趋向补语\{\}/i' => [
            'type' => 'predicate_complement_directional',
            'pattern' => '/\w+(上|下|来|去|进|出|回|过|起|开)/',
            'description' => '述补结构-趋向动词作补语（如"站起来、走出去"）'
        ],
        // 8. 主谓谓语句（通用）
        '/主谓谓语句\{\}/i' => [
            'type' => 'subject_predicate_predicate',
            'pattern' => '/(\w+)\s+(\w+\s+\w+)/',
            'description' => '主谓谓语句（如"他学习努力、这本书内容很好"）'
        ],
        // 9. 主谓谓语句--限制谓语
        '/主谓谓语句-限制谓语\{\}/i' => [
            'type' => 'subject_predicate_predicate_limited',
            'pattern' => '/(\w+)\s+(很|非常|特别)\w+/',
            'description' => '主谓谓语句--限制谓语（如"他很努力、这本书非常好"）'
        ],
        // 10. 动词并列式
        '/动词并列式\{\}/i' => [
            'type' => 'verb_coordinate',
            'pattern' => '/(\w+)\s+和\s+(\w+)/',
            'description' => '动词并列式（如"学习和工作、吃饭和睡觉"）'
        ],
        // 11. 代词做主语的主谓结构
        '/代词主语主谓结构\{\}/i' => [
            'type' => 'pronoun_subject',
            'pattern' => '/(我|你|他|她|它|我们|你们|他们|这|那)\s+\w+/',
            'description' => '代词做主语的主谓结构（如"我吃饭、他学习"）'
        ],
        // 12. 代词做主语的主谓结构，但代词不为"我"
        '/代词主语主谓结构-非我\{\}/i' => [
            'type' => 'pronoun_subject_not_wo',
            'pattern' => '/(你|他|她|它|我们|你们|他们|这|那)\s+\w+/',
            'description' => '代词做主语的主谓结构，但代词不为"我"（如"你吃饭、他学习"）'
        ],
        // 13. 动词性宾语的动宾结构
        '/动词宾语动宾结构\{\}/i' => [
            'type' => 'verb_object_verb',
            'pattern' => '/(想|要|喜欢|希望)\s+(\w+)/',
            'description' => '动词性宾语的动宾结构（如"想吃饭、喜欢学习"）'
        ],
        // 14. 双宾句
        '/双宾句\{\}/i' => [
            'type' => 'double_object',
            'pattern' => '/(给|送|教|告诉)\s+(\w+)\s+(\w+)/',
            'description' => '双宾句（如"给他一本书、教我英语"）'
        ],
        // 15. 主谓结构（通用）
        '/主谓结构\{\}/i' => [
            'type' => 'subject_predicate',
            'pattern' => '/(\w+)\s+(\w+)/',
            'description' => '主谓结构（如"小明吃饭、太阳升起"）'
        ]
    ];
}

// 解析检索式
function parseQueryPattern($query) {
    $mappings = getPatternMappings();
    
    foreach ($mappings as $regex => $config) {
        if (preg_match($regex, $query)) {
            return $config;
        }
    }
    
    // 默认精确匹配 - 使用#作为分隔符并添加适当修饰符
    return [
        'type' => 'default',
        'pattern' => '#' . preg_quote($query, '#') . '#u',
        'description' => '精确匹配检索式：' . $query
    ];
}


// 自定义PHP检索函数
function phpSearch($content, $query) {
    $matches = [];
    $frequency = 0;
    $parsed = parseQueryPattern($query);
    
    if (preg_match_all($parsed['pattern'], $content, $found, PREG_OFFSET_CAPTURE)) {
        $frequency = count($found[0]);
        
        foreach ($found[0] as $item) {
            $matchText = $item[0];
            $matchPos = $item[1];
            
            // 提取上下文（前后各60字符）
            $start = max(0, $matchPos - 60);
            $end = min(strlen($content), $matchPos + strlen($matchText) + 60);
            $context = substr($content, $start, $end - $start);
            
            // 高亮匹配部分（标红）
            $highlighted = str_replace(
                $matchText,
                '<span class="highlight">' . $matchText . '</span>',
                $context
            );
            
            // 添加上下文标识
            $prefix = $start > 0 ? "..." : "";
            $suffix = $end < strlen($content) ? "..." : "";
            
            $matches[] = $prefix . $highlighted . $suffix;
        }
    }
    
    return [
        'frequency' => $frequency,
        'examples' => array_unique($matches),
        'pattern_info' => $parsed
    ];
}

// 初始化变量
$searchResults = [];
$allSearchResults = [];
$searchQuery = '';
$pagination = [
    'total' => 0,
    'pages' => 0,
    'current_page' => 1,
    'per_page' => 10
];
$patternDescription = '';
$patternMappings = getPatternMappings();

// 处理检索请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perform_search']) && !empty($_POST['selected_ids']) && !empty($_POST['search_query'])) {
    $selectedIds = $_POST['selected_ids'];
    $searchQuery = trim($_POST['search_query']);
    $current_page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $current_page = max(1, $current_page);
    
    // 解析检索式
    $parsedQuery = parseQueryPattern($searchQuery);
    $patternDescription = $parsedQuery['description'];
    
    // 获取选中的记录
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, corpus_name, source, content 
        FROM corpus_entries 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($selectedIds);
    $entries = $stmt->fetchAll();
    
    // 准备传给Python的数据
    $data = [
        'query' => $searchQuery,
        'parsed_pattern' => $parsedQuery,
        'entries' => $entries
    ];
    
    // 写入临时文件
    $tempInputFile = tempnam(sys_get_temp_dir(), 'chunk_input_') . '.json';
    file_put_contents($tempInputFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    
    // 调用chunk_analyzer.py脚本（修改为调用指定的Python脚本）
    $pythonScript = __DIR__ . '/chunk_analyzer_ku.py';
    $command = escapeshellcmd("python " . $pythonScript . " " . escapeshellarg($tempInputFile) . " " . $current_page);
    $pythonOutput = shell_exec($command);
    
    // 读取Python结果
    $tempOutputFile = str_replace('input', 'output', $tempInputFile);
    $pythonPaginated = [];
    
    if (file_exists($tempOutputFile)) {
        $jsonContent = file_get_contents($tempOutputFile);
        $pythonPaginated = json_decode($jsonContent, true) ?? [];
        unlink($tempOutputFile);
    }
    
    // 清理临时文件
    if (file_exists($tempInputFile)) unlink($tempInputFile);
    
    // 提取Python数据
    $pythonResults = $pythonPaginated['data'] ?? [];
    $pagination = [
        'total' => $pythonPaginated['total'] ?? 0,
        'pages' => $pythonPaginated['pages'] ?? 0,
        'current_page' => $pythonPaginated['current_page'] ?? 1,
        'per_page' => $pythonPaginated['per_page'] ?? 10
    ];
    $allResults = $pythonPaginated['all_data'] ?? [];
    
    // 融合PHP检索结果（当Python脚本未返回结果时作为备份）
    foreach ($entries as $entry) {
        $entryId = $entry['id'];
        $content = $entry['content'];
        
        // 检查是否已在Python结果中
        $inPython = false;
        foreach ($allResults as $pr) {
            if ($pr['id'] == $entryId) {
                $inPython = true;
                break;
            }
        }
        
        if (!$inPython) {
            $customResult = phpSearch($content, $searchQuery);
            if ($customResult['frequency'] > 0) {
                $allResults[] = [
                    'id' => $entryId,
                    'corpus_name' => $entry['corpus_name'],
                    'source' => $entry['source'],
                    'frequency' => $customResult['frequency'],
                    'examples' => $customResult['examples'],
                    'pattern_info' => $customResult['pattern_info']
                ];
            }
        }
    }
    
    // 重新分页
    $total = count($allResults);
    $pages = (int)ceil($total / $pagination['per_page']);
    $start = ($pagination['current_page'] - 1) * $pagination['per_page'];
    $end = $start + $pagination['per_page'];
    $searchResults = array_slice($allResults, $start, $end);
    
    // 更新分页信息
    $pagination['total'] = $total;
    $pagination['pages'] = $pages;
    
    // 保存会话
    $_SESSION['search_results'] = $searchResults;
    $_SESSION['all_search_results'] = $allResults;
    $_SESSION['search_query'] = $searchQuery;
    $_SESSION['pagination'] = $pagination;
    $_SESSION['pattern_description'] = $patternDescription;
}

// 从会话恢复数据
if (isset($_SESSION['search_results']) && !isset($_POST['perform_search']) && !isset($_POST['clear_conditions'])) {
    $searchResults = $_SESSION['search_results'];
    $allSearchResults = $_SESSION['all_search_results'] ?? [];
    $searchQuery = $_SESSION['search_query'] ?? '';
    $pagination = $_SESSION['pagination'] ?? [
        'total' => 0,
        'pages' => 0,
        'current_page' => 1,
        'per_page' => 10
    ];
    $patternDescription = $_SESSION['pattern_description'] ?? '';
}

// 获取语料记录
try {
    $stmt = $pdo->prepare("
        SELECT id, corpus_name, source, author_tag 
        FROM corpus_entries 
        WHERE corpus_type = :corpus_type
        ORDER BY id ASC
    ");
    $stmt->bindValue(':corpus_type', '组块结构分析文本（Tree）', PDO::PARAM_STR);
    $stmt->execute();
    $corpusEntries = $stmt->fetchAll();
} catch (PDOException $e) {
    die("获取语料记录失败: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>语料库组块分析文件检索</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.8;
            color: #333;
            background-color: #f5f7fa;
            padding: 20px;
        }
        .container {
            max-width: 1300px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            padding: 30px;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 15px;
            margin-bottom: 30px;
            font-size: 24px;
        }
        h2 {
            color: #34495e;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin: 25px 0 15px;
            font-size: 20px;
        }
        h3 {
            color: #34495e;
            margin: 20px 0 10px;
            font-size: 18px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 14px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        tr:hover {
            background-color: #fafbfc;
        }
        .search-form {
            background-color: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #2c3e50;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
        }
        .btn {
            display: inline-block;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .btn-primary {
            background-color: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background-color: #218838;
        }
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background-color: #bb2d3b;
        }
        .results {
            margin-top: 30px;
            padding: 25px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        .match-item {
            margin-bottom: 30px;
            padding: 20px;
            background-color: white;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }
        .match-item h4 {
            margin-top: 0;
            color: #2c3e50;
            border-left: 4px solid #28a745;
            padding-left: 12px;
            font-size: 17px;
        }
        .stats {
            font-size: 17px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #e3f2fd;
            border-radius: 6px;
            color: #2c3e50;
        }
        .pattern-desc {
            color: #2c3e50;
            font-style: italic;
            margin: 15px 0;
            padding: 12px;
            background-color: #fff8e1;
            border-radius: 6px;
            border-left: 4px solid #ffc107;
        }
        .match-content {
            margin-top: 15px;
            padding-left: 20px;
        }
        .match-content li {
            margin-bottom: 15px;
            padding: 12px;
            background-color: #f9f9f9;
            border-radius: 5px;
            list-style-position: inside;
            border-left: 3px solid #3498db;
        }
        .highlight {
            color: #dc3545;
            font-weight: bold;
            padding: 0 3px;
            background-color: #fff3cd;
            border-radius: 3px;
        }
        .frequency {
            color: #dc3545;
            font-weight: bold;
            font-size: 17px;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
            padding: 12px;
            border-radius: 6px;
            margin: 15px 0;
            border-left: 4px solid #dc3545;
        }
        .button-group {
            margin-top: 20px;
        }
        .pagination {
            text-align: center;
            margin: 30px 0;
            padding: 10px;
        }
        .pagination .btn {
            margin: 0 5px;
            padding: 10px 18px;
        }
        .pattern-help {
            background-color: #e8f4fd;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 14px;
            color: #2c3e50;
        }
        .pattern-help h4 {
            margin-top: 0;
            color: #2c3e50;
            font-size: 16px;
        }
        .pattern-help ul {
            margin-left: 20px;
        }
        .pattern-help li {
            margin-bottom: 8px;
        }
        .no-results {
            text-align: center;
            padding: 40px 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin: 25px 0;
            color: #666;
        }
        .download-section {
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>语料库组块分析文件检索</h1>
        
        <h2>可选语料列表</h2>
        <p>以下是语料类型为"组块结构分析文本（Tree）"的记录，请选择需要检索的记录：</p>
        
        <form method="post" id="searchForm">
            <table>
                <tr>
                    <th style="width: 60px;">选择</th>
                    <th>ID</th>
                    <th>语料名称</th>
                    <th>来源</th>
                    <th>作者标签</th>
                </tr>
                <?php if (count($corpusEntries) > 0): ?>
                    <?php foreach ($corpusEntries as $entry): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_ids[]" value="<?php echo htmlspecialchars($entry['id']); ?>"></td>
                            <td><?php echo htmlspecialchars($entry['id']); ?></td>
                            <td><?php echo htmlspecialchars($entry['corpus_name']); ?></td>
                            <td><?php echo htmlspecialchars($entry['source']); ?></td>
                            <td><?php echo htmlspecialchars($entry['author_tag']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px;">没有找到符合条件的语料记录</td>
                    </tr>
                <?php endif; ?>
            </table>
            
            <div class="search-form">
                <h3>检索条件</h3>
                <div class="form-group">
                    <label for="search_query">检索式：</label>
                    <input type="text" id="search_query" name="search_query" 
                           value="<?php echo htmlspecialchars($searchQuery); ?>"
                           placeholder="例如：v一v{}、状中结构{}" required>
                    
                    <div class="pattern-help">
                        <h4>支持的中文组块结构检索需求与检索式：</h4>
                        <ul>
                            <li><strong>离合用法{X-Y}</strong> - 词组的离合用法（如"离合用法{吃-饭}"）。检索式：X*Y{}</li>
                            <li><strong>状中结构{}</strong> - 状中结构（如"很好、非常漂亮"）。检索式：NULL-MOD[]VP-PRD[]{}</li>
                            <li><strong>v一v{}</strong> - 动词重叠式"v一v"（如"看一看、听一听"）。检索式：v一v{}</li>
                            <li><strong>v一v宾语{}</strong> - 动词重叠式"v一v"其后宾语（如"看一看书"）。检索式：VP-PRD[v一v]NP-OBJ[]{}</li>
                            <li><strong>述补结构{}</strong> - 述补结构（如"跑得快、看不见"）。检索式：VP-PRD[]NULL-MOD[]{}</li>
                            <li><strong>述补结构-动词补语{}</strong> - 述补结构-动词作补语（如"打败、看见"）。检索式：VP-PRD[]NULL-MOD[v]{}</li>
                            <li><strong>述补结构-趋向补语{}</strong> - 述补结构-趋向动词作补语（如"站起来"）。检索式：VP-PRD[](NULL-MOD[]){$1=[起来 上去 上来 下去 下来]}</li>
                            <li><strong>主谓谓语句{}</strong> - 主谓谓语句（如"他学习努力"）。检索式：NP-SBJ[]VP-PRD[NP-SBJ[]VP-PRD[]] </li>
                            <li><strong>主谓谓语句-限制谓语{}</strong> - 主谓谓语句--限制谓语（如"他很努力"）。检索式：NP-SBJ[]VP-PRD[NP-SBJ[]VP-PRD[(v)]]{$1=[知道 了解 懂得 听说 明白]}</li>
                            <li><strong>动词并列式{}</strong> - 动词并列式（如"学习和工作"）。检索式：VP-PRD[v和v]{}</li>
                            <li><strong>代词主语主谓结构{}</strong> - 代词做主语的主谓结构（如"我吃饭"）。检索式：NP-SBJ[r]VP-PRD[]{}</li>
                            <li><strong>代词主语主谓结构-非我{}</strong> - 代词做主语的主谓结构（非"我"）。检索式：NP-SBJ[(r)]VP-PRD[]{$1!=[我]}</li>
                            <li><strong>动词宾语动宾结构{}</strong> - 动词性宾语的动宾结构（如"想吃饭"）。检索式：VP-PRD[]VP-OBJ[]</li>
                            <li><strong>双宾句{}</strong> - 双宾句（如"给他一本书"）。检索式：VP-PRD[]NP-OBJ[]NP-OBJ[]{}</li>
                            <li><strong>主谓结构{}</strong> - 主谓结构（如"小明吃饭"）。检索式：NP-SBJ[]VP-PRD[v]{}</li>
                        </ul>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="perform_search" class="btn btn-primary">执行检索</button>
                    <button type="submit" name="clear_conditions" class="btn btn-danger">清除条件</button>
                </div>
            </div>
            
            <?php if (!empty($searchResults) && is_array($searchResults)): ?>
                <div class="results">
                    <h3>检索结果</h3>
                    <div class="stats">
                        <p>检索式：<strong><?php echo htmlspecialchars($searchQuery); ?></strong></p>
                        <p>检索类型：<strong><?php echo $patternDescription; ?></strong></p>
                        <p>符合条件的记录总数：<strong><?php echo $pagination['total']; ?></strong> 条</p>
                    </div>
                    
                    <?php if (!empty($patternDescription)): ?>
                        <div class="pattern-desc">
                            <i class="icon-info"></i> 系统已识别检索需求：<?php echo $patternDescription; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($searchResults as $result): ?>
                        <?php
                        $id = $result['id'] ?? '未知ID';
                        $corpusName = $result['corpus_name'] ?? '未知名称';
                        $source = $result['source'] ?? '未知来源';
                        $frequency = $result['frequency'] ?? 0;
                        $examples = $result['examples'] ?? [];
                        ?>
                        <div class="match-item">
                            <h4>记录ID: <?php echo htmlspecialchars($id); ?></h4>
                            <p><strong>语料名称：</strong><?php echo htmlspecialchars($corpusName); ?></p>
                            <p><strong>来源：</strong><?php echo htmlspecialchars($source); ?></p>
                            <p><strong>匹配频次：</strong><span class="frequency"><?php echo $frequency; ?></span> 次</p>
                            
                            <?php if (!empty($examples)): ?>
                                <p><strong>匹配内容：</strong></p>
                                <ul class="match-content">
                                    <?php foreach ($examples as $example): ?>
                                        <li><?php echo $example; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p><strong>匹配内容：</strong>无匹配内容</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- 分页导航 -->
                    <?php if ($pagination['pages'] > 1): ?>
                        <div class="pagination">
                            <form method="post" style="display: inline-block;">
                                <input type="hidden" name="perform_search" value="1">
                                <input type="hidden" name="search_query" value="<?php echo htmlspecialchars($searchQuery); ?>">
                                <?php foreach ($_POST['selected_ids'] ?? [] as $id): ?>
                                    <input type="hidden" name="selected_ids[]" value="<?php echo htmlspecialchars($id); ?>">
                                <?php endforeach; ?>
                                
                                <?php if ($pagination['current_page'] > 1): ?>
                                    <button type="submit" name="page" value="<?php echo $pagination['current_page'] - 1; ?>" class="btn btn-primary">上一页</button>
                                <?php endif; ?>
                                
                                <span style="margin: 0 15px; color: #2c3e50;">
                                    第 <?php echo $pagination['current_page']; ?> 页 / 共 <?php echo $pagination['pages']; ?> 页
                                    (总计 <?php echo $pagination['total']; ?> 条)
                                </span>
                                
                                <?php if ($pagination['current_page'] < $pagination['pages']): ?>
                                    <button type="submit" name="page" value="<?php echo $pagination['current_page'] + 1; ?>" class="btn btn-primary">下一页</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <div class="download-section">
                        <button type="submit" name="download_report" class="btn btn-primary">下载检索报告</button>
                    </div>
                </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perform_search'])): ?>
                <div class="no-results">
                    <p>没有找到与"<?php echo htmlspecialchars($searchQuery); ?>"匹配的内容</p>
                    <p>请检查检索式是否正确，或选择更多语料记录</p>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        document.getElementById('searchForm').addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.name === 'perform_search') {
                const checkboxes = document.querySelectorAll('input[name="selected_ids[]"]:checked');
                const searchQuery = document.getElementById('search_query').value.trim();
                
                if (checkboxes.length === 0) {
                    alert('请至少选择一条语料记录');
                    e.preventDefault();
                    return false;
                }
                
                if (searchQuery === '') {
                    alert('请输入检索式');
                    e.preventDefault();
                    return false;
                }
                
                // 简单的检索式格式验证
                if (!searchQuery.includes('{') || !searchQuery.includes('}')) {
                    if (!confirm('检索式格式似乎不正确，正确格式应为"结构类型{}"（如"v一v{}"），是否继续检索？')) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
            return true;
        });
    </script>
</body>
</html>
