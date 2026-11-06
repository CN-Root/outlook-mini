<?php
/**
 * MIME 正文提取（包 C Step 5 从 api.php 原样搬出）
 *
 * 为什么不做成类：包 D 的 D1 才会把 MIME 解析整体重写（多段嵌套、编码名、内联图等）。
 * 此刻任何"顺手面向对象化"都会让包 D 的 diff 混进无关噪音，也会让"逻辑一字未改"
 * 变得无法核对。所以这里保留**同名全局函数 + 同参数名 + 同一份实现**，
 * 连注释都照抄，只换文件位置。
 *
 * 不要在包 C 阶段给它加参数、加返回值类型声明、或改成 static 方法。
 */

/**
 * 核心辅助函数：提取并解码邮件正文
 * 处理 Base64, Quoted-Printable 以及 GBK 编码
 */
function extract_message_body($part) {
    // 分割头部和正文（邮件协议中头部和正文由第一个空行分隔）
    $parts = preg_split('/\r?\n\r?\n/', $part, 2);
    if (count($parts) < 2) return '';
    
    $header = $parts[0];
    $content = trim($parts[1]);

    // 1. 处理传输编码 (Transfer-Encoding)
    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $header)) {
        $content = base64_decode(str_replace(["\r", "\n"], '', $content));
    } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $header)) {
        $content = quoted_printable_decode($content);
    }

    // 2. 处理字符集编码 (Charset)
    if (preg_match('/charset="?([^"\r\n;]+)"?/i', $header, $charMat)) {
        $charset = strtoupper($charMat[1]);
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $content = mb_convert_encoding($content, 'UTF-8', $charset);
        }
    }
    
    return $content;
}
