<?php

function buildNotesFromClassification(array $classified, string $entity): array
{
    $scheduleItems = $classified['schedule_items'] ?? [];
    $notes = [];

    $preferredOrder = match ($entity) {
        'corporate' => [
            'share_capital',
            'reserves',
            'lt_borrowings',
            'trade_payables',
            'ppe',
            'inventory',
            'receivables',
            'cash',
            'revenue',
            'other_expenses',
        ],
        'llp' => [
            'share_capital',
            'reserves',
            'lt_borrowings',
            'trade_payables',
            'ppe',
            'receivables',
            'cash',
        ],
        default => [
            'share_capital',
            'lt_borrowings',
            'trade_payables',
            'ppe',
            'inventory',
            'receivables',
            'cash',
        ],
    };

    foreach ($preferredOrder as $code) {
        if (!isset($scheduleItems[$code])) {
            continue;
        }

        $item = $scheduleItems[$code];
        if ((float) ($item['amount'] ?? 0) == 0.0 && empty($item['rows'])) {
            continue;
        }

        $rows = [];
        foreach (($item['rows'] ?? []) as $row) {
            $rows[] = [
                $row['ledger_name'] ?? '',
                $row['parent_group'] ?? '',
                (float) ($row['amount'] ?? 0),
            ];
        }

        $note = [
            'title' => $item['label'] ?? ucwords(str_replace('_', ' ', $code)),
            'headers' => ['Ledger', 'Parent Group', 'Amount'],
            'table' => $rows,
        ];

        if ($rows === []) {
            $note['headers'] = ['Particulars', 'Amount'];
            $note['table'] = [['Closing Balance', (float) ($item['amount'] ?? 0)]];
        }

        if ($code === 'trade_payables' && $entity === 'corporate') {
            $note['text'] = 'Trade payables should be reviewed for MSME and other creditor classification before final issue.';
        } elseif ($code === 'receivables' && $entity === 'corporate') {
            $note['text'] = 'Trade receivables ageing and credit risk disclosures can be expanded from this mapped balance as required.';
        }

        $notes[] = $note;
    }

    return $notes;
}
