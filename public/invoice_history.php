<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/invoice_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

ensurePlanTables($pdo);
ensureInvoiceTables($pdo);

$invoices = getInvoicesForUser($pdo, $userId);

$page_title = 'Invoice History';
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Billing', 'href' => BASE_URL . 'invoice_history.php'],
    ['label' => 'Invoices']
]) ?>

<?= uiPageHero('Invoice History') ?>

<style>
.invoice-grid { display:grid; grid-template-columns:1fr; gap:18px; }
.invoice-empty { text-align:center; padding:48px 24px; background:linear-gradient(180deg, #ffffff 0%, #f9fcfb 100%); border:1px solid #d7e6e3; border-radius:18px; box-shadow:0 18px 40px rgba(17, 94, 89, 0.08); }
.invoice-empty-icon { width:64px; height:64px; background:rgba(15, 118, 110, 0.08); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; color:#0f766e; }
.invoice-empty h3 { font-size:18px; color:#17312f; margin-bottom:6px; }
.invoice-empty p { font-size:14px; color:#5f7a77; margin-bottom:16px; }
.invoice-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px; }
.invoice-summary-card { background:linear-gradient(180deg, #ffffff 0%, #f8fcfb 100%); padding:18px 20px; border-radius:18px; box-shadow:0 18px 40px rgba(17, 94, 89, 0.08); border:1px solid #d7e6e3; }
.invoice-summary-number { font-size:28px; font-weight:800; color:#0f766e; margin-bottom:4px; }
.invoice-summary-label { font-size:13px; color:#5f7a77; }
.invoice-status { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.invoice-status.paid { background:rgba(31, 146, 84, 0.1); color:#1f9254; }
.invoice-status.issued { background:rgba(15, 118, 110, 0.1); color:#0f766e; }
.invoice-status.draft { background:rgba(107, 114, 128, 0.1); color:#6b7280; }
.invoice-status.cancelled { background:rgba(192, 57, 43, 0.1); color:#c0392b; }
@media (max-width: 768px) {
    .invoice-summary { grid-template-columns:1fr; }
}
</style>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<?php if (empty($invoices)): ?>
    <div class="invoice-empty">
        <div class="invoice-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="32" height="32"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <h3>No Invoices Yet</h3>
        <p>You don't have any invoices at the moment. Invoices are generated when you purchase or renew a plan.</p>
        <a class="btn" href="<?= BASE_URL ?>upgrade.php">View Plans</a>
    </div>
<?php else: ?>
    <?php
    $totalPaid = 0;
    $totalCount = count($invoices);
    $paidCount = 0;
    foreach ($invoices as $inv) {
        if ($inv['status'] === 'paid' || $inv['status'] === 'issued') {
            $totalPaid += (int) ($inv['total_value'] ?? 0);
            $paidCount++;
        }
    }
    ?>
    <div class="invoice-summary">
        <div class="invoice-summary-card">
            <div class="invoice-summary-number"><?= $totalCount ?></div>
            <div class="invoice-summary-label">Total Invoices</div>
        </div>
        <div class="invoice-summary-card">
            <div class="invoice-summary-number"><?= $paidCount ?></div>
            <div class="invoice-summary-label">Paid / Issued</div>
        </div>
        <div class="invoice-summary-card">
            <div class="invoice-summary-number">Rs.<?= number_format($totalPaid / 100) ?></div>
            <div class="invoice-summary-label">Total Amount</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Plan</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <?php
                $planStmt = $pdo->prepare("SELECT name FROM plans WHERE id = ?");
                $planStmt->execute([(int) $invoice['plan_id']]);
                $planName = $planStmt->fetchColumn() ?: 'N/A';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($invoice['invoice_date']))) ?></td>
                    <td><?= htmlspecialchars($planName) ?></td>
                    <td>Rs.<?= number_format((int) $invoice['total_value'] / 100) ?></td>
                    <td>
                        <span class="invoice-status <?= htmlspecialchars($invoice['status']) ?>">
                            <?= htmlspecialchars(ucfirst($invoice['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($invoice['status'] === 'issued' || $invoice['status'] === 'paid'): ?>
                            <a class="btn" href="<?= BASE_URL ?>invoice_download.php?invoice_number=<?= urlencode($invoice['invoice_number']) ?>" style="padding:6px 14px;font-size:12px;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download PDF
                            </a>
                        <?php else: ?>
                            <span class="status" style="padding:4px 10px;font-size:11px;">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
unset($_SESSION['error']);
unset($_SESSION['success']);
require_once __DIR__ . '/layouts/footer_v2.php';
?>
