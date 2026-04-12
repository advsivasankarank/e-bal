<?php
require_once __DIR__ . '/session_bootstrap.php';

function requireCompany() {
    if (empty($_SESSION['company_id'])) {
        header("Location: /e-bal/public/company_dashboard/company_list.php?error=select_company");
        exit;
    }
}

function requireFY() {
    if (empty($_SESSION['fy_id'])) {
        header("Location: /e-bal/public/company_dashboard/financial_year.php?error=select_fy");
        exit;
    }
}

function requireFullContext() {
    requireCompany();
    requireFY();
}
