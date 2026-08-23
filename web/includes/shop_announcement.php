<?php
/**
 * Shared helpers for the shop announcement banner.
 *
 * The announcement is authored by the shop owner (in user/website/index.php)
 * and displayed at the top of the public shop page (web/shop.php).
 * It supports a safe subset of Markdown (bold, italic, links, etc.).
 */

/**
 * Make sure the announcement columns exist on website_settings.
 */
function shop_announcement_ensure_columns(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'shop_announcement'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE website_settings ADD COLUMN shop_announcement TEXT NULL COMMENT 'ئاگادارکردنەوەی سەرەوەی فرۆشگا (markdown)' AFTER shop_banner");
    }
    if ($check) {
        $check->close();
    }

    $check = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'shop_announcement_enabled'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE website_settings ADD COLUMN shop_announcement_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=ئاگادارکردنەوە پیشان بدرێت' AFTER shop_announcement");
    }
    if ($check) {
        $check->close();
    }
}

/**
 * Should the announcement be shown on the shop page?
 */
function shop_announcement_is_active(array $settings): bool
{
    return (int) ($settings['shop_announcement_enabled'] ?? 0) === 1
        && trim((string) ($settings['shop_announcement'] ?? '')) !== '';
}

/**
 * Convert a small, safe subset of Markdown to HTML.
 *
 * The input is fully HTML-escaped FIRST, so no raw markup from the author can
 * reach the browser. Only the whitelisted inline formatting below is turned
 * back into tags. Supported:
 *   **bold**  __bold__      -> <strong>
 *   *italic*  _italic_      -> <em>
 *   ~~strike~~              -> <del>
 *   `code`                  -> <code>
 *   [text](https://url)     -> <a> (http/https/mailto only)
 *   line breaks / blank line -> <br>
 */
function shop_announcement_render_html(?string $markdown): string
{
    $text = trim((string) $markdown);
    if ($text === '') {
        return '';
    }

    // Normalize newlines and escape everything up front (XSS-safe baseline).
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Links: [label](url) — only http(s)/mailto, url already HTML-escaped above.
    $text = preg_replace_callback(
        '/\[([^\]\n]+)\]\(([^)\s]+)\)/',
        static function (array $m): string {
            $label = $m[1];
            $url = $m[2];
            // htmlspecialchars turned & into &amp; etc.; decode for the scheme test only.
            $rawUrl = htmlspecialchars_decode($url, ENT_QUOTES);
            if (!preg_match('#^(https?://|mailto:)#i', $rawUrl)) {
                return $label; // drop unsafe scheme, keep the visible text
            }
            return '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . $label . '</a>';
        },
        $text
    );

    // Inline code: `code`
    $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);

    // Bold: **text** or __text__
    $text = preg_replace('/\*\*([^\n]+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^\n]+?)__/s', '<strong>$1</strong>', $text);

    // Strikethrough: ~~text~~
    $text = preg_replace('/~~([^\n]+?)~~/s', '<del>$1</del>', $text);

    // Italic: *text* or _text_ (after bold so ** isn't eaten)
    $text = preg_replace('/(?<![\*\w])\*(?!\s)([^\*\n]+?)(?<!\s)\*(?![\*\w])/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![_\w])_(?!\s)([^_\n]+?)(?<!\s)_(?![_\w])/', '<em>$1</em>', $text);

    // Line breaks.
    $text = nl2br($text, false);

    return $text;
}
