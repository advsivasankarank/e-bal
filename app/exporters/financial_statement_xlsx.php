<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function exportFinancialStatementsToXlsx(array $fs, string $companyName, string $fyName): string
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('e-BAL')
        ->setLastModifiedBy('e-BAL')
        ->setTitle("{$companyName} - Financial Statements - {$fyName}");

    buildBsSheet($spreadsheet, $fs, $companyName, $fyName);
    buildPlSheet($spreadsheet, $fs, $companyName, $fyName);
    buildNotesSheets($spreadsheet, $fs);

    $filename = sys_get_temp_dir() . '/ebal_export_' . uniqid() . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($filename);
    $spreadsheet->disconnectWorksheets();
    return $filename;
}

function colLetter(int $index): string
{
    return chr(64 + $index);
}

function writeXlsxHeader($sheet, int $row, array $headers): string
{
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getStyle($col . $row)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM);
        $col++;
    }
    return --$col;
}

function writeXlsxRow($sheet, int $row, array $values, array $figureCols = []): void
{
    $col = 'A';
    foreach ($values as $idx => $value) {
        if (in_array($idx, $figureCols, true) && is_numeric($value)) {
            $sheet->setCellValue($col . $row, (float) $value);
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        } else {
            $sheet->setCellValue($col . $row, (string) $value);
        }
        $col++;
    }
}

function writeXlsxSummaryRows($sheet, int &$row, array $items, array $data, bool $hasPrev, string $prevLetter): void
{
    foreach ($items as $key => $label) {
        $currentVal = (float) ($data[$key] ?? 0);
        writeXlsxRow($sheet, $row, [$label, '', $currentVal], [2]);
        if ($hasPrev) {
            $prevVal = (float) ($data['prev_' . $key] ?? 0);
            $sheet->setCellValue($prevLetter . $row, $prevVal);
            $sheet->getStyle($prevLetter . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($prevLetter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $row++;
    }
}

function writeXlsxSection($sheet, int &$row, string $sectionName, array $section, array $data, bool $hasPrev, string $lastCol): void
{
    $prevCol = $hasPrev ? $lastCol : '';

    $sheet->setCellValue('A' . $row, $sectionName);
    $range = 'A' . $row . ':' . $lastCol . $row;
    $sheet->getStyle($range)->getFont()->setBold(true);
    $sheet->getStyle($range)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF0F4F8');
    $row++;

    writeXlsxSummaryRows($sheet, $row, $section['items'], $data, $hasPrev, $lastCol);

    $totalVal = (float) ($data[$section['total']] ?? 0);
    writeXlsxRow($sheet, $row, ['TOTAL', '', $totalVal], [2]);
    if ($hasPrev) {
        $prevTotalVal = (float) ($data['prev_' . $section['total']] ?? 0);
        $sheet->setCellValue($lastCol . $row, $prevTotalVal);
        $sheet->getStyle($lastCol . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($lastCol . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    $boldRange = 'A' . $row . ':' . $lastCol . $row;
    $sheet->getStyle($boldRange)->getFont()->setBold(true);
    $row++;
    $row++;
}

function buildBsSheet(Spreadsheet $spreadsheet, array $fs, string $companyName, string $fyName): void
{
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Balance Sheet');

    $data = $fs['data'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);
    $lastCol = $hasPrev ? 'D' : 'C';

    $r = 1;
    $sheet->setCellValue('A1', $companyName);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $r = 2;
    $sheet->setCellValue('A2', 'Balance Sheet as at ' . ($data['date'] ?? $fyName));
    $sheet->getStyle('A2')->getFont()->setSize(11);
    $r = 4;

    if ($hasPrev) {
        writeXlsxHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)']);
    } else {
        writeXlsxHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)']);
    }
    $r++;

    writeXlsxSection($sheet, $r, 'EQUITY AND LIABILITIES', [
        'items' => [
            'capital' => 'Capital / Equity',
            'reserves' => 'Reserves & Surplus',
            'borrowings' => 'Borrowings',
            'payables' => 'Trade Payables',
            'current_liabilities' => 'Other Current Liabilities',
        ],
        'total' => 'total_liabilities',
    ], $data, $hasPrev, $lastCol);

    writeXlsxSection($sheet, $r, 'ASSETS', [
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
    ], $data, $hasPrev, $lastCol);

    $colCount = $hasPrev ? 4 : 3;
    $lastColL = colLetter($colCount);
    $sheet->getStyle("A4:{$lastColL}" . ($r - 2))->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', $lastColL) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function buildPlSheet(Spreadsheet $spreadsheet, array $fs, string $companyName, string $fyName): void
{
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Profit & Loss');

    $data = $fs['data'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);
    $lastCol = $hasPrev ? 'D' : 'C';

    $plLabel = in_array($fs['entity_subcategory'] ?? '', ['trust', 'society'], true) ? 'Income & Expenditure' : 'Profit & Loss';

    $r = 1;
    $sheet->setCellValue('A1', $companyName);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $r = 2;
    $sheet->setCellValue('A2', 'Statement of Profit & Loss for the year ended ' . ($data['date'] ?? $fyName));
    $sheet->getStyle('A2')->getFont()->setSize(11);
    $r = 4;

    if ($hasPrev) {
        writeXlsxHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)', 'Previous (₹)']);
    } else {
        writeXlsxHeader($sheet, $r, ['Particulars', 'Note', 'Current (₹)']);
    }
    $r++;

    writeXlsxSummaryRows($sheet, $r, [
        'revenue' => 'Revenue / Income',
        'other_income' => 'Other Income',
    ], $data, $hasPrev, $lastCol);

    $totalIncome = (float) ($data['revenue'] ?? 0) + (float) ($data['other_income'] ?? 0);
    writeXlsxRow($sheet, $r, ['Total Income', '', $totalIncome], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $prevTotalIncome = (float) ($data['prev_revenue'] ?? 0) + (float) ($data['prev_other_income'] ?? 0);
        $sheet->setCellValue($lastCol . $r, $prevTotalIncome);
        $sheet->getStyle($lastCol . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($lastCol . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle($lastCol . $r)->getFont()->setBold(true);
    }
    $r++;

    $sheet->setCellValue('A' . $r, 'Expenses');
    $range = 'A' . $r . ':' . $lastCol . $r;
    $sheet->getStyle($range)->getFont()->setBold(true);
    $sheet->getStyle($range)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF0F4F8');
    $r++;

    writeXlsxSummaryRows($sheet, $r, [
        'employee_cost' => 'Employee Cost',
        'finance_cost' => 'Finance Cost',
        'depreciation' => 'Depreciation',
        'other_expenses' => 'Other Expenses',
    ], $data, $hasPrev, $lastCol);

    $totalExpenses = (float) ($data['expenses'] ?? 0);
    writeXlsxRow($sheet, $r, ['Total Expenses', '', $totalExpenses], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $prevTotalExpenses = (float) ($data['prev_expenses'] ?? 0);
        $sheet->setCellValue($lastCol . $r, $prevTotalExpenses);
        $sheet->getStyle($lastCol . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($lastCol . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle($lastCol . $r)->getFont()->setBold(true);
    }
    $r++;

    $netProfit = $totalIncome - $totalExpenses;
    writeXlsxRow($sheet, $r, ['Net ' . $plLabel, '', $netProfit], [2]);
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);
    if ($hasPrev) {
        $prevNetProfit = (float) ($data['prev_revenue'] ?? 0) + (float) ($data['prev_other_income'] ?? 0)
            - (float) ($data['prev_expenses'] ?? 0);
        $sheet->setCellValue($lastCol . $r, $prevNetProfit);
        $sheet->getStyle($lastCol . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($lastCol . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle($lastCol . $r)->getFont()->setBold(true);
    }
    $r++;

    $colCount = $hasPrev ? 4 : 3;
    $lastColL = colLetter($colCount);
    $sheet->getStyle("A4:{$lastColL}" . ($r - 1))->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', $lastColL) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

function buildNotesSheets(Spreadsheet $spreadsheet, array $fs): void
{
    $notes = $fs['notes'] ?? [];
    $sections = $notes['sections'] ?? [];
    $hasPrev = !($fs['is_first_year'] ?? false);
    $lastCol = $hasPrev ? 'C' : 'B';

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
            writeXlsxHeader($sheet, $r, ['Particulars', 'Current (₹)', 'Previous (₹)']);
        } else {
            writeXlsxHeader($sheet, $r, ['Particulars', 'Current (₹)']);
        }
        $r++;

        foreach ($lines as $line) {
            $label = $line['label'] ?? '';
            $current = (float) ($line['current'] ?? 0);
            $previous = (float) ($line['previous'] ?? 0);
            writeXlsxRow($sheet, $r, [$label, $current], [1]);
            if ($hasPrev) {
                $sheet->setCellValue($lastCol . $r, $previous);
                $sheet->getStyle($lastCol . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle($lastCol . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $r++;
        }

        writeXlsxRow($sheet, $r, ['Total', $currentTotal], [1]);
        $sheet->getStyle('A' . $r)->getFont()->setBold(true);
        $sheet->getStyle('B' . $r)->getFont()->setBold(true);
        if ($hasPrev) {
            $sheet->setCellValue($lastCol . $r, $previousTotal);
            $sheet->getStyle($lastCol . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle($lastCol . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($lastCol . $r)->getFont()->setBold(true);
        }
        $r++;

        $colCount = $hasPrev ? 3 : 2;
        $lastColL = colLetter($colCount);
        $sheet->getStyle("A3:{$lastColL}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        foreach (range('A', $lastColL) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
