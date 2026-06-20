<?php
/**
 * e-BAL V2 — Main Entry Point
 * Authenticated users are redirected to My Assignments (V2 Workspace).
 * Unauthenticated users see the landing page.
 */
require_once __DIR__ . '/../app/session_bootstrap.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    require __DIR__ . '/landing.php';
    exit;
}

/* Authenticated users enter the V2 Workspace */
header('Location: ' . BASE_URL . 'my_assignments.php');
exit;
