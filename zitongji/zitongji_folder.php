<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600); // 延长执行时间

// 增加内存限制 - 从512M增加到1G
ini_set('memory_limit', '1024M');

// 尝试加载Composer自动加载文件
$composerPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php'
];

$autoloadLoaded = false;
foreach ($composerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadLoaded = true;
        break;
    }
}

// 检查必要的扩展和库
$missingExtensions = [];
$missingLibraries = [];
$libraryCheckDetails = [];

// 检查Zip扩展
if (!class_exists('ZipArchive')) {
    $missingExtensions[] = 'ZipArchive（处理Word文件和压缩文件需要）';
}

// 检查PHPWord库
$phpWordFound = false;
if (class_exists('PhpOffice\PhpWord\IOFactory')) {
    $phpWordFound = true;
    $libraryCheckDetails['PHPWord'] = '已找到: PhpOffice\PhpWord\IOFactory';
} else {
    $missingLibraries[] = 'PHPWord（处理Word文件需要）';
    $libraryCheckDetails['PHPWord'] = '未找到: PhpOffice\PhpWord\IOFactory';
}

// 检查Jieba库
$jiebaFound = false;
if (class_exists('Fukuball\Jieba\Jieba')) {
    $jiebaFound = true;
    $libraryCheckDetails['Jieba-PHP'] = '已找到: Fukuball\Jieba\Jieba';
} else {
    $missingLibraries[] = 'Jieba-PHP（中文分词需要）';
    $libraryCheckDetails['Jieba-PHP'] = '未找到: Fukuball\Jieba\Jieba';
}

// 检查Composer自动加载是否成功
if (!$autoloadLoaded) {
    $missingLibraries[] = 'Composer自动加载文件（vendor/autoload.php）';
    $libraryCheckDetails['Composer'] = '未找到自动加载文件，已搜索路径: ' . implode(', ', $composerPaths);
}

// 确保目录存在
$tmpDir = 'tmp/';
$resultDir = 'results/';
$wordlistsDir = 'wordlists/';

foreach ([$tmpDir, $resultDir, $wordlistsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 初始化变量
$message = '';
$allReportFiles = [];
$folderName = '';
$maxFileSize = 10 * 1024 * 1024; // 限制最大文件大小为10MB

// 加载常用字表数据
function loadWordLists() {
    global $wordlistsDir;
    
    $wordlists = [
        'common' => [],      // 常用字表
        'traditional' => [], // 繁体字表
        'variant' => [],     // 异体字表
        'dialect' => [],     // 方言字表
        'korean' => [],      // 韩国汉字
        'japanese' => [],    // 日本汉字
        'non_standard' => [],// 不规范简化字
        'old_measure' => [], // 旧计量用字
        'old_print' => []    // 旧印刷字形
    ];
    
    foreach ($wordlists as $key => $list) {
        $filePath = $wordlistsDir . $key . '.txt';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $words = explode("\n", trim($content));
            $wordlists[$key] = array_filter(array_map('trim', $words));
        } else {
            $sampleData = getSampleWordList($key);
            file_put_contents($filePath, implode("\n", $sampleData));
            $wordlists[$key] = $sampleData;
        }
    }
    
    return $wordlists;
}

// 获取样本字表数据
function getSampleWordList($type) {
    switch ($type) {
        case 'common':
            return ['的', '一', '是', '在', '不', '了', '有', '和', '人', '我', '都', '好', '看', '说', '他', '她', '它'];
        case 'traditional':
            return ['體', '認', '愛', '葉', '聽', '裡', '後', '眾', '東', '風', '為', '會'];
        case 'variant':
            return ['爲', '甯', '於', '隻', '麼', '牠', '衆'];
        case 'dialect':
            return ['睇', '企', '摞', '搵', '冧', '靓', '冇', '谂'];
        case 'korean':
            return ['乭', '乶', '乷', '乸', '乹', '乺', '乻', '乼'];
        case 'japanese':
            return ['働', '込', '抜', '拝', '桜', '畑', '辻', '峠'];
        case 'non_standard':
            return ['仃', '仃', '仃', '仃', '仃', '仃'];
        case 'old_measure':
            return ['寸', '尺', '丈', '石', '斗', '升', '斤', '两'];
        case 'old_print':
            return ['⺀', '⺁', '⺂', '⺃', '⺄', '⺅', '⺆', '⺇'];
        default:
            return [];
    }
}

// 读取不同类型文件的内容，增加文件大小检查
function readFileContent($filePath, $extension, &$error = '', $maxSize = 10 * 1024 * 1024) {
    // 检查文件大小
    if (filesize($filePath) > $maxSize) {
        $error = "文件过大（超过" . ($maxSize / 1024 / 1024) . "MB），无法处理";
        return false;
    }
    
    try {
        switch (strtolower($extension)) {
            case 'txt':
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
                
                // 限制文本长度，防止过大文本消耗内存
                $maxTextLength = 1000000; // 限制最大文本长度为100万字
                if (mb_strlen($content) > $maxTextLength) {
                    $content = mb_substr($content, 0, $maxTextLength);
                    $error = "警告：文本过长，已截断至前100万字进行分析";
                }
                
                return trim($content);
                
            case 'doc':
                if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
                    $error = "PHPWord库未安装，无法处理Word文件";
                    return false;
                }
                
                try {
                    $phpWord = PhpOffice\PhpWord\IOFactory::load($filePath, 'Word2003');
                } catch (Exception $e) {
                    $error = "不支持的DOC文件格式: " . $e->getMessage();
                    return false;
                }
                break;
                
            case 'docx':
                if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
                    $error = "PHPWord库未安装，无法处理Word文件";
                    return false;
                }
                
                try {
                    $phpWord = PhpOffice\PhpWord\IOFactory::load($filePath);
                } catch (Exception $e) {
                    $error = "不支持的DOCX文件格式: " . $e->getMessage();
                    return false;
                }
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
                if ($element instanceof PhpOffice\PhpWord\Element\TextRun) {
                    foreach ($element->getElements() as $textElement) {
                        if ($textElement instanceof PhpOffice\PhpWord\Element\Text) {
                            $text .= $textElement->getText() . ' ';
                        }
                    }
                } elseif ($element instanceof PhpOffice\PhpWord\Element\Text) {
                    $text .= $element->getText() . ' ';
                }
            }
            
            // 防止内存溢出，处理一部分就检查长度
            if (mb_strlen($text) > 1000000) {
                $text = mb_substr($text, 0, 1000000);
                $error = "警告：Word文档内容过长，已截断至前100万字进行分析";
                break;
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

// 优化的文本分析函数，减少内存使用
function analyzeText($content, $wordlists) {
    $result = [
        'summary' => [],
        'char_frequency' => [],
        'char_usage' => [],
        'traditional_chars' => [],
        'variant_chars' => [],
        'non_standard_chars' => [],
        'punctuation' => [],
        'coverage' => [],
        'common_compare' => [],
        'category_stats' => [],
        'coverage_report' => []
    ];
    
    if (!class_exists('Fukuball\Jieba\Jieba')) {
        throw new Exception("Jieba-PHP库未安装，无法进行文本分析");
    }
    
    // 初始化Jieba时使用更小的词库
    Fukuball\Jieba\Jieba::init([
        'mode' => 'default',
        'dict' => 'small'  // 使用小词库减少内存占用
    ]);
    Fukuball\Jieba\Posseg::init([
        'mode' => 'default',
        'dict' => 'small'  // 使用小词库
    ]);
    
    // 总字符数
    $totalChars = mb_strlen($content);
    $result['summary']['总字符数'] = $totalChars;
    
    // 分批处理字符，减少内存占用
    $charCounts = [];
    $batchSize = 10000; // 每批处理10000个字符
    $totalBatches = ceil($totalChars / $batchSize);
    
    for ($i = 0; $i < $totalBatches; $i++) {
        $start = $i * $batchSize;
        $batchContent = mb_substr($content, $start, $batchSize);
        $chars = preg_split('/(?<!^)(?!$)/u', $batchContent);
        
        foreach ($chars as $char) {
            if (trim($char) === '') continue;
            if (!isset($charCounts[$char])) {
                $charCounts[$char] = 0;
            }
            $charCounts[$char]++;
        }
        
        // 清理变量，释放内存
        unset($batchContent, $chars);
    }
    
    // 字种数
    $result['summary']['字种数'] = count($charCounts);
    
    // 统计标点符号
    $punctuation = [];
    $punctuationCount = 0;
    $punctuationChars = ['，', '。', '、', '；', '：', '？', '！', '“', '”', '‘', '’', '（', '）', '【', '】', '《', '》', ',', '.', ';', ':', '?', '!', '"', '\'', '(', ')', '[', ']', '{', '}'];
    
    foreach ($charCounts as $char => $count) {
        if (in_array($char, $punctuationChars)) {
            $punctuation[$char] = $count;
            $punctuationCount += $count;
        }
    }
    $result['summary']['标点总次数'] = $punctuationCount;
    $result['punctuation'] = $punctuation;
    
    // 统计英文字母
    $englishCount = 0;
    foreach ($charCounts as $char => $count) {
        if (preg_match('/[a-zA-Z]/', $char)) {
            $englishCount += $count;
        }
    }
    $result['summary']['英文字总次数'] = $englishCount;
    
    // 统计繁体字、简化字
    $traditionalCount = 0;
    $simplifiedCount = 0;
    $traditionalChars = [];
    
    foreach ($charCounts as $char => $count) {
        if (in_array($char, $wordlists['traditional'])) {
            $traditionalCount += $count;
            $traditionalChars[$char] = $count;
        } else {
            if (preg_match('/\p{Han}/u', $char)) {
                $simplifiedCount += $count;
            }
        }
    }
    
    $result['summary']['繁体字总次数'] = $traditionalCount;
    $result['summary']['简化字总次数'] = $simplifiedCount;
    $result['traditional_chars'] = $traditionalChars;
    
    // 统计异体字
    $variantCount = 0;
    $variantChars = [];
    foreach ($charCounts as $char => $count) {
        if (in_array($char, $wordlists['variant'])) {
            $variantCount += $count;
            $variantChars[$char] = $count;
        }
    }
    $result['variant_chars'] = $variantChars;
    
    // 总字表频率（按频率排序）
    arsort($charCounts);
    $result['char_frequency'] = $charCounts;
    
    // 总字表使用率
    $charUsage = [];
    foreach ($charCounts as $char => $count) {
        $charUsage[$char] = [
            'count' => $count,
            'frequency' => round(($count / $totalChars) * 100, 4)
        ];
    }
    $result['char_usage'] = $charUsage;
    
    // 统计其他不规范字
    $nonStandardChars = [];
    foreach ($charCounts as $char => $count) {
        if (in_array($char, $wordlists['non_standard'])) {
            $nonStandardChars[$char] = $count;
        }
    }
    $result['non_standard_chars'] = $nonStandardChars;
    
    // 与常用字表对比
    $commonInText = [];
    $uncommonInText = [];
    
    foreach ($charCounts as $char => $count) {
        if (in_array($char, $wordlists['common'])) {
            $commonInText[$char] = $count;
        } else {
            if (preg_match('/\p{Han}/u', $char)) {
                $uncommonInText[$char] = $count;
            }
        }
    }
    
    arsort($commonInText);
    arsort($uncommonInText);
    
    $result['common_compare'] = [
        'common' => $commonInText,
        'uncommon' => $uncommonInText
    ];
    
    // 计算覆盖率
    $commonCharsCount = array_sum($commonInText);
    $coverageRate = $totalChars > 0 ? round(($commonCharsCount / $totalChars) * 100, 2) : 0;
    
    $result['coverage'] = [
        'common_chars_used' => count($commonInText),
        'common_chars_total' => count($wordlists['common']),
        'coverage_rate' => $coverageRate
    ];
    
    // 字分类使用情况统计
    $categories = [
        '规范字' => [],
        '繁体字' => $wordlists['traditional'],
        '异体字' => $wordlists['variant'],
        '方言字' => $wordlists['dialect'],
        '韩国汉字' => $wordlists['korean'],
        '日本汉字' => $wordlists['japanese'],
        '不规范简化字' => $wordlists['non_standard'],
        '旧计量用字' => $wordlists['old_measure'],
        '旧印刷字形' => $wordlists['old_print']
    ];
    
    $categoryStats = [];
    $totalTexts = 1;
    
    foreach ($categories as $category => $charsInCategory) {
        $count = 0;
        $foundChars = [];
        
        if ($category == '规范字') {
            foreach ($charCounts as $char => $charCount) {
                $isOtherCategory = false;
                foreach ($categories as $cat => $cChars) {
                    if ($cat != '规范字' && in_array($char, $cChars)) {
                        $isOtherCategory = true;
                        break;
                    }
                }
                
                if (!$isOtherCategory && preg_match('/\p{Han}/u', $char)) {
                    $count += $charCount;
                    $foundChars[$char] = $charCount;
                }
            }
        } else {
            foreach ($charsInCategory as $char) {
                if (isset($charCounts[$char])) {
                    $count += $charCounts[$char];
                    $foundChars[$char] = $charCounts[$char];
                }
            }
        }
        
        $frequency = $totalChars > 0 ? round(($count / $totalChars) * 100, 4) : 0;
        $categoryTotalChars = count($charsInCategory);
        $categoryUsageRate = $categoryTotalChars > 0 ? round((count($foundChars) / $categoryTotalChars) * 100, 2) : 0;
        
        $categoryStats[$category] = [
            '频次' => $count,
            '频率(%)' => $frequency,
            '文本数' => $totalTexts,
            '字种数' => count($foundChars),
            '在该类中的频率(%)' => $categoryUsageRate
        ];
    }
    
    // 计算累加频率
    $sortedCategories = $categoryStats;
    uasort($sortedCategories, function($a, $b) {
        return $b['频次'] - $a['频次'];
    });
    
    $cumulativeFrequency = 0;
    foreach ($sortedCategories as &$stats) {
        $cumulativeFrequency += $stats['频率(%)'];
        $stats['累加频率(%)'] = round($cumulativeFrequency, 4);
    }
    
    $result['category_stats'] = $sortedCategories;
    
    // 汉字字表覆盖率报告
    $coverageReport = [];
    
    // 常用字覆盖率
    $commonCoverage = [
        '覆盖率(%)' => $coverageRate,
        '字种数' => count($commonInText),
        '占所有字种数的比例(%)' => $result['summary']['字种数'] > 0 ? 
            round((count($commonInText) / $result['summary']['字种数']) * 100, 2) : 0
    ];
    $coverageReport['常用字'] = $commonCoverage;
    
    // 各分类字覆盖率
    foreach ($categories as $category => $charsInCategory) {
        if ($category == '规范字') continue;
        
        $foundCount = 0;
        foreach ($charsInCategory as $char) {
            if (isset($charCounts[$char])) {
                $foundCount++;
            }
        }
        
        $categoryTotal = count($charsInCategory);
        $coverage = $categoryTotal > 0 ? round(($foundCount / $categoryTotal) * 100, 2) : 0;
        $proportion = $result['summary']['字种数'] > 0 ? 
            round(($foundCount / $result['summary']['字种数']) * 100, 2) : 0;
        
        $coverageReport[$category] = [
            '覆盖率(%)' => $coverage,
            '字种数' => $foundCount,
            '占所有字种数的比例(%)' => $proportion
        ];
    }
    
    // 按覆盖率排序
    uasort($coverageReport, function($a, $b) {
        return $b['覆盖率(%)'] - $a['覆盖率(%)'];
    });
    
    $result['coverage_report'] = $coverageReport;
    
    // 移除对不存在的destroy()方法的调用
    // 使用unset和垃圾回收来释放内存
    unset($jieba, $posseg);
    gc_collect_cycles();
    
    // 释放内存
    unset($charCounts, $commonInText, $uncommonInText, $categoryStats, $coverageReport);
    
    return $result;
}

// 生成单个文件的报告
function generateFileReports($analysisResult, $originalFilename, $relativePath, $resultDir) {
    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
    $reportFiles = [];
    $fileResultDir = $resultDir . $relativePath . '/';
    
    if (!is_dir($fileResultDir)) {
        mkdir($fileResultDir, 0755, true);
    }
    
    // 1. 摘要报告
    $summaryContent = "=== 文本摘要报告 ===\n\n";
    foreach ($analysisResult['summary'] as $key => $value) {
        $summaryContent .= sprintf("%-10s: %d\n", $key, $value);
    }
    
    $summaryFile = $baseName . '_摘要报告.txt';
    file_put_contents($fileResultDir . $summaryFile, $summaryContent);
    $reportFiles[] = $relativePath . '/' . $summaryFile;
    
    // 2. 总字表频率（限制输出数量，防止过大文件）
    $frequencyContent = "=== 总字表频率（按频率倒序，前500个字） ===\n\n";
    $frequencyContent .= "字\t频次\n";
    $count = 0;
    foreach ($analysisResult['char_frequency'] as $char => $cnt) {
        $frequencyContent .= $char . "\t" . $cnt . "\n";
        $count++;
        if ($count >= 500) break; // 只输出前500个字
    }
    
    $frequencyFile = $baseName . '_总字表频率.txt';
    file_put_contents($fileResultDir . $frequencyFile, $frequencyContent);
    $reportFiles[] = $relativePath . '/' . $frequencyFile;
    
    // 3. 总字表使用率（限制输出数量）
    $usageContent = "=== 总字表使用率（按频率倒序，前500个字） ===\n\n";
    $usageContent .= "字\t频次\t频率(%)\n";
    $count = 0;
    foreach ($analysisResult['char_usage'] as $char => $data) {
        $usageContent .= $char . "\t" . $data['count'] . "\t" . $data['frequency'] . "\n";
        $count++;
        if ($count >= 500) break;
    }
    
    $usageFile = $baseName . '_总字表使用率.txt';
    file_put_contents($fileResultDir . $usageFile, $usageContent);
    $reportFiles[] = $relativePath . '/' . $usageFile;
    
    // 4. 繁体字表
    $traditionalContent = "=== 繁体字表 ===\n\n";
    $traditionalContent .= "字\t频次\n";
    arsort($analysisResult['traditional_chars']);
    foreach ($analysisResult['traditional_chars'] as $char => $count) {
        $traditionalContent .= $char . "\t" . $count . "\n";
    }
    
    $traditionalFile = $baseName . '_繁体字表.txt';
    file_put_contents($fileResultDir . $traditionalFile, $traditionalContent);
    $reportFiles[] = $relativePath . '/' . $traditionalFile;
    
    // 5. 异体字表
    $variantContent = "=== 异体字表 ===\n\n";
    $variantContent .= "字\t频次\n";
    arsort($analysisResult['variant_chars']);
    foreach ($analysisResult['variant_chars'] as $char => $count) {
        $variantContent .= $char . "\t" . $count . "\n";
    }
    
    $variantFile = $baseName . '_异体字表.txt';
    file_put_contents($fileResultDir . $variantFile, $variantContent);
    $reportFiles[] = $relativePath . '/' . $variantFile;
    
    // 6. 其他不规范字
    $nonStandardContent = "=== 其他不规范字 ===\n\n";
    $nonStandardContent .= "字\t频次\n";
    arsort($analysisResult['non_standard_chars']);
    foreach ($analysisResult['non_standard_chars'] as $char => $count) {
        $nonStandardContent .= $char . "\t" . $count . "\n";
    }
    
    $nonStandardFile = $baseName . '_其他不规范字.txt';
    file_put_contents($fileResultDir . $nonStandardFile, $nonStandardContent);
    $reportFiles[] = $relativePath . '/' . $nonStandardFile;
    
    // 7. 标点符号
    $punctuationContent = "=== 标点符号统计 ===\n\n";
    $punctuationContent .= "标点\t频次\n";
    arsort($analysisResult['punctuation']);
    foreach ($analysisResult['punctuation'] as $char => $count) {
        $punctuationContent .= $char . "\t" . $count . "\n";
    }
    
    $punctuationFile = $baseName . '_标点符号.txt';
    file_put_contents($fileResultDir . $punctuationFile, $punctuationContent);
    $reportFiles[] = $relativePath . '/' . $punctuationFile;
    
    // 8. 覆盖率
    $coverageContent = "=== 覆盖率统计 ===\n\n";
    $coverageContent .= "常用字使用数量: " . $analysisResult['coverage']['common_chars_used'] . "\n";
    $coverageContent .= "总常用字数量: " . $analysisResult['coverage']['common_chars_total'] . "\n";
    $coverageContent .= "覆盖率: " . $analysisResult['coverage']['coverage_rate'] . "%\n";
    
    $coverageFile = $baseName . '_覆盖率.txt';
    file_put_contents($fileResultDir . $coverageFile, $coverageContent);
    $reportFiles[] = $relativePath . '/' . $coverageFile;
    
    // 9. 与常用字表对比
    $compareContent = "=== 与常用字表对比 ===\n\n";
    
    $compareContent .= "--- 文本中出现的常用字（前200个） ---\n";
    $compareContent .= "字\t频次\n";
    $count = 0;
    foreach ($analysisResult['common_compare']['common'] as $char => $cnt) {
        $compareContent .= $char . "\t" . $cnt . "\n";
        $count++;
        if ($count >= 200) break;
    }
    
    $compareContent .= "\n--- 文本中出现的非常用字（前200个） ---\n";
    $compareContent .= "字\t频次\n";
    $count = 0;
    foreach ($analysisResult['common_compare']['uncommon'] as $char => $cnt) {
        $compareContent .= $char . "\t" . $cnt . "\n";
        $count++;
        if ($count >= 200) break;
    }
    
    $compareFile = $baseName . '_与常用字表对比.txt';
    file_put_contents($fileResultDir . $compareFile, $compareContent);
    $reportFiles[] = $relativePath . '/' . $compareFile;
    
    // 10. 字分类使用情况报告
    $categoryContent = "=== 字分类使用情况报告 ===\n\n";
    $categoryContent .= "类别\t频次\t频率(%)\t文本数\t字种数\t在该类中的频率(%)\t累加频率(%)\n";
    
    foreach ($analysisResult['category_stats'] as $category => $stats) {
        $categoryContent .= $category . "\t" . 
                           $stats['频次'] . "\t" . 
                           $stats['频率(%)'] . "\t" . 
                           $stats['文本数'] . "\t" . 
                           $stats['字种数'] . "\t" . 
                           $stats['在该类中的频率(%)'] . "\t" . 
                           $stats['累加频率(%)'] . "\n";
    }
    
    $categoryFile = $baseName . '_字分类使用情况报告.txt';
    file_put_contents($fileResultDir . $categoryFile, $categoryContent);
    $reportFiles[] = $relativePath . '/' . $categoryFile;
    
    // 11. 汉字字表覆盖率报告
    $coverageReportContent = "=== 汉字字表覆盖率报告 ===\n\n";
    $coverageReportContent .= "字表类型\t覆盖率(%)\t字种数\t占所有字种数的比例(%)\n";
    
    foreach ($analysisResult['coverage_report'] as $type => $data) {
        $coverageReportContent .= $type . "\t" . 
                                 $data['覆盖率(%)'] . "\t" . 
                                 $data['字种数'] . "\t" . 
                                 $data['占所有字种数的比例(%)'] . "\n";
    }
    
    $coverageReportFile = $baseName . '_汉字字表覆盖率报告.txt';
    file_put_contents($fileResultDir . $coverageReportFile, $coverageReportContent);
    $reportFiles[] = $relativePath . '/' . $coverageReportFile;
    
    // 为单个文件创建汇总包
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $zipFilename = $baseName . '_分析报告汇总.zip';
        $zipPath = $fileResultDir . $zipFilename;
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($reportFiles as $file) {
                $localPath = $resultDir . $file;
                $zip->addFile($localPath, basename($localPath));
            }
            $zip->close();
            $reportFiles[] = $relativePath . '/' . $zipFilename;
        }
    }
    
    // 释放内存
    unset($analysisResult);
    
    return [
        'file' => $originalFilename,
        'reports' => $reportFiles,
        'relative_path' => $relativePath
    ];
}

// 处理单个文件
function processFile($filePath, $originalName, $relativePath, $resultDir, $wordlists, &$allReportFiles, &$processedFiles, &$errorFiles, $maxFileSize) {
    $pathInfo = pathinfo($filePath);
    $extension = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';
    
    $allowedExtensions = ['txt', 'doc', 'docx'];
    if (!in_array(strtolower($extension), $allowedExtensions)) {
        $errorFiles[] = [
            'file' => $originalName,
            'error' => '不支持的文件类型'
        ];
        return false;
    }
    
    // 检查文件大小
    if (filesize($filePath) > $maxFileSize) {
        $errorFiles[] = [
            'file' => $originalName,
            'error' => '文件过大（超过' . ($maxFileSize / 1024 / 1024) . 'MB），无法处理'
        ];
        return false;
    }
    
    // 如果是Word文件但PHPWord未安装，直接报错
    if (in_array(strtolower($extension), ['doc', 'docx']) && !class_exists('PhpOffice\PhpWord\IOFactory')) {
        $errorFiles[] = [
            'file' => $originalName,
            'error' => 'PHPWord库未安装，无法处理Word文件'
        ];
        return false;
    }
    
    // 读取文件内容
    $error = '';
    $content = readFileContent($filePath, $extension, $error, $maxFileSize);
    if ($content === false) {
        $errorFiles[] = [
            'file' => $originalName,
            'error' => $error
        ];
        return false;
    }
    
    try {
        // 分析文本
        $analysisResult = analyzeText($content, $wordlists);
        
        // 生成报告
        $fileReports = generateFileReports($analysisResult, $originalName, $relativePath, $resultDir);
        
        $allReportFiles = array_merge($allReportFiles, $fileReports['reports']);
        $processedFiles[] = $fileReports;
        
        // 清理内存
        unset($content, $analysisResult, $fileReports);
        
        return true;
    } catch (Exception $e) {
        $errorFiles[] = [
            'file' => $originalName,
            'error' => '分析过程出错: ' . $e->getMessage()
        ];
        return false;
    }
}

// 递归遍历文件夹并处理所有文件，增加并发控制
function processDirectory($dir, $resultDir, $wordlists, &$allReportFiles, &$processedFiles, &$errorFiles, $parentDir = '', $maxFileSize = 10 * 1024 * 1024) {
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    
    $fileCount = 0;
    $maxConcurrentFiles = 5; // 限制同时处理的文件数量
    
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        $itemPath = $dir . '/' . $item;
        $relativePath = $parentDir ? $parentDir . '/' . $item : $item;
        
        if (is_dir($itemPath)) {
            // 递归处理子文件夹
            processDirectory($itemPath, $resultDir, $wordlists, $allReportFiles, $processedFiles, $errorFiles, $relativePath, $maxFileSize);
        } else {
            // 控制并发处理的文件数量
            if ($fileCount >= $maxConcurrentFiles) {
                // 每处理一定数量的文件后清理内存并休息一下
                gc_collect_cycles();
                sleep(1);
                $fileCount = 0;
            }
            
            // 处理文件
            $originalName = basename($item);
            processFile($itemPath, $originalName, $parentDir, $resultDir, $wordlists, $allReportFiles, $processedFiles, $errorFiles, $maxFileSize);
            
            $fileCount++;
        }
    }
}

// 处理文件夹上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($missingExtensions) && empty($missingLibraries)) {
    if (isset($_FILES['folder']) && is_array($_FILES['folder']['error']) && $_FILES['folder']['error'][0] !== UPLOAD_ERR_NO_FILE) {
        $files = $_FILES['folder'];
        $tmpFolder = $tmpDir . uniqid() . '/';
        mkdir($tmpFolder, 0755, true);
        
        $processedFiles = [];
        $errorFiles = [];
        $allReportFiles = [];
        $folderName = '';
        
        // 保存上传的文件夹内容
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $nameParts = explode('/', $files['name'][$i]);
                $fileName = array_pop($nameParts);
                $relativePath = implode('/', $nameParts);
                
                // 获取根文件夹名称
                if (empty($folderName) && !empty($nameParts)) {
                    $folderName = $nameParts[0];
                }
                
                // 检查文件大小
                if ($files['size'][$i] > $maxFileSize) {
                    $errorFiles[] = [
                        'file' => $files['name'][$i],
                        'error' => '文件过大（超过' . ($maxFileSize / 1024 / 1024) . 'MB），已跳过'
                    ];
                    continue;
                }
                
                // 创建目录结构
                if (!empty($relativePath)) {
                    $targetDir = $tmpFolder . $relativePath . '/';
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                }
                
                // 移动文件
                $targetFile = $tmpFolder . $files['name'][$i];
                move_uploaded_file($files['tmp_name'][$i], $targetFile);
            }
        }
        
        // 加载字表数据
        $wordlists = loadWordLists();
        
        // 处理文件夹中的所有文件
        processDirectory($tmpFolder, $resultDir, $wordlists, $allReportFiles, $processedFiles, $errorFiles, '', $maxFileSize);
        
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
        
        // 创建整个文件夹的汇总包
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $zipFilename = $folderName . '_全部分析报告汇总.zip';
            $zipPath = $resultDir . $zipFilename;
            
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                foreach ($allReportFiles as $file) {
                    $localPath = $resultDir . $file;
                    if (file_exists($localPath)) {
                        $zip->addFile($localPath, $file);
                    }
                }
                $zip->close();
                array_unshift($allReportFiles, $zipFilename);
            }
        }
        
        // 生成结果消息
        $totalFiles = count($processedFiles) + count($errorFiles);
        if ($totalFiles > 0) {
            $message = "成功处理文件夹 '$folderName'，共 " . $totalFiles . " 个文件，其中成功 " . count($processedFiles) . " 个，失败 " . count($errorFiles) . " 个。";
        } else {
            $message = "文件夹中未找到可处理的文件（支持TXT、DOC、DOCX）";
        }
        
        $_SESSION['processed_files'] = $processedFiles;
        $_SESSION['error_files'] = $errorFiles;
        $_SESSION['all_report_files'] = $allReportFiles;
        $_SESSION['folder_name'] = $folderName;
        
        // 清理内存
        gc_collect_cycles();
    } else {
        $message = "请选择要上传的文件夹";
    }
}

// 下载报告文件
if (isset($_GET['download']) && !empty($_GET['file']) && isset($_SESSION['all_report_files'])) {
    $filename = $_GET['file'];
    $filePath = $resultDir . $filename;
    
    if (in_array($filename, $_SESSION['all_report_files']) && file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . (pathinfo($filename, PATHINFO_EXTENSION) == 'zip' ? 'application/zip' : 'text/plain'));
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $message = "下载文件不存在或无权访问";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>中文文本详细统计分析工具（文件夹版）</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
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
        .critical-error {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            border: 1px solid #ef9a9a;
        }
        .reports-section {
            margin-top: 20px;
        }
        .report-list {
            list-style-type: none;
            padding: 0;
        }
        .report-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .report-list li:last-child {
            border-bottom: none;
        }
        .download-link {
            color: #2196F3;
            text-decoration: none;
        }
        .download-link:hover {
            text-decoration: underline;
        }
        .section-title {
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        .report-category {
            margin: 20px 0;
            padding: 15px;
            background-color: #fff;
            border-radius: 6px;
        }
        .file-group {
            margin: 15px 0;
            padding-left: 20px;
        }
        .file-group-title {
            font-weight: bold;
            margin: 10px 0;
            color: #333;
        }
        .instructions {
            background-color: #e9f7fe;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .folder-indicator {
            color: #666;
            font-style: italic;
            margin: 10px 0 5px 10px;
            font-weight: bold;
        }
        .error-details {
            font-size: 0.9em;
            color: #721c24;
        }
        .code-block {
            background-color: #f8f8f8;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            overflow-x: auto;
            margin: 10px 0;
        }
        .diagnostic-info {
            background-color: #e8f5e9;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 0.9em;
        }
        .memory-settings {
            background-color: #fff3e0;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>中文文本详细统计分析工具（文件夹版）</h1>
        
        <?php if (!empty($missingExtensions) || !empty($missingLibraries)): ?>
            <div class="critical-error">
                <strong>错误：检测到缺失必要的组件，无法运行</strong>
                
                <?php if (!empty($missingExtensions)): ?>
                    <p><strong>缺失的PHP扩展：</strong></p>
                    <ul>
                        <?php foreach ($missingExtensions as $ext): ?>
                            <li><?php echo $ext; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                
                <?php if (!empty($missingLibraries)): ?>
                    <p><strong>缺失的PHP库：</strong></p>
                    <ul>
                        <?php foreach ($missingLibraries as $lib): ?>
                            <li><?php echo $lib; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <div class="diagnostic-info">
                        <p><strong>诊断信息：</strong></p>
                        <ul>
                            <?php foreach ($libraryCheckDetails as $lib => $info): ?>
                                <li><?php echo $lib . ': ' . $info; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <p><strong>请按照以下步骤安装所需库：</strong></p>
                    <ol>
                        <li>确保已安装Composer（PHP包管理器）</li>
                        <li>在项目根目录打开命令行</li>
                        <li>运行以下命令：</li>
                        <div class="code-block">
                            composer require phpoffice/phpword<br>
                            composer require fukuball/jieba-php
                        </div>
                    </ol>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="memory-settings">
                <p><strong>系统设置：</strong>内存限制已设置为1024MB，最大文件处理大小为10MB</p>
            </div>
            
            <div class="diagnostic-info">
                <p><strong>组件检查：</strong>所有必要的组件已正确安装</p>
                <ul>
                    <?php foreach ($libraryCheckDetails as $lib => $info): ?>
                        <li><?php echo $lib . ': ' . $info; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div class="instructions">
                <h3>工具说明</h3>
                <p>本工具可对上传的文件夹中所有中文文本进行详细统计分析，支持TXT、DOC和DOCX格式文件。</p>
                <p><strong>注意事项：</strong></p>
                <ul>
                    <li>为避免内存不足，系统会自动限制文件大小不超过10MB</li>
                    <li>过大的文本会被截断至前100万字进行分析</li>
                    <li>字频统计结果中，只显示频率最高的前500个字</li>
                </ul>
            </div>
            
            <div class="upload-form">
                <form method="post" enctype="multipart/form-data">
                    <label for="folder">选择文件夹（将处理其中所有TXT、DOC、DOCX文件）：</label>
                    <input type="file" name="folder[]" id="folder" webkitdirectory directory multiple
                        accept=".txt,.doc,.docx" required>
                    <p><small>提示：选择文件夹后，系统将递归处理其中所有支持的文件类型</small></p>
                    <br>
                    <button type="submit">开始分析</button>
                </form>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?php echo strpos($message, '失败') !== false || strpos($message, '错误') !== false ? 'error' : 'success'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['processed_files']) && !empty($_SESSION['processed_files'])): ?>
                <div class="reports-section">
                    <h3 class="section-title">分析报告：<?php echo $_SESSION['folder_name']; ?></h3>
                    
                    <!-- 全部报告汇总 -->
                    <div class="report-category">
                        <h4>全部报告汇总：</h4>
                        <ul class="report-list">
                            <?php 
                            $fullPackage = '';
                            if (isset($_SESSION['all_report_files'])) {
                                foreach ($_SESSION['all_report_files'] as $file) {
                                    if (strpos($file, '全部分析报告汇总.zip') !== false) {
                                        $fullPackage = $file;
                                        break;
                                    }
                                }
                            }
                            
                            if ($fullPackage):
                            ?>
                                <li>
                                    <a href="?download=1&file=<?php echo urlencode($fullPackage); ?>" class="download-link">
                                        <?php echo $fullPackage; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <!-- 按文件分组的报告 -->
                    <div class="report-category">
                        <h4>按文件查看报告：</h4>
                        
                        <?php 
                        $filesByDir = [];
                        if (isset($_SESSION['processed_files'])) {
                            foreach ($_SESSION['processed_files'] as $file) {
                                $dir = $file['relative_path'] ?: '根目录';
                                if (!isset($filesByDir[$dir])) {
                                    $filesByDir[$dir] = [];
                                }
                                $filesByDir[$dir][] = $file;
                            }
                        }
                        
                        foreach ($filesByDir as $dir => $files):
                        ?>
                            <div class="folder-indicator">📂 <?php echo $dir; ?></div>
                            <div class="file-group">
                                <?php foreach ($files as $file): ?>
                                    <div class="file-group-title">📄 <?php echo $file['file']; ?></div>
                                    <ul class="report-list">
                                        <?php foreach ($file['reports'] as $report): ?>
                                            <li>
                                                <a href="?download=1&file=<?php echo urlencode($report); ?>" class="download-link">
                                                    <?php echo basename($report); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_files']) && !empty($_SESSION['error_files'])): ?>
                <div class="report-category">
                    <h4 class="section-title">处理失败的文件：</h4>
                    <ul class="report-list">
                        <?php foreach ($_SESSION['error_files'] as $file): ?>
                            <li>
                                <?php echo $file['file']; ?>
                                <div class="error-details">原因：<?php echo $file['error']; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
