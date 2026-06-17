<?php

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table;

function exportFinancialStatementsToDocx(array $fs, string $companyName, string $fyName): string
{
    $phpWord = new PhpWord();
    $phpWord->getDocumentProperties()->setTitle($companyName . ' - Financial Statements - ' . $fyName);
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(10);

    buildBsSection($phpWord, $fs, $companyName, $fyName);
    buildPlSection($phpWord, $fs, $companyName, $fyName);
    buildNotesSections($phpWord, $fs);

    $filename = sys_get_temp_dir() . '/ebal_export_' . uniqid() . '.docx';
    $writer = new Word2007($phpWord);
    $writer->save($filename);
    return $filename;
}

function addDocxCompanyHeader($section, string $companyName, string $titleText, string $subtitleText): void
{
    $section->addTitle($companyName, 1);
    $section->addTitle($titleText, 2);
    $section->addText($subtitleText, ['italic' => true, 'size' => 10]);
    $section->addTextBreak(0.5);
}

function addDocxTableRow($table, array $values, bool $isBold = false, bool $isSection = false, array $figureCols = []): void
{
    $row = $table->addRow();
    foreach ($values as $idx => $value) {
        $text = (string) $value;
        if (in_array($idx, $figureCols, true) && is_numeric($value)) {
            $text = number_format((float) $value, 2);
        }
        $cell = $row->addCell(
            $idx === 0 ? 6000 : 2000,
            ['alignment' => in_array($idx, $figureCols, true) ? 'right' : 'left']
        );
        $cell->addText($text, ['bold' => $isBold || $isSection]);
    }
}

function addDocxSummaryRows($table, array $items, array $data, array $prevData, array $figureCols = [2]): void
{
    $hasPrev = !empty($prevData);
    foreach ($items as $key => $label) {
        $currVal = (float) ($data[$key] ?? 0);
        $prevVal = (float) ($prevData['prev_' . $key] ?? 0);
        if ($hasPrev) {
            addDocxTableRow($table, [$label, '', $currVal, $prevVal], false, false, $figureCols);
        } else {
            addDocxTableRow($table, [$label, '', $currVal], false, false, $figureCols);
        }
    }
}

function buildBsSection(PhpWord $phpWord, array $fs, string $companyName, string $fyName): void
{
    $section = $phpWord->addSection(['margin' => [1440, 1440, 1440, 1440]]);
    $data = $fs['data'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);

    addDocxCompanyHeader($section, $companyName, 'Balance Sheet', 'As at ' . ($data['date'] ?? $fyName));

    $tableStyle = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
    $firstRowStyle = ['bgColor' => 'F0F4F8'];
    $phpWord->addTableStyle('BsTable', $tableStyle, $firstRowStyle);
    $table = $section->addTable('BsTable');

    if ($hasPrev) {
        addDocxTableRow($table, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)'], true, false, [2, 3]);
    } else {
        addDocxTableRow($table, ['Particulars', 'Note', 'Current (₹)'], true, false, [2]);
    }

    $bsSections = [
        'EQUITY AND LIABILITIES' => [
            'items' => ['capital' => 'Capital / Equity', 'reserves' => 'Reserves & Surplus',
                'borrowings' => 'Borrowings', 'payables' => 'Trade Payables',
                'current_liabilities' => 'Other Current Liabilities'],
            'total' => 'total_liabilities',
        ],
        'ASSETS' => [
            'items' => ['fixed_assets' => 'Fixed Assets', 'investments' => 'Investments',
                'loans' => 'Loans & Advances', 'inventory' => 'Inventory',
                'receivables' => 'Trade Receivables', 'cash' => 'Cash & Bank',
                'other_current_assets' => 'Other Current Assets'],
            'total' => 'total_assets',
        ],
    ];

    foreach ($bsSections as $secName => $sec) {
        addDocxTableRow($table, [$secName, '', '', ''], true, true, []);
        addDocxSummaryRows($table, $sec['items'], $data, $hasPrev ? $data : [], $hasPrev ? [2, 3] : [2]);

        $totalVal = (float) ($data[$sec['total']] ?? 0);
        if ($hasPrev) {
            $prevTotalVal = (float) ($data['prev_' . $sec['total']] ?? 0);
            addDocxTableRow($table, ['TOTAL', '', $totalVal, $prevTotalVal], true, false, [2, 3]);
        } else {
            addDocxTableRow($table, ['TOTAL', '', $totalVal], true, false, [2]);
        }
    }
}

function buildPlSection(PhpWord $phpWord, array $fs, string $companyName, string $fyName): void
{
    $section = $phpWord->addSection(['margin' => [1440, 1440, 1440, 1440]]);
    $data = $fs['data'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);

    $plLabel = ($fs['entity_subcategory'] ?? '') === 'trust' ? 'Income & Expenditure' : 'Profit & Loss';

    addDocxCompanyHeader($section, $companyName, 'Statement of ' . $plLabel, 'For the year ended ' . ($data['date'] ?? $fyName));

    $tableStyle = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
    $firstRowStyle = ['bgColor' => 'F0F4F8'];
    $phpWord->addTableStyle('PlTable', $tableStyle, $firstRowStyle);
    $table = $section->addTable('PlTable');

    if ($hasPrev) {
        addDocxTableRow($table, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)'], true, false, [2, 3]);
    } else {
        addDocxTableRow($table, ['Particulars', 'Note', 'Current (₹)'], true, false, [2]);
    }

    addDocxSummaryRows($table, ['revenue' => 'Revenue / Income', 'other_income' => 'Other Income'], $data, $hasPrev ? $data : [], $hasPrev ? [2, 3] : [2]);

    $totalIncome = (float) ($data['revenue'] ?? 0) + (float) ($data['other_income'] ?? 0);
    $prevTotalIncome = (float) ($data['prev_revenue'] ?? 0) + (float) ($data['prev_other_income'] ?? 0);
    if ($hasPrev) {
        addDocxTableRow($table, ['Total Income', '', $totalIncome, $prevTotalIncome], true, false, [2, 3]);
    } else {
        addDocxTableRow($table, ['Total Income', '', $totalIncome], true, false, [2]);
    }

    addDocxTableRow($table, ['Expenses', '', '', ''], true, true, []);
    addDocxSummaryRows($table, ['employee_cost' => 'Employee Cost', 'finance_cost' => 'Finance Cost',
        'depreciation' => 'Depreciation', 'other_expenses' => 'Other Expenses'], $data, $hasPrev ? $data : [], $hasPrev ? [2, 3] : [2]);

    $totalExpenses = (float) ($data['expenses'] ?? 0);
    $prevTotalExpenses = (float) ($data['prev_expenses'] ?? 0);
    if ($hasPrev) {
        addDocxTableRow($table, ['Total Expenses', '', $totalExpenses, $prevTotalExpenses], true, false, [2, 3]);
    } else {
        addDocxTableRow($table, ['Total Expenses', '', $totalExpenses], true, false, [2]);
    }

    $netProfit = $totalIncome - $totalExpenses;
    $prevNetProfit = $prevTotalIncome - $prevTotalExpenses;
    if ($hasPrev) {
        addDocxTableRow($table, ['Net ' . $plLabel, '', $netProfit, $prevNetProfit], true, false, [2, 3]);
    } else {
        addDocxTableRow($table, ['Net ' . $plLabel, '', $netProfit], true, false, [2]);
    }
}

function buildNotesSections(PhpWord $phpWord, array $fs): void
{
    $notes = $fs['notes'] ?? [];
    $sections = $notes['sections'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);

    if (empty($sections)) {
        return;
    }

    $noteSection = $phpWord->addSection(['margin' => [1440, 1440, 1440, 1440]]);
    $noteSection->addTitle('Notes to Accounts', 2);

    foreach ($sections as $section) {
        $title = $section['title'] ?? 'Note';
        $noteSection->addTitle($title, 3);
        $noteSection->addTextBreak(0.3);

        $lines = $section['lines'] ?? [];
        $currentTotal = (float) ($section['current_total'] ?? 0);
        $previousTotal = (float) ($section['previous_total'] ?? 0);

        $tableStyle = ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 60];
        $firstRowStyle = ['bgColor' => 'F5F5F5'];
        $phpWord->addTableStyle('NoteTable_' . uniqid(), $tableStyle, $firstRowStyle);
        $table = $noteSection->addTable('NoteTable_' . uniqid());

        if ($hasPrev) {
            addDocxTableRow($table, ['Particulars', 'Current (₹)', 'Previous (₹)'], true, false, [1, 2]);
        } else {
            addDocxTableRow($table, ['Particulars', 'Current (₹)'], true, false, [1]);
        }

        foreach ($lines as $line) {
            $label = $line['label'] ?? '';
            $current = (float) ($line['current'] ?? 0);
            $previous = (float) ($line['previous'] ?? 0);
            if ($hasPrev) {
                addDocxTableRow($table, [$label, $current, $previous], false, false, [1, 2]);
            } else {
                addDocxTableRow($table, [$label, $current], false, false, [1]);
            }
        }

        if ($hasPrev) {
            addDocxTableRow($table, ['Total', $currentTotal, $previousTotal], true, false, [1, 2]);
        } else {
            addDocxTableRow($table, ['Total', $currentTotal], true, false, [1]);
        }

        $noteSection->addTextBreak(1);
    }
}
