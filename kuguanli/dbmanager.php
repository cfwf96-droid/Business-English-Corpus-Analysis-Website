<?php
// 设置安全的会话参数并启动会话
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// 设置防缓存头信息（所有页面都不缓存）
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

// 数据库配置
$host = 'localhost';
$dbUser = 'root';
$dbPass = 'whyylw_666666';
$dbName = 'business_chinese_corpus';

// 初始化变量
$message = '';
$currentPage = 1;
$recordsPerPage = 10; // 每页显示10条记录

// 处理登出 - 增强安全性，完全清除会话
if (isset($_GET['logout'])) {
    // 清除所有会话变量
    $_SESSION = array();
    
    // 如果使用cookie存储会话ID，清除cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // 销毁会话
    session_destroy();
    header("Location: dbmanager.php");
    exit;
}

// 处理登录
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // 连接数据库验证
    $conn = new mysqli($host, $dbUser, $dbPass);
    if ($conn->connect_error) {
        $message = '<div class="alert alert-danger">数据库连接失败: ' . $conn->connect_error . '</div>';
    } else {
        // 验证成功后设置会话
        if ($username === 'root' && $password === 'whyylw_666666') {
            // 登录成功前先清除旧会话数据
            $_SESSION = [];
            // 生成新的会话ID防止会话固定攻击
            session_regenerate_id(true);
            // 设置新的会话变量
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_time'] = time();
        } else {
            $message = '<div class="alert alert-danger">用户名或密码不正确</div>';
        }
        $conn->close();
    }
}

// 处理删除操作
if (isset($_GET['delete']) && $_SESSION['loggedin'] === true) {
    $id = intval($_GET['delete']);
    
    // 连接数据库
    $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        die("连接失败: " . $conn->connect_error);
    }
    
    // 删除记录
    $sql = "DELETE FROM corpus_entries WHERE id = $id";
    if ($conn->query($sql) === TRUE) {
        $message = '<div class="alert alert-success">记录删除成功</div>';
    } else {
        $message = '<div class="alert alert-danger">删除失败: ' . $conn->error . '</div>';
    }
    
    $conn->close();
}

// 处理编辑操作
$editRecord = null;
if (isset($_GET['edit']) && $_SESSION['loggedin'] === true) {
    $id = intval($_GET['edit']);
    
    // 连接数据库
    $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        die("连接失败: " . $conn->connect_error);
    }
    
    // 获取要编辑的记录
    $sql = "SELECT * FROM corpus_entries WHERE id = $id";
    $result = $conn->query($sql);
    if ($result->num_rows == 1) {
        $editRecord = $result->fetch_assoc();
    } else {
        $message = '<div class="alert alert-danger">未找到指定记录</div>';
    }
    
    $conn->close();
}

// 处理保存编辑
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save']) && $_SESSION['loggedin'] === true) {
    $id = intval($_POST['id']);
    
    // 连接数据库
    $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        die("连接失败: " . $conn->connect_error);
    }
    
    // 处理表单数据 - 将corpus_source改为source
    $corpus_name = $conn->real_escape_string(trim($_POST['corpus_name']));
    $source = $conn->real_escape_string(trim($_POST['source']));
    $author_tag = $conn->real_escape_string(trim($_POST['author_tag']));
    $corpus_type = $conn->real_escape_string(trim($_POST['corpus_type']));
    $content = $conn->real_escape_string(trim($_POST['content']));
    $remarks = $conn->real_escape_string(trim($_POST['remarks']));
    
    // 更新记录 - 将corpus_source改为source
    $sql = "UPDATE corpus_entries SET 
            corpus_name = '$corpus_name', 
            source = '$source', 
            author_tag = '$author_tag', 
            corpus_type = '$corpus_type', 
            content = '$content', 
            remarks = '$remarks' 
            WHERE id = $id";
    
    if ($conn->query($sql) === TRUE) {
        $message = '<div class="alert alert-success">记录更新成功</div>';
        $editRecord = null; // 清除编辑状态
    } else {
        $message = '<div class="alert alert-danger">更新失败: ' . $conn->error . '</div>';
    }
    
    $conn->close();
}

// 如果已登录，获取统计信息和记录
$totalRecords = 0;
$corpusNameStats = [];
$sourceStats = []; // 原corpusSourceStats改为sourceStats
$authorTagStats = [];
$corpusTypeStats = [];
$records = [];
$totalPages = 0;

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    // 处理分页
    if (isset($_GET['page']) && is_numeric($_GET['page'])) {
        $currentPage = intval($_GET['page']);
    }
    
    // 处理分类查看 - 将corpus_source改为source
    $whereClause = '';
    if (isset($_GET['corpus_name'])) {
        $corpusName = urldecode($_GET['corpus_name']);
        $whereClause = "WHERE corpus_name = '" . addslashes($corpusName) . "'";
    } elseif (isset($_GET['source'])) {
        $source = urldecode($_GET['source']);
        $whereClause = "WHERE source = '" . addslashes($source) . "'";
    } elseif (isset($_GET['author_tag'])) {
        $authorTag = urldecode($_GET['author_tag']);
        $whereClause = "WHERE author_tag = '" . addslashes($authorTag) . "'";
    } elseif (isset($_GET['corpus_type'])) {
        $corpusType = urldecode($_GET['corpus_type']);
        $whereClause = "WHERE corpus_type = '" . addslashes($corpusType) . "'";
    }
    
    // 连接数据库
    $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        die("连接失败: " . $conn->connect_error);
    }
    
    // 获取总记录数
    $sql = "SELECT COUNT(*) AS total FROM corpus_entries $whereClause";
    $result = $conn->query($sql);
    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        $totalRecords = $row['total'];
        $totalPages = ceil($totalRecords / $recordsPerPage);
        
        // 确保当前页在有效范围内
        if ($currentPage < 1) {
            $currentPage = 1;
        } elseif ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
    }
    
    // 获取分类统计信息 - 将corpus_source改为source
    $sql = "SELECT corpus_name, COUNT(*) AS count FROM corpus_entries GROUP BY corpus_name";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $corpusNameStats[] = $row;
    }
    
    $sql = "SELECT source, COUNT(*) AS count FROM corpus_entries GROUP BY source";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $sourceStats[] = $row;
    }
    
    $sql = "SELECT author_tag, COUNT(*) AS count FROM corpus_entries GROUP BY author_tag";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $authorTagStats[] = $row;
    }
    
    $sql = "SELECT corpus_type, COUNT(*) AS count FROM corpus_entries GROUP BY corpus_type";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $corpusTypeStats[] = $row;
    }
    
    // 获取当前页的记录
    $startFrom = ($currentPage - 1) * $recordsPerPage;
    $sql = "SELECT * FROM corpus_entries $whereClause LIMIT $startFrom, $recordsPerPage";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="cache-control" content="no-store, no-cache, must-revalidate">
    <meta name="pragma" content="no-cache">
    <meta name="expires" content="0">
    <title>数据库管理</title>
    <style>
        /* 基础样式重置 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        
        body {
            background-color: #f8f9fa;
            color: #212529;
            line-height: 1.5;
            padding: 20px;
        }
        
        /* 容器样式 */
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        /* 通用样式 */
        h2, h3 {
            margin-bottom: 1rem;
            color: #343a40;
        }
        
        a {
            color: #0d6efd;
            text-decoration: none;
        }
        
        a:hover {
            color: #0a58ca;
            text-decoration: underline;
        }
        
        .mb-4 {
            margin-bottom: 1.5rem !important;
        }
        
        .mb-3 {
            margin-bottom: 1rem !important;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem !important;
        }
        
        .mt-2 {
            margin-top: 0.5rem !important;
        }
        
        .text-center {
            text-align: center !important;
        }
        
        .d-flex {
            display: flex !important;
        }
        
        .justify-content-between {
            justify-content: space-between !important;
        }
        
        .align-items-center {
            align-items: center !important;
        }
        
        .justify-content-md-end {
            justify-content: flex-end !important;
        }
        
        .gap-2 {
            gap: 0.5rem !important;
        }
        
        .flex-wrap {
            flex-wrap: wrap !important;
        }
        
        .me-md-2 {
            margin-right: 0.5rem !important;
        }
        
        /* 表单样式 */
        .login-form {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            display: block;
            width: 100%;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            line-height: 1.5;
            color: #212529;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus {
            color: #212529;
            background-color: #fff;
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* 按钮样式 */
        .btn {
            display: inline-block;
            font-weight: 400;
            line-height: 1.5;
            color: #212529;
            text-align: center;
            text-decoration: none;
            vertical-align: middle;
            cursor: pointer;
            background-color: transparent;
            border: 1px solid transparent;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            border-radius: 0.25rem;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .btn-primary {
            color: #fff;
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .btn-primary:hover {
            color: #fff;
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        
        .btn-secondary {
            color: #fff;
            background-color: #6c757d;
            border-color: #6c757d;
        }
        
        .btn-secondary:hover {
            color: #fff;
            background-color: #5c636a;
            border-color: #565e64;
        }
        
        .btn-danger {
            color: #fff;
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        .btn-danger:hover {
            color: #fff;
            background-color: #bb2d3b;
            border-color: #b02a37;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            border-radius: 0.25rem;
        }
        
        /* 提示框样式 */
        .alert {
            position: relative;
            padding: 1rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
        }
        
        .alert-success {
            color: #0f5132;
            background-color: #d1e7dd;
            border-color: #badbcc;
        }
        
        .alert-danger {
            color: #842029;
            background-color: #f8d7da;
            border-color: #f5c2c7;
        }
        
        .alert-info {
            color: #055160;
            background-color: #cff4fc;
            border-color: #b6effb;
        }
        
        /* 统计信息样式 */
        .stats {
            margin-bottom: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
            border: 1px solid #dee2e6;
        }
        
        .stat-item {
            margin-bottom: 15px;
        }
        
        .stat-link {
            font-size: 1.2em;
            font-weight: bold;
            color: red;
            margin-right: 15px;
        }
        
        .stat-link:hover {
            color: #c82333;
            text-decoration: underline;
        }
        
        /* 表格样式 */
        .table-container {
            margin-top: 20px;
        }
        
        .table {
            width: 100%;
            margin-bottom: 1rem;
            color: #212529;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid #dee2e6;
        }
        
        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #dee2e6;
            background-color: #e9ecef;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .table-hover tbody tr:hover {
            color: #212529;
            background-color: rgba(0, 0, 0, 0.075);
        }
        
        .content-preview {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* 编辑表单样式 */
        .edit-form {
            margin-top: 20px;
            padding: 20px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        /* 分页样式 */
        .pagination {
            display: flex;
            padding-left: 0;
            list-style: none;
            margin-top: 20px;
            justify-content: center;
        }
        
        .page-item {
            margin: 0 2px;
        }
        
        .page-link {
            position: relative;
            display: block;
            padding: 0.5rem 0.75rem;
            color: #0d6efd;
            text-decoration: none;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }
        
        .page-link:hover {
            z-index: 2;
            color: #0a58ca;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }
        
        .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #fff;
            border-color: #dee2e6;
            opacity: 0.65;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        /* 响应式样式 */
        @media (max-width: 768px) {
            .d-flex {
                flex-direction: column;
            }
            
            .me-md-2 {
                margin-right: 0 !important;
                margin-bottom: 0.5rem !important;
            }
            
            .table-responsive {
                display: block;
                width: 100%;
                overflow-x: auto;
            }
            
            .stat-item {
                word-wrap: break-word;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true): ?>
            <!-- 登录表单 -->
            <div class="login-form">
                <h2 class="text-center mb-4">数据库管理员登录</h2>
                <?php echo $message; ?>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">用户名</label>
                        <!-- 增强安全性：确保输入框为空且不自动填充 -->
                        <input type="text" class="form-control" id="username" name="username" required 
                               autocomplete="off" autocapitalize="none" autocorrect="off">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">密码</label>
                        <!-- 增强安全性：确保输入框为空且不自动填充 -->
                        <input type="password" class="form-control" id="password" name="password" required 
                               autocomplete="new-password">
                    </div>
                    <div class="d-flex gap-2 justify-content-md-end">
                        <button type="button" class="btn btn-secondary me-md-2" onclick="clearForm()">清除</button>
                        <button type="submit" class="btn btn-primary" name="login">登录</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <!-- 登录成功后的管理界面 -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>中文语料库管理</h2>
                <a href="dbmanager.php?logout" class="btn btn-danger">退出登录</a>
            </div>
            
            <?php echo $message; ?>
            
            <!-- 提示信息 -->
            <div class="alert alert-info mb-4">
                提示：您可以使用主页中的<a href="../index_ylw.php">中文语料库录入</a>功能添加新语料，也可以通过记录编辑功能完成语料库记录的增加。
            </div>
            
            <?php if ($editRecord): ?>
                <!-- 编辑表单 - 将corpus_source改为source -->
                <div class="edit-form">
                    <h3>编辑记录</h3>
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <input type="hidden" name="id" value="<?php echo $editRecord['id']; ?>">
                        
                        <div class="mb-3">
                            <label for="corpus_name" class="form-label">语料名称</label>
                            <input type="text" class="form-control" id="corpus_name" name="corpus_name" 
                                   value="<?php echo htmlspecialchars($editRecord['corpus_name']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="source" class="form-label">语料来源</label>
                            <input type="text" class="form-control" id="source" name="source" 
                                   value="<?php echo htmlspecialchars($editRecord['source']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="author_tag" class="form-label">作者信息</label>
                            <input type="text" class="form-control" id="author_tag" name="author_tag" 
                                   value="<?php echo htmlspecialchars($editRecord['author_tag']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="corpus_type" class="form-label">语料类型</label>
                            <input type="text" class="form-control" id="corpus_type" name="corpus_type" 
                                   value="<?php echo htmlspecialchars($editRecord['corpus_type']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="content" class="form-label">语料内容</label>
                            <textarea class="form-control" id="content" name="content" rows="5" required><?php echo htmlspecialchars($editRecord['content']); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">备注</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="3"><?php echo htmlspecialchars($editRecord['remarks']); ?></textarea>
                        </div>
                        
                        <div class="d-flex gap-2 justify-content-md-end">
                            <a href="dbmanager.php" class="btn btn-secondary me-md-2">取消</a>
                            <button type="submit" class="btn btn-primary" name="save">保存</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- 统计信息 - 将corpus_source改为source -->
                <div class="stats">
                    <h3>语料库统计信息</h3>
                    
                    <div class="stat-item">
                        <strong>总记录数：</strong>
                        <span class="stat-link"><?php echo $totalRecords; ?></span>
                    </div>
                    
                    <div class="stat-item">
                        <strong>按语料名称分类：</strong><br>
                        <?php foreach ($corpusNameStats as $stat): ?>
                            <a href="dbmanager.php?corpus_name=<?php echo urlencode($stat['corpus_name']); ?>" class="stat-link">
                                <?php echo htmlspecialchars($stat['corpus_name']); ?> (<?php echo $stat['count']; ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="stat-item">
                        <strong>按语料来源分类：</strong><br>
                        <?php foreach ($sourceStats as $stat): ?>
                            <a href="dbmanager.php?source=<?php echo urlencode($stat['source']); ?>" class="stat-link">
                                <?php echo htmlspecialchars($stat['source']); ?> (<?php echo $stat['count']; ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="stat-item">
                        <strong>按作者信息分类：</strong><br>
                        <?php foreach ($authorTagStats as $stat): ?>
                            <a href="dbmanager.php?author_tag=<?php echo urlencode($stat['author_tag']); ?>" class="stat-link">
                                <?php echo htmlspecialchars($stat['author_tag']); ?> (<?php echo $stat['count']; ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="stat-item">
                        <strong>按语料类型分类：</strong><br>
                        <?php foreach ($corpusTypeStats as $stat): ?>
                            <a href="dbmanager.php?corpus_type=<?php echo urlencode($stat['corpus_type']); ?>" class="stat-link">
                                <?php echo htmlspecialchars($stat['corpus_type']); ?> (<?php echo $stat['count']; ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- 记录表格 - 将corpus_source改为source -->
                <div class="table-container">
                    <h3>语料库记录列表</h3>
                    <?php if (count($records) == 0): ?>
                        <div class="alert alert-info">没有找到记录</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>语料名称</th>
                                        <th>语料来源</th>
                                        <th>作者信息</th>
                                        <th>语料类型</th>
                                        <th>语料内容</th>
                                        <th>备注</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td><?php echo $record['id']; ?></td>
                                            <td><?php echo htmlspecialchars($record['corpus_name']); ?></td>
                                            <td><?php echo htmlspecialchars($record['source']); ?></td>
                                            <td><?php echo htmlspecialchars($record['author_tag']); ?></td>
                                            <td><?php echo htmlspecialchars($record['corpus_type']); ?></td>
                                            <td class="content-preview" title="<?php echo htmlspecialchars($record['content']); ?>">
                                                <?php echo nl2br(htmlspecialchars($record['content'])); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['remarks']); ?></td>
                                            <td class="action-buttons">
                                                <a href="dbmanager.php?edit=<?php echo $record['id']; ?>" class="btn btn-sm btn-primary">编辑</a>
                                                <a href="dbmanager.php?delete=<?php echo $record['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('确定要删除这条记录吗？')">删除</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- 分页控件 - 将corpus_source改为source -->
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo ($currentPage == 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="dbmanager.php?page=1<?php 
                                        echo isset($_GET['corpus_name']) ? '&corpus_name=' . urlencode($_GET['corpus_name']) : 
                                        (isset($_GET['source']) ? '&source=' . urlencode($_GET['source']) : 
                                        (isset($_GET['author_tag']) ? '&author_tag=' . urlencode($_GET['author_tag']) : 
                                        (isset($_GET['corpus_type']) ? '&corpus_type=' . urlencode($_GET['corpus_type']) : ''))); 
                                    ?>">首页</a>
                                </li>
                                <li class="page-item <?php echo ($currentPage == 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="dbmanager.php?page=<?php echo $currentPage - 1; ?><?php 
                                        echo isset($_GET['corpus_name']) ? '&corpus_name=' . urlencode($_GET['corpus_name']) : 
                                        (isset($_GET['source']) ? '&source=' . urlencode($_GET['source']) : 
                                        (isset($_GET['author_tag']) ? '&author_tag=' . urlencode($_GET['author_tag']) : 
                                        (isset($_GET['corpus_type']) ? '&corpus_type=' . urlencode($_GET['corpus_type']) : ''))); 
                                    ?>">上一页</a>
                                </li>
                                
                                <!-- 页码输入框 -->
                                <li class="page-item">
                                    <form method="get" action="dbmanager.php" class="d-flex align-items-center">
                                        <?php if (isset($_GET['corpus_name'])): ?>
                                            <input type="hidden" name="corpus_name" value="<?php echo urlencode($_GET['corpus_name']); ?>">
                                        <?php elseif (isset($_GET['source'])): ?>
                                            <input type="hidden" name="source" value="<?php echo urlencode($_GET['source']); ?>">
                                        <?php elseif (isset($_GET['author_tag'])): ?>
                                            <input type="hidden" name="author_tag" value="<?php echo urlencode($_GET['author_tag']); ?>">
                                        <?php elseif (isset($_GET['corpus_type'])): ?>
                                            <input type="hidden" name="corpus_type" value="<?php echo urlencode($_GET['corpus_type']); ?>">
                                        <?php endif; ?>
                                        <input type="number" name="page" min="1" max="<?php echo $totalPages; ?>" value="<?php echo $currentPage; ?>" class="form-control form-control-sm" style="width: 60px; margin: 0 5px;">
                                        <button type="submit" class="btn btn-sm btn-secondary">跳转</button>
                                    </form>
                                </li>
                                
                                <li class="page-item <?php echo ($currentPage == $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="dbmanager.php?page=<?php echo $currentPage + 1; ?><?php 
                                        echo isset($_GET['corpus_name']) ? '&corpus_name=' . urlencode($_GET['corpus_name']) : 
                                        (isset($_GET['source']) ? '&source=' . urlencode($_GET['source']) : 
                                        (isset($_GET['author_tag']) ? '&author_tag=' . urlencode($_GET['author_tag']) : 
                                        (isset($_GET['corpus_type']) ? '&corpus_type=' . urlencode($_GET['corpus_type']) : ''))); 
                                    ?>">下一页</a>
                                </li>
                                <li class="page-item <?php echo ($currentPage == $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="dbmanager.php?page=<?php echo $totalPages; ?><?php 
                                        echo isset($_GET['corpus_name']) ? '&corpus_name=' . urlencode($_GET['corpus_name']) : 
                                        (isset($_GET['source']) ? '&source=' . urlencode($_GET['source']) : 
                                        (isset($_GET['author_tag']) ? '&author_tag=' . urlencode($_GET['author_tag']) : 
                                        (isset($_GET['corpus_type']) ? '&corpus_type=' . urlencode($_GET['corpus_type']) : ''))); 
                                    ?>">尾页</a>
                                </li>
                            </ul>
                        </nav>
                        <div class="text-center">
                            显示第 <?php echo ($currentPage - 1) * $recordsPerPage + 1; ?> 到 <?php echo min($currentPage * $recordsPerPage, $totalRecords); ?> 条，共 <?php echo $totalRecords; ?> 条记录
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // 确保页面加载完成后清空登录表单，防止浏览器自动填充
        document.addEventListener('DOMContentLoaded', function() {
            // 检查是否存在登录表单
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            
            if (usernameInput && passwordInput) {
                // 强制清空输入框
                usernameInput.value = '';
                passwordInput.value = '';
                
                // 移除可能的自动填充样式
                usernameInput.autocomplete = 'off';
                passwordInput.autocomplete = 'new-password';
            }
        });
        
        // 清除表单内容
        function clearForm() {
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
        }
    </script>
</body>
</html>
