<?php
/**
 * e-BAL V2 — Assignment Workspace Launcher
 *
 * Premium accounting workspace entry point.
 * Replaces generic CRUD form with professional assignment launch environment.
 *
 * Architecture: Company + Financial Year = Assignment
 * Every operation uses both company_id AND fy_id.
 */
$page_title = 'Assignments';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

/* ---- Resolve owner ---- */
$v2UserId  = (int) ($_SESSION['user_id'] ?? 0);
$v2OwnerId = $v2UserId > 0 ? getOwnerUserId($pdo, $v2UserId) : 0;
$effectiveOwner = $v2OwnerId > 0 ? $v2OwnerId : $v2UserId;

/* ---- Query all assignments with full context ---- */
$v2Assignments = [];
$entityCounts = ['all' => 0];
$statusCounts = ['all' => 0, 'new' => 0, 'in_progress' => 0, 'completed' => 0, 'ready' => 0];

if ($effectiveOwner > 0) {
    $v2Stmt = $pdo->prepare("
        SELECT
            c.id AS company_id,
            c.name AS company_name,
            c.category,
            c.pan,
            c.cin,
            c.llp_code,
            fy.id AS fy_id,
            fy.fy_label,
            fy.fy_start,
            fy.fy_end,
            COALESCE(ws.ledger_fetched, 0) AS ledger_fetched,
            COALESCE(ws.mapping_completed, 0) AS mapping_completed,
            COALESCE(ws.tally_fetched, 0) AS tally_fetched,
            COALESCE(ws.notes_prepared, 0) AS notes_prepared,
            COALESCE(ws.profit_loss_prepared, 0) AS profit_loss_prepared,
            COALESCE(ws.balance_sheet_prepared, 0) AS balance_sheet_prepared,
            COALESCE(ws.directors_report_prepared, 0) AS directors_report_prepared,
            COALESCE(ws.verified, 0) AS verified,
            ws.updated_at
        FROM companies c
        INNER JOIN financial_years fy ON fy.company_id = c.id
        LEFT JOIN workflow_status ws ON ws.company_id = c.id AND ws.fy_id = fy.id
        WHERE c.owner_user_id = ? OR c.owner_user_id IS NULL
        ORDER BY c.name ASC, fy.fy_start DESC
    ");
    $v2Stmt->execute([$effectiveOwner]);
    $v2Rows = $v2Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Current Indian FY */
    $currentMonth = (int) date('m');
    $currentYear = (int) date('Y');
    $currentFYStart = $currentMonth >= 4 ? $currentYear : $currentYear - 1;

    /* Entity label map */
    $entityLabelMap = [
        'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Company',
        'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
        'trust' => 'Trust', 'society' => 'Society', 'individual' => 'Individual', 'huf' => 'HUF',
    ];

    foreach ($v2Rows as $row) {
        $catKey = strtolower(str_replace(['-', ' '], '_', $row['category']));
        $isCorporate = $catKey === 'corporate';
        $isLLP = $catKey === 'llp';

        /* Build workflow steps */
        $steps = [
            ['key' => 'ledger_fetched', 'label' => 'Data', 'done' => (bool) $row['ledger_fetched']],
            ['key' => 'mapping_completed', 'label' => 'Mapping', 'done' => (bool) $row['mapping_completed']],
            ['key' => 'tally_fetched', 'label' => 'TB', 'done' => (bool) $row['tally_fetched']],
            ['key' => 'notes_prepared', 'label' => 'Notes', 'done' => (bool) $row['notes_prepared']],
            ['key' => 'profit_loss_prepared', 'label' => 'P&L', 'done' => (bool) $row['profit_loss_prepared']],
            ['key' => 'balance_sheet_prepared', 'label' => 'BS', 'done' => (bool) $row['balance_sheet_prepared']],
        ];
        if ($isCorporate) {
            $steps[] = ['key' => 'directors_report_prepared', 'label' => 'DR', 'done' => (bool) $row['directors_report_prepared']];
        }

        $totalSteps = count($steps);
        $completedSteps = 0;
        foreach ($steps as $s) { if ($s['done']) $completedSteps++; }
        $progressPct = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;

        /* Determine status */
        $verified = (bool) $row['verified'];
        $allDone = $completedSteps === $totalSteps;
        if ($verified || $allDone) $queueStatus = 'completed';
        elseif ($completedSteps === 0) $queueStatus = 'new';
        else $queueStatus = 'in_progress';

        /* Find next bottleneck */
        $lastDoneIdx = -1;
        for ($i = 0; $i < $totalSteps; $i++) { if ($steps[$i]['done']) $lastDoneIdx = $i; }
        $nextIdx = $lastDoneIdx + 1;
        $nextAction = '';
        if ($queueStatus === 'completed') $nextAction = 'Export deliverables';
        elseif ($queueStatus === 'new') $nextAction = 'Import ledgers';
        elseif ($nextIdx < $totalSteps) $nextAction = 'Complete ' . $steps[$nextIdx]['label'];

        /* Identifier */
        $identifier = '';
        $idType = '';
        if ($isCorporate && !empty($row['cin'])) { $identifier = $row['cin']; $idType = 'CIN'; }
        elseif ($isLLP && !empty($row['llp_code'])) { $identifier = $row['llp_code']; $idType = 'LLPIN'; }
        elseif (!empty($row['pan'])) { $identifier = $row['pan']; $idType = 'PAN'; }

        /* Is current FY? */
        $fyStartYear = (int) substr($row['fy_start'] ?? '', 0, 4);
        $isCurrentFY = ($fyStartYear === $currentFYStart);

        $v2Assignments[] = [
            'company_id'      => (int) $row['company_id'],
            'company_name'    => $row['company_name'],
            'category'        => $row['category'],
            'entity_label'    => $entityLabelMap[$catKey] ?? ucfirst(str_replace('_', ' ', $catKey)),
            'fy_id'           => (int) $row['fy_id'],
            'fy_label'        => $row['fy_label'],
            'fy_start'        => $row['fy_start'] ?? '',
            'fy_end'          => $row['fy_end'] ?? '',
            'is_current_fy'   => $isCurrentFY,
            'identifier'      => $identifier,
            'id_type'         => $idType,
            'steps'           => $steps,
            'total_steps'     => $totalSteps,
            'completed_steps' => $completedSteps,
            'progress_pct'    => $progressPct,
            'queue_status'    => $queueStatus,
            'next_action'     => $nextAction,
            'verified'        => $verified,
            'last_modified'   => $row['updated_at'] ?? '',
        ];

        /* Count for metrics */
        $entityCounts['all']++;
        $entityCounts[$catKey] = ($entityCounts[$catKey] ?? 0) + 1;
        $statusCounts['all']++;
        $statusCounts[$queueStatus] = ($statusCounts[$queueStatus] ?? 0) + 1;
        if ($queueStatus === 'completed' || $queueStatus === 'new' || $queueStatus === 'in_progress') {
            $statusCounts[$queueStatus]++;
        }
    }
}

/* ---- Build entity list for navigator (grouped by company, latest FY first) ---- */
$entityList = [];
$seen = [];
foreach ($v2Assignments as $a) {
    $cid = $a['company_id'];
    if (!isset($entityList[$cid])) {
        $entityList[$cid] = [
            'id' => $cid,
            'name' => $a['company_name'],
            'category' => $a['category'],
            'entity_label' => $a['entity_label'],
            'identifier' => $a['identifier'],
            'id_type' => $a['id_type'],
            'fy_count' => 0,
            'latest_fy' => $a['fy_label'],
            'latest_status' => $a['queue_status'],
            'latest_progress' => $a['progress_pct'],
            'total_assignments' => 0,
        ];
    }
    $entityList[$cid]['fy_count']++;
    $entityList[$cid]['total_assignments']++;
}

/* Sort by name */
usort($entityList, fn($a, $b) => strcmp($a['name'], $b['name']));
?>

<!-- WORKSPACE LAUNCHER -->
<div class="awl-launcher" id="workspace-launcher">

    <!-- Entity Navigator -->
    <div class="awl-panel awl-navigator" id="entity-navigator">
        <div class="awl-panel-header">
            <h2 class="awl-panel-title">Entity Navigator</h2>
            <span class="awl-panel-count"><?= count($entityList) ?> entities</span>
        </div>

        <div class="awl-search-box">
            <svg class="awl-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="entity-search" class="awl-search-input" placeholder="Search companies instantly..." autocomplete="off">
            <kbd class="awl-search-kbd">/</kbd>
        </div>

        <div class="awl-entity-list" id="entity-list">
            <?php if (empty($entityList)): ?>
                <div class="awl-empty-state">
                    <div class="awl-empty-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <h3>No entities registered</h3>
                    <p>Create your first company to begin preparing financial statements.</p>
                    <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="awl-btn awl-btn-primary">Create Company</a>
                </div>
            <?php else: ?>
                <?php foreach ($entityList as $ent): ?>
                    <div class="awl-entity-item"
                         data-entity-id="<?= $ent['id'] ?>"
                         data-entity-search="<?= htmlspecialchars(strtolower($ent['name'] . ' ' . $ent['identifier'] . ' ' . $ent['entity_label'])) ?>"
                         onclick="selectEntity(<?= $ent['id'] ?>)">
                        <div class="awl-entity-avatar"><?= strtoupper(substr($ent['name'], 0, 2)) ?></div>
                        <div class="awl-entity-info">
                            <div class="awl-entity-name"><?= htmlspecialchars($ent['name']) ?></div>
                            <div class="awl-entity-meta">
                                <span class="awl-entity-type"><?= htmlspecialchars($ent['entity_label']) ?></span>
                                <?php if ($ent['identifier']): ?>
                                    <span class="awl-entity-id"><?= htmlspecialchars($ent['identifier']) ?></span>
                                <?php endif; ?>
                                <span class="awl-entity-fys"><?= $ent['fy_count'] ?> FY</span>
                            </div>
                        </div>
                        <div class="awl-entity-arrow">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: FY Timeline + Intelligence + Launch -->
    <div class="awl-right-col">

        <!-- Financial Year Timeline -->
        <div class="awl-panel awl-fy-panel" id="fy-panel">
            <div class="awl-panel-header">
                <h2 class="awl-panel-title" id="fy-panel-title">Select an Entity</h2>
                <span class="awl-panel-subtitle" id="fy-panel-subtitle">Choose a company from the navigator to view financial periods</span>
            </div>

            <div class="awl-fy-timeline" id="fy-timeline">
                <div class="awl-fy-empty" id="fy-empty">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Financial periods will appear here</span>
                </div>
                <div class="awl-fy-list" id="fy-list" style="display:none;"></div>
            </div>

            <!-- No FYs message -->
            <div class="awl-no-fy" id="no-fy-message" style="display:none;">
                <div class="awl-no-fy-content">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <div>
                        <strong>No financial years found</strong>
                        <p>This entity has no financial year periods. Create one to start an assignment.</p>
                    </div>
                    <a href="<?= BASE_URL ?>company_dashboard/financial_year.php" class="awl-btn awl-btn-sm awl-btn-outline" id="create-fy-btn">Create Financial Year</a>
                </div>
            </div>
        </div>

        <!-- Entity Intelligence Panel -->
        <div class="awl-panel awl-intelligence" id="intelligence-panel" style="display:none;">
            <div class="awl-panel-header">
                <h2 class="awl-panel-title">Entity Intelligence</h2>
            </div>
            <div class="awl-intel-grid" id="intel-grid">
                <div class="awl-intel-item">
                    <span class="awl-intel-label">Entity Type</span>
                    <span class="awl-intel-value" id="intel-type">—</span>
                </div>
                <div class="awl-intel-item">
                    <span class="awl-intel-label">PAN</span>
                    <span class="awl-intel-value" id="intel-pan">—</span>
                </div>
                <div class="awl-intel-item">
                    <span class="awl-intel-label">CIN / LLPIN</span>
                    <span class="awl-intel-value" id="intel-cin">—</span>
                </div>
                <div class="awl-intel-item">
                    <span class="awl-intel-label">Financial Periods</span>
                    <span class="awl-intel-value" id="intel-fy-count">—</span>
                </div>
                <div class="awl-intel-item">
                    <span class="awl-intel-label">Current FY Status</span>
                    <span class="awl-intel-value" id="intel-status">—</span>
                </div>
                <div class="awl-intel-item">
                    <span class="awl-intel-label">Last Activity</span>
                    <span class="awl-intel-value" id="intel-activity">—</span>
                </div>
            </div>
        </div>

        <!-- Workspace Launch Zone -->
        <div class="awl-panel awl-launch-zone" id="launch-zone" style="display:none;">
            <div class="awl-launch-content">
                <div class="awl-launch-info">
                    <div class="awl-launch-company" id="launch-company">—</div>
                    <div class="awl-launch-fy" id="launch-fy">—</div>
                </div>
                <a href="#" class="awl-btn awl-btn-launch" id="launch-btn" onclick="launchWorkspace(); return false;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Open Workspace
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ASSIGNMENT GRID (All assignments) -->
<div class="awl-section" id="assignments-section">
    <div class="awl-section-header">
        <h2 class="awl-section-title">All Assignments</h2>
        <div class="awl-section-controls">
            <div class="awl-filters">
                <button class="awl-filter-btn active" data-filter="all">All <span class="awl-filter-count"><?= $statusCounts['all'] ?></span></button>
                <button class="awl-filter-btn" data-filter="needs_action">Needs Action <span class="awl-filter-count"><?= ($statusCounts['new'] ?? 0) + ($statusCounts['in_progress'] ?? 0) ?></span></button>
                <button class="awl-filter-btn" data-filter="completed">Completed <span class="awl-filter-count"><?= $statusCounts['completed'] ?? 0 ?></span></button>
            </div>
            <div class="awl-search-inline">
                <input type="text" id="grid-search" class="awl-search-inline-input" placeholder="Search company, PAN, CIN..." autocomplete="off">
            </div>
        </div>
    </div>

    <div class="awl-grid" id="assignment-grid">
        <?php if (empty($v2Assignments)): ?>
            <div class="awl-empty.assignments" style="grid-column: 1 / -1;">
                <div class="awl-empty-visual">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <h3>No assignments yet</h3>
                <p>Create a company and financial year to begin preparing financial statements.</p>
                <div class="awl-empty-actions">
                    <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="awl-btn awl-btn-primary">Create Company</a>
                    <a href="<?= BASE_URL ?>company_dashboard/company_list.php" class="awl-btn awl-btn-outline">View All Companies</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($v2Assignments as $a): ?>
                <div class="awl-card"
                     data-company-id="<?= $a['company_id'] ?>"
                     data-fy-id="<?= $a['fy_id'] ?>"
                     data-entity="<?= htmlspecialchars(strtolower(str_replace(['-', ' '], '_', $a['category']))) ?>"
                     data-status="<?= htmlspecialchars($a['queue_status']) ?>"
                     data-search="<?= htmlspecialchars(strtolower($a['company_name'] . ' ' . $a['identifier'] . ' ' . $a['fy_label'] . ' ' . $a['entity_label'])) ?>">

                    <div class="awl-card-header">
                        <div class="awl-card-title"><?= htmlspecialchars($a['company_name']) ?></div>
                        <div class="awl-card-fy">
                            <?php if ($a['is_current_fy']): ?>
                                <span class="awl-fy-badge current">Current FY</span>
                            <?php endif; ?>
                            <?= htmlspecialchars($a['fy_label']) ?>
                        </div>
                    </div>

                    <div class="awl-card-tags">
                        <span class="awl-tag awl-tag-type"><?= htmlspecialchars($a['entity_label']) ?></span>
                        <?php if ($a['identifier']): ?>
                            <span class="awl-tag awl-tag-id" title="<?= htmlspecialchars($a['id_type'] . ': ' . $a['identifier']) ?>"><?= htmlspecialchars($a['identifier']) ?></span>
                        <?php endif; ?>
                        <span class="awl-tag awl-tag-status awl-tag-<?= $a['queue_status'] ?>"><?= htmlspecialchars($a['next_action']) ?></span>
                    </div>

                    <div class="awl-card-progress">
                        <div class="awl-progress-track">
                            <div class="awl-progress-fill" style="width: <?= $a['progress_pct'] ?>%"></div>
                        </div>
                        <div class="awl-progress-meta">
                            <span><?= $a['progress_pct'] ?>%</span>
                            <span><?= $a['completed_steps'] ?>/<?= $a['total_steps'] ?> steps</span>
                        </div>
                    </div>

                    <div class="awl-card-steps">
                        <?php foreach ($a['steps'] as $s): ?>
                            <span class="awl-step <?= $s['done'] ? 'done' : '' ?>">
                                <span class="awl-step-dot"></span>
                                <?= htmlspecialchars($s['label']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="awl-card-footer">
                        <button class="awl-btn awl-btn-primary awl-btn-sm" onclick="openAssignment(<?= $a['company_id'] ?>, <?= $a['fy_id'] ?>)">
                            <?= $a['queue_status'] === 'completed' ? 'Export' : 'Continue' ?> →
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden: Company FY data for JS -->
<script>
var ebalCompanies = <?= json_encode(array_values($entityList), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var ebalBaseUrl = '<?= BASE_URL ?>';
</script>

<script src="<?= BASE_URL ?>asset/js/workspace_launcher.js?v=<?= filemtime(__DIR__ . '/../asset/js/workspace_launcher.js') ?>"></script>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
