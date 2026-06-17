<?php
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
}
require_once __DIR__ . '/../app/helpers/runtime_helper.php';

if (!defined('ENV')) {
    define('ENV', getenv('APP_ENV') ?: 'local');
}

if (!defined('BASE_URL')) {
    $envBase = getenv('APP_BASE_URL');
    if ($envBase !== false && $envBase !== '') {
        define('BASE_URL', rtrim($envBase, '/') . '/');
    } elseif (ENV === 'local') {
        define('BASE_URL', '/e-bal/public/');
    } else {
        requireProductionValue('APP_BASE_URL', false, 'Application configuration missing.');
        define('BASE_URL', '/');
    }
}

if (!defined('TALLY_BRIDGE_URL')) {
    define('TALLY_BRIDGE_URL', getenv('TALLY_BRIDGE_URL') ?: '');
}

if (!defined('TALLY_BRIDGE_TOKEN')) {
    $bridgeToken = getenv('TALLY_BRIDGE_TOKEN');
    $defaultBridgeToken = ENV === 'local' ? 'ebal_22091978' : '';
    define('TALLY_BRIDGE_TOKEN', $bridgeToken !== false && $bridgeToken !== '' ? $bridgeToken : $defaultBridgeToken);
}
if (!defined('TALLY_BRIDGE_WEBHOOK_TOKEN')) {
    $webhookToken = getenv('TALLY_BRIDGE_WEBHOOK_TOKEN');
    define('TALLY_BRIDGE_WEBHOOK_TOKEN', $webhookToken !== false && $webhookToken !== '' ? $webhookToken : TALLY_BRIDGE_TOKEN);
}
if (!defined('TALLY_ODBC_DRIVER')) {
    define('TALLY_ODBC_DRIVER', getenv('TALLY_ODBC_DRIVER') ?: 'Tally ODBC Driver');
}
if (!defined('TALLY_ODBC_SERVER')) {
    define('TALLY_ODBC_SERVER', getenv('TALLY_ODBC_SERVER') ?: 'localhost');
}
if (!defined('TALLY_ODBC_PORT')) {
    define('TALLY_ODBC_PORT', getenv('TALLY_ODBC_PORT') ?: '9000');
}

define('MCA_LOOKUP_URL', getenv('MCA_LOOKUP_URL') ?: '');
define('MCA_LOOKUP_TOKEN', getenv('MCA_LOOKUP_TOKEN') ?: '');
define('DIRECTORS_REPORT_AI_URL', getenv('DIRECTORS_REPORT_AI_URL') ?: '');
define('DIRECTORS_REPORT_AI_TOKEN', getenv('DIRECTORS_REPORT_AI_TOKEN') ?: '');
$bridgeSettings = __DIR__ . '/bridge_settings.php';
if (file_exists($bridgeSettings) && isLocalEnv()) {
    require_once $bridgeSettings;
}

function url($path) {
    return BASE_URL . $path;
}
