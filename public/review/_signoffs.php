<?php
/**
 * Sign-Off Panel - Sprint 4C
 * Three-role sign-off: Staff, Manager, Partner
 * Expects: $pdo, $company_id, $fy_id
 */
if (!isset($pdo) || !isset($company_id) || !isset($fy_id)) return;

function getSignoffData($pdo, $companyId, $fyId) {
    require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
    return loadManualInputsByPrefix($pdo, (int) $companyId, (int) $fyId, 'signoff_');
}

$signoffs = getSignoffData($pdo, $company_id, $fy_id);
$roles = [
    ['key' => 'staff', 'label' => 'Staff', 'description' => 'Data entry and statement preparation'],
    ['key' => 'manager', 'label' => 'Manager', 'description' => 'Accuracy and completeness review'],
    ['key' => 'partner', 'label' => 'Partner', 'description' => 'Authorisation for delivery'],
];

if (!function_exists('reviewCanRenderSignoffAction')) {
    function reviewCanRenderSignoffAction(string $role, array $signoffs): bool {
        $currentRole = strtolower((string) ($_SESSION['user_role'] ?? ''));
        if (in_array($currentRole, ['admin', 'superadmin'], true)) return true;
        if ($currentRole !== $role) return false;
        if ($role === 'manager' && empty($signoffs['signoff_staff_by'])) return false;
        if ($role === 'partner' && (empty($signoffs['signoff_staff_by']) || empty($signoffs['signoff_manager_by']))) return false;
        return true;
    }
}
?>

<?php foreach ($roles as $role): ?>
<?php
    $signedBy = $signoffs['signoff_' . $role['key'] . '_by'] ?? '';
    $signedAt = $signoffs['signoff_' . $role['key'] . '_at'] ?? '';
    $isSigned = $signedBy !== '';
?>
<div class="rw-signoff-card">
    <div class="rw-signoff-role">
        <div class="rw-role-label"><?= $role['label'] ?></div>
        <div class="rw-role-name"><?= $role['description'] ?></div>
    </div>
    <div class="rw-signoff-status">
        <?php if ($isSigned): ?>
        <div class="rw-signed-info">
            <span class="rw-name">Signed by user #<?= htmlspecialchars($signedBy) ?></span>
            <?php if ($signedAt): ?><br><span class="rw-time"><?= htmlspecialchars($signedAt) ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="color:var(--muted,#6b7280);font-size:0.85rem;">Not signed</div>
        <?php endif; ?>
    </div>
    <span class="rw-signoff-badge <?= $isSigned ? 'signed' : 'pending' ?>"><?= $isSigned ? 'Signed' : 'Pending' ?></span>
    <div class="rw-signoff-actions">
        <?php if ($isSigned && reviewCanRenderSignoffAction($role['key'], $signoffs)): ?>
            <button type="button" class="btn btn-sm rw-revoke-btn" data-role="<?= $role['key'] ?>">Revoke</button>
        <?php elseif (!$isSigned && reviewCanRenderSignoffAction($role['key'], $signoffs)): ?>
            <button type="button" class="btn btn-sm btn-primary rw-signoff-btn" data-role="<?= $role['key'] ?>" data-action="sign">Sign as <?= $role['label'] ?></button>
        <?php else: ?>
            <span style="font-size:0.78rem;color:var(--muted,#6b7280);">Restricted</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
