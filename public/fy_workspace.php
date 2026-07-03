<?php
/**
 * e-BAL V2 — Financial Year Console
 *
 * Lifecycle console with:
 * - FY Manager (create, select, edit FYs)
 * - Closure Readiness Panel (10-check engine)
 * - FY Closure Wizard (4-step guided flow)
 * - Post-Closure prompts (Create Next FY, Carry Forward)
 * - Opening Balance Register (detailed view)
 * - Audit Trail
 */
$page_title = 'Financial Year Console';

/* ---- Bootstrap before any output ---- */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';
require_once __DIR__ . '/../app/helpers/workflow_navigation_helper.php';
require_once __DIR__ . '/../app/helpers/fy_closure_helper.php';
require_once __DIR__ . '/../app/helpers/closure_readiness_helper.php';
require_once __DIR__ . '/../app/helpers/financial_year_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$entityId = (int) ($_GET['entity_id'] ?? 0);
$fyId = (int) ($_GET['fy_id'] ?? 0);

/* ---- Handle POST actions (before any output) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'close_fy' && $entityId > 0 && $fyId > 0) {
        if (!userCanCloseFY($pdo, $userId)) {
            $_SESSION['fy_console_error'] = 'You do not have permission to close financial years.';
        } else {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $confirmed = (string) ($_POST['confirm_final'] ?? '');
            if ($confirmed !== '1') {
                $_SESSION['fy_console_error'] = 'Please confirm that all financial statements are final before closing.';
            } else {
                $result = closeFinancialYear($pdo, $entityId, $fyId, $userId, $reason);
                if ($result['success']) {
                    $_SESSION['fy_console_message'] = $result['message'];
                    $_SESSION['fy_console_closure_just_happened'] = '1';
                } else {
                    $_SESSION['fy_console_error'] = $result['message'];
                }
            }
        }
        header("Location: fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $fyId);
        exit;
    }

    if ($postAction === 'create_next_fy' && $entityId > 0 && $fyId > 0) {
        $currentFy = null;
        $cfStmt = $pdo->prepare("SELECT fy_label, fy_end FROM financial_years WHERE id = ? AND company_id = ?");
        $cfStmt->execute([$fyId, $entityId]);
        $currentFy = $cfStmt->fetch(PDO::FETCH_ASSOC);

        if ($currentFy) {
            $currentEnd = (string) ($currentFy['fy_end'] ?? '');
            $nextStartYear = (int) date('Y', strtotime($currentEnd . ' +1 day'));
            $nextLabel = $nextStartYear . '-' . ($nextStartYear + 1);

            $dupStmt = $pdo->prepare("SELECT id FROM financial_years WHERE company_id = ? AND fy_label = ?");
            $dupStmt->execute([$entityId, $nextLabel]);
            if ($dupStmt->fetch()) {
                $_SESSION['fy_console_error'] = "Financial Year {$nextLabel} already exists.";
            } else {
                try {
                    $result = ensureFinancialYearRecord($pdo, $entityId, $nextLabel);
                    $newFyId = is_array($result) ? (int) ($result['id'] ?? 0) : (int) $result;
                    if ($newFyId > 0) {
                        $_SESSION['fy_console_message'] = "Financial Year {$nextLabel} created successfully.";
                        $_SESSION['fy_console_next_fy_created'] = $newFyId;
                        header("Location: fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $newFyId);
                        exit;
                    } else {
                        $_SESSION['fy_console_error'] = 'Financial Year was created but could not be opened.';
                    }
                } catch (Throwable $e) {
                    $_SESSION['fy_console_error'] = 'Failed to create Financial Year: ' . $e->getMessage();
                }
            }
        }
        header("Location: fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $fyId);
        exit;
    }

    if ($postAction === 'carry_forward' && $entityId > 0 && $fyId > 0) {
        if (!userCanCloseFY($pdo, $userId)) {
            $_SESSION['fy_console_error'] = 'You do not have permission to carry forward opening balances.';
        } else {
            $prevFyIdForCf = getPreviousFYId($pdo, $entityId, $fyId);
            if ($prevFyIdForCf !== null) {
                $obsCheck = $pdo->prepare("SELECT COUNT(*) FROM fy_opening_balance_sources WHERE company_id = ? AND fy_id = ?");
                $obsCheck->execute([$entityId, $fyId]);
                if ((int) $obsCheck->fetchColumn() === 0) {
                    $result = createOpeningBalances($pdo, $entityId, $fyId, $prevFyIdForCf, $userId);
                    if ($result['success']) {
                        $_SESSION['fy_console_message'] = $result['message'];
                    } else {
                        $_SESSION['fy_console_error'] = $result['message'] ?? 'Carry-forward failed.';
                    }
                } else {
                    $_SESSION['fy_console_error'] = 'Opening balances have already been carried forward for this FY.';
                }
            } else {
                $_SESSION['fy_console_error'] = 'No previous FY found to carry forward from.';
            }
        }
        header("Location: fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $fyId);
        exit;
    }

    if ($postAction === 'regenerate_snapshot' && $entityId > 0 && $fyId > 0) {
        if (!userCanCloseFY($pdo, $userId)) {
            $_SESSION['fy_console_error'] = 'You do not have permission to regenerate snapshots.';
        } else {
            $result = regenerateSnapshot($pdo, $entityId, $fyId, $userId);
            if ($result['success']) {
                $_SESSION['fy_console_message'] = $result['message'];
            } else {
                $_SESSION['fy_console_error'] = $result['message'] ?? 'Snapshot regeneration failed.';
            }
        }
        header("Location: fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $fyId);
        exit;
    }
}

/* ---- Validate entity access (may redirect) ---- */
validateEntityAccessOrRedirect($pdo, $entityId, 'view');

$stmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code FROM companies WHERE id = ?");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    $_SESSION['error'] = 'Entity not found.';
    header("Location: " . BASE_URL . "dashboard_company.php");
    exit;
}

/* ---- Validate FY (graceful when missing) ---- */
$hasActiveFy = false;
$fy = null;

if ($fyId > 0) {
    $fyStmt = $pdo->prepare("SELECT id, fy_label, fy_start, fy_end, status FROM financial_years WHERE id = ? AND company_id = ?");
    $fyStmt->execute([$fyId, $entityId]);
    $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
    if ($fy) {
        $hasActiveFy = true;
    }
}

/* ---- Set session context ---- */
$_SESSION['company_id'] = $entityId;
$_SESSION['company_name'] = $entity['name'];
if ($hasActiveFy) {
    $_SESSION['fy_id'] = $fyId;
    $_SESSION['fy_name'] = $fy['fy_label'];
} else {
    unset($_SESSION['fy_id']);
    unset($_SESSION['fy_name']);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/* ---- Load workflow status (only if FY active) ---- */
$wf = null;
$wfStatus = [];
if ($hasActiveFy) {
    $wfStmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id = ? AND fy_id = ?");
    $wfStmt->execute([$entityId, $fyId]);
    $wf = $wfStmt->fetch(PDO::FETCH_ASSOC);

    $wfStatus = [
        'tally_fetched' => (int) ($wf['tally_fetched'] ?? 0),
        'ledger_fetched' => (int) ($wf['ledger_fetched'] ?? 0),
        'mapping_completed' => (int) ($wf['mapping_completed'] ?? 0),
        'notes_prepared' => (int) ($wf['notes_prepared'] ?? 0),
        'profit_loss_prepared' => (int) ($wf['profit_loss_prepared'] ?? 0),
        'balance_sheet_prepared' => (int) ($wf['balance_sheet_prepared'] ?? 0),
        'verified' => (int) ($wf['verified'] ?? 0),
        'reports_generated' => (int) ($wf['reports_generated'] ?? 0),
    ];
}

/* ---- Previous FY Detection ---- */
$prevFyId = null;
$prevFyLabel = 'Not Available';
$prevFyStatus = 'Not Available';
$prevFyClosed = false;
$hasPrevYearData = false;

if ($hasActiveFy) {
    ensureFYClosureSchema($pdo);
    $prevFyId = getPreviousFYId($pdo, $entityId, $fyId);
    $hasPrevYearData = hasPreviousYearData($pdo, $entityId, $fyId);

    if ($prevFyId !== null) {
        $prevFyStmt = $pdo->prepare("SELECT id, fy_label, status FROM financial_years WHERE id = ? AND company_id = ?");
        $prevFyStmt->execute([$prevFyId, $entityId]);
        $prevFy = $prevFyStmt->fetch(PDO::FETCH_ASSOC);
        if ($prevFy) {
            $prevFyLabel = $prevFy['fy_label'] ?? 'Unknown';
            $prevFyStatus = $prevFy['status'] ?? 'draft';
            $prevFyClosed = ($prevFy['status'] ?? '') === 'closed';
        }
    }
}

/* ---- Opening Balance Status ---- */
$openingBalanceStatus = 'Not Available';
if ($hasActiveFy) {
    if ($prevFyId === null) {
        $openingBalanceStatus = 'Not Available';
    } elseif (!$prevFyClosed) {
        $openingBalanceStatus = 'Pending Previous FY Closure';
    } else {
        $snapshot = getClosingSnapshotSummary($pdo, $entityId, $prevFyId);
        if (empty($snapshot)) {
            $openingBalanceStatus = 'Closure Snapshot Pending';
        } else {
            $hasOpening = false;
            try {
                $obStmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledgers WHERE company_id = ? AND fy_id = ? AND opening_amount != 0");
                $obStmt->execute([$entityId, $fyId]);
                $hasOpening = (int) $obStmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                /* Column may not exist — ignore */
            }
            $openingBalanceStatus = $hasOpening ? 'Carried Forward' : 'Ready for Carry Forward';
        }
    }
}

/* ---- Closure Info ---- */
$closureInfo = $hasActiveFy ? getFYClosureInfo($pdo, $entityId, $fyId) : null;
$currentFyStatus = $hasActiveFy ? ($fy['status'] ?? 'draft') : 'none';

/* ---- Closure Readiness (only if FY active and not closed) ---- */
$closureReadiness = null;
if ($hasActiveFy && $currentFyStatus !== 'closed') {
    $closureReadiness = computeClosureReadiness($pdo, $entityId, $fyId);
}

/* ---- Validation Failures (for wizard step 2) ---- */
$validationFailures = [];
if ($hasActiveFy && $currentFyStatus !== 'closed') {
    $validationFailures = getValidationFailures($pdo, $entityId, $fyId);
    if (empty($validationFailures)) {
        $validationFailures = validateFYClosure($pdo, $entityId, $fyId);
    }
}

/* ---- Audit Trail ---- */
$auditLog = [];
if ($hasActiveFy) {
    $auditLog = getFYClosureAuditLog($pdo, $entityId, $fyId);
}

/* ---- Next FY detection ---- */
$nextFYId = null;
$nextFYLabel = '';
if ($hasActiveFy) {
    $nextFYId = getNextFYId($pdo, $entityId, $fyId);
    if ($nextFYId !== null) {
        $nfStmt = $pdo->prepare("SELECT fy_label FROM financial_years WHERE id = ? AND company_id = ?");
        $nfStmt->execute([$nextFYId, $entityId]);
        $nextFYLabel = (string) $nfStmt->fetchColumn();
    }
}

/* ---- Opening Balance Source ---- */
$obSource = null;
if ($hasActiveFy) {
    $obSource = getOpeningBalanceSource($pdo, $entityId, $fyId);
}

/* ---- Permission check ---- */
$canClose = userCanCloseFY($pdo, $userId);

/* ---- Flash messages ---- */
$flashMessage = $_SESSION['fy_console_message'] ?? null;
$flashError = $_SESSION['fy_console_error'] ?? null;
$justClosed = $_SESSION['fy_console_closure_just_happened'] ?? null;
$nextFyCreated = $_SESSION['fy_console_next_fy_created'] ?? null;
unset($_SESSION['fy_console_message'], $_SESSION['fy_console_error'], $_SESSION['fy_console_closure_just_happened'], $_SESSION['fy_console_next_fy_created']);

/* ---- Check if fy_manager.php exists ---- */
$hasFyManagerRoute = file_exists(__DIR__ . '/fy_manager.php');

/* ---- All redirects complete, safe to output HTML ---- */
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => htmlspecialchars($entity['name'])],
    ['label' => 'Financial Year Console'],
]) ?>

<?= uiPageHero('Financial Year Console', 'Manage financial years, closure status, and opening balance carry-forward for ' . htmlspecialchars($entity['name'])) ?>

<?php
$navData = getWorkflowNavigation($pdo, $entityId, $fyId);
echo renderWorkflowNavigation($navData);
?>

<?= uiWorkspaceStart() ?>

<!-- Flash Messages -->
<?php if ($flashMessage): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:8px;">
    <span>&#9989;</span> <?= htmlspecialchars($flashMessage) ?>
</div>
<?php endif; ?>
<?php if ($flashError): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#991b1b;display:flex;align-items:center;gap:8px;">
    <span>&#10060;</span> <?= htmlspecialchars($flashError) ?>
</div>
<?php endif; ?>

<!-- Status Summary Bar -->
<?php if (!$hasActiveFy): ?>
<!-- No Active FY Banner -->
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
    <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#fbbf24);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">&#9888;&#65039;</div>
    <div>
        <div style="font-size:.88rem;font-weight:700;color:#92400e;">No active financial year selected</div>
        <div style="font-size:.82rem;color:#a16207;margin-top:2px;">Create or select a financial year to continue. All workflow steps require an active FY.</div>
    </div>
    <?php if ($hasFyManagerRoute): ?>
    <a href="<?= BASE_URL ?>fy_manager.php?entity_id=<?= $entityId ?>" class="v2-btn v2-btn--primary" style="font-size:.78rem;padding:6px 14px;margin-left:auto;white-space:nowrap;">Select / Create FY &rarr;</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px;">
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;">
        <div style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Entity</div>
        <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($entity['name']) ?></div>
    </div>
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;">
        <div style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Active FY</div>
        <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($fy['fy_label']) ?></div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($fy['fy_start'] ?? '') ?> to <?= htmlspecialchars($fy['fy_end'] ?? '') ?> &middot; Status: <?= ucfirst(htmlspecialchars($currentFyStatus)) ?></div>
    </div>
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;">
        <div style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Previous FY</div>
        <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($prevFyLabel) ?></div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:2px;">Status: <?= ucfirst(htmlspecialchars($prevFyStatus)) ?></div>
    </div>
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;">
        <div style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Opening Balance</div>
        <div style="font-size:.88rem;font-weight:600;color:<?= $openingBalanceStatus === 'Carried Forward' ? 'var(--success)' : ($openingBalanceStatus === 'Ready for Carry Forward' ? 'var(--brand)' : 'var(--muted)') ?>;"><?= htmlspecialchars($openingBalanceStatus) ?></div>
    </div>
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;">
        <div style="font-size:.72rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Previous Year Figures</div>
        <div style="font-size:.88rem;font-weight:600;color:<?= $hasPrevYearData ? 'var(--success)' : 'var(--muted)' ?>;"><?= $hasPrevYearData ? 'Available' : 'Not Available' ?></div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     CLOSURE READINESS PANEL
     ============================================================ -->
<?php if ($hasActiveFy && $closureReadiness): ?>
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 22px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <div style="font-size:.95rem;font-weight:700;color:var(--text);">Closure Readiness</div>
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="font-size:.82rem;font-weight:600;color:<?= $closureReadiness['status'] === 'ready' ? 'var(--success)' : ($closureReadiness['has_blockers'] ? '#dc2626' : 'var(--warning)') ?>;">
                <?= htmlspecialchars($closureReadiness['label']) ?>
            </div>
            <div style="width:48px;height:48px;border-radius:50%;background:conic-gradient(<?= $closureReadiness['has_blockers'] ? '#dc2626' : ($closureReadiness['status'] === 'ready' ? 'var(--success)' : 'var(--brand)') ?> <?= $closureReadiness['score'] ?>%, var(--border) 0);display:flex;align-items:center;justify-content:center;">
                <div style="width:38px;height:38px;border-radius:50%;background:var(--panel);display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;color:var(--text);">
                    <?= $closureReadiness['score'] ?>%
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;">
        <?php foreach ($closureReadiness['checks'] as $check): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg);border-radius:6px;font-size:.82rem;">
            <span style="flex-shrink:0;width:20px;text-align:center;">
                <?php if ($check['status'] === 'pass'): ?>
                    <span style="color:var(--success);">&#10003;</span>
                <?php elseif ($check['status'] === 'blocker'): ?>
                    <span style="color:#dc2626;">&#10007;</span>
                <?php else: ?>
                    <span style="color:var(--warning);">&#9888;</span>
                <?php endif; ?>
            </span>
            <span style="color:var(--text);font-weight:500;"><?= htmlspecialchars($check['label']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($closureReadiness['blockers'])): ?>
    <div style="margin-top:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:.82rem;color:#991b1b;">
        <strong>Blockers:</strong>
        <ul style="margin:4px 0 0 16px;padding:0;">
            <?php foreach ($closureReadiness['blockers'] as $b): ?>
            <li><?= htmlspecialchars($b) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($closureReadiness['warnings'])): ?>
    <div style="margin-top:8px;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.82rem;color:#92400e;">
        <strong>Warnings:</strong>
        <ul style="margin:4px 0 0 16px;padding:0;">
            <?php foreach ($closureReadiness['warnings'] as $w): ?>
            <li><?= htmlspecialchars($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================================
     POST-CLOSURE PROMPT: Create Next FY
     ============================================================ -->
<?php if ($hasActiveFy && $currentFyStatus === 'closed' && $nextFYId === null): ?>
<div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;">
    <div style="font-size:.9rem;font-weight:700;color:#1e40af;margin-bottom:6px;">Create Next Financial Year</div>
    <div style="font-size:.82rem;color:#1e3a5f;margin-bottom:12px;">
        This FY has been closed. Would you like to create the next financial year?
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post" style="display:inline;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="create_next_fy">
            <button type="submit" class="v2-btn v2-btn--primary" style="font-size:.82rem;">Create Next FY &rarr;</button>
        </form>
        <a href="<?= BASE_URL ?>fy_workspace.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" class="v2-btn v2-btn--outline" style="font-size:.82rem;">Later</a>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     POST-CLOSURE PROMPT: Carry Forward
     ============================================================ -->
<?php if ($hasActiveFy && $currentFyStatus === 'closed' && $nextFYId !== null && $openingBalanceStatus === 'Ready for Carry Forward'): ?>
<div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;">
    <div style="font-size:.9rem;font-weight:700;color:#1e40af;margin-bottom:6px;">Carry Forward Opening Balances</div>
    <div style="font-size:.82rem;color:#1e3a5f;margin-bottom:12px;">
        Previous FY closing snapshot found. Opening balances can be carried forward to <?= htmlspecialchars($nextFYLabel ?: 'next FY') ?>.
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post" style="display:inline;">
            <?= csrfInput() ?>
            <input type="hidden" name="action" value="carry_forward">
            <button type="submit" class="v2-btn v2-btn--primary" style="font-size:.82rem;">Carry Forward Now &rarr;</button>
        </form>
        <a href="<?= BASE_URL ?>fy_workspace.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" class="v2-btn v2-btn--outline" style="font-size:.82rem;">Later</a>
    </div>
</div>
<?php endif; ?>

<!-- Status Messages -->
<?php if (!$hasActiveFy): ?>
<!-- No messages -->
<?php elseif ($prevFyId === null): ?>
<div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#1e40af;">
    &#8505;&#65039; No previous financial year is available for comparison. Previous year figures will not be shown until an earlier FY is created, completed, and closed.
</div>
<?php elseif ($openingBalanceStatus === 'Pending Previous FY Closure'): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#92400e;">
    &#9888;&#65039; Previous Year figures are not available because FY <?= htmlspecialchars($prevFyLabel) ?> is not closed.
    <?php if ($hasFyManagerRoute): ?>
    <a href="<?= BASE_URL ?>fy_manager.php?entity_id=<?= $entityId ?>" style="color:var(--brand);font-weight:600;margin-left:6px;">Go to FY Manager &rarr;</a>
    <?php endif; ?>
</div>
<?php elseif ($openingBalanceStatus === 'Closure Snapshot Pending'): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#92400e;">
    &#9888;&#65039; Previous FY is closed but closing snapshot is not yet available. Opening balances cannot be carried forward yet.
</div>
<?php elseif ($openingBalanceStatus === 'Ready for Carry Forward'): ?>
<div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#1e40af;">
    &#128260; Previous FY is closed and closing snapshot is available. Opening balances are ready to be carried forward.
</div>
<?php elseif ($openingBalanceStatus === 'Carried Forward'): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius-lg);padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#166534;">
    &#9989; Opening balances have been carried forward from FY <?= htmlspecialchars($prevFyLabel) ?>. Previous year figures are available.
</div>
<?php endif; ?>

<!-- Console Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px;">

    <!-- Card 1: FY Manager -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;min-height:180px;">
        <div style="display:flex;justify-content:space-between;align-items:start;">
            <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">&#128197;</div>
            <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $currentFyStatus === 'closed' ? 'var(--success)' : ($currentFyStatus === 'none' ? 'var(--warning)' : 'var(--brand)') ?>15;color:<?= $currentFyStatus === 'closed' ? 'var(--success)' : ($currentFyStatus === 'none' ? 'var(--warning)' : 'var(--brand)') ?>;"><?= $currentFyStatus === 'none' ? 'No FY Selected' : ucfirst(htmlspecialchars($currentFyStatus)) ?></span>
        </div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">FY Manager</div>
            <div style="font-size:.82rem;color:var(--muted);margin-top:4px;">Create, select, and manage financial years for this entity.</div>
        </div>
        <div style="margin-top:auto;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($hasFyManagerRoute): ?>
            <a href="<?= BASE_URL ?>fy_manager.php?entity_id=<?= $entityId ?>" class="v2-btn v2-btn--primary" style="font-size:.78rem;padding:6px 14px;">Select / Create FY</a>
            <?php else: ?>
            <button disabled class="v2-btn" style="font-size:.78rem;padding:6px 14px;opacity:.5;cursor:not-allowed;">FY Manager Pending</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 2: FY Closure -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;min-height:180px;">
        <div style="display:flex;justify-content:space-between;align-items:start;">
            <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a78bfa);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">&#128274;</div>
            <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $currentFyStatus === 'closed' ? 'var(--success)' : 'var(--muted)' ?>15;color:<?= $currentFyStatus === 'closed' ? 'var(--success)' : 'var(--muted)' ?>;"><?= $currentFyStatus === 'closed' ? 'Closed' : 'Open' ?></span>
        </div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">FY Closure</div>
            <div style="font-size:.82rem;color:var(--muted);margin-top:4px;">Validate and close completed financial years after review and deliverables acceptance.</div>
        </div>
        <?php if (!empty($closureInfo) && ($closureInfo['status'] ?? '') === 'closed'): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            Closed on <?= htmlspecialchars(date('d M Y', strtotime($closureInfo['closed_at'] ?? ''))) ?>
            <?php if (!empty($closureInfo['closure_notes'])): ?>
            <br><em><?= htmlspecialchars($closureInfo['closure_notes']) ?></em>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:auto;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($currentFyStatus !== 'closed' && $hasActiveFy): ?>
                <?php if ($canClose): ?>
                    <?php if ($closureReadiness && !$closureReadiness['has_blockers']): ?>
                    <button onclick="document.getElementById('closureWizard').style.display='block';document.getElementById('closureWizard').scrollIntoView({behavior:'smooth'});" class="v2-btn v2-btn--primary" style="font-size:.78rem;padding:6px 14px;background:#dc2626;border-color:#dc2626;">Close Financial Year</button>
                    <?php else: ?>
                    <button disabled class="v2-btn" style="font-size:.78rem;padding:6px 14px;opacity:.5;cursor:not-allowed;" title="Resolve all blockers before closing.">Close FY</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button disabled class="v2-btn" style="font-size:.78rem;padding:6px 14px;opacity:.5;cursor:not-allowed;" title="Administrator permission required.">Close FY</button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($currentFyStatus === 'closed' && $canClose): ?>
                <a href="<?= BASE_URL ?>data_console/fy_closure.php" class="v2-btn v2-btn--outline" style="font-size:.78rem;padding:6px 14px;">View Closure Details</a>
            <?php endif; ?>
        </div>
        <?php if (!$canClose && $hasActiveFy && $currentFyStatus !== 'closed'): ?>
        <div style="font-size:.72rem;color:var(--muted);margin-top:4px;">&#128274; Administrator permission required to close financial years.</div>
        <?php endif; ?>
    </div>

    <!-- Card 3: Opening Balance Register -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;min-height:180px;">
        <div style="display:flex;justify-content:space-between;align-items:start;">
            <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#059669,#10b981);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">&#128203;</div>
            <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $openingBalanceStatus === 'Carried Forward' ? 'var(--success)' : ($openingBalanceStatus === 'Ready for Carry Forward' ? 'var(--brand)' : ($openingBalanceStatus === 'Closure Snapshot Pending' ? 'var(--warning)' : 'var(--muted)')) ?>15;color:<?= $openingBalanceStatus === 'Carried Forward' ? 'var(--success)' : ($openingBalanceStatus === 'Ready for Carry Forward' ? 'var(--brand)' : ($openingBalanceStatus === 'Closure Snapshot Pending' ? 'var(--warning)' : 'var(--muted)')) ?>;"><?= htmlspecialchars($openingBalanceStatus) ?></span>
        </div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">Opening Balance Register</div>
            <div style="font-size:.82rem;color:var(--muted);margin-top:4px;">Carry forward previous FY closing balances into current FY opening balances.</div>
        </div>

        <!-- Detailed register info -->
        <?php if ($hasActiveFy): ?>
        <div style="font-size:.78rem;background:var(--bg);padding:10px 12px;border-radius:6px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <div><span style="color:var(--muted);">Previous FY:</span> <strong><?= htmlspecialchars($prevFyLabel) ?></strong></div>
            <div><span style="color:var(--muted);">Status:</span> <strong><?= htmlspecialchars($prevFyStatus) ?></strong></div>
            <div><span style="color:var(--muted);">Snapshot:</span> <strong><?= $prevFyClosed ? 'Available' : 'Pending' ?></strong></div>
            <div><span style="color:var(--muted);">Carry Forward:</span> <strong><?= $openingBalanceStatus === 'Carried Forward' ? 'Completed' : 'Pending' ?></strong></div>
            <?php if ($obSource): ?>
            <div><span style="color:var(--muted);">Source FY:</span> <strong>#<?= (int) ($obSource['source_fy_id'] ?? 0) ?></strong></div>
            <div><span style="color:var(--muted);">Carried On:</span> <strong><?= htmlspecialchars(date('d M Y', strtotime($obSource['populated_at'] ?? ''))) ?></strong></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($prevFyId === null): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            No previous financial year available for carry-forward.
        </div>
        <?php elseif ($openingBalanceStatus === 'Pending Previous FY Closure'): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            Previous FY (<?= htmlspecialchars($prevFyLabel) ?>) is not closed. Carry-forward is not yet available.
        </div>
        <?php elseif ($openingBalanceStatus === 'Closure Snapshot Pending'): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            Previous FY is closed but closing snapshot is not available. Carry-forward cannot proceed.
        </div>
        <?php elseif ($openingBalanceStatus === 'Ready for Carry Forward'): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            Previous FY (<?= htmlspecialchars($prevFyLabel) ?>) is closed. Opening balances are ready to be carried forward.
        </div>
        <?php elseif ($openingBalanceStatus === 'Carried Forward'): ?>
        <div style="font-size:.78rem;color:var(--muted);background:var(--bg);padding:8px 10px;border-radius:6px;">
            Opening balances carried forward from FY <?= htmlspecialchars($prevFyLabel) ?>. Previous year figures are available in statements.
        </div>
        <?php endif; ?>
        <div style="margin-top:auto;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($openingBalanceStatus === 'Ready for Carry Forward' && $canClose): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Carry forward opening balances from <?= htmlspecialchars($prevFyLabel) ?>?');">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="carry_forward">
                <button type="submit" class="v2-btn v2-btn--primary" style="font-size:.78rem;padding:6px 14px;">Carry Forward Now</button>
            </form>
            <?php else: ?>
            <button disabled class="v2-btn" style="font-size:.78rem;padding:6px 14px;opacity:.5;cursor:not-allowed;" title="Carry forward not available yet.">Carry Forward</button>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ============================================================
     FY CLOSURE WIZARD (Inline, JS-driven)
     ============================================================ -->
<?php if ($hasActiveFy && $currentFyStatus !== 'closed' && $canClose): ?>
<div id="closureWizard" style="display:none;background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:0;margin-bottom:24px;overflow:hidden;">
    <!-- Wizard Header -->
    <div style="background:linear-gradient(135deg,#7c3aed,#a78bfa);padding:18px 24px;color:#fff;">
        <div style="font-size:1.1rem;font-weight:700;">Financial Year Closure Wizard</div>
        <div style="font-size:.82rem;opacity:.9;margin-top:2px;">Follow the steps below to close this financial year.</div>
    </div>

    <!-- Step Indicator -->
    <div style="display:flex;padding:16px 24px;gap:0;border-bottom:1px solid var(--border);">
        <div class="wizard-step-indicator active" data-step="1" style="flex:1;text-align:center;padding:8px 4px;font-size:.78rem;font-weight:600;color:#7c3aed;border-bottom:2px solid #7c3aed;">
            1. Summary
        </div>
        <div class="wizard-step-indicator" data-step="2" style="flex:1;text-align:center;padding:8px 4px;font-size:.78rem;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;">
            2. Validation
        </div>
        <div class="wizard-step-indicator" data-step="3" style="flex:1;text-align:center;padding:8px 4px;font-size:.78rem;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;">
            3. Confirmation
        </div>
        <div class="wizard-step-indicator" data-step="4" style="flex:1;text-align:center;padding:8px 4px;font-size:.78rem;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;">
            4. Close
        </div>
    </div>

    <!-- Wizard Steps -->
    <div style="padding:24px;">

        <!-- STEP 1: Summary -->
        <div class="wizard-content" data-step="1">
            <div style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:14px;">Financial Year Summary</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Entity</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($entity['name']) ?></div>
                </div>
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Financial Year</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($fy['fy_label']) ?></div>
                </div>
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Start Date</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($fy['fy_start'] ?? '—') ?></div>
                </div>
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">End Date</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($fy['fy_end'] ?? '—') ?></div>
                </div>
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Current Status</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--text);"><?= ucfirst(htmlspecialchars($currentFyStatus)) ?></div>
                </div>
                <div style="padding:10px 14px;background:var(--bg);border-radius:8px;">
                    <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;">Readiness Score</div>
                    <div style="font-size:.88rem;font-weight:600;color:<?= ($closureReadiness['score'] ?? 0) >= 90 ? 'var(--success)' : 'var(--warning)' ?>;"><?= ($closureReadiness['score'] ?? 0) ?>% — <?= htmlspecialchars($closureReadiness['label'] ?? 'N/A') ?></div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button onclick="document.getElementById('closureWizard').style.display='none';" class="v2-btn v2-btn--outline" style="font-size:.82rem;">Cancel</button>
                <button onclick="wizardNext(2)" class="v2-btn v2-btn--primary" style="font-size:.82rem;">Next &rarr;</button>
            </div>
        </div>

        <!-- STEP 2: Validation -->
        <div class="wizard-content" data-step="2" style="display:none;">
            <div style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:14px;">Validation Summary</div>
            <?php
            $valErrors = array_filter($validationFailures, fn($f) => $f['severity'] === 'error');
            $valWarnings = array_filter($validationFailures, fn($f) => $f['severity'] === 'warning');
            ?>
            <?php if (!empty($valErrors)): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:.85rem;font-weight:600;color:#dc2626;margin-bottom:6px;">&#10007; Blockers (must resolve)</div>
                <?php foreach ($valErrors as $e): ?>
                <div style="padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:.82rem;color:#991b1b;margin-bottom:6px;">
                    <?= htmlspecialchars($e['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($valWarnings)): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:.85rem;font-weight:600;color:#d97706;margin-bottom:6px;">&#9888; Warnings</div>
                <?php foreach ($valWarnings as $w): ?>
                <div style="padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:.82rem;color:#92400e;margin-bottom:6px;">
                    <?= htmlspecialchars($w['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (empty($valErrors) && empty($valWarnings)): ?>
            <div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:.85rem;color:#166534;margin-bottom:12px;">
                &#10003; All validation checks passed.
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button onclick="wizardNext(1)" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&larr; Back</button>
                <button onclick="wizardNext(3)" class="v2-btn v2-btn--primary" style="font-size:.82rem;">Next &rarr;</button>
            </div>
        </div>

        <!-- STEP 3: Confirmation -->
        <div class="wizard-content" data-step="3" style="display:none;">
            <div style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:14px;">Confirmation</div>
            <div style="padding:16px;background:var(--bg);border-radius:8px;margin-bottom:16px;">
                <div style="font-size:.88rem;font-weight:600;color:var(--text);margin-bottom:8px;">Closing this Financial Year will:</div>
                <ul style="font-size:.82rem;color:var(--muted);margin:0;padding-left:20px;">
                    <li style="margin-bottom:4px;">Freeze this year's figures.</li>
                    <li style="margin-bottom:4px;">Generate a closing snapshot.</li>
                    <li style="margin-bottom:4px;">Enable comparative figures for the next FY.</li>
                    <li style="margin-bottom:4px;">Allow creation of the next Financial Year.</li>
                    <li style="margin-bottom:4px;">Carry forward opening balances.</li>
                    <li style="margin-bottom:4px;">Require administrator permission to reopen.</li>
                </ul>
            </div>
            <div style="padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:16px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:.85rem;color:#991b1b;">
                    <input type="checkbox" id="confirmFinalCheckbox" style="margin-top:3px;" onchange="document.getElementById('closeFYBtn').disabled = !this.checked;">
                    <span><strong>I confirm that all financial statements and deliverables are final.</strong></span>
                </label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button onclick="wizardNext(2)" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&larr; Back</button>
                <button onclick="wizardNext(4)" class="v2-btn v2-btn--primary" style="font-size:.82rem;" id="proceedToCloseBtn" disabled>Proceed to Close &rarr;</button>
            </div>
        </div>

        <!-- STEP 4: Close -->
        <div class="wizard-content" data-step="4" style="display:none;">
            <div style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:14px;">Close Financial Year</div>
            <div style="padding:14px 16px;background:var(--bg);border-radius:8px;margin-bottom:16px;font-size:.82rem;color:var(--muted);">
                You are about to close <strong><?= htmlspecialchars($fy['fy_label']) ?></strong> for <strong><?= htmlspecialchars($entity['name']) ?></strong>.
                This action will freeze all figures and generate a closing snapshot.
            </div>
            <form method="post" id="closeFYForm">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="close_fy">
                <input type="hidden" name="confirm_final" value="1">
                <div style="margin-bottom:14px;">
                    <label for="closureReason" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Reason / Notes (optional)</label>
                    <textarea id="closureReason" name="reason" rows="3" style="width:100%;padding:10px 12px;border:1px solid var(--border-strong);border-radius:8px;font-size:.85rem;resize:vertical;" placeholder="Optional reason or notes for closing this financial year."></textarea>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" onclick="wizardNext(3)" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&larr; Back</button>
                    <button type="submit" id="closeFYBtn" class="v2-btn" style="font-size:.82rem;background:#dc2626;color:#fff;border-color:#dc2626;" disabled>Close Financial Year</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    window.wizardNext = function(step) {
        document.querySelectorAll('.wizard-content').forEach(function(el) { el.style.display = 'none'; });
        document.querySelectorAll('.wizard-step-indicator').forEach(function(el) {
            el.style.color = 'var(--muted)';
            el.style.borderBottomColor = 'transparent';
        });
        var content = document.querySelector('.wizard-content[data-step="' + step + '"]');
        if (content) content.style.display = 'block';
        var indicators = document.querySelectorAll('.wizard-step-indicator');
        for (var i = 0; i < indicators.length; i++) {
            var s = parseInt(indicators[i].getAttribute('data-step'));
            if (s <= step) {
                indicators[i].style.color = '#7c3aed';
                indicators[i].style.borderBottomColor = '#7c3aed';
            }
        }
        if (step === 4) {
            document.getElementById('closeFYBtn').disabled = !document.getElementById('confirmFinalCheckbox').checked;
            document.getElementById('proceedToCloseBtn').disabled = !document.getElementById('confirmFinalCheckbox').checked;
        }
    };

    var confirmCb = document.getElementById('confirmFinalCheckbox');
    if (confirmCb) {
        confirmCb.addEventListener('change', function() {
            document.getElementById('closeFYBtn').disabled = !this.checked;
            document.getElementById('proceedToCloseBtn').disabled = !this.checked;
        });
    }
})();
</script>
<?php endif; ?>

<!-- ============================================================
     PLANNED WORKFLOW
     ============================================================ -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:20px;">
    <div style="font-size:.85rem;font-weight:600;color:var(--text);margin-bottom:10px;">FY Lifecycle Workflow</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:.82rem;color:var(--muted);">
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Create FY</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Import Data</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Prepare Statements</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Review & Validate</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Generate Deliverables</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Close FY</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Create Next FY</span>
        <span>&rarr;</span>
        <span style="padding:4px 10px;background:var(--bg);border-radius:6px;font-weight:500;color:var(--text);">Carry Forward</span>
    </div>
</div>

<!-- ============================================================
     AUDIT TRAIL
     ============================================================ -->
<?php if ($hasActiveFy && !empty($auditLog)): ?>
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 22px;margin-bottom:20px;">
    <div style="font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:12px;">Audit Trail</div>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead>
                <tr style="border-bottom:1px solid var(--border);">
                    <th style="text-align:left;padding:8px 12px;color:var(--muted);font-weight:600;">Action</th>
                    <th style="text-align:left;padding:8px 12px;color:var(--muted);font-weight:600;">User</th>
                    <th style="text-align:left;padding:8px 12px;color:var(--muted);font-weight:600;">Date &amp; Time</th>
                    <th style="text-align:left;padding:8px 12px;color:var(--muted);font-weight:600;">Reason / Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($auditLog as $log): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 12px;font-weight:600;color:var(--text);">
                        <?php
                        $actionLabel = ucfirst(htmlspecialchars($log['action'] ?? ''));
                        $actionColor = ($log['action'] ?? '') === 'closed' ? 'var(--success)' : (($log['action'] ?? '') === 'reopened' ? 'var(--warning)' : 'var(--muted)');
                        ?>
                        <span style="color:<?= $actionColor ?>;"><?= $actionLabel ?></span>
                    </td>
                    <td style="padding:8px 12px;color:var(--text);">User #<?= (int) ($log['performed_by'] ?? 0) ?></td>
                    <td style="padding:8px 12px;color:var(--text);"><?= htmlspecialchars(date('d M Y H:i', strtotime($log['performed_at'] ?? ''))) ?></td>
                    <td style="padding:8px 12px;color:var(--muted);"><?= htmlspecialchars($log['reason'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     QUICK ACTIONS
     ============================================================ -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <?php if ($hasActiveFy): ?>
    <a href="<?= BASE_URL ?>data/index.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&#128202; Data Console</a>
    <a href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&#128200; Financial Statements</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>entity_select.php" class="v2-btn v2-btn--outline" style="font-size:.82rem;">&#128275; Switch Entity</a>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
