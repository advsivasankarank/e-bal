<?php
/**
 * report_download.php / invoice_download.php are streaming download
 * endpoints -- their error paths used to be a bare exit('...')/die('...')
 * with no HTML, no site chrome, and no link back into the app. A user who
 * lands on one directly (stale bookmark, expired session, a blocked
 * download) hit a genuine dead end. This renders a minimal, self-contained
 * error page with a link back instead.
 */
function exitWithDownloadError(string $message, int $statusCode = 400, string $backUrl = '', string $backLabel = 'Back to e-BAL'): void
{
    http_response_code($statusCode);

    if ($backUrl === '' && defined('BASE_URL')) {
        $backUrl = BASE_URL . 'dashboard_company.php';
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>Download Unavailable</title>'
        . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;max-width:560px;margin:60px auto;padding:0 20px;color:#1f2937;}'
        . '.box{background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:20px 24px;}'
        . 'a.btn{display:inline-block;margin-top:16px;padding:8px 16px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:0.9rem;}</style>'
        . '</head><body><div class="box"><strong>This download is unavailable.</strong><p>' . nl2br(htmlspecialchars($message)) . '</p></div>'
        . ($backUrl !== '' ? '<a class="btn" href="' . htmlspecialchars($backUrl) . '">' . htmlspecialchars($backLabel) . '</a>' : '')
        . '</body></html>';
    exit;
}
