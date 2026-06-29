<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());

$selectedFormat = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$allowedFormats = ['pdf', 'xlsx', 'docx', 'html'];

if (!in_array($selectedFormat, $allowedFormats, true)) {
    $selectedFormat = 'pdf';
}

$page_title = 'Export Centre';
$showSidebar = true;
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Export', 'href' => BASE_URL . 'export_centre.php'],
    ['label' => 'Centre']
]) ?>

<?= uiPageHero('Export Centre') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => ucwords(str_replace('_', ' ', $companyCategory)),
    'profile' => 0,
    'status' => '',
    'edit_url' => '',
]) ?>

<style>
:root {
    --font-sans: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    --bg: #f1f5f9; --panel: #fff; --border: #e2e8f0;
    --text: #0f172a; --muted: #64748b; --brand: #0f4c81;
    --success: #16a34a; --warning: #d97706; --danger: #dc2626;
    --radius: 10px; --shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.master-stepper {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 20px;
    box-shadow: var(--shadow);
}
.ms-step { display: flex; align-items: center; gap: 8px; }
.ms-circle {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700;
}
.ms-circle.done { background: var(--success); color: #fff; }
.ms-circle.active { background: var(--brand); color: #fff; box-shadow: 0 0 0 3px rgba(15,76,129,0.2); }
.ms-circle.pending { background: var(--bg); color: var(--muted); border: 2px solid var(--border); }
.ms-label { font-size: 0.82rem; }
.ms-label.muted { color: var(--muted); }
.ms-line { width: 40px; height: 2px; background: var(--border); margin: 0 8px; }
.ms-line.done { background: var(--success); }

.export-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

.format-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
.format-card {
    background: var(--panel); border: 2px solid var(--border); border-radius: 12px;
    padding: 18px; cursor: pointer; transition: all 0.15s; text-align: center;
    text-decoration: none; display: block; color: inherit;
}
.format-card:hover { border-color: var(--brand); box-shadow: 0 2px 8px rgba(15,76,129,0.1); transform: translateY(-1px); }
.format-card.selected { border-color: var(--brand); background: #f8faff; }
.format-card .icon { font-size: 2rem; margin-bottom: 8px; }
.format-card h4 { font-size: 0.95rem; margin: 0 0 4px; }
.format-card p { font-size: 0.75rem; color: var(--muted); margin: 0; }

.config-panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    box-shadow: var(--shadow);
}
.config-panel h3 { font-size: 1rem; margin: 0 0 16px; }
.config-row { display: flex; gap: 14px; margin-bottom: 14px; }
.config-row .form-group { flex: 1; }
.form-group { margin-bottom: 14px; }
.form-group label {
    display: block; font-size: 0.8rem; font-weight: 600;
    color: var(--muted); margin-bottom: 4px;
}
.form-group select,
.form-group input {
    width: 100%; padding: 8px 10px;
    border: 1px solid var(--border); border-radius: 8px;
    font-size: 0.85rem; background: var(--panel);
}

.toggle-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.toggle-row input[type=checkbox] { width: auto; }
.toggle-row label { font-size: 0.85rem; cursor: pointer; }

.preview-panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
}
.preview-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.preview-header h3 { font-size: 0.95rem; margin: 0; }
.preview-body {
    flex: 1; min-height: 400px;
    display: flex;
}
.preview-body iframe {
    width: 100%; height: 100%; min-height: 400px;
    border: none;
}
.preview-footer {
    padding: 14px 18px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    margin-top: 28px;
}
.section-header h2 { font-size: 1rem; }
.history-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--panel);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow);
}
.history-table th {
    padding: 10px 14px; text-align: left;
    font-size: 0.8rem; font-weight: 600;
    color: var(--muted); background: var(--bg);
    border-bottom: 1px solid var(--border);
}
.history-table td {
    padding: 10px 14px; font-size: 0.85rem;
    border-bottom: 1px solid var(--border);
}
.history-table .format-badge {
    font-size: 0.7rem; padding: 2px 8px;
    border-radius: 4px; font-weight: 600;
}
.history-table .format-badge.pdf { background: #fee2e2; color: var(--danger); }
.history-table .format-badge.xlsx { background: #dcfce7; color: var(--success); }
.history-table .format-badge.docx { background: #dbeafe; color: #2563eb; }
.history-table .format-badge.html { background: #f3e8ff; color: #7c3aed; }

.btn-success {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px; border: none; border-radius: 8px;
    font-weight: 600; cursor: pointer; font-size: 0.85rem;
    background: var(--success); color: #fff; text-decoration: none;
    justify-content: center;
}
.btn-success:hover { opacity: 0.9; }

@media (max-width: 900px) {
    .export-grid { grid-template-columns: 1fr; }
    .format-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="card" style="margin-bottom:18px;">
    Export financial statements, notes, and reports in PDF, Excel, or Word format. Configure options below and generate your download.
</div>

<?php if ($company_id <= 0): ?>
    <div class="error-box" style="margin-bottom:18px;">
        <p>No company selected. Please select a company and financial year from the dashboard before exporting.</p>
        <a class="btn" href="<?= BASE_URL ?>dashboard_main.php" style="margin-top:12px;">Go to Dashboard</a>
    </div>
<?php else: ?>
    <div class="master-stepper">
        <div class="ms-step"><span class="ms-circle done">&#10003;</span><span class="ms-label">Select Format</span></div>
        <div class="ms-line done"></div>
        <div class="ms-step"><span class="ms-circle active">2</span><span class="ms-label">Configure</span></div>
        <div class="ms-line"></div>
        <div class="ms-step"><span class="ms-circle pending">3</span><span class="ms-label muted">Preview</span></div>
        <div class="ms-line"></div>
        <div class="ms-step"><span class="ms-circle pending">4</span><span class="ms-label muted">Download</span></div>
    </div>

    <div class="export-grid">
        <div>
            <div class="format-grid">
                <a class="format-card <?= $selectedFormat === 'pdf' ? 'selected' : '' ?>" href="?format=pdf">
                    <div class="icon">&#128196;</div>
                    <h4>PDF</h4>
                    <p>Print-ready format. Best for filing and signatures.</p>
                </a>
                <a class="format-card <?= $selectedFormat === 'xlsx' ? 'selected' : '' ?>" href="?format=xlsx">
                    <div class="icon">&#128202;</div>
                    <h4>Excel (XLSX)</h4>
                    <p>Editable spreadsheet. Best for analysis.</p>
                </a>
                <a class="format-card <?= $selectedFormat === 'docx' ? 'selected' : '' ?>" href="?format=docx">
                    <div class="icon">&#128221;</div>
                    <h4>Word (DOCX)</h4>
                    <p>Editable document. Best for reports.</p>
                </a>
                <a class="format-card <?= $selectedFormat === 'html' ? 'selected' : '' ?>" href="?format=html">
                    <div class="icon">&#127760;</div>
                    <h4>HTML</h4>
                    <p>Web-ready format. Best for email/portal.</p>
                </a>
            </div>

            <div class="config-panel">
                <h3>Export Configuration</h3>
                <div class="config-row">
                    <div class="form-group">
                        <label>Paper Size</label>
                        <select id="paper_size">
                            <option value="A4" selected>A4</option>
                            <option value="Letter">Letter</option>
                            <option value="Legal">Legal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Orientation</label>
                        <select id="orientation">
                            <option value="portrait" selected>Portrait</option>
                            <option value="landscape">Landscape</option>
                        </select>
                    </div>
                </div>

                <h4 style="font-size:0.85rem;color:var(--muted);margin:16px 0 8px;">
                    Include in Export
                    <span style="font-weight:400;color:var(--muted);font-size:0.75rem;">&mdash; all sections included</span>
                </h4>
                <div class="toggle-row"><input type="checkbox" checked disabled title="Always included in export"><label>Balance Sheet</label></div>
                <div class="toggle-row"><input type="checkbox" checked disabled title="Always included in export"><label>Profit &amp; Loss Statement</label></div>
                <div class="toggle-row"><input type="checkbox" checked disabled title="Always included in export"><label>Notes to Accounts</label></div>

                <div style="display:flex;gap:10px;margin-top:18px;">
                    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=<?= urlencode($selectedFormat) ?>" style="flex:1;text-align:center;" target="_blank">
                        Download <?= strtoupper($selectedFormat) ?>
                    </a>
                    <a class="btn-outline" href="<?= BASE_URL ?>report_download.php?format=pdf" target="_blank">
                        Preview PDF
                    </a>
                </div>
            </div>
        </div>

        <div class="preview-panel">
            <div class="preview-header">
                <h3>Preview &mdash; <?= strtoupper($selectedFormat) ?></h3>
            </div>
            <div class="preview-body" style="padding:0;overflow:hidden;">
                <iframe src="<?= BASE_URL ?>report_download.php?format=html" title="Report preview" loading="lazy"></iframe>
            </div>
            <div class="preview-footer">
                <a class="btn-success" href="<?= BASE_URL ?>report_download.php?format=<?= urlencode($selectedFormat) ?>" style="flex:1;text-align:center;" target="_blank">
                    Download <?= strtoupper($selectedFormat) ?>
                </a>
            </div>
        </div>
    </div>

    <div class="section-header">
        <h2>Export History</h2>
    </div>
    <table class="history-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Format</th>
                <th>Contents</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= date('d M Y, h:i A', time()) ?></td>
                <td><span class="format-badge <?= $selectedFormat ?>"><?= strtoupper($selectedFormat) ?></span></td>
                <td>BS + P&L + Notes</td>
                <td>
                    <a class="btn-outline btn-sm" href="<?= BASE_URL ?>report_download.php?format=<?= urlencode($selectedFormat) ?>" target="_blank">Download</a>
                </td>
            </tr>
            <tr>
                <td colspan="4" style="text-align:center;color:var(--muted);font-size:0.8rem;">
                    Previous exports appear here. Use the download buttons above to generate new exports.
                </td>
            </tr>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
