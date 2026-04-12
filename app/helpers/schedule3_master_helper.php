<?php

function getSchedule3NotesMaster(): array
{
    return require __DIR__ . '/../../public/data_console/schedule3_notes_master.php';
}

function getSchedule3MappingSupport(): array
{
    return require __DIR__ . '/../../public/data_console/schedule3_mapping_list.php';
}

function schedule3MasterCodeToScheduleCodes(): array
{
    return [
        'SC' => ['share_capital'],
        'OE' => ['reserves'],
        'BOR' => ['lt_borrowings'],
        'DT' => ['deferred_tax_liability', 'deferred_tax_asset'],
        'ONCL' => ['other_non_current_liabilities'],
        'LTP' => ['long_term_provisions'],
        'STB' => ['st_borrowings'],
        'TP' => ['trade_payables', 'trade_payables_msme'],
        'OCL' => ['other_financial_liabilities', 'other_current_liabilities'],
        'STP' => ['short_term_provisions'],
        'PPE' => ['ppe', 'cwip'],
        'INT' => ['intangible_assets'],
        'INV' => ['investments_non_current', 'investments_current'],
        'LOAN' => ['loans_non_current', 'loans_current'],
        'OFA' => ['other_non_current_assets', 'bank_balances_other'],
        'INVTRY' => ['inventory'],
        'TR' => ['receivables'],
        'CASH' => ['cash'],
        'OCA' => ['other_current_assets'],
        'REV' => ['revenue'],
        'OIN' => ['other_income'],
        'MAT' => ['materials'],
        'PUR' => ['purchase_stock'],
        'INVCHG' => ['inventory_change'],
        'EMP' => ['employee_cost'],
        'FIN' => ['finance_cost'],
        'DEP' => ['depreciation'],
        'EXP' => ['other_expenses'],
    ];
}
