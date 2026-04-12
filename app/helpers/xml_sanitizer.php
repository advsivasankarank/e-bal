<?php

function isValidXmlCodePoint($codePoint)
{
    return $codePoint === 0x9
        || $codePoint === 0xA
        || $codePoint === 0xD
        || ($codePoint >= 0x20 && $codePoint <= 0xD7FF)
        || ($codePoint >= 0xE000 && $codePoint <= 0xFFFD)
        || ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF);
}

function sanitizeTallyXML($raw)
{
    if (!$raw) return '';

    // 1. Remove UTF-8 BOM
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    // 2. Handle UTF-16/UTF-8 payloads more carefully
    if (substr($raw, 0, 2) === "\xFF\xFE") {
        $raw = substr($raw, 2);
        $converted = iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) {
            $raw = $converted;
        }
    } elseif (substr($raw, 0, 2) === "\xFE\xFF") {
        $raw = substr($raw, 2);
        $converted = iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) {
            $raw = $converted;
        }
    } elseif (strpos(substr($raw, 0, 200), "\x00") !== false) {
        $converted = iconv('UTF-16', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) {
            $raw = $converted;
        }
    }

    // 3. Drop invalid numeric XML character references like &#4; or &#x4;
    $raw = preg_replace_callback(
        '/&#(?:x([0-9A-Fa-f]+)|([0-9]+));/',
        static function ($matches) {
            $codePoint = isset($matches[1]) && $matches[1] !== ''
                ? hexdec($matches[1])
                : (int) $matches[2];

            return isValidXmlCodePoint($codePoint) ? $matches[0] : '';
        },
        $raw
    );

    // 4. Remove invalid XML characters (per XML 1.0 spec)
    $raw = preg_replace(
        '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
        '',
        $raw
    );

    // 5. Keep the XML declaration but trim any garbage before the actual document
    $raw = preg_replace('/^[^<]*/', '', $raw);

    // 6. If encoding still says UTF-16 after conversion, fix the declaration
    $raw = preg_replace(
        '/<\?xml([^>]*?)encoding=["\']utf-16["\']([^>]*?)\?>/i',
        '<?xml$1encoding="utf-8"$2?>',
        $raw,
        1
    );

    // 7. Trim garbage before root element
    $start = strpos($raw, '<ENVELOPE');
    if ($start !== false) {
        $raw = substr($raw, $start);
    }

    // 8. Final cleanup
    return trim($raw);
}
