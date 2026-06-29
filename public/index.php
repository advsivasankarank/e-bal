<?php
/**
 * e-BAL V2 — Entry Point
 *
 * Authenticated users go to Entity Dashboard.
 * Unauthenticated users see the landing page.
 */
require_once __DIR__ . '/../app/session_bootstrap.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    require __DIR__ . '/landing.php';
    exit;
}

/* Authenticated users enter the Entity Dashboard */
header('Location: ' . BASE_URL . 'dashboard_company.php');
exit;
