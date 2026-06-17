<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

function exportFinancialStatementsToXlsx(array $fs, string $companyName, string $fyName): string
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('e-BAL')
        ->setLastModifiedBy('e-BAL')
        ->setTitle($companyName . ' - Financial Statements - ' . $fyName);

    buildBsSheet($spreadsheet, $fs, $companyName, $fyName);
    buildPlSheet($spreadsheet, $fs, $companyName, $fyName);
    buildNotesSheets($spreadsheet, $fs);

    $filename = sys_get_temp_dir() . '/ebal_export_' . uniqid() . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($filename);
    $spreadsheet->disconnectWorksheets();
    return $filename;
}

function applyHeaderStyle($sheet, int $row): void
{
    $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true)->setSize(12);
}

function applySectionStyle($sheet, int $row, int $colCount = 4): void
{
    $sheet->getStyle("A{$row}:{$colCount}{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:{$colCount}{$row}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF0F4F8');
}

function writeTableHeader($sheet, int $row, array $headers): void
{
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getStyle($col . $row)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM);
        $col++;
    }
}

function writeDataRow($sheet, int $row, array $values, array $figureCols = []): void
{
    $col = 'A';
    foreach ($values as $idx => $value) {
        $cell = $col . $row;
        if (in_array($idx, $figureCols, true) && is_numeric($value)) {
            $sheet->setCellValue($cell, (float) $value);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        } else {
            $sheet->setCellValue($cell, (string) $value);
        }
        $col++;
    }
}

function applyTableBorders($sheet, int $startRow, int $endRow, int $colCount = 4): void
{
    $lastCol = chr(64 + $colCount);
    $sheet->getStyle("A{$startRow}:{$lastCol}{$endRow}")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);
}

function writeSummaryRows($sheet, &$row, array $items, array $data, array $prevData, int $noteCol = 1): void
{
    $hasPrev = !empty($prevData);
    foreach ($items as $key => $label) {
        $currentVal = (float) ($data[$key] ?? 0);
        $prevVal = (float) ($prevData[$key] ?? 0);
        writeDataRow($sheet, $row, [$label, '', $currentVal], [2]);
        if ($hasPrev) {
            $sheet->setCellValue('D' . $row, $prevVal);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $row++;
    }
}

function buildBsSheet(Spreadsheet $spreadsheet, array $fs, string $companyName, string $fyName): void
{
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Balance Sheet');

    $data = $fs['data'] ?? [];
    $prevData = [];
    if (!($fs['is_first_year'] ?? false)) {
        $prevData = array_filter($data, function ($k) { return str_starts_with((string) $k, 'prev_'); }, ARRAY_FILTER_USE_KEY);
    }
    $hasPrev = !empty($prevData);

    $r = 1;
    $sheet->setCellValue('A1', $companyName);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $r = 2;
    $sheet->setCellValue('A2', 'Balance Sheet as at ' . ($data['date'] ?? $fyName));
    $sheet->getStyle('A2')->getFont()->setSize(11);
    $r = 4;

    if ($hasPrev) {
        writeTableHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)']);
    } else {
        writeTableHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)']);
    }
    $r++;

    $bsSections = [
        'EQUITY AND LIABILITIES' => [
            'items' => [
                'capital' => 'Capital / Equity',
                'reserves' => 'Reserves & Surplus',
                'borrowings' => 'Borrowings',
                'payables' => 'Trade Payables',
                'current_liabilities' => 'Other Current Liabilities',
            ],
            'total' => 'total_liabilities',
        ],
        'ASSETS' => [
            'items' => [
                'fixed_assets' => 'Fixed Assets',
                'investments' => 'Investments',
                'loans' => 'Loans & Advances',
                'inventory' => 'Inventory',
                'receivables' => 'Trade Receivables',
                'cash' => 'Cash & Bank',
                'other_current_assets' => 'Other Current Assets',
            ],
            'total' => 'total_assets',
        ],
    ];

    foreach ($bsSections as $sectionName => $section) {
        $sheet->setCellValue('A' . $r, $sectionName);
        applySectionStyle($sheet, $r);
        $r++;

        writeSummaryRows($sheet, $r, $section['items'], $data, $prevData);

        $totalVal = (float) ($data[$section['total']] ?? 0);
        $prevTotalVal = (float) ($prevData['prev_' . $section['total']] ?? 0);
        writeDataRow($sheet, $r, ['TOTAL', '', $totalVal], [2]);
        if ($hasPrev) {
            $sheet->setCellValue('D' . $r, $prevTotalVal);
            $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle('A' . $r . ':' . ($hasPrev ? 'D' : 'C') . $r)->getFont()->setBold(true);
        $r++;
        $r++;
    }

    $colCount = $hasPrev ? 4 : 3;
    applyTableBorders($sheet, 4, $r - 2, $colCount);

    foreach (range('A', chr(64 + $colCount)) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function buildPlSheet(Spreadsheet $spreadsheet, array $fs, string $companyName, string $fyName): void
{
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Profit & Loss');

    $data = $fs['data'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);

    $r = 1;
    $sheet->setCellValue('A1', $companyName);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $r = 2;
    $sheet->setCellValue('A2', 'Statement of Profit & Loss for the year ended ' . ($data['date'] ?? $fyName));
    $sheet->getStyle('A2')->getFont()->setSize(11);
    $r = 4;

    $plLabel = ($fs['entity_subcategory'] ?? '') === 'trust' ? 'Income & Expenditure' : 'Profit & Loss';

    if ($hasPrev) {
        writeTableHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)']);
    } else {
        writeTableHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)']);
    }
    $r++;

    $plItems = [
        'revenue' => 'Revenue / Income',
        'other_income' => 'Other Income',
    ];
    $expenseItems = [
        'employee_cost' => 'Employee Cost',
        'finance_cost' => 'Finance Cost',
        'depreciation' => 'Depreciation',
        'other_expenses' => 'Other Expenses',
    ];

    writeSummaryRows($sheet, $r, $plItems, $data, $prevData ?? [], 1);

    $totalIncome = (float) ($data['revenue'] ?? 0) + (float) ($data['other_income'] ?? 0);
    $prevTotalIncome = (float) ($prevData['prev_revenue'] ?? 0) + (float) ($prevData['prev_other_income'] ?? 0);
    writeDataRow($sheet, $r, ['Total Income', '', $totalIncome], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $sheet->setCellValue('D' . $r, $prevTotalIncome);
        $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('D' . $r)->getFont()->setBold(true);
    }
    $r++;

    $sheet->setCellValue('A' . $r, 'Expenses');
    applySectionStyle($sheet, $r);
    $r++;

    writeSummaryRows($sheet, $r, $expenseItems, $data, $prevData ?? [], 1);

    $totalExpenses = (float) ($data['expenses'] ?? 0);
    $prevTotalExpenses = (float) ($prevData['prev_expenses'] ?? 0);
    writeDataRow($sheet, $r, ['Total Expenses', '', $totalExpenses], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $sheet->setCellValue('D' . $r, $prevTotalExpenses);
        $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('D' . $r)->getFont()->setBold(true);
    }
    $r++;

    $netProfit = $totalIncome - $totalExpenses;
    $prevNetProfit = $prevTotalIncome - $prevTotalExpenses;
    writeDataRow($sheet, $r, ['Net ' . $plLabel, '', $netProfit], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $sheet->setCellValue('D' . $r, $prevNetProfit);
        $sheet->getStyle('D' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('D' . $r)->getFont()->setBold(true);
    }
    $r++;

    $colCount = $hasPrev ? 4 : 3;
    applyTableBorders($sheet, 4, $r - 1, $colCount);

    foreach (range('A', chr(64 + $colCount)) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function buildNotesSheets(Spreadsheet $spreadsheet, array $fs): void
{
    $notes = $fs['notes'] ?? [];
    $sections = $notes['sections'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);

    foreach ($sections as $section) {
        $title = $section['title'] ?? 'Note';
        $sheetName = mb_substr(preg_replace('/[^\w\s]/', '', $title), 0, 31);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($sheetName ?: 'Note');

        $lines = $section['lines'] ?? [];
        $currentTotal = (float) ($section['current_total'] ?? 0);
        $previousTotal = (float) ($section['previous_total'] ?? 0);

        $r = 1;
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $r = 3;

        if ($hasPrev) {
            writeTableHeader($sheet, $r, ['Particulars', 'Current (₹)', 'Previous (₹)']);
        } else {
            writeTableHeader($sheet, $r, ['Particulars', 'Current (₹)']);
        }
        $r++;

        foreach ($lines as $line) {
            $label = $line['label'] ?? '';
            $current = (float) ($line['current'] ?? 0);
            $previous = (float) ($line['previous'] ?? 0);
            writeDataRow($sheet, $r, [$label, $current], [1]);
            if ($hasPrev) {
                $sheet->setCellValue('C' . $r, $previous);
                $sheet->getStyle('C' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $r++;
        }

        writeDataRow($sheet, $r, ['Total', $currentTotal], [1]);
        $sheet->getStyle('A' . $r)->getFont()->setBold(true);
        $sheet->getStyle('B' . $r)->getFont()->setBold(true);
        if ($hasPrev) {
            $sheet->setCellValue('C' . $r, $previousTotal);
            $sheet->getStyle('C' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('C' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('C' . $r)->getFont()->setBold(true);
        }
        $r++;

        $colCount = $hasPrev ? 3 : 2;
        applyTableBorders($sheet, 3, $r, $colCount);

        foreach (range('A', chr(64 + $colCount)) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
