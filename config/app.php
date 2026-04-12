<?php
if (!defined('ENV')) {
    define('ENV', getenv('APP_ENV') ?: 'local');
}

if (!defined('BASE_URL')) {
    if (ENV === 'local') {
        define('BASE_URL', '/e-bal/public/');
    } else {
        $envBase = getenv('APP_BASE_URL');
        define('BASE_URL', $envBase ? rtrim($envBase, '/') . '/' : '/');
    }
}
define('MCA_LOOKUP_URL', getenv('MCA_LOOKUP_URL') ?: '');
define('MCA_LOOKUP_TOKEN', getenv('MCA_LOOKUP_TOKEN') ?: '');
define('DIRECTORS_REPORT_AI_URL', getenv('DIRECTORS_REPORT_AI_URL') ?: '');
define('DIRECTORS_REPORT_AI_TOKEN', getenv('DIRECTORS_REPORT_AI_TOKEN') ?: '');

function url($path) {
    return BASE_URL . $path;
}
