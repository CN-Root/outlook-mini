<?php
/**
 * 邮件正文 HTML 白名单净化器（包 E · E-4，安全项 H-2 的服务端半程）
 *
 * 本类是 H-2 的两道防线之一（另一道是 dashboard.php 里 iframe 的 sandbox，见 E-4）。
 * 它只负责一件事：把"不可信的邮件正文 HTML"洗成"只含安全标签/安全属性的 HTML"。
 *
 * 为什么需要它：
 *   E-3 的 escapeHtml 只保护"字段进 DOM"（subject / from / email / remark / 组名…），
 *   而正文是另一条路——前端 renderMailBody 仍 document.write 整段 HTML，escapeHtml 管不到。
 *   <img src=x onerror>、<svg onload>、<iframe srcdoc> 都能在文档里执行脚本，且同源 iframe
 *   能读到父页 state（含全部账号凭据）。所以必须在正文进响应之前，先在服务端剥干净。
 *
 * 白名单策略（本步定型的契约，E-4 之后不要擅自放宽）：
 *   - 删整颗标签：script / style / iframe / object / embed / form / input / link / meta
 *     （连同其子内容一起删——script/style/iframe 的子内容就是危险本体，不能留下）
 *   - 不在安全清单里的标签：删标签、留文本（如 <font>x</font> → x，<svg onload> → 消失）
 *   - 删属性：所有 on* 事件属性；href/src 里 javascript: / data:text/html 开头的值
 *     （href 改成 #，src 直接删属性）
 *   - 解析失败（畸形 HTML）不抛异常：返回转义后的原文，让 get_mails 永远不 500
 *
 * 纯函数纪律：
 *   - 不读任何超全局（$_GET/$_POST/$_SERVER/…），不 echo、不 exit、不写库、不发网络；
 *   - 同一个输入永远同一个输出，可直接做单元断言。
 */
final class HtmlSanitizer
{
    /** 连标签带子内容整体删除的危险标签 */
    private const BLOCK_TAGS = [
        'script'   => true,
        'style'    => true,
        'iframe'   => true,
        'object'   => true,
        'embed'    => true,
        'form'     => true,
        'input'    => true,
        'link'     => true,
        'meta'     => true,
        'base'     => true,
        'frame'    => true,
        'frameset' => true,
        'noscript' => true,
        'applet'   => true,
    ];

    /** 保留的安全标签（其余一律"删标签留文本"） */
    private const ALLOWED_TAGS = [
        'p' => true, 'div' => true, 'span' => true, 'a' => true, 'img' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true,
        'tr' => true, 'td' => true, 'th' => true, 'br' => true, 'hr' => true,
        'strong' => true, 'b' => true, 'em' => true, 'i' => true, 'u' => true,
        'ul' => true, 'ol' => true, 'li' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'blockquote' => true, 'code' => true, 'pre' => true,
        'sub' => true, 'sup' => true, 'small' => true, 's' => true, 'strike' => true,
    ];

    /**
     * 净化一段 HTML，返回只含白名单标签/属性的 HTML 字符串。
     *
     * @param  string $html 邮件正文（可信度为零，可能含任意攻击载荷）
     * @return string 净化后的 HTML；解析失败时返回转义后的原文（绝不抛异常）
     */
    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            // 前置 XML 编码声明：让 libxml 按 UTF-8 解析，避免中文正文被按 Latin-1 解成乱码。
            // 显式包一层 <html><body>：libxml 对"裸文本直接挂在 body 下"会自动补 <p> 包裹，
            // 那会让纯文本正文 / 块元素后的文本被改写（破坏 byte 级一致性，D0 的 md5 锚点会红）。
            // 有了显式 body 包裹后，裸文本原样保留。LIBXML_NONET 禁止外部实体/DTD 网络加载，堵 XXE/SSRF。
            $ok = $doc->loadHTML(
                '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            libxml_clear_errors();

            // 畸形 HTML 解析失败：按契约返回转义原文，不抛异常、不让 get_mails 500。
            if ($ok === false) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return '';
            }

            // 递归清洗：危险标签整颗删、未知标签留文本、已知标签清属性
            self::cleanNode($body);

            $out = '';
            foreach ($body->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }

            return $out;
        } finally {
            // 无论成败都把 libxml 内部错误状态复位，避免污染调用方的后续 DOM 操作
            libxml_use_internal_errors($prev);
            libxml_clear_errors();
        }
    }

    /**
     * 递归清洗一个节点的子节点。
     *
     * @param DOMElement $node 父节点（body 或某个已通过白名单的元素）
     */
    private static function cleanNode(DOMElement $node): void
    {
        $i = 0;
        while ($i < $node->childNodes->length) {
            $child = $node->childNodes->item($i);
            if ($child === null) {
                break;
            }

            // 注释直接删，不留 <!-- -->
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue; // 长度减一，下一个节点顶到同一下标，不 i++
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $i++; // 文本节点原样保留
                continue;
            }

            /** @var DOMElement $child */
            $tag = strtolower($child->tagName);

            // 1) 危险标签：整颗删除（含子内容）
            if (isset(self::BLOCK_TAGS[$tag])) {
                $node->removeChild($child);
                continue;
            }

            // 2) 未知标签：删壳留文本，把子节点上提到父级继续清洗
            if (!isset(self::ALLOWED_TAGS[$tag])) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue; // 上提的子节点落在同一下标，继续处理
            }

            // 3) 白名单标签：清属性后递归子节点
            self::cleanAttributes($child);
            self::cleanNode($child);
            $i++;
        }
    }

    /**
     * 清理单个元素的属性：删所有 on*、净化 javascript:/data:text/html 的 href/src。
     *
     * @param DOMElement $el
     */
    private static function cleanAttributes(DOMElement $el): void
    {
        $toRemove = [];

        foreach ($el->attributes as $attr) {
            $name  = strtolower($attr->nodeName);
            $value = $attr->nodeValue;

            // 所有事件属性（onerror/onload/onclick/onmouseover…）一律删
            if (str_starts_with($name, 'on')) {
                $toRemove[] = $attr->nodeName;
                continue;
            }

            // 只对 href / src 做协议白名单：javascript: 与 data:text/html 都是脚本向量
            if ($name === 'href' || $name === 'src') {
                if (self::isDangerousUrl($value)) {
                    if ($name === 'href') {
                        // href 改成 '#' 保住链接可点性但去掉执行能力
                        $el->setAttribute('href', '#');
                    } else {
                        // src 无"降级"余地，直接删属性（img 变为不加载）
                        $toRemove[] = $attr->nodeName;
                    }
                }
            }
        }

        foreach ($toRemove as $n) {
            $el->removeAttribute($n);
        }
    }

    /**
     * 判断一个 URL 值是否危险：剥掉空白、解掉实体后，看是否以 javascript: / data:text/html 开头。
     *
     * @param  string $value 属性原始值
     * @return bool
     */
    private static function isDangerousUrl(string $value): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 去掉所有空白与控制字符（\x00-\x20），挡住 "java script:" / "java\tscript:" 这类绕过
        $collapsed = preg_replace('/[\x00-\x20]+/', '', strtolower($decoded));

        return str_starts_with($collapsed, 'javascript:')
            || str_starts_with($collapsed, 'data:text/html');
    }
}
