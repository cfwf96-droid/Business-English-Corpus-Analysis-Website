<?php
// 网站标题
$siteTitle = "商务汉语语料网";

// 工具列表数据
$tools = [
    [
        'name' => '中文文本词性标注',
        'icon' => '🏷️',
        'link' => 'pos/pos.php',
        'description' => '对中文文本进行词性标注处理'
    ],
    [
        'name' => '中文文本分词文本',
        'icon' => '✂️',
        'link' => 'segment/segment.php',
        'description' => '将中文文本分割为词语单元'
    ],
    [
        'name' => '中文组块结构分析1',
        'icon' => '🔄',
        'link' => 'chunk-bracket/chunk.php',
        'description' => '分析中文文本的组块结构'
    ],
    [
        'name' => '文本字统计分析',
        'icon' => '🔤',
        'link' => 'zitongji/zitongji.php',
        'description' => '统计文本中汉字出现频率'
    ],
    [
        'name' => '文本词统计分析',
        'icon' => '📄',
        'link' => 'citongji/citongji.php',
        'description' => '分析文本中词语的出现情况'
    ],
    [
        'name' => '中文文本字词查询',
        'icon' => '🔍',
        'link' => 'zicichaxun/zicichaxun.php',
        'description' => '查询文本中的特定字词'
    ],
    [
        'name' => '中文组块结构分析2',
        'icon' => '🔀',
        'link' => 'zukuaifenxi/zukuaifenxipy.php',
        'description' => '另一种中文组块结构分析方式'
    ],
    [
        'name' => '字词统计与词云绘制',
        'icon' => '📊',
        'link' => 'ciyun/ciyun.php',
        'description' => '生成文本字词统计词云'
    ],
     // 修改的中文组块结构检索模块
    [
        'name' => '语料库组块分析文件检索',
        'icon' => '📁',
        'link' => 'zkjs/jszk.php',
        'description' => '检索中文文本的组块结构信息'
    ],
    // 新增的用户上传组块分析文件检索模块
    [
        'name' => '用户上传组块分析文件检索',
        'icon' => '📤',
        'link' => 'zkjs/zkfs2.php',
        'description' => '检索用户上传文件的组块结构信息'
    ],
    [
        'name' => '中文语料库录入',
        'icon' => '💾',
        'link' => 'yuliaoluru/yuliaoluru.php',
        'description' => '录入和管理中文语料库数据'
    ],
    // 新增的商务汉语语料库管理模块
    [
        'name' => '商务汉语语料库管理',
        'icon' => '⚙️',
        'link' => 'kuguanli/dbmanager.php',
        'description' => '管理商务汉语语料库的相关数据'
    ]
   

];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $siteTitle; ?></title>
    
    <style>
        /* 基础样式重置 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        body {
            background-color: #f9fafb;
            color: #1f2937;
            line-height: 1.5;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }
        
        /* 头部样式 */
        header {
            background: linear-gradient(90deg, #2563eb, #0ea5e9);
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 2rem 0;
        }
        
        .header-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
        }
        
        @media (min-width: 768px) {
            .header-content {
                flex-direction: row;
            }
        }
        
        .header-title {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 768px) {
            .header-title {
                margin-bottom: 0;
            }
        }
        
        .header-title i {
            font-size: 2rem;
            margin-right: 1rem;
        }
        
        .header-title h1 {
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        .header-desc {
            text-align: center;
            opacity: 0.9;
        }
        
        @media (min-width: 768px) {
            .header-desc {
                text-align: right;
            }
        }
        
        /* 导航栏样式 */
        nav {
            background-color: white;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
        }
        
        .nav-content {
            display: flex;
            justify-content: center;
        }
        
        .nav-text {
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        .nav-text i {
            margin-right: 0.25rem;
        }
        
        /* 主要内容区样式 */
        main {
            padding: 2.5rem 0;
        }
        
        .intro-section {
            text-align: center;
            max-width: 600px;
            margin: 0 auto 3rem;
        }
        
        .intro-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: bold;
            margin-bottom: 1rem;
            color: #1f2937;
        }
        
        .intro-desc {
            color: #6b7280;
        }
        
        /* 工具卡片网格 */
        .tools-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        
        @media (min-width: 640px) {
            .tools-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .tools-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        .tool-card {
            display: block;
            text-decoration: none;
        }
        
        .card-inner {
            background-color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f3f4f6;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-inner:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.15);
        }
        
        .card-icon {
            width: 3.5rem;
            height: 3.5rem;
            background-color: rgba(37, 99, 235, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 1.5rem;
            color: #2563eb;
        }
        
        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        
        .card-desc {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
        }
        
        /* 页脚样式 */
        footer {
            background-color: #1f2937;
            color: #d1d5db;
            margin-top: 4rem;
            padding: 2rem 0;
        }
        
        .footer-content {
            text-align: center;
        }
        
        .footer-copyright {
            margin-bottom: 0.5rem;
        }
        
        .footer-info {
            font-size: 0.875rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <!-- 页面头部 -->
    <header>
        <div class="container header-content">
            <div class="header-title">
                <span>🌐</span>
                <h1><?php echo $siteTitle; ?></h1>
            </div>
            <div class="header-desc">
                <p>商务汉语中文语料处理与分析平台</p>
            </div>
        </div>
    </header>

    <!-- 导航栏 -->
    <nav>
        <div class="container nav-content">
            <span class="nav-text">
                <span>ℹ️</span>提供商务汉语文本处理工具
            </span>
        </div>
    </nav>

    <!-- 主要内容区 -->
    <main>
        <div class="container">
            <!-- 页面介绍 -->
            <section class="intro-section">
                <h2 class="intro-title">商务汉语中文文本分析工具集</h2>
                <p class="intro-desc"> 本语料库系教育部中外语言交流合作中心国际中文教育研究课题：“中文+职业技能”背景下商务汉语词汇等级构建（项目编号：22YH59C）的阶段性成果。 </p>
            </section>
            
            <!-- 工具卡片网格 -->
            <section class="tools-grid">
                <?php foreach ($tools as $index => $tool): ?>
                    <a href="<?php echo $tool['link']; ?>" class="tool-card">
                        <div class="card-inner">
                            <div class="card-icon">
                                <?php echo $tool['icon']; ?>
                            </div>
                            <h3 class="card-title"><?php echo $tool['name']; ?></h3>
                            <p class="card-desc"><?php echo $tool['description']; ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </section>
        </div>
    </main>

    <!-- 页脚 -->
    <footer>
        <div class="container footer-content">
            <p class="footer-copyright">&copy; <?php echo date("Y"); ?> 商务汉语语料网 - 专注中文语料处理与分析</p>
            <p class="footer-info">提供专业、高效的商务汉语中文自然语言处理工具</p>
        </div>
    </footer>

    <!-- 页面交互脚本 -->
    <script>
        // 页面加载时的渐入动画
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.card-inner');
            cards.forEach((card, index) => {
                // 初始状态
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                // 延迟动画，创建级联效果
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100 * index);
            });
        });
    </script>
</body>
</html>
