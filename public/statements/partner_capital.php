<?php
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/engines/fs_engine.php';
require_once __DIR__ . '/../../app/engines/classification_engine.php';
require_once __DIR__ . '/../../app/helpers/entity_master_helper.php';
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
require_once __DIR__ . '/../../app/helpers/fy_closure_helper.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';

requireFullContext();
ensurePartnerCapitalSchema($pdo);

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$entityCategory = getEntityCategory($pdo, $company_id);
$entitySubcategory = getEntitySubcategory($pdo, $company_id);
$isPartnerEligible = $entityCategory === 'llp' || $entitySubcategory === 'partnership';

$page_title = "Partners' Capital Schedule";
$error = '';
$success = '';

if ($isPartnerEligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_partner') {
        $partnerName = trim((string) ($_POST['partner_name'] ?? ''));
        if ($partnerName === '') {
            $error = 'Partner name is required.';
        } else {
            $ownerId = getOwnerUserId($pdo, $userId); // resolves to the workspace owner for staff sub-accounts, matching createDirector()'s convention
            $partnerId = createPartner($pdo, $ownerId, [
                'partner_name' => $partnerName,
                'pan' => trim((string) ($_POST['pan'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'mobile' => trim((string) ($_POST['mobile'] ?? '')),
            ]);
            addCompanyPartner($pdo, $company_id, $partnerId, [
                'designation' => trim((string) ($_POST['designation'] ?? '')) ?: 'Partner',
                'appointment_date' => trim((string) ($_POST['appointment_date'] ?? '')) ?: null,
            ]);
            $success = 'Partner added.';
        }
    } elseif ($action === 'save_schedule') {
        $partnerIds = array_map('intval', (array) ($_POST['partner_id'] ?? []));
        foreach ($partnerIds as $pid) {
            if ($pid <= 0) {
                continue;
            }
            savePartnerCapitalMovement($pdo, $company_id, $fy_id, $pid, [
                'share_percentage' => $_POST['share_percentage'][$pid] ?? 0,
                'opening_balance' => $_POST['opening_balance'][$pid] ?? 0,
                'capital_introduced' => $_POST['capital_introduced'][$pid] ?? 0,
                'remuneration' => $_POST['remuneration'][$pid] ?? 0,
                'interest_on_capital' => $_POST['interest_on_capital'][$pid] ?? 0,
                'withdrawals' => $_POST['withdrawals'][$pid] ?? 0,
            ], $userId > 0 ? $userId : null);
        }
        $success = 'Capital schedule saved.';
    }
}

require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Financial Statements', 'href' => BASE_URL . 'statements/financials.php'],
    ['label' => "Partners' Capital Schedule"],
]) ?>

<?= uiPageHero("Partners' Capital Schedule", 'Per-partner opening balance, contributions, remuneration, interest, drawings and share of profit — matches the ICAI illustrative format (Note 3a) for LLP and Partnership entities.') ?>

<?= uiContextCard([
    'company' => htmlspecialchars((string) $companyName),
    'fy' => htmlspecialchars((string) $fyName),
]) ?>

<?php if (!$isPartnerEligible): ?>
    <div class="card error-box">
        <p>The Partners' Capital Schedule applies to LLP and Partnership entities only. This company is classified as
            "<?= htmlspecialchars($entityCategory === 'non_corporate' ? $entitySubcategory : $entityCategory) ?>".</p>
        <a class="btn" href="<?= BASE_URL ?>statements/financials.php">Back to Financial Statements</a>
    </div>
<?php else:
    $classified = getClassifiedData($pdo, $company_id, $fy_id);
    $totalProfit = (float) ($classified['summary']['profit'] ?? 0);

    $prevFyId = getPreviousFYId($pdo, $company_id, $fy_id);
    $schedule = getPartnerCapitalSchedule($pdo, $company_id, $fy_id, $prevFyId);
    $totalSharePercent = array_sum(array_column($schedule, 'share_percentage'));
?>

    <?php if ($error !== ''): ?><div class="error-box"><p><?= htmlspecialchars($error) ?></p></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="success-box"><p><?= htmlspecialchars($success) ?></p></div><?php endif; ?>

    <?= uiKpiCards([
        ['value' => count($schedule), 'label' => 'Partners'],
        ['value' => format_inr_number($totalProfit), 'label' => 'Profit for the Year'],
        ['value' => number_format($totalSharePercent, 2) . '%', 'label' => 'Total Profit Share Allocated'],
    ]) ?>

    <?php if (!empty($schedule) && abs($totalSharePercent - 100.0) > 0.01): ?>
        <div class="card" style="border-left: 3px solid #d97706; margin-bottom: 16px;">
            <p style="margin:0;">Profit-share percentages total <?= number_format($totalSharePercent, 2) ?>%, not 100%.
                Each partner's "Share of Profit" below is computed as Profit for the Year &times; their %, so the schedule
                will not fully allocate the year's profit until this is corrected.</p>
        </div>
    <?php endif; ?>

    <?php if (empty($schedule)): ?>
        <div class="card">
            <p>No partners added yet for this company. Add the first partner below.</p>
        </div>
    <?php else: ?>
    <form method="post" id="scheduleForm">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="save_schedule">
        <div class="card" style="overflow-x: auto; margin-bottom: 16px;">
            <table border="1" cellpadding="8" cellspacing="0" width="100%">
                <tr>
                    <th>Partner</th>
                    <th>Share %</th>
                    <th>Opening Balance</th>
                    <th>Capital Introduced</th>
                    <th>Remuneration</th>
                    <th>Interest on Capital</th>
                    <th>Withdrawals</th>
                    <th>Share of Profit</th>
                    <th>Closing Balance</th>
                </tr>
                <?php foreach ($schedule as $row):
                    $shareOfProfit = $totalProfit * ($row['share_percentage'] / 100);
                    $closing = partnerCapitalClosingBalance([
                        'opening_balance' => $row['opening_balance'],
                        'capital_introduced' => $row['capital_introduced'],
                        'remuneration' => $row['remuneration'],
                        'interest_on_capital' => $row['interest_on_capital'],
                        'withdrawals' => $row['withdrawals'],
                    ], $shareOfProfit);
                    $pid = $row['partner_id'];
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($row['partner_name']) ?>
                        <div style="font-size:0.8em;color:#64748b;"><?= htmlspecialchars($row['designation']) ?></div>
                        <input type="hidden" name="partner_id[]" value="<?= $pid ?>">
                    </td>
                    <td><input type="number" step="0.01" name="share_percentage[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['share_percentage']) ?>" style="width:80px;"></td>
                    <td><input type="number" step="0.01" name="opening_balance[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['opening_balance']) ?>" style="width:110px;"></td>
                    <td><input type="number" step="0.01" name="capital_introduced[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['capital_introduced']) ?>" style="width:110px;"></td>
                    <td><input type="number" step="0.01" name="remuneration[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['remuneration']) ?>" style="width:110px;"></td>
                    <td><input type="number" step="0.01" name="interest_on_capital[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['interest_on_capital']) ?>" style="width:110px;"></td>
                    <td><input type="number" step="0.01" name="withdrawals[<?= $pid ?>]" value="<?= htmlspecialchars((string) $row['withdrawals']) ?>" style="width:110px;"></td>
                    <td style="text-align:right;"><?= format_inr_number($shareOfProfit) ?></td>
                    <td style="text-align:right;font-weight:600;"><?= format_inr_number($closing) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <button class="btn btn-primary" type="submit">Save Capital Schedule</button>
    </form>
    <?php endif; ?>

    <div class="card" style="margin-top:24px;">
        <h3>Add a Partner</h3>
        <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="add_partner">
            <div class="form-group">
                <label for="partner_name">Name</label>
                <input id="partner_name" name="partner_name" type="text" required>
            </div>
            <div class="form-group">
                <label for="designation">Designation</label>
                <select id="designation" name="designation">
                    <option value="Partner">Partner</option>
                    <?php if ($entityCategory === 'llp'): ?>
                        <option value="Designated Partner">Designated Partner</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="pan">PAN</label>
                <input id="pan" name="pan" type="text" maxlength="10">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input id="email" name="email" type="email">
            </div>
            <div class="form-group">
                <label for="mobile">Mobile</label>
                <input id="mobile" name="mobile" type="text">
            </div>
            <div class="form-group">
                <label for="appointment_date">Appointment Date</label>
                <input id="appointment_date" name="appointment_date" type="date">
            </div>
            <button class="btn" type="submit">Add Partner</button>
        </form>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
