<?php

function reportDocumentStyles(): string
{
    return <<<'CSS'
<style>
@page {
    margin: 14mm 12mm 16mm 12mm;
    size: A4 portrait;
}
body {
    font-family: Garamond, "EB Garamond", Georgia, "Times New Roman", serif;
    counter-reset: section;
    font-size: 10px;
    line-height: 1.3;
    margin: 0;
    padding: 0;
}
.report-shell { background: transparent; border: 0; padding: 0; }
.report-page {
    width: 100%;
    min-height: auto;
    margin: 0 auto 12px;
    background: #fff;
    border: 1px solid #d8e2ef;
    border-radius: 0;
    box-shadow: none;
    padding: 0;
    box-sizing: border-box;
    page-break-after: always;
    overflow: hidden;
}
.report-page:last-child { page-break-after: auto; }
.report-page-title {
    margin: 0 0 6px;
    font-size: 16px;
    line-height: 1.25;
    color: #0f172a;
}
.report-page-subtitle {
    margin: 0 0 12px;
    color: #475569;
    font-size: 10px;
}
.report-shell h2 { margin: 0 0 8px; font-size: 16px; }
.report-shell h3 { margin: 14px 0 8px; font-size: 13px; }
.report-shell table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    table-layout: fixed;
}
.report-shell th, .report-shell td {
    border: 1px solid #dbe3ef;
    padding: 4px 5px;
    text-align: left;
    vertical-align: top;
    font-size: 10px;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.report-shell tr.section td, .report-shell tr.section th { background: #f5f8fc; font-weight: 700; }
.report-shell td.figure, .report-shell th.figure {
    text-align: right;
    white-space: nowrap;
    width: 28%;
}
.report-shell td.note-col, .report-shell th.note-col {
    width: 8%;
    text-align: center;
}
.report-shell td.particulars, .report-shell th.particulars {
    width: auto;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}
.notes-cover { margin-bottom: 12px; }
.note-block { break-inside: avoid; page-break-inside: avoid; margin-bottom: 14px; }
.notes-shell {
    border-top: 2px solid #d7e3f3;
    padding-top: 6px;
}
.note-heading {
    margin: 0 0 8px;
    padding: 6px 10px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
    border: 1px solid #d9e5f2;
    border-radius: 6px;
    color: #0f172a;
    font-size: 12px;
    line-height: 1.35;
}
.note-table {
    margin-top: 0;
    border: 1px solid #d9e5f2;
    table-layout: fixed;
}
.note-table th, .note-table td {
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 10px;
    padding: 4px 5px;
}
.note-table thead th {
    background: #eef4f9;
    font-size: 10px;
    letter-spacing: 0.02em;
    color: #334155;
}
.note-table tbody td:first-child {
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}
.note-table tbody td {
    white-space: nowrap;
}
.note-table tbody tr:nth-child(even) td {
    background: #fbfdff;
}
.note-table tfoot td {
    background: #f3f7fb;
    font-weight: 700;
}
.note-policy-list {
    margin: 10px 0 0 18px;
    padding: 0;
}
.note-policy-list li {
    margin-bottom: 4px;
    line-height: 1.4;
    font-size: 10px;
}
.signature-table td { border: 0 !important; }
.signature-block { margin-top: 20px; }
.signature-caption { color: #475569; font-size: 10px; margin-top: 6px; }
.report-export-cover {
    margin: 0 auto 12px;
    width: 100%;
    color: #0f172a;
    page-break-after: always;
}
.report-export-cover h1 {
    margin: 0 0 6px;
    font-size: 22px;
}
.report-export-cover p {
    margin: 0 0 4px;
    color: #475569;
    font-size: 11px;
}
.toc-page {
    page-break-after: always;
    margin-bottom: 14mm;
}
.toc-page h2 {
    font-size: 18px;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 6px;
    margin-bottom: 12px;
}
.toc-entry {
    padding: 4px 0;
    font-size: 11px;
    border-bottom: 1px dotted #ccc;
}
.toc-entry.main {
    font-weight: 700;
    font-size: 12px;
    margin-top: 6px;
}
.page-footer {
    text-align: center;
    font-size: 8px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding: 3mm 12mm;
    background: #fff;
    width: 100%;
    box-sizing: border-box;
}
.page-footer .page-counter:after {
    content: counter(page, decimal);
}
.page-footer .total-pages:before {
    content: counter(total-pages, decimal);
}
/* eBAL stat header */
.ebal-stat-header { margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #d7e3f3; }
.ebal-stat-header--compact { margin-bottom: 6px; }
.ebal-company-name { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
.ebal-company-detail { font-size: 10px; color: #475569; }
.ebal-placeholder { font-style: italic; color: #94a3b8; }
.ebal-footer-brand { font-size: 8px; color: #94a3b8; text-align: center; margin-top: 16px; padding-top: 8px; border-top: 1px solid #e2e8f0; }
.ebal-empty-note { font-size: 10px; color: #64748b; font-style: italic; padding: 6px 0; }
.ebal-disclosure-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; margin-top: 8px; font-size: 10px; color: #334155; }
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
