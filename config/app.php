<?php
define('BASE_URL', '/e-bal/public/');
define('MCA_LOOKUP_URL', getenv('MCA_LOOKUP_URL') ?: '');
define('MCA_LOOKUP_TOKEN', getenv('MCA_LOOKUP_TOKEN') ?: '');
define('DIRECTORS_REPORT_AI_URL', getenv('DIRECTORS_REPORT_AI_URL') ?: '');
define('DIRECTORS_REPORT_AI_TOKEN', getenv('DIRECTORS_REPORT_AI_TOKEN') ?: '');

function url($path) {
    return BASE_URL . $path;
}
