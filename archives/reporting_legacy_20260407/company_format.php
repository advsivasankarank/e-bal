<?php

function formatCompanyFS($data)
{
    return [
        "balance_sheet" => [
            "Equity & Liabilities" => [
                "Equity" => $data['equity'] ?? 0,
                "Borrowings" => $data['borrowings'] ?? 0,
                "Current Liabilities" => $data['current_liabilities'] ?? 0,
                "Total Equity & Liabilities" => $data['liabilities_total'] ?? 0,
            ],
            "Assets" => [
                "Non-Current Assets" => $data['non_current_assets'] ?? 0,
                "Current Assets" => $data['current_assets'] ?? 0,
                "Total Assets" => $data['assets_total'] ?? 0,
            ]
        ],

        "pnl" => [
            "Revenue from Operations" => $data['revenue'] ?? 0,
            "Expenses" => $data['expenses'] ?? 0,
            "Profit Before Tax" => $data['profit'] ?? 0
        ],
        "title" => "Schedule III Financial Statements"
    ];
}
