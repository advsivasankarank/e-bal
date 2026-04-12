<?php

function getMapping($pdo, $company_id, $ledger_name)
{
    $stmt = $pdo->prepare("
        SELECT schedule_code 
        FROM ledger_mapping 
        WHERE company_id = ? AND ledger_name = ?
        LIMIT 1
    ");
    $stmt->execute([$company_id, $ledger_name]);
    return $stmt->fetchColumn();
}

function saveMapping($pdo, $company_id, $ledger_name, $schedule_code)
{
    $stmt = $pdo->prepare("
        INSERT INTO ledger_mapping (company_id, ledger_name, schedule_code)
        VALUES (?, ?, ?)
    ");
    return $stmt->execute([$company_id, $ledger_name, $schedule_code]);
}

function suggestMapping($ledger)
{
    $ledger = strtolower($ledger);

    if (strpos($ledger,'debtor') !== false) return 'TR';
    if (strpos($ledger,'creditor') !== false) return 'TP';
    if (strpos($ledger,'cash') !== false) return 'CASH';
    if (strpos($ledger,'bank') !== false) return 'CASH';
    if (strpos($ledger,'loan') !== false) return 'LT_BORR';
    if (strpos($ledger,'capital') !== false) return 'SC';
    if (strpos($ledger,'reserve') !== false) return 'RS';
    if (strpos($ledger,'sales') !== false) return 'REV';
    if (strpos($ledger,'purchase') !== false) return 'PUR';
    if (strpos($ledger,'salary') !== false) return 'EMP';
    if (strpos($ledger,'interest') !== false) return 'FIN';

    return '';
}