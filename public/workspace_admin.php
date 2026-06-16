<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

$page_title = 'Workspace Admin';
$errors = [];
$messages = [];

ensurePlanTables($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!isSuperAdmin($pdo, $userId)) {
    $_SESSION['error'] = 'Workspace Admin is restricted to superadmin access only.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

$clientAdmins = listClientAdmins($pdo);
$selectedWorkspaceId = (int) ($_GET['workspace_user_id'] ?? $_POST['workspace_user_id'] ?? ($clientAdmins[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['workspace_action'] ?? '');

    if ($action === 'create_client_admin') {
        $name = trim((string) ($_POST['client_admin_name'] ?? ''));
        $email = trim((string) ($_POST['client_admin_email'] ?? ''));
        $password = (string) ($_POST['client_admin_password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            $errors[] = 'Client admin name, email, and password are required.';
        } else {
            try {
                $selectedWorkspaceId = createClientAdmin($pdo, $name, $email, $password);
                $messages[] = 'Client admin created successfully.';
                $clientAdmins = listClientAdmins($pdo);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to create client admin.';
            }
        }
    }

    if ($action === 'assign_plan') {
        $planCode = trim((string) ($_POST['plan_code'] ?? ''));
        $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
        $recordBilling = ((string) ($_POST['record_billing'] ?? '1')) === '1';
        $transactionType = trim((string) ($_POST['transaction_type'] ?? 'renewal'));
        $paymentStatus = trim((string) ($_POST['payment_status'] ?? 'paid'));
        $billedAt = trim((string) ($_POST['billed_at'] ?? date('Y-m-d')));
        $amountInr = (int) ($_POST['amount_inr'] ?? 0);
        $billingNotes = trim((string) ($_POST['billing_notes'] ?? ''));

        if ($selectedWorkspaceId <= 0) {
            $errors[] = 'Select a client workspace first.';
        } elseif ($planCode === '' || $expiresAt === '') {
            $errors[] = 'Plan and expiry date are required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $errors[] = 'Expiry date must be in YYYY-MM-DD format.';
        } elseif ($recordBilling && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $billedAt)) {
            $errors[] = 'Billing date must be in YYYY-MM-DD format.';
        } else {
            try {
                upsertWorkspaceLicense(
                    $pdo,
                    $selectedWorkspaceId,
                    $planCode,
                    $expiresAt,
                    $recordBilling,
                    $transactionType,
                    $paymentStatus,
                    $billedAt,
                    $amountInr,
                    $billingNotes
                );
                $messages[] = $recordBilling
                    ? 'Workspace plan and billing entry updated successfully.'
                    : 'Workspace plan updated successfully.';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update the workspace plan.';
            }
        }
    }
}

$workspaceUserId = $selectedWorkspaceId > 0 ? $selectedWorkspaceId : 0;
$ownerId = $workspaceUserId > 0 ? getOwnerUserId($pdo, $workspaceUserId) : 0;
$ownerUser = $ownerId > 0 ? getUserById($pdo, $ownerId) : null;
$planUsage = $workspaceUserId > 0 ? getPlanUsage($pdo, $workspaceUserId) : null;
$catalog = getPlanCatalog($pdo);
$workspaceUsers = $workspaceUserId > 0 ? listWorkspaceUsers($pdo, $workspaceUserId) : [];
$workspaceCompanies = $workspaceUserId > 0 ? listWorkspaceCompanies($pdo, $workspaceUserId) : [];
$activeLicense = ($workspaceUserId > 0 && $ownerId > 0) ? getActiveLicense($pdo, $ownerId) : null;
$selectedPlanCode = (string) ($activeLicense['plan'] ?? ($catalog[0]['code'] ?? 'starter'));
$selectedExpiry = (string) ($activeLicense['expires_at'] ?? date('Y-m-d', strtotime('+1 year')));
$selectedPlanPrice = 0;

foreach ($catalog as $catalogPlan) {
    if ((string) $catalogPlan['code'] === $selectedPlanCode) {
        $selectedPlanPrice = (int) ($catalogPlan['price_inr'] ?? 0);
        break;
    }
}

require_once __DIR__ . '/layouts/header.php';
?>

<div class="page-title">Workspace Admin</div>

<?php foreach ($messages as $message): ?>
    <div class="success-box"><p><?= htmlspecialchars($message) ?></p></div>
<?php endforeach; ?>

<?php if ($errors !== []): ?>
    <div class="error-box">
        <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.simple-stack { display:flex; flex-direction:column; gap:16px; }
.simple-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.simple-form { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:14px; }
.simple-form .full { grid-column:1 / -1; }
.simple-form label { display:block; margin-bottom:6px; font-weight:600; }
.simple-form input,
.simple-form select,
.simple-form textarea {
    width:100%;
    padding:10px 12px;
    border:1px solid #cfd8e3;
    border-radius:8px;
    box-sizing:border-box;
}
.simple-form textarea { min-height:88px; resize:vertical; }
.mini-stats { display:grid; grid-template-columns:repeat(4, minmax(120px, 1fr)); gap:12px; margin-top:14px; }
.mini-stat { background:#f8fbff; border:1px solid #d8e2ef; border-radius:12px; padding:12px; }
.mini-stat strong { display:block; margin-top:8px; font-size:20px; color:#0f172a; }
.simple-table { width:100%; border-collapse:collapse; margin-top:12px; }
.simple-table th, .simple-table td { border:1px solid #dbe3ef; padding:10px 12px; text-align:left; }
.simple-table th { background:#eff6fb; color:#334155; }
.muted { color:#64748b; }
.compact-list { margin:10px 0 0 18px; line-height:1.7; }
.billing-box { padding:14px; border:1px dashed #cfd8e3; border-radius:12px; background:#fafcfe; }
@media (max-width: 960px) {
    .simple-grid, .simple-form, .mini-stats { grid-template-columns:1fr; }
}
</style>

<div class="simple-stack">
    <div class="card section-card">
        <strong>1. Select Client Workspace</strong>
        <p class="muted">Choose the client admin account you want to manage.</p>
        <form method="get" class="simple-form" style="background:none; border:0; box-shadow:none; padding:0; max-width:none;">
            <div class="full">
                <label for="workspace_user_id">Client Workspace</label>
                <select id="workspace_user_id" name="workspace_user_id" onchange="this.form.submit()">
                    <?php if ($clientAdmins === []): ?>
                        <option value="0">No client admin found yet</option>
                    <?php else: ?>
                        <?php foreach ($clientAdmins as $clientAdmin): ?>
                            <option value="<?= (int) $clientAdmin['id'] ?>" <?= $selectedWorkspaceId === (int) $clientAdmin['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($clientAdmin['name'] . ' - ' . $clientAdmin['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </form>
    </div>

    <div class="simple-grid">
        <div class="card section-card">
            <strong>2. Create Client Admin</strong>
            <p class="muted">Create a new customer admin account.</p>
            <form method="post" class="simple-form" style="background:none; border:0; box-shadow:none; padding:0; max-width:none;">
                <?= csrfInput() ?>
                <input type="hidden" name="workspace_action" value="create_client_admin">
                <div class="full">
                    <label for="client_admin_name">Name</label>
                    <input id="client_admin_name" name="client_admin_name" type="text">
                </div>
                <div>
                    <label for="client_admin_email">Email</label>
                    <input id="client_admin_email" name="client_admin_email" type="email">
                </div>
                <div>
                    <label for="client_admin_password">Temporary Password</label>
                    <input id="client_admin_password" name="client_admin_password" type="password">
                </div>
                <div class="full">
                    <button class="btn-primary" type="submit">Create Client Admin</button>
                </div>
            </form>
        </div>

        <div class="card section-card">
            <strong>3. Assign / Renew Plan</strong>
            <p class="muted">Set the commercial plan and billing event for the selected workspace.</p>
            <form method="post" class="simple-form" style="background:none; border:0; box-shadow:none; padding:0; max-width:none;">
                <?= csrfInput() ?>
                <input type="hidden" name="workspace_action" value="assign_plan">
                <input type="hidden" name="workspace_user_id" value="<?= (int) $selectedWorkspaceId ?>">
                <div class="full">
                    <label for="plan_code">Plan</label>
                    <select id="plan_code" name="plan_code" required data-plan-selector>
                        <?php foreach ($catalog as $plan): ?>
                            <option value="<?= htmlspecialchars($plan['code']) ?>" data-price="<?= (int) $plan['price_inr'] ?>" <?= $selectedPlanCode === $plan['code'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($plan['name']) ?> - Rs.<?= number_format((int) $plan['price_inr']) ?>/year
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="full">
                    <label for="expires_at">Expiry Date</label>
                    <input type="date" id="expires_at" name="expires_at" value="<?= htmlspecialchars($selectedExpiry) ?>" required>
                </div>
                <div class="full billing-box">
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                        <input type="checkbox" name="record_billing" value="1" checked>
                        Record billing event for revenue tracking
                    </label>
                    <div class="simple-form" style="background:none; border:0; box-shadow:none; padding:0; max-width:none; margin-top:0;">
                        <div>
                            <label for="transaction_type">Billing Type</label>
                            <select id="transaction_type" name="transaction_type">
                                <option value="new_sale">New Sale</option>
                                <option value="renewal" selected>Renewal</option>
                                <option value="upgrade">Upgrade</option>
                                <option value="downgrade">Downgrade</option>
                                <option value="manual_adjustment">Manual Adjustment</option>
                            </select>
                        </div>
                        <div>
                            <label for="payment_status">Payment Status</label>
                            <select id="payment_status" name="payment_status">
                                <option value="paid" selected>Paid</option>
                                <option value="pending">Pending</option>
                                <option value="waived">Waived</option>
                                <option value="refunded">Refunded</option>
                            </select>
                        </div>
                        <div>
                            <label for="billed_at">Billing Date</label>
                            <input type="date" id="billed_at" name="billed_at" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                        </div>
                        <div>
                            <label for="amount_inr">Amount (INR)</label>
                            <input type="number" id="amount_inr" name="amount_inr" min="0" step="1" value="<?= $selectedPlanPrice ?>">
                        </div>
                        <div class="full">
                            <label for="billing_notes">Notes</label>
                            <textarea id="billing_notes" name="billing_notes" placeholder="Optional internal note for this billing entry"></textarea>
                        </div>
                    </div>
                </div>
                <div class="full">
                    <button class="btn-primary" type="submit">Save Plan</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card section-card">
        <strong>Workspace Summary</strong><br>
        <?php if ($ownerUser): ?>
            Owner: <?= htmlspecialchars((string) $ownerUser['name']) ?><br>
            Email: <?= htmlspecialchars((string) $ownerUser['email']) ?><br>
            Plan: <?= htmlspecialchars((string) ($planUsage['plan_name'] ?? 'No Active Plan')) ?><?php if (!empty($planUsage['expires_at'])): ?> · Expires <?= htmlspecialchars((string) $planUsage['expires_at']) ?><?php endif; ?>

            <div class="mini-stats">
                <div class="mini-stat">
                    <span>Companies</span>
                    <strong><?= (int) ($planUsage['companies_used'] ?? 0) ?> / <?= (int) ($planUsage['company_limit'] ?? 0) ?></strong>
                </div>
                <div class="mini-stat">
                    <span>Users</span>
                    <strong><?= (int) ($planUsage['users_used'] ?? 0) ?> / <?= (int) ($planUsage['user_limit'] ?? 0) ?></strong>
                </div>
                <div class="mini-stat">
                    <span>AI</span>
                    <strong><?= !empty($planUsage['ai_enabled']) ? 'Enabled' : 'Disabled' ?></strong>
                </div>
                <div class="mini-stat">
                    <span>Annual Price</span>
                    <strong>Rs.<?= number_format((int) ($planUsage['price_inr'] ?? 0)) ?></strong>
                </div>
            </div>
        <?php else: ?>
            <p class="muted">Select or create a client workspace to see the details.</p>
        <?php endif; ?>
    </div>

    <div class="simple-grid">
        <div class="card section-card">
            <strong>Workspace Users</strong>
            <table class="simple-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($workspaceUsers === []): ?>
                        <tr><td colspan="3">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workspaceUsers as $workspaceUser): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $workspaceUser['name']) ?></td>
                                <td><?= htmlspecialchars((string) $workspaceUser['email']) ?></td>
                                <td><?= htmlspecialchars(ucfirst((string) $workspaceUser['role'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card section-card">
            <strong>Workspace Companies</strong>
            <table class="simple-table">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Category</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($workspaceCompanies === []): ?>
                        <tr><td colspan="2">No companies found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workspaceCompanies as $workspaceCompany): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $workspaceCompany['name']) ?></td>
                                <td><?= htmlspecialchars((string) $workspaceCompany['category']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card section-card">
        <strong>Plan Reference</strong>
        <ul class="compact-list">
            <?php foreach ($catalog as $plan): ?>
                <li>
                    <?= htmlspecialchars($plan['name']) ?>:
                    Rs.<?= number_format((int) $plan['price_inr']) ?>/year,
                    <?= (int) $plan['company_limit'] >= 999 ? 'Unlimited companies' : ((int) $plan['company_limit'] . ' companies') ?>,
                    <?= (int) $plan['user_limit'] ?> users,
                    <?= (int) $plan['ai_enabled'] === 1 ? 'AI enabled' : 'AI disabled' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const planSelect = document.querySelector('[data-plan-selector]');
    const amountInput = document.getElementById('amount_inr');

    if (!planSelect || !amountInput) {
        return;
    }

    const syncAmount = function () {
        const selected = planSelect.options[planSelect.selectedIndex];
        amountInput.value = selected ? (selected.dataset.price || '0') : '0';
    };

    planSelect.addEventListener('change', syncAmount);
    syncAmount();
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
