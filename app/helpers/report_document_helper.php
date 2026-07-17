<?php

require_once __DIR__ . '/directors_report_ai_helper.php';

function reportDocumentStyles(): string
{
    return <<<'CSS'
<style>
@page {
    margin: 14mm 12mm 16mm 12mm;
    size: A4 portrait;
}
body {
    font-family: "DejaVu Sans", "DejaVu Sans Condensed", sans-serif;
    counter-reset: section;
    font-size: 9px;
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
    font-size: 14px;
    line-height: 1.25;
    color: #0f172a;
}
.report-page-subtitle {
    margin: 0 0 10px;
    color: #475569;
    font-size: 9px;
}
.report-shell h2 { margin: 0 0 8px; font-size: 14px; }
.report-shell h3 { margin: 12px 0 6px; font-size: 11px; }
.report-shell table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    table-layout: fixed;
}
.report-shell th, .report-shell td {
    border: 1px solid #dbe3ef;
    padding: 3px 4px;
    text-align: left;
    vertical-align: top;
    font-size: 8.5px;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.report-shell tr.section td, .report-shell tr.section th { background: #f5f8fc; font-weight: 700; }
/* Statement table: Particulars 44%, Note 8%, Current 24%, Previous 24% */
.report-shell table.statement-table th.particulars,
.report-shell table.statement-table td.particulars {
    width: 44%;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}
.report-shell table.statement-table th.note-col,
.report-shell table.statement-table td.note-col {
    width: 8%;
    text-align: center;
}
.report-shell table.statement-table th.figure,
.report-shell table.statement-table td.figure {
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
/* Fallback for tables without statement-table class */
.report-shell td.figure, .report-shell th.figure {
    text-align: right;
    white-space: nowrap;
    width: 24%;
}
.report-shell td.previous-year, .report-shell th.previous-year {
    display: table-cell !important;
    visibility: visible !important;
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.report-shell td.current-year, .report-shell th.current-year {
    display: table-cell !important;
    visibility: visible !important;
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.report-shell td.note-col, .report-shell th.note-col {
    width: 8%;
    text-align: center;
}
.report-shell td.particulars, .report-shell th.particulars {
    width: 46%;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}
.notes-cover { margin-bottom: 10px; }
.note-block { break-inside: avoid; page-break-inside: avoid; margin-bottom: 12px; }
.notes-shell {
    border-top: 2px solid #d7e3f3;
    padding-top: 6px;
}
.note-heading {
    margin: 0 0 6px;
    padding: 5px 8px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
    border: 1px solid #d9e5f2;
    border-radius: 4px;
    color: #0f172a;
    font-size: 10px;
    line-height: 1.3;
}
/* Hide collapse icon in PDF — prevents "?" rendering */
.note-collapse-icon { display: none !important; }
.note-table {
    margin-top: 0;
    border: 1px solid #d9e5f2;
    table-layout: fixed;
}
/* Notes table: Ledger 52%, Current 24%, Previous 24% */
.note-table th.particulars,
.note-table td.particulars {
    width: 52%;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
}
.note-table th.figure,
.note-table td.figure {
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.note-table th.previous-year,
.note-table td.previous-year {
    display: table-cell !important;
    visibility: visible !important;
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.note-table th.current-year,
.note-table td.current-year {
    display: table-cell !important;
    visibility: visible !important;
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.note-table th, .note-table td {
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 8.5px;
    padding: 3px 4px;
}
.note-table thead th {
    background: #eef4f9;
    font-size: 8.5px;
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
    margin: 8px 0 0 18px;
    padding: 0;
}
.note-policy-list li {
    margin-bottom: 3px;
    line-height: 1.3;
    font-size: 9px;
}
.signature-table td { border: 0 !important; }
.signature-block { margin-top: 16px; }
.signature-caption { color: #475569; font-size: 9px; margin-top: 4px; }
.directors-report-preview {
    width: 100%;
    box-sizing: border-box;
    font-size: 9px;
    line-height: 1.55;
    color: #1e293b;
}
.directors-report-preview p {
    width: 100%;
    box-sizing: border-box;
    margin: 0 0 8px;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.directors-report-preview h2 {
    font-size: 16px;
    text-align: center;
    margin: 0 0 4px;
    color: #0f172a;
    letter-spacing: 0.4px;
}
.directors-report-preview > p:first-of-type {
    text-align: left;
    margin: 0 0 16px;
    font-size: 9px;
}
.directors-report-preview h3 {
    font-size: 10px;
    margin: 14px 0 5px;
    color: #0f172a;
    border-bottom: 1px solid #d9e5f2;
    padding-bottom: 3px;
}
.report-section-text {
    width: 100%;
    box-sizing: border-box;
    margin: 0 0 4px;
    text-align: left;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.directors-report-table {
    margin: 6px 0 16px;
    border: 1px solid #d9e5f2;
    table-layout: fixed;
    border-collapse: collapse;
}
.directors-report-table th,
.directors-report-table td {
    border: 1px solid #dbe3ef;
    padding: 3px 5px;
    font-size: 8.5px;
    word-wrap: break-word;
}
.directors-report-table th.particulars,
.directors-report-table td:first-child {
    width: 52%;
    text-align: left;
}
.directors-report-table th.figure,
.directors-report-table td.figure {
    width: 24%;
    text-align: right;
    white-space: nowrap;
}
.directors-report-table thead th {
    background: #eef4f9;
    color: #334155;
}
.directors-report-table tr.total-row td {
    background: #f3f7fb;
}
.directors-report-signoff {
    margin-top: 30px;
    font-size: 9.5px;
    line-height: 1.6;
}
.directors-report-place-date {
    margin-top: 18px;
    font-size: 9px;
    color: #334155;
}
.directors-report-place-date div {
    margin-bottom: 2px;
}
.report-export-cover {
    margin: 0 auto 10px;
    width: 100%;
    color: #0f172a;
    page-break-after: always;
}
.report-export-cover h1 {
    margin: 0 0 6px;
    font-size: 20px;
}
.report-export-cover p {
    margin: 0 0 4px;
    color: #475569;
    font-size: 10px;
}
.toc-page {
    page-break-after: always;
    margin-bottom: 14mm;
}
.toc-page h2 {
    font-size: 16px;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 6px;
    margin-bottom: 10px;
}
.toc-entry {
    padding: 3px 0;
    font-size: 10px;
    border-bottom: 1px dotted #ccc;
}
.toc-entry.main {
    font-weight: 700;
    font-size: 11px;
    margin-top: 4px;
}
.toc-entry a {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    text-decoration: none;
    color: inherit;
}
.toc-page-num::before {
    content: target-counter(attr(href), page);
}
.exec-summary-page {
    padding-top: 4mm;
}
.page-footer {
    text-align: center;
    font-size: 7px;
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
.ebal-stat-header { margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #d7e3f3; }
.ebal-stat-header--compact { margin-bottom: 4px; }
.ebal-company-name { font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 2px; }
.ebal-company-detail { font-size: 9px; color: #475569; }
.ebal-placeholder { font-style: italic; color: #94a3b8; }
.ebal-footer-brand { font-size: 7px; color: #94a3b8; text-align: center; margin-top: 12px; padding-top: 6px; border-top: 1px solid #e2e8f0; }
.ebal-empty-note { font-size: 9px; color: #64748b; font-style: italic; padding: 4px 0; }
.ebal-disclosure-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px 8px; margin-top: 6px; font-size: 9px; color: #334155; }
/* Premium Cover Page */
.cover-page {
    width: 100%;
    min-height: 267mm;
    page-break-after: always;
    position: relative;
    overflow: hidden;
    font-family: "DejaVu Sans", "DejaVu Sans Condensed", sans-serif;
    background: linear-gradient(160deg, #e8f6f8 0%, #ffffff 35%, #fff8eb 100%);
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}
.cover-wave-teal {
    position: absolute;
    left: -50mm;
    top: 80mm;
    width: 200mm;
    height: 100mm;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(0,121,145,0.70), rgba(0,174,190,0.30));
}
.cover-wave-orange {
    position: absolute;
    right: -55mm;
    top: 100mm;
    width: 200mm;
    height: 105mm;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(255,180,52,0.40), rgba(245,116,32,0.75));
}
.cover-wave-light {
    position: absolute;
    left: 20mm;
    top: 60mm;
    width: 160mm;
    height: 80mm;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(255,255,255,0.5), rgba(200,240,245,0.25));
}
.cover-company-block {
    position: absolute;
    top: 28mm;
    left: 20mm;
    right: 20mm;
    text-align: center;
    z-index: 2;
}
.cover-company-name {
    font-size: 20px;
    font-weight: 700;
    color: #0f3d4e;
    letter-spacing: 0.5px;
    line-height: 1.3;
    margin-bottom: 6mm;
}
.cover-company-meta {
    font-size: 9.5px;
    line-height: 1.6;
    color: #3d6070;
}
.cover-company-meta span {
    display: block;
}
.cover-title-block {
    position: absolute;
    top: 105mm;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 2;
}
.cover-title {
    font-size: 42px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 1px;
    text-shadow: 0 2px 8px rgba(0,80,100,0.25);
    margin-bottom: 6mm;
}
.cover-fy {
    font-size: 28px;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.5px;
    text-shadow: 0 2px 6px rgba(0,80,100,0.20);
}
.cover-divider {
    width: 60mm;
    height: 2px;
    background: rgba(255,255,255,0.6);
    margin: 8mm auto;
}
.cover-footer {
    position: absolute;
    bottom: 25mm;
    left: 0;
    right: 0;
    text-align: center;
    z-index: 2;
}
.cover-footer-text {
    font-size: 13px;
    font-style: italic;
    color: #3d6070;
    letter-spacing: 0.3px;
}
.cover-footer-brand {
    font-size: 11px;
    font-weight: 700;
    color: #0f766e;
    margin-top: 3mm;
}
</style>
CSS;
}

function renderExecutiveSummaryPage(array $fs, string $companyName, string $fyName): string
{
    $data = $fs['data'] ?? [];
    $company_meta = $fs['company_meta'] ?? [];
    $cin = trim((string) ($company_meta['cin'] ?? ''));
    $registeredAddress = trim((string) ($company_meta['registered_address'] ?? ''));
    $plLabel = ($fs['entity_subcategory'] ?? '') === 'trust' ? 'Income & Expenditure Account' : 'Profit & Loss Account';

    $highlights = [
        ['label' => 'Revenue', 'current' => $data['revenue'] ?? 0, 'previous' => $data['prev_revenue'] ?? 0],
        ['label' => 'Total Expenses', 'current' => $data['expenses'] ?? 0, 'previous' => $data['prev_expenses'] ?? 0],
        ['label' => 'Profit Before Tax', 'current' => $data['pbt'] ?? 0, 'previous' => $data['prev_pbt'] ?? 0],
        ['label' => 'Profit After Tax', 'current' => $data['pat'] ?? 0, 'previous' => $data['prev_pat'] ?? 0],
        ['label' => 'Total Assets', 'current' => $data['total_assets'] ?? 0, 'previous' => $data['prev_total_assets'] ?? 0],
        ['label' => 'Total Liabilities', 'current' => $data['total_liabilities'] ?? 0, 'previous' => $data['prev_total_liabilities'] ?? 0],
    ];

    ob_start();
    ?>
    <div class="report-page exec-summary-page" id="executive-summary">
        <div class="ebal-stat-header">
            <div class="ebal-company-name"><?= htmlspecialchars($companyName ?: 'Company Name Not Configured') ?></div>
            <?php if ($registeredAddress): ?>
            <div class="ebal-company-detail">Registered Office: <?= htmlspecialchars($registeredAddress) ?></div>
            <?php else: ?>
            <div class="ebal-company-detail ebal-placeholder">Registered Office: Not configured</div>
            <?php endif; ?>
            <?php if ($cin): ?>
            <div class="ebal-company-detail">CIN: <?= htmlspecialchars($cin) ?></div>
            <?php else: ?>
            <div class="ebal-company-detail ebal-placeholder">CIN: Not configured</div>
            <?php endif; ?>
        </div>

        <h2 class="report-page-title">Executive Summary</h2>
        <p class="report-page-subtitle">Key financial highlights for FY <?= htmlspecialchars($fyName) ?> (figures in Indian Rupees).</p>

        <table class="statement-table" border="1" width="100%" cellpadding="5">
            <tr><th class="particulars">Particulars</th><th class="figure current-year">Current Year</th><th class="figure previous-year">Previous Year</th></tr>
            <?php foreach ($highlights as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['label']) ?></td>
                <td class="figure"><?= format_inr((float) $row['current']) ?></td>
                <td class="figure previous-year"><?= format_inr((float) $row['previous']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <p style="margin-top:14px;font-size:9px;color:#475569;">This Executive Summary is provided for quick reference only. The Directors' Report, Balance Sheet, Statement of <?= htmlspecialchars($plLabel) ?> and Notes to Accounts that follow form part of, and should be read together with, this summary.</p>
    </div>
    <?php
    return (string) ob_get_clean();
}

function renderDirectorsReportSection(array $sections, string $companyName, string $fyName, array $company_meta, array $data = [], string $directorsReportPlace = '', string $directorsReportDate = ''): string
{
    $cin = trim((string) ($company_meta['cin'] ?? ''));
    $registeredAddress = trim((string) ($company_meta['registered_address'] ?? ''));

    ob_start();
    ?>
    <section class="report-page" id="directors-report">
        <div class="ebal-stat-header">
            <div class="ebal-company-name"><?= htmlspecialchars($companyName ?: 'Company Name Not Configured') ?></div>
            <?php if ($registeredAddress): ?>
            <div class="ebal-company-detail">Registered Office: <?= htmlspecialchars($registeredAddress) ?></div>
            <?php else: ?>
            <div class="ebal-company-detail ebal-placeholder">Registered Office: Not configured</div>
            <?php endif; ?>
            <?php if ($cin): ?>
            <div class="ebal-company-detail">CIN: <?= htmlspecialchars($cin) ?></div>
            <?php else: ?>
            <div class="ebal-company-detail ebal-placeholder">CIN: Not configured</div>
            <?php endif; ?>
        </div>
        <p class="report-page-subtitle">Financial Year: <?= htmlspecialchars($fyName) ?></p>
        <?php include __DIR__ . '/../../public/reports_dashboard/formats/directors_report_company.php'; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

/**
 * Standalone Directors' Report document (its own cover-free single PDF/DOCX,
 * not embedded in the Financial Statements bundle).
 */
function renderDirectorsReportDocument(array $sections, string $companyName, string $fyName, array $company_meta, array $data = [], string $directorsReportPlace = '', string $directorsReportDate = ''): string
{
    return '<div class="report-shell">' . renderDirectorsReportSection($sections, $companyName, $fyName, $company_meta, $data, $directorsReportPlace, $directorsReportDate) . '</div>';
}

function renderFinancialReportDocument(array $fs, string $companyName, string $fyName, array $directorsReportSections = [], string $directorsReportPlace = '', string $directorsReportDate = ''): string
{
    $data = $fs['data'];
    $notes = $fs['notes'];
    $company_meta = $fs['company_meta'] ?? [];
    $formatTemplate = $fs['format_template'];
    $notesTemplate = $fs['notes_template'];

    $plLabel = ($fs['entity_subcategory'] ?? '') === 'trust' ? 'Income & Expenditure Account' : 'Profit & Loss Account';
    $noteSections = $notes['sections'] ?? [];

    $cin = trim((string) ($company_meta['cin'] ?? ''));
    $registeredAddress = trim((string) ($company_meta['registered_address'] ?? ''));
    $hasDirectorsReport = ($fs['entity_category'] ?? '') === 'corporate' && !empty($directorsReportSections);

    ob_start();
    ?>
    <!-- Premium Cover Page -->
    <div class="cover-page">
        <div class="cover-wave-teal"></div>
        <div class="cover-wave-orange"></div>
        <div class="cover-wave-light"></div>

        <div class="cover-company-block">
            <div class="cover-company-name"><?= htmlspecialchars($companyName) ?></div>
            <div class="cover-company-meta">
                <?php if ($cin): ?>
                    <span>CIN: <?= htmlspecialchars($cin) ?></span>
                <?php else: ?>
                    <span style="font-style:italic;">CIN: Not configured</span>
                <?php endif; ?>
                <?php if ($registeredAddress): ?>
                    <span>Registered Office: <?= htmlspecialchars($registeredAddress) ?></span>
                <?php else: ?>
                    <span style="font-style:italic;">Registered Office: Not configured</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="cover-title-block">
            <div class="cover-title">Annual Report</div>
            <div class="cover-divider"></div>
            <div class="cover-fy"><?= htmlspecialchars($fyName) ?></div>
        </div>

        <div class="cover-footer">
            <div class="cover-footer-text">Prepared by</div>
            <div class="cover-footer-brand">e-BAL</div>
        </div>
    </div>

    <?= renderExecutiveSummaryPage($fs, $companyName, $fyName) ?>

    <div class="toc-page">
        <h2>Table of Contents</h2>
        <?php if ($hasDirectorsReport): ?>
        <div class="toc-entry main"><a href="#directors-report"><span>Directors' Report</span><span class="toc-page-num"></span></a></div>
        <?php endif; ?>
        <div class="toc-entry main"><a href="#balance-sheet"><span>Balance Sheet</span><span class="toc-page-num"></span></a></div>
        <div class="toc-entry main"><a href="#profit-loss"><span>Statement of <?= htmlspecialchars($plLabel) ?></span><span class="toc-page-num"></span></a></div>
        <div class="toc-entry main"><a href="#notes-to-accounts"><span>Notes to Accounts</span><span class="toc-page-num"></span></a></div>
        <?php foreach ($noteSections as $noteSection): ?>
            <div class="toc-entry"><span style="padding-left:16px;"><?= htmlspecialchars($noteSection['title'] ?? '') ?></span></div>
        <?php endforeach; ?>
    </div>

    <?php if ($hasDirectorsReport): ?>
    <div class="report-shell">
        <?= renderDirectorsReportSection($directorsReportSections, $companyName, $fyName, $company_meta, $data, $directorsReportPlace, $directorsReportDate) ?>
    </div>
    <?php endif; ?>

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

function buildReportExportFilename(string $companyName, string $fyName, string $extension, string $documentLabel = 'financial-statements'): string
{
    $base = trim($companyName) !== '' ? $companyName : 'financial-statements';
    $fy = trim($fyName) !== '' ? $fyName : 'financial-year';
    $filename = $base . '-' . $fy . '-' . $documentLabel;
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: $documentLabel;
    $filename = trim($filename, '-');

    return $filename . '.' . ltrim($extension, '.');
}
