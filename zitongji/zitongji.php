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
$uploadDir = 'uploads/';
$resultDir = 'results/';
$wordlistsDir = 'wordlists/'; // 用于存放字表数据

foreach ([$uploadDir, $resultDir, $wordlistsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 初始化变量
$message = '';
$reportFiles = [];
$originalFilename = '';

// 加载常用字表数据（如果不存在则创建基本版本）
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
    
    // 检查字表文件是否存在，不存在则创建基本版本
    foreach ($wordlists as $key => $list) {
        $filePath = $wordlistsDir . $key . '.txt';
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $words = explode("\n", trim($content));
            $wordlists[$key] = array_filter(array_map('trim', $words));
        } else {
            // 创建基本字表文件
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

// 读取不同类型文件的内容
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
                // 处理.doc文件
                try {
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

// 统计文本基本信息
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
    
    // 初始化Jieba
    Jieba::init();
    Posseg::init();
    
    // 总字符数
    $totalChars = mb_strlen($content);
    $result['summary']['总字符数'] = $totalChars;
    
    // 按字符拆分
    $chars = preg_split('/(?<!^)(?!$)/u', $content);
    
    // 统计每个字符的出现次数
    $charCounts = [];
    foreach ($chars as $char) {
        if (trim($char) === '') continue;
        if (!isset($charCounts[$char])) {
            $charCounts[$char] = 0;
        }
        $charCounts[$char]++;
    }
    
    // 字种数（不同字符的数量）
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
            // 简单判断：不在繁体字表中的汉字视为简化字
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
    
    // 总字表使用率（每个字的使用频率）
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
            // 只统计汉字
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
    $totalTexts = 1; // 单个文件分析
    
    foreach ($categories as $category => $charsInCategory) {
        $count = 0;
        $foundChars = [];
        
        if ($category == '规范字') {
            // 规范字 = 总汉字 - 其他分类的字
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
        if ($category == '规范字') continue; // 规范字已包含在常用字统计中
        
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
    
    return $result;
}

// 生成报告文件
function generateReports($analysisResult, $originalFilename, $resultDir) {
    $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
    $reportFiles = [];
    
    // 1. 摘要报告
    $summaryContent = "=== 文本摘要报告 ===\n\n";
    foreach ($analysisResult['summary'] as $key => $value) {
        $summaryContent .= sprintf("%-10s: %d\n", $key, $value);
    }
    
    $summaryFile = $baseName . '_摘要报告.txt';
    file_put_contents($resultDir . $summaryFile, $summaryContent);
    $reportFiles[] = $summaryFile;
    
    // 2. 总字表频率
    $frequencyContent = "=== 总字表频率（按频率倒序） ===\n\n";
    $frequencyContent .= "字\t频次\n";
    foreach ($analysisResult['char_frequency'] as $char => $count) {
        $frequencyContent .= $char . "\t" . $count . "\n";
    }
    
    $frequencyFile = $baseName . '_总字表频率.txt';
    file_put_contents($resultDir . $frequencyFile, $frequencyContent);
    $reportFiles[] = $frequencyFile;
    
    // 3. 总字表使用率
    $usageContent = "=== 总字表使用率（按频率倒序） ===\n\n";
    $usageContent .= "字\t频次\t频率(%)\n";
    foreach ($analysisResult['char_usage'] as $char => $data) {
        $usageContent .= $char . "\t" . $data['count'] . "\t" . $data['frequency'] . "\n";
    }
    
    $usageFile = $baseName . '_总字表使用率.txt';
    file_put_contents($resultDir . $usageFile, $usageContent);
    $reportFiles[] = $usageFile;
    
    // 4. 繁体字表
    $traditionalContent = "=== 繁体字表 ===\n\n";
    $traditionalContent .= "字\t频次\n";
    arsort($analysisResult['traditional_chars']);
    foreach ($analysisResult['traditional_chars'] as $char => $count) {
        $traditionalContent .= $char . "\t" . $count . "\n";
    }
    
    $traditionalFile = $baseName . '_繁体字表.txt';
    file_put_contents($resultDir . $traditionalFile, $traditionalContent);
    $reportFiles[] = $traditionalFile;
    
    // 5. 异体字表
    $variantContent = "=== 异体字表 ===\n\n";
    $variantContent .= "字\t频次\n";
    arsort($analysisResult['variant_chars']);
    foreach ($analysisResult['variant_chars'] as $char => $count) {
        $variantContent .= $char . "\t" . $count . "\n";
    }
    
    $variantFile = $baseName . '_异体字表.txt';
    file_put_contents($resultDir . $variantFile, $variantContent);
    $reportFiles[] = $variantFile;
    
    // 6. 其他不规范字
    $nonStandardContent = "=== 其他不规范字 ===\n\n";
    $nonStandardContent .= "字\t频次\n";
    arsort($analysisResult['non_standard_chars']);
    foreach ($analysisResult['non_standard_chars'] as $char => $count) {
        $nonStandardContent .= $char . "\t" . $count . "\n";
    }
    
    $nonStandardFile = $baseName . '_其他不规范字.txt';
    file_put_contents($resultDir . $nonStandardFile, $nonStandardContent);
    $reportFiles[] = $nonStandardFile;
    
    // 7. 标点符号
    $punctuationContent = "=== 标点符号统计 ===\n\n";
    $punctuationContent .= "标点\t频次\n";
    arsort($analysisResult['punctuation']);
    foreach ($analysisResult['punctuation'] as $char => $count) {
        $punctuationContent .= $char . "\t" . $count . "\n";
    }
    
    $punctuationFile = $baseName . '_标点符号.txt';
    file_put_contents($resultDir . $punctuationFile, $punctuationContent);
    $reportFiles[] = $punctuationFile;
    
    // 8. 覆盖率
    $coverageContent = "=== 覆盖率统计 ===\n\n";
    $coverageContent .= "常用字使用数量: " . $analysisResult['coverage']['common_chars_used'] . "\n";
    $coverageContent .= "总常用字数量: " . $analysisResult['coverage']['common_chars_total'] . "\n";
    $coverageContent .= "覆盖率: " . $analysisResult['coverage']['coverage_rate'] . "%\n";
    
    $coverageFile = $baseName . '_覆盖率.txt';
    file_put_contents($resultDir . $coverageFile, $coverageContent);
    $reportFiles[] = $coverageFile;
    
    // 9. 与常用字表对比
    $compareContent = "=== 与常用字表对比 ===\n\n";
    
    $compareContent .= "--- 文本中出现的常用字 ---\n";
    $compareContent .= "字\t频次\n";
    foreach ($analysisResult['common_compare']['common'] as $char => $count) {
        $compareContent .= $char . "\t" . $count . "\n";
    }
    
    $compareContent .= "\n--- 文本中出现的非常用字 ---\n";
    $compareContent .= "字\t频次\n";
    foreach ($analysisResult['common_compare']['uncommon'] as $char => $count) {
        $compareContent .= $char . "\t" . $count . "\n";
    }
    
    $compareFile = $baseName . '_与常用字表对比.txt';
    file_put_contents($resultDir . $compareFile, $compareContent);
    $reportFiles[] = $compareFile;
    
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
    file_put_contents($resultDir . $categoryFile, $categoryContent);
    $reportFiles[] = $categoryFile;
    
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
    file_put_contents($resultDir . $coverageReportFile, $coverageReportContent);
    $reportFiles[] = $coverageReportFile;
    
    // 打包所有报告
    $zip = new ZipArchive();
    $zipFilename = $baseName . '_分析报告汇总.zip';
    $zipPath = $resultDir . $zipFilename;
    
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($reportFiles as $file) {
            $zip->addFile($resultDir . $file, $file);
        }
        $zip->close();
        $reportFiles[] = $zipFilename;
    }
    
    return $reportFiles;
}

// 处理文件上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && empty($missingExtensions)) {
    $file = $_FILES['file'];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "上传错误: " . $file['error'];
    } else {
        // 验证文件类型
        $allowedTypes = [
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($file['tmp_name']);
        
        if (!isset($allowedTypes[$mimeType])) {
            $message = "不支持的文件类型。请上传TXT或Word文件。";
        } else {
            // 处理文件名
            $originalFilename = $file['name'];
            $extension = $allowedTypes[$mimeType];
            $filename = uniqid() . '.' . $extension;
            $targetPath = $uploadDir . $filename;
            
            // 移动上传文件
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // 读取文件内容
                $error = '';
                $content = readFileContent($targetPath, $extension, $error);
                
                if ($content !== false) {
                    // 加载字表数据
                    $wordlists = loadWordLists();
                    
                    // 分析文本
                    $analysisResult = analyzeText($content, $wordlists);
                    
                    // 生成报告
                    $reportFiles = generateReports($analysisResult, $originalFilename, $resultDir);
                    
                    // 清理上传的文件
                    unlink($targetPath);
                    
                    $message = "文本分析完成！共生成 " . (count($reportFiles) - 1) . " 份报告和1个汇总包。";
                    $_SESSION['report_files'] = $reportFiles;
                    $_SESSION['original_filename'] = $originalFilename;
                } else {
                    $message = "无法读取文件内容: " . $error;
                }
            } else {
                $message = "文件上传失败";
            }
        }
    }
}

// 下载报告文件
if (isset($_GET['download']) && !empty($_GET['file']) && isset($_SESSION['report_files'])) {
    $filename = $_GET['file'];
    $filePath = $resultDir . $filename;
    
    // 验证文件是否在报告列表中
    if (in_array($filename, $_SESSION['report_files']) && file_exists($filePath)) {
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
    <title>中文文本详细统计分析工具</title>
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
        }
        .instructions {
            background-color: #e9f7fe;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>中文文本详细统计分析工具</h1>
        
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
            </div>
        <?php endif; ?>
        
        <div class="instructions">
            <h3>工具说明</h3>
            <p>本工具可对上传的中文文本进行详细统计分析，支持TXT、DOC和DOCX格式文件。分析完成后将生成以下报告：</p>
            <ul>
                <li>摘要报告（总字符数、字种数、标点次数等）</li>
                <li>总字表频率和使用率</li>
                <li>繁体字、异体字和不规范字表</li>
                <li>标点符号统计</li>
                <li>覆盖率分析</li>
                <li>与常用字表对比</li>
                <li>字分类使用情况报告</li>
                <li>汉字字表覆盖率报告</li>
            </ul>
            <p>注：首次使用时会自动创建基础字表数据，您可以在wordlists文件夹中自定义各类字表。</p>
        </div>
        
        <div class="upload-form">
            <form method="post" enctype="multipart/form-data">
                <label for="file">选择文件（支持TXT、DOC、DOCX）：</label>
                <input type="file" name="file" id="file" 
                    accept="<?php echo class_exists('ZipArchive') ? '.txt,.doc,.docx' : '.txt'; ?>" required>
                <br>
                <button type="submit">开始分析</button>
            </form>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo strpos($message, '失败') !== false || strpos($message, '错误') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['report_files']) && !empty($_SESSION['report_files'])): ?>
            <div class="reports-section">
                <h3 class="section-title">分析报告：<?php echo $_SESSION['original_filename']; ?></h3>
                
                <div class="report-category">
                    <h4>全部报告文件：</h4>
                    <ul class="report-list">
                        <?php foreach ($_SESSION['report_files'] as $file): ?>
                            <li>
                                <a href="?download=1&file=<?php echo urlencode($file); ?>" class="download-link">
                                    <?php echo $file; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

