<?php

function formatNonCorpFS($data)
{
    return [
        "balance_sheet" => [
            "Owners’ Funds & Liabilities" => [
                "Capital / Owners' Funds" => $data['equity'] ?? 0,
                "Borrowings" => $data['borrowings'] ?? 0,
                "Current Liabilities" => $data['current_liabilities'] ?? 0,
                "Total Funds & Liabilities" => $data['liabilities_total'] ?? 0,
            ],
            "Assets" => [
                "Non-Current Assets" => $data['non_current_assets'] ?? 0,
                "Current Assets" => $data['current_assets'] ?? 0,
                "Total Assets" => $data['assets_total'] ?? 0,
            ]
        ],

        "pnl" => [
            "Income" => $data['revenue'] ?? 0,
            "Expenses" => $data['expenses'] ?? 0,
            "Net Profit" => $data['profit'] ?? 0
        ],
        "title" => "Non-Corporate Financial Statements"
    ];
}
