<?php
require_once __DIR__ . '/../config/app.php';

header('Location: ' . BASE_URL . 'data_console/xml_import.php', true, 302);
exit;
