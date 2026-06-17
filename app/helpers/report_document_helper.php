<?php

function reportDocumentStyles(): string
{
    return <<<'CSS'
<style>
@page {
    margin: 22mm 16mm 24mm;
    size: A4 portrait;
}
body {
    font-family: 'DejaVu Sans', sans-serif;
    counter-reset: section;
}
.report-shell { background: transparent; border: 0; padding: 0; }
.report-page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto 24px;
    background: #fff;
    border: 1px solid #d8e2ef;
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    padding: 18mm 16mm;
    box-sizing: border-box;
    page-break-after: always;
}
.report-page:last-child { page-break-after: auto; }
.report-page-title {
    margin: 0 0 8px;
    font-size: 24px;
    line-height: 1.25;
    color: #0f172a;
}
.report-page-subtitle {
    margin: 0 0 18px;
    color: #475569;
    font-size: 13px;
}
.report-shell h2 { margin: 0 0 10px; font-size: 22px; }
.report-shell h3 { margin: 18px 0 10px; font-size: 16px; }
.report-shell table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.report-shell th, .report-shell td { border: 1px solid #dbe3ef; padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
.report-shell tr.section td, .report-shell tr.section th { background: #f5f8fc; font-weight: 700; }
.report-shell td.figure, .report-shell th.figure { text-align: right; white-space: nowrap; }
.notes-cover { margin-bottom: 16px; }
.note-block { break-inside: avoid; page-break-inside: avoid; margin-bottom: 22px; }
.notes-shell {
    border-top: 2px solid #d7e3f3;
    padding-top: 6px;
}
.note-heading {
    margin: 0 0 10px;
    padding: 10px 12px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
    border: 1px solid #d9e5f2;
    border-radius: 8px;
    color: #0f172a;
    font-size: 15px;
    line-height: 1.35;
}
.note-table {
    margin-top: 0;
    border: 1px solid #d9e5f2;
}
.note-table thead th {
    background: #eef4f9;
    font-size: 12.5px;
    letter-spacing: 0.02em;
    color: #334155;
}
.note-table tbody tr:nth-child(even) td {
    background: #fbfdff;
}
.note-table tfoot td {
    background: #f3f7fb;
    font-weight: 700;
}
.note-policy-list {
    margin: 12px 0 0 18px;
    padding: 0;
}
.note-policy-list li {
    margin-bottom: 6px;
    line-height: 1.5;
}
.signature-table td { border: 0 !important; }
.signature-block { margin-top: 28px; }
.signature-caption { color: #475569; font-size: 12px; margin-top: 8px; }
.report-export-cover {
    margin: 0 auto 18px;
    width: 210mm;
    color: #0f172a;
    page-break-after: always;
}
.report-export-cover h1 {
    margin: 0 0 6px;
    font-size: 26px;
}
.report-export-cover p {
    margin: 0 0 4px;
    color: #475569;
    font-size: 13px;
}
.toc-page {
    page-break-after: always;
    margin-bottom: 20mm;
}
.toc-page h2 {
    font-size: 20px;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 6px;
    margin-bottom: 14px;
}
.toc-entry {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 13px;
    border-bottom: 1px dotted #ccc;
}
.toc-entry.main {
    font-weight: 700;
    font-size: 14px;
    margin-top: 8px;
}
.toc-entry .page-num {
    font-weight: 700;
}
.page-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 9px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding: 4mm 16mm;
    background: #fff;
    width: 100%;
}
.page-footer .page-counter:after {
    content: counter(page, decimal);
}
.page-footer .total-pages:before {
    content: counter(total-pages, decimal);
}
</style>
CSS;
}

function renderFinancialReportDocument(array $fs, string $companyName, string $fyName): string
{
    $data = $fs['data'];
    $notes = $fs['notes'];
    $company_meta = $fs['company_meta'] ?? [];
    $formatTemplate = $fs['format_template'];
    $notesTemplate = $fs['notes_template'];
    $isFirstYear = (bool) ($fs['is_first_year'] ?? false);

    $plLabel = ($fs['entity_subcategory'] ?? '') === 'trust' ? 'Income & Expenditure Account' : 'Profit & Loss Account';
    $noteSections = $notes['sections'] ?? [];

    ob_start();
    ?>
    <div class="report-export-cover">
        <h1><?= htmlspecialchars($companyName) ?></h1>
        <p><?= htmlspecialchars($fs['title'] ?? 'Financial Statements') ?></p>
        <p>Financial Year: <?= htmlspecialchars($fyName) ?></p>
    </div>

    <div class="toc-page">
        <h2>Table of Contents</h2>
        <div class="toc-entry main"><span>Balance Sheet</span></div>
        <div class="toc-entry main"><span>Statement of <?= htmlspecialchars($plLabel) ?></span></div>
        <div class="toc-entry main"><span>Notes to Accounts</span></div>
        <?php foreach ($noteSections as $noteSection): ?>
            <div class="toc-entry"><span style="padding-left:16px;"><?= htmlspecialchars($noteSection['title'] ?? '') ?></span></div>
        <?php endforeach; ?>
    </div>

    <div class="report-shell">
        <?php include $formatTemplate; ?>
        <?php include $notesTemplate; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function wrapReportHtmlDocument(string $title, string $bodyHtml): string
{
    $footer = <<<'FOOTER'
<div class="page-footer">
    Page <span class="page-counter"></span>
</div>
FOOTER;
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' .
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8') .
        '</title>' . reportDocumentStyles() . '</head><body>' . $bodyHtml . $footer . '</body></html>';
}

function buildReportExportFilename(string $companyName, string $fyName, string $extension): string
{
    $base = trim($companyName) !== '' ? $companyName : 'financial-statements';
    $fy = trim($fyName) !== '' ? $fyName : 'financial-year';
    $filename = $base . '-' . $fy . '-financial-statements';
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'financial-statements';
    $filename = trim($filename, '-');

    return $filename . '.' . ltrim($extension, '.');
}
