<?php
// 数据库配置
$host = 'localhost';
$dbname = 'business_chinese_corpus';
$username = 'root';
$password = 'whyylw_666666';

// 初始化变量
$message = '';
$messageType = '';
$formData = [];

// 连接数据库
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    $message = "数据库连接失败: " . $e->getMessage();
    $messageType = 'error';
}

// 处理表单提交
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($pdo)) {
    $formData = $_POST;
    $valid = true;
    $errors = [];
    
    // 验证语料名称
    if (empty($_POST['corpus_name'])) {
        $errors[] = "语料名称不能为空";
        $valid = false;
    } elseif (strlen($_POST['corpus_name']) > 200) {
        $errors[] = "语料名称不能超过200个字符";
        $valid = false;
    } elseif (!preg_match('/^[\w\s\p{Han}-]+$/u', $_POST['corpus_name'])) {
        $errors[] = "语料名称包含不允许的字符";
        $valid = false;
    }
    
    // 验证语料来源
    $validSources = ['专业教材', '网站网页', '期刊论文', '其它来源'];
    if (empty($_POST['source']) || !in_array($_POST['source'], $validSources)) {
        $errors[] = "请选择有效的语料来源";
        $valid = false;
    }
    
    // 验证作者标记
    if (!empty($_POST['author_tag']) && strlen($_POST['author_tag']) > 200) {
        $errors[] = "作者标记不能超过200个字符";
        $valid = false;
    } elseif (!empty($_POST['author_tag']) && !preg_match('/^[\w\s\p{Han}-]+$/u', $_POST['author_tag'])) {
        $errors[] = "作者标记包含不允许的字符";
        $valid = false;
    }
    
    // 验证语料类型
    $validTypes = ['原始文本', '分词文本（Segment）', '词性标注文本（POS）', '组块结构分析文本（Tree）'];
    if (empty($_POST['corpus_type']) || !in_array($_POST['corpus_type'], $validTypes)) {
        $errors[] = "请选择有效的语料类型";
        $valid = false;
    }
    
    // 验证文件上传
    $content = '';
    if ($_FILES['corpus_file']['error'] == UPLOAD_ERR_OK) {
        $fileInfo = pathinfo($_FILES['corpus_file']['name']);
        
        // 验证文件格式
        if (strtolower($fileInfo['extension']) != 'txt') {
            $errors[] = "请上传txt格式的文本文件";
            $valid = false;
        } else {
            // 读取文件内容
            $content = file_get_contents($_FILES['corpus_file']['tmp_name']);
            
            // 根据语料类型验证内容格式
            $corpusType = $_POST['corpus_type'];
            if ($corpusType == '分词文本（Segment）' && !preg_match('/[\w\p{Han}]+\s+[\w\p{Han}]/u', $content)) {
                $errors[] = "分词文本格式不正确，词语之间应使用空格分隔";
                $valid = false;
            } elseif ($corpusType == '词性标注文本（POS）' && !preg_match('/[\w\p{Han}]+\/[a-zA-Z]+/u', $content)) {
                $errors[] = "词性标注文本格式不正确，应使用'词语/词性'格式";
                $valid = false;
            } elseif ($corpusType == '组块结构分析文本（Tree）' && !preg_match('/\([A-Z]+\s*\([^)]+\)\)/', $content)) {
                $errors[] = "组块结构分析文本格式不正确，应包含类似(NP (NN 词语))的结构";
                $valid = false;
            }
        }
    } else {
        $errors[] = "请上传语料文件";
        $valid = false;
    }
    
    // 验证备注说明
    if (!empty($_POST['remarks']) && strlen($_POST['remarks']) > 200) {
        $errors[] = "备注说明不能超过200个字符";
        $valid = false;
    }
    
    // 如果验证通过，保存到数据库
    if ($valid) {
        try {
            $stmt = $pdo->prepare("INSERT INTO corpus_entries 
                                 (corpus_name, source, author_tag, corpus_type, content, remarks) 
                                 VALUES (:corpus_name, :source, :author_tag, :corpus_type, :content, :remarks)");
            
            $stmt->bindParam(':corpus_name', $_POST['corpus_name']);
            $stmt->bindParam(':source', $_POST['source']);
            $stmt->bindParam(':author_tag', $_POST['author_tag']);
            $stmt->bindParam(':corpus_type', $_POST['corpus_type']);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':remarks', $_POST['remarks']);
            
            $stmt->execute();
            
            $message = "语料信息已成功保存到数据库！";
            $messageType = 'success';
            $formData = []; // 清空表单数据
        } catch(PDOException $e) {
            $message = "保存失败: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "请修正以下错误: <br>" . implode("<br>", $errors);
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商务汉语语料库录入</title>
    <style>
        body {
            font-family: "Microsoft YaHei", sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
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
        input[type="text"], 
        textarea, 
        select,
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-family: inherit;
            font-size: 16px;
        }
        input[type="text"], select {
            height: 40px;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .btn-submit {
            background-color: #4CAF50;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            transition: background-color 0.3s;
        }
        .btn-submit:hover {
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
        .hint {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>商务汉语语料库录入</h1>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="corpus_name">语料名称 *</label>
                <input type="text" id="corpus_name" name="corpus_name" 
                       value="<?php echo isset($formData['corpus_name']) ? htmlspecialchars($formData['corpus_name']) : ''; ?>"
                       maxlength="200" required>
                <div class="hint">最多200个字符，可包含汉字、字母、数字、下划线和空格</div>
            </div>
            
            <div class="form-group">
                <label for="source">语料来源 *</label>
                <select id="source" name="source" required>
                    <option value="">请选择来源</option>
                    <option value="专业教材" <?php echo isset($formData['source']) && $formData['source'] == '专业教材' ? 'selected' : ''; ?>>专业教材</option>
                    <option value="网站网页" <?php echo isset($formData['source']) && $formData['source'] == '网站网页' ? 'selected' : ''; ?>>网站网页</option>
                    <option value="期刊论文" <?php echo isset($formData['source']) && $formData['source'] == '期刊论文' ? 'selected' : ''; ?>>期刊论文</option>
                    <option value="其它来源" <?php echo isset($formData['source']) && $formData['source'] == '其它来源' ? 'selected' : ''; ?>>其它来源</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="author_tag">作者标记</label>
                <input type="text" id="author_tag" name="author_tag" 
                       value="<?php echo isset($formData['author_tag']) ? htmlspecialchars($formData['author_tag']) : ''; ?>"
                       maxlength="200">
                <div class="hint">最多200个字符，可包含汉字、字母、数字、下划线和空格</div>
            </div>
            
            <div class="form-group">
                <label for="corpus_type">语料类型 *</label>
                <select id="corpus_type" name="corpus_type" required>
                    <option value="">请选择类型</option>
                    <option value="原始文本" <?php echo isset($formData['corpus_type']) && $formData['corpus_type'] == '原始文本' ? 'selected' : ''; ?>>原始文本</option>
                    <option value="分词文本（Segment）" <?php echo isset($formData['corpus_type']) && $formData['corpus_type'] == '分词文本（Segment）' ? 'selected' : ''; ?>>分词文本（Segment）</option>
                    <option value="词性标注文本（POS）" <?php echo isset($formData['corpus_type']) && $formData['corpus_type'] == '词性标注文本（POS）' ? 'selected' : ''; ?>>词性标注文本（POS）</option>
                    <option value="组块结构分析文本（Tree）" <?php echo isset($formData['corpus_type']) && $formData['corpus_type'] == '组块结构分析文本（Tree）' ? 'selected' : ''; ?>>组块结构分析文本（Tree）</option>
                </select>
                <div class="hint">请选择与上传文件内容匹配的类型</div>
            </div>
            
            <div class="form-group">
                <label for="corpus_file">语料文件 *</label>
                <input type="file" id="corpus_file" name="corpus_file" accept=".txt" required>
                <div class="hint">
                    请上传txt格式文件，内容需符合所选语料类型的格式要求<br>
                    - 分词文本：词语之间用空格分隔<br>
                    - 词性标注文本：使用"词语/词性"格式<br>
                    - 组块结构分析文本：包含类似(NP (NN 词语))的结构
                </div>
            </div>
            
            <div class="form-group">
                <label for="remarks">备注说明</label>
                <textarea id="remarks" name="remarks" maxlength="200"><?php echo isset($formData['remarks']) ? htmlspecialchars($formData['remarks']) : ''; ?></textarea>
                <div class="hint">最多200个字符</div>
            </div>
            
            <button type="submit" class="btn-submit">提交语料信息</button>
        </form>
    </div>
</body>
</html>
    