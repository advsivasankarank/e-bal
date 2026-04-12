<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/directors_report_ai_helper.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/layouts/header.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);
$fs = generateFinancialStatements(
    $pdo,
    $company_id,
    $fy_id,
    $fyName,
    $manualBundle['current'] ?? [],
    $manualBundle['previous'] ?? []
);

if (($fs['entity_category'] ?? '') !== 'corporate') {
    echo '<div class="error-box"><p>Directors Report is currently available only for Corporate entities.</p></div>';
    require_once __DIR__ . '/layouts/footer.php';
    exit;
}

updateWorkflow($company_id, $fy_id, 'directors_report_prepared');

$sectionDefinitions = getDirectorsReportSectionDefinitions();
$draftSections = [];
foreach ($sectionDefinitions as $key => $title) {
    $draftSections[$key] = (string) ($manualBundle['saved_current']['directors_report_' . $key] ?? '');
}

$hasSavedSections = array_filter($draftSections, static fn ($value) => trim((string) $value) !== '') !== [];
$draft = (string) ($manualBundle['saved_current']['directors_report_draft'] ?? '');
$draftSource = $hasSavedSections ? 'Saved Draft' : ($draft !== '' ? 'Saved Draft' : 'Not Generated');
$infoMessage = '';

if (!$hasSavedSections && trim($draft) !== '') {
    $generatedFallback = buildDirectorsReportFallbackSections($fs, $companyName, $fyName);
    $draftSections = $generatedFallback;
} elseif (!$hasSavedSections) {
    $draftSections = buildDirectorsReportFallbackSections($fs, $companyName, $fyName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['directors_report_action'] ?? '');

    if ($action === 'generate_ai') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0 && !hasFeature($userId, 'ai_notes', $pdo)) {
            $infoMessage = 'AI draft is not available on your current plan. Upgrade to use AI features.';
        } else {
            $generated = generateDirectorsReportDraft($fs, $companyName, $fyName);
            $draft = (string) ($generated['draft'] ?? '');
            $draftSections = $generated['sections'] ?? $draftSections;
            $draftSource = (string) ($generated['source'] ?? 'Built-in Draft');
            $infoMessage = (string) ($generated['message'] ?? '');
        }
    } elseif ($action === 'save') {
        foreach ($sectionDefinitions as $key => $title) {
            $draftSections[$key] = trim((string) ($_POST['directors_report_' . $key] ?? ''));
        }
        $draft = combineDirectorsReportSections($draftSections, $companyName);
        $payload = ['directors_report_draft' => $draft];
        foreach ($draftSections as $key => $value) {
            $payload['directors_report_' . $key] = $value;
        }
        saveManualInputs($pdo, $company_id, $fy_id, $payload);
        $draftSource = 'Saved Draft';
        $infoMessage = 'Directors report draft saved.';
    }
}
?>

<div class="page-title">Directors Report</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fyName) ?></strong>
</div>

<?php if ($infoMessage !== ''): ?>
    <div class="card section-card"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>

<div class="card section-card">
    Build the corporate directors report from the prepared financial statements. Use the AI draft option for a first version, then review and finalise before issue.
</div>

<div class="draft-box">
    <div class="draft-actions">
        <form method="post">
            <input type="hidden" name="directors_report_action" value="generate_ai">
            <button class="btn-primary" type="submit">Generate AI Draft</button>
        </form>
        <a class="btn" href="<?= BASE_URL ?>reports.php#balance-sheet">Back To Financial Statements</a>
    </div>

    <div class="draft-meta">
        Draft source: <strong><?= htmlspecialchars($draftSource) ?></strong>
    </div>

    <form method="post">
        <input type="hidden" name="directors_report_action" value="save">
        <?php foreach ($sectionDefinitions as $key => $title): ?>
            <div class="form-group">
                <label for="directors_report_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($title) ?></label>
                <textarea id="directors_report_<?= htmlspecialchars($key) ?>" name="directors_report_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars((string) ($draftSections[$key] ?? '')) ?></textarea>
            </div>
        <?php endforeach; ?>
        <button class="btn-primary" type="submit">Save Directors Report Draft</button>
    </form>
</div>

<div class="preview-box">
    <h3>Frozen Preview</h3>
    <?php
    $sections = $draftSections;
    $company_meta = $fs['company_meta'] ?? [];
    include __DIR__ . '/reports_dashboard/formats/directors_report_company.php';
    ?>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
