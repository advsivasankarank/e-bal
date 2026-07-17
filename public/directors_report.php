<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/directors_report_ai_helper.php';
require_once __DIR__ . '/../app/helpers/share_capital_helper.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);
$shareholders = getShareholders($pdo, $company_id, $fy_id);
$fs = generateFinancialStatements(
    $pdo,
    $company_id,
    $fy_id,
    $fyName,
    $manualBundle['current'] ?? [],
    $manualBundle['previous'] ?? []
);

if (($fs['entity_category'] ?? '') !== 'corporate') {
    require_once __DIR__ . '/layouts/header_v2.php';
    ?>
    <?= uiBreadcrumb([
        ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
        ['label' => 'Directors Report'],
    ]) ?>
    <?= uiPageHero('Directors Report') ?>
    <div class="card section-card">
        <p>Directors' Report is currently available only for Corporate entities. This entity is not registered as a company under the Companies Act, 2013.</p>
        <a class="btn" href="<?= BASE_URL ?>reports.php#balance-sheet">Back To Financial Statements</a>
    </div>
    <?php
    require_once __DIR__ . '/layouts/footer_v2.php';
    exit;
}

/* Keep balance_sheet_prepared/notes_prepared in sync here too -- this page
   is a third entry point onto the same workflow flags financials.php and
   reports.php maintain, and directors_report_prepared should never be
   markable "done" while the statements it quotes figures from (PAT, net
   worth, Financial Performance table) are themselves invalid or unbalanced. */
$hasReportData = (bool) ($fs['has_data'] ?? false);
$currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
$noteCompleteness = $fs['validation']['note_completeness'] ?? ['missing' => [], 'is_complete' => true];
$validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);
syncWorkflowFromValidation($pdo, $company_id, $fy_id, $hasReportData, $validationResult, $currentDiff, $noteCompleteness);
$workflow = getWorkflow($company_id, $fy_id);
$statementsReady = $hasReportData
    && ($workflow['balance_sheet_prepared'] ?? 0) == 1
    && ($workflow['notes_prepared'] ?? 0) == 1;

$loadedDirectorsReport = loadDirectorsReportSections($manualBundle, $fs, $companyName, $fyName, $shareholders);
$sectionDefinitions = $loadedDirectorsReport['definitions'];
$draftSections = $loadedDirectorsReport['sections'];

$hasSavedSections = array_filter($draftSections, static fn ($value) => trim((string) $value) !== '') !== [];
$draft = (string) ($manualBundle['saved_current']['directors_report_draft'] ?? '');
$draftSource = $hasSavedSections ? 'Saved Draft' : ($draft !== '' ? 'Saved Draft' : 'Not Generated');
$infoMessage = '';

$directorsReportPlace = trim((string) ($manualBundle['saved_current']['directors_report_place'] ?? ''));
$directorsReportDate = trim((string) ($manualBundle['saved_current']['directors_report_date'] ?? ''));
if ($directorsReportDate === '') {
    $directorsReportDate = date('d.m.Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = (string) ($_POST['directors_report_action'] ?? '');

    if ($action === 'generate_ai') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0 && !hasFeature($userId, 'directors_report_ai', $pdo)) {
            $infoMessage = 'AI draft is not available on your current plan. Upgrade to use AI features.';
        } else {
            $generated = generateDirectorsReportDraft($fs, $companyName, $fyName, $shareholders);
            $draft = (string) ($generated['draft'] ?? '');
            $draftSections = $generated['sections'] ?? $draftSections;
            $draftSource = (string) ($generated['source'] ?? 'Built-in Draft');
            $infoMessage = (string) ($generated['message'] ?? '');
        }
    } elseif ($action === 'save') {
        foreach ($sectionDefinitions as $key => $title) {
            $draftSections[$key] = trim((string) ($_POST['directors_report_' . $key] ?? ''));
        }
        $directorsReportPlace = trim((string) ($_POST['directors_report_place'] ?? ''));
        $directorsReportDate = trim((string) ($_POST['directors_report_date'] ?? '')) ?: date('d.m.Y');
        $draft = combineDirectorsReportSections($draftSections, $companyName);
        $payload = [
            'directors_report_draft' => $draft,
            'directors_report_place' => $directorsReportPlace,
            'directors_report_date' => $directorsReportDate,
        ];
        foreach ($draftSections as $key => $value) {
            $payload['directors_report_' . $key] = $value;
        }
        saveManualInputs($pdo, $company_id, $fy_id, $payload);
        $draftSource = 'Saved Draft';
        if ($statementsReady) {
            updateWorkflow($company_id, $fy_id, 'directors_report_prepared');
            $infoMessage = 'Directors report draft saved and finalised.';
        } else {
            $infoMessage = 'Draft saved, but not finalised -- the Balance Sheet must balance and Notes must be complete before this report can be marked ready for delivery.';
        }
    }
}

require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['label' => 'Directors Report']
]) ?>

<?= uiPageHero('Directors Report') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => '',
    'profile' => 0,
    'status' => '',
    'edit_url' => '',
]) ?>

<?php if ($infoMessage !== ''): ?>
    <div class="card section-card"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>

<?php if (!$statementsReady): ?>
    <div class="card section-card" style="background:#fffbeb;border:1px solid #fcd34d;">
        <strong style="color:#92400e;">Not yet finalisable:</strong>
        <span style="color:#475569;">The Balance Sheet must balance and Notes must be complete before this Directors' Report can be marked ready for download/delivery. You can still draft and save it below.</span>
        <a href="<?= BASE_URL ?>reports.php#balance-sheet" style="margin-left:6px;">Review Financial Statements &rarr;</a>
    </div>
<?php endif; ?>

<div class="card section-card">
    Build the corporate directors report from the prepared financial statements. Use the AI draft option for a first version, then review and finalise before issue.
</div>

<div class="draft-box">
    <div class="draft-actions">
        <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="directors_report_action" value="generate_ai">
            <button class="btn-primary" type="submit">Generate AI Draft</button>
        </form>
        <a class="btn" href="<?= BASE_URL ?>reports.php#balance-sheet">Back To Financial Statements</a>
    </div>

    <div class="draft-meta">
        Draft source: <strong><?= htmlspecialchars($draftSource) ?></strong>
    </div>

    <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="directors_report_action" value="save">
        <?php foreach ($sectionDefinitions as $key => $title): ?>
            <div class="form-group">
                <label for="directors_report_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($title) ?></label>
                <textarea id="directors_report_<?= htmlspecialchars($key) ?>" name="directors_report_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars((string) ($draftSections[$key] ?? '')) ?></textarea>
            </div>
        <?php endforeach; ?>
        <div class="form-group">
            <label for="directors_report_place">Place (Signature Block)</label>
            <input type="text" id="directors_report_place" name="directors_report_place" value="<?= htmlspecialchars($directorsReportPlace) ?>" placeholder="e.g. Chennai">
        </div>
        <div class="form-group">
            <label for="directors_report_date">Date (Signature Block)</label>
            <input type="text" id="directors_report_date" name="directors_report_date" value="<?= htmlspecialchars($directorsReportDate) ?>" placeholder="DD.MM.YYYY">
        </div>
        <button class="btn-primary" type="submit">Save Directors Report Draft</button>
    </form>
</div>

<div class="preview-box">
    <h3>Frozen Preview</h3>
    <?php
    $sections = $draftSections;
    $company_meta = $fs['company_meta'] ?? [];
    $data = $fs['data'] ?? [];
    include __DIR__ . '/reports_dashboard/formats/directors_report_company.php';
    ?>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
