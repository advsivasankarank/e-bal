<?php
/**
 * e-BAL V2 — My Assignments
 *
 * Entry point into the V2 workspace.
 * Shows assignment cards for all companies assigned to the current user.
 *
 * No database changes. Reuses:
 *   - companies
 *   - financial_years
 *   - workflow_status
 */
$page_title = 'My Assignments';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';

/* HARDENED: ensureWorkflowColumns() removed — DDL in migration script only */

/* ---- Query assignments ---- */
$v2UserId  = (int) ($_SESSION['user_id'] ?? 0);
$v2OwnerId = $v2UserId > 0 ? getOwnerUserId($pdo, $v2UserId) : 0;

$v2Assignments = [];

/* ---- Query user's companies for assignment selector ---- */
$v2UserCompanies = [];
if ($v2OwnerId > 0 || $v2UserId > 0) {
    $companyStmt = $pdo->prepare("SELECT id, name FROM companies WHERE owner_user_id = ? OR owner_user_id IS NULL ORDER BY name ASC");
    $companyStmt->execute([$v2OwnerId > 0 ? $v2OwnerId : $v2UserId]);
    $v2UserCompanies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($v2OwnerId > 0 || $v2UserId > 0) {
    /* HARDENED: Removed dead $ownerClause — using parameterized query instead */

    /* TODO: Remove NULL fallback after migrating legacy companies with NULL owner_user_id.
       See migration plan: UPDATE companies SET owner_user_id = <id> WHERE owner_user_id IS NULL */
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
            COALESCE(ws.ledger_fetched, 0) AS ledger_fetched,
            COALESCE(ws.mapping_completed, 0) AS mapping_completed,
            COALESCE(ws.tally_fetched, 0) AS tally_fetched,
            COALESCE(ws.notes_prepared, 0) AS notes_prepared,
            COALESCE(ws.profit_loss_prepared, 0) AS profit_loss_prepared,
            COALESCE(ws.balance_sheet_prepared, 0) AS balance_sheet_prepared,
            COALESCE(ws.directors_report_prepared, 0) AS directors_report_prepared,
            COALESCE(ws.verified, 0) AS verified
        FROM companies c
        INNER JOIN financial_years fy ON fy.company_id = c.id
        LEFT JOIN workflow_status ws ON ws.company_id = c.id AND ws.fy_id = fy.id
        WHERE c.owner_user_id = ? OR c.owner_user_id IS NULL
        ORDER BY c.name ASC, fy.fy_start DESC
    ");
    $v2Stmt->execute([$v2OwnerId > 0 ? $v2OwnerId : $v2UserId]);
    $v2Rows = $v2Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---- Compute per-row progress ---- */
    foreach ($v2Rows as $row) {
        $isCorporate = strtolower(str_replace(['-', ' '], '_', $row['category'])) === 'corporate';
        $isLLP = strtolower(str_replace(['-', ' '], '_', $row['category'])) === 'llp';

        /* Define steps based on entity type */
        $steps = [];
        $steps[] = ['key' => 'ledger_fetched',      'label' => 'Data',     'done' => (bool) $row['ledger_fetched']];
        $steps[] = ['key' => 'mapping_completed',    'label' => 'Mapping',  'done' => (bool) $row['mapping_completed']];
        $steps[] = ['key' => 'tally_fetched',        'label' => 'TB',       'done' => (bool) $row['tally_fetched']];
        $steps[] = ['key' => 'notes_prepared',       'label' => 'Notes',    'done' => (bool) $row['notes_prepared']];
        $steps[] = ['key' => 'profit_loss_prepared', 'label' => 'P&L',      'done' => (bool) $row['profit_loss_prepared']];
        $steps[] = ['key' => 'balance_sheet_prepared','label' => 'BS',      'done' => (bool) $row['balance_sheet_prepared']];

        if ($isCorporate) {
            $steps[] = ['key' => 'directors_report_prepared', 'label' => 'DR', 'done' => (bool) $row['directors_report_prepared']];
        }

        $totalSteps = count($steps);
        $completedSteps = 0;
        foreach ($steps as $s) {
            if ($s['done']) $completedSteps++;
        }
        $progressPct = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;

        /* Determine queue status */
        $verified = (bool) $row['verified'];
        $allDone = $completedSteps === $totalSteps;

        if ($verified || $allDone) {
            $queueStatus = 'completed';
        } elseif ($completedSteps === 0) {
            $queueStatus = 'new';
        } elseif ($completedSteps < $totalSteps) {
            /* Check what's the current bottleneck */
            $lastDoneIdx = -1;
            for ($i = 0; $i < $totalSteps; $i++) {
                if ($steps[$i]['done']) $lastDoneIdx = $i;
            }
            $nextIdx = $lastDoneIdx + 1;
            if ($nextIdx < $totalSteps) {
                $nextKey = $steps[$nextIdx]['key'];
                if ($nextKey === 'tally_fetched') {
                    $queueStatus = 'tb_pending';
                } elseif ($nextKey === 'balance_sheet_prepared') {
                    $queueStatus = 'statements_pending';
                } elseif ($nextKey === 'mapping_completed') {
                    $queueStatus = 'mapping_pending';
                } else {
                    $queueStatus = 'in_progress';
                }
            } else {
                $queueStatus = 'in_progress';
            }
        } else {
            $queueStatus = 'in_progress';
        }

        /* Derive identifier */
        $identifier = '';
        $idType = '';
        if ($isCorporate && !empty($row['cin'])) {
            $identifier = $row['cin'];
            $idType = 'CIN';
        } elseif ($isLLP && !empty($row['llp_code'])) {
            $identifier = $row['llp_code'];
            $idType = 'LLPIN';
        } elseif (!empty($row['pan'])) {
            $identifier = $row['pan'];
            $idType = 'PAN';
        }

        /* Entity label */
        $entityLabelMap = [
            'corporate' => 'Company',
            'llp' => 'LLP',
            'non_corporate' => 'Company',
            'partnership' => 'Partnership',
            'proprietorship' => 'Proprietorship',
            'trust' => 'Trust',
            'society' => 'Society',
            'individual' => 'Individual',
            'huf' => 'HUF',
        ];
        $catKey = strtolower(str_replace(['-', ' '], '_', $row['category']));
        $entityLabel = $entityLabelMap[$catKey] ?? ucfirst(str_replace('_', ' ', $catKey));

        /* Next action */
        $nextAction = '';
        if ($queueStatus === 'completed') {
            $nextAction = 'Export deliverables';
        } elseif ($queueStatus === 'new') {
            $nextAction = 'Import ledgers';
        } else {
            $nextIdx = $lastDoneIdx + 1;
            if ($nextIdx < $totalSteps) {
                $nextAction = 'Complete ' . $steps[$nextIdx]['label'];
            }
        }

        $v2Assignments[] = [
            'company_id'      => (int) $row['company_id'],
            'company_name'    => $row['company_name'],
            'category'        => $row['category'],
            'entity_label'    => $entityLabel,
            'fy_id'           => (int) $row['fy_id'],
            'fy_label'        => $row['fy_label'],
            'identifier'      => $identifier,
            'id_type'         => $idType,
            'steps'           => $steps,
            'total_steps'     => $totalSteps,
            'completed_steps' => $completedSteps,
            'progress_pct'    => $progressPct,
            'queue_status'    => $queueStatus,
            'next_action'     => $nextAction,
        ];
    }
}
?>

<div class="v2-section-tag">My Assignments</div>
<div class="v2-page-title">
    <h1>My Assignments</h1>
    <p>Companies and financial years assigned to you</p>
</div>

<!-- Assignment Selector Panel -->
<div class="v2-assign-selector" id="assignment-selector">
    <div class="v2-assign-selector-header">
        <span class="v2-assign-selector-title">Quick Open</span>
        <span class="v2-assign-selector-sub">Select a company and financial year to start working</span>
    </div>
    <form method="get" action="<?= BASE_URL ?>assignment_home.php" class="v2-assign-selector-form" id="selector-form">
        <div class="v2-assign-selector-fields">
            <div class="v2-assign-selector-field">
                <label for="sel-company">Company</label>
                <select name="company_id" id="sel-company" required>
                    <option value="">Select Company</option>
                    <?php foreach ($v2UserCompanies as $co): ?>
                    <option value="<?= (int) $co['id'] ?>" <?= ((int) $co['id'] === (int) ($_SESSION['company_id'] ?? 0)) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($co['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="v2-assign-selector-field">
                <label for="sel-fy">Financial Year</label>
                <select name="fy_id" id="sel-fy" required>
                    <option value="">Select Financial Year</option>
                </select>
            </div>
            <div class="v2-assign-selector-field v2-assign-selector-action">
                <button type="submit" class="v2-btn v2-btn-primary" id="sel-open-btn" disabled>Open Assignment</button>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    var companySelect = document.getElementById('sel-company');
    var fySelect = document.getElementById('sel-fy');
    var openBtn = document.getElementById('sel-open-btn');
    var form = document.getElementById('selector-form');

    if (!companySelect || !fySelect) return;

    function loadFYs(companyId) {
        fySelect.innerHTML = '<option value="">Loading...</option>';
        openBtn.disabled = true;
        if (!companyId) {
            fySelect.innerHTML = '<option value="">Select Financial Year</option>';
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '<?= BASE_URL ?>company_dashboard/financial_year_ajax.php?company_id=' + companyId);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    fySelect.innerHTML = '<option value="">Select Financial Year</option>';
                    if (!Array.isArray(data) || data.length === 0) {
                        fySelect.innerHTML += '<option value="" disabled>No financial years found</option>';
                    } else {
                        data.forEach(function(fy) {
                            var opt = document.createElement('option');
                            opt.value = fy.id;
                            opt.textContent = fy.fy_label;
                            fySelect.appendChild(opt);
                        });
                    }
                    openBtn.disabled = fySelect.value === '';
                } catch(e) {
                    fySelect.innerHTML = '<option value="">Error loading data</option>';
                }
            } else {
                fySelect.innerHTML = '<option value="">Error loading data</option>';
            }
        };
        xhr.onerror = function() {
            fySelect.innerHTML = '<option value="">Network error</option>';
        };
        xhr.send();
    }

    companySelect.addEventListener('change', function() {
        loadFYs(this.value);
    });

    fySelect.addEventListener('change', function() {
        openBtn.disabled = this.value === '';
    });

    // Load FYs on page load if company is pre-selected
    if (companySelect.value) {
        loadFYs(companySelect.value);
    }
})();
</script>

<!-- Metrics -->
<div class="v2-assign-metrics" id="metrics">
    <?php
    $counts = ['all' => 0, 'new' => 0, 'in_progress' => 0, 'completed' => 0];
    foreach ($v2Assignments as $a) {
        $counts['all']++;
        if ($a['queue_status'] === 'completed') {
            $counts['completed']++;
        } elseif ($a['queue_status'] === 'new') {
            $counts['new']++;
        } else {
            $counts['in_progress']++;
        }
    }
    ?>
    <button class="v2-assign-metric active" data-filter-status="all">
        <span class="v2-assign-metric-num"><?= $counts['all'] ?></span>
        <span class="v2-assign-metric-lbl">All Assigned</span>
    </button>
    <button class="v2-assign-metric" data-filter-status="needs_action">
        <span class="v2-assign-metric-num"><?= $counts['new'] + $counts['in_progress'] ?></span>
        <span class="v2-assign-metric-lbl">Needs Action</span>
    </button>
    <button class="v2-assign-metric" data-filter-status="in_progress">
        <span class="v2-assign-metric-num"><?= $counts['in_progress'] ?></span>
        <span class="v2-assign-metric-lbl">In Progress</span>
    </button>
    <button class="v2-assign-metric" data-filter-status="completed">
        <span class="v2-assign-metric-num"><?= $counts['completed'] ?></span>
        <span class="v2-assign-metric-lbl">Completed</span>
    </button>
</div>

<!-- Filters Row -->
<div class="v2-assign-filters">
    <!-- Entity Type Tabs -->
    <div class="v2-assign-entity-tabs" id="entity-tabs">
        <?php
        $entityCounts = ['all' => 0];
        foreach ($v2Assignments as $a) {
            $cat = strtolower(str_replace(['-', ' '], '_', $a['category']));
            $entityCounts['all']++;
            $entityCounts[$cat] = ($entityCounts[$cat] ?? 0) + 1;
        }
        $entityTypes = [
            'all'          => 'All',
            'corporate'    => 'Company',
            'llp'          => 'LLP',
            'partnership'  => 'Partnership',
            'proprietorship' => 'Proprietorship',
            'trust'        => 'Trust',
            'society'      => 'Society',
        ];
        foreach ($entityTypes as $key => $label):
            $cnt = $entityCounts[$key] ?? 0;
        ?>
            <button class="v2-assign-entity-tab <?= $key === 'all' ? 'active' : '' ?>"
                    data-filter-entity="<?= htmlspecialchars($key) ?>">
                <?= htmlspecialchars($label) ?> (<?= $cnt ?>)
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Search -->
    <div class="v2-assign-search">
        <input type="text" id="search-input" placeholder="Search company, PAN, CIN, LLPIN…" autocomplete="off">
    </div>
</div>

<!-- Recently Opened -->
<div class="v2-assign-recent" id="recent-section" style="display:none;">
    <h3 class="v2-assign-recent-title">Recently Opened</h3>
    <div class="v2-assign-recent-list" id="recent-list"></div>
</div>

<!-- Assignment Cards -->
<div class="v2-assign-grid" id="assignment-grid">
    <?php if (empty($v2Assignments)): ?>
        <div class="v2-empty" style="grid-column: 1 / -1;">
            <div class="v2-empty-icon">📋</div>
            <h3>No assignments yet</h3>
            <p>Create a company in the workspace to get started.</p>
            <a href="<?= BASE_URL ?>company_dashboard/company_create.php" class="v2-btn v2-btn-primary">Create Company</a>
        </div>
    <?php else: ?>
        <?php foreach ($v2Assignments as $a): ?>
            <div class="v2-assign-card"
                 data-company-id="<?= $a['company_id'] ?>"
                 data-fy-id="<?= $a['fy_id'] ?>"
                 data-entity="<?= htmlspecialchars(strtolower(str_replace(['-', ' '], '_', $a['category']))) ?>"
                 data-status="<?= htmlspecialchars($a['queue_status']) ?>"
                 data-search="<?= htmlspecialchars(strtolower($a['company_name'] . ' ' . $a['identifier'] . ' ' . $a['fy_label'] . ' ' . $a['entity_label'])) ?>">

                <div class="v2-assign-card-head">
                    <div class="v2-assign-card-name" title="<?= htmlspecialchars($a['company_name']) ?>"><?= htmlspecialchars($a['company_name']) ?></div>
                    <div class="v2-assign-card-fy">FY <?= htmlspecialchars($a['fy_label']) ?></div>
                </div>

                <div class="v2-assign-card-meta">
                    <span class="v2-assign-tag v2-assign-tag-entity"><?= htmlspecialchars($a['entity_label']) ?></span>
                    <?php if ($a['identifier']): ?>
                        <span class="v2-assign-tag v2-assign-tag-id" title="<?= htmlspecialchars($a['id_type'] . ': ' . $a['identifier']) ?>"><?= htmlspecialchars($a['identifier']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="v2-assign-card-progress">
                    <div class="v2-assign-progress-bar">
                        <div class="v2-assign-progress-fill" style="width: <?= $a['progress_pct'] ?>%"></div>
                    </div>
                    <div class="v2-assign-progress-labels">
                        <span><?= $a['progress_pct'] ?>% complete</span>
                        <span><?= $a['completed_steps'] ?>/<?= $a['total_steps'] ?> steps</span>
                    </div>
                </div>

                <div class="v2-assign-card-steps">
                    <?php foreach ($a['steps'] as $s): ?>
                        <span class="v2-assign-step <?= $s['done'] ? 'done' : 'pending' ?>">
                            <span class="v2-assign-step-check"><?= $s['done'] ? '✓' : '○' ?></span>
                            <?= htmlspecialchars($s['label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <div class="v2-assign-card-footer">
                    <div class="v2-assign-card-action">
                        <span class="v2-assign-status-badge v2-assign-status-<?= $a['queue_status'] ?>">
                            <?= htmlspecialchars($a['next_action']) ?>
                        </span>
                    </div>
                    <button class="v2-btn v2-btn-primary v2-btn-sm v2-assign-continue"
                            onclick="openAssignment(<?= $a['company_id'] ?>, <?= $a['fy_id'] ?>)">
                        <?= $a['queue_status'] === 'completed' ? 'Export →' : 'Continue →' ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- No results message -->
<div class="v2-assign-no-results" id="no-results" style="display:none;">
    <div class="v2-empty">
        <div class="v2-empty-icon">🔍</div>
        <h3>No matching assignments</h3>
        <p>Try adjusting your search or filters.</p>
    </div>
</div>

<script>
(function() {
    /* ---- Recently Opened (localStorage) ---- */
    var RECENT_KEY = 'v2-recent-assignments';
    var RECENT_LIMIT = 5;

    function getRecent() {
        try {
            return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
        } catch(e) { return []; }
    }

    function addRecent(companyId, fyId, name) {
        var recent = getRecent().filter(function(r) {
            return !(r.companyId === companyId && r.fyId === fyId);
        });
        recent.unshift({ companyId: companyId, fyId: fyId, name: name, ts: Date.now() });
        if (recent.length > RECENT_LIMIT) recent = recent.slice(0, RECENT_LIMIT);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(recent)); } catch(e) {}
    }

    function renderRecent() {
        var recent = getRecent();
        var section = document.getElementById('recent-section');
        var list = document.getElementById('recent-list');
        if (!recent.length || !section || !list) { if (section) section.style.display = 'none'; return; }
        section.style.display = '';
        list.innerHTML = recent.map(function(r) {
            return '<a class="v2-assign-recent-item" href="assignment_home.php?company_id=' + r.companyId + '&fy_id=' + r.fyId + '">' +
                '<span class="v2-assign-recent-name">' + escapeHtml(r.name) + '</span>' +
                '<span class="v2-assign-recent-go">→</span></a>';
        }).join('');
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    window.openAssignment = function(companyId, fyId) {
        var cards = document.querySelectorAll('.v2-assign-card');
        for (var i = 0; i < cards.length; i++) {
            if (parseInt(cards[i].getAttribute('data-company-id')) === companyId &&
                parseInt(cards[i].getAttribute('data-fy-id')) === fyId) {
                var name = cards[i].querySelector('.v2-assign-card-name');
                if (name) addRecent(companyId, fyId, name.textContent.trim());
                break;
            }
        }
        window.location.href = 'assignment_home.php?company_id=' + companyId + '&fy_id=' + fyId;
    };

    renderRecent();

    /* ---- Search ---- */
    var searchInput = document.getElementById('search-input');
    var cards = document.querySelectorAll('.v2-assign-card');
    var noResults = document.getElementById('no-results');

    function applyFilters() {
        var query = (searchInput.value || '').toLowerCase().trim();
        var activeEntity = document.querySelector('.v2-assign-entity-tab.active');
        var entityFilter = activeEntity ? activeEntity.getAttribute('data-filter-entity') : 'all';
        var activeMetric = document.querySelector('.v2-assign-metric.active');
        var statusFilter = activeMetric ? activeMetric.getAttribute('data-filter-status') : 'all';

        var visible = 0;
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var cardEntity = card.getAttribute('data-entity');
            var cardStatus = card.getAttribute('data-status');
            var cardSearch = card.getAttribute('data-search');

            var matchEntity = entityFilter === 'all' || cardEntity === entityFilter;
            var matchStatus = statusFilter === 'all' ||
                (statusFilter === 'needs_action' && (cardStatus === 'new' || cardStatus === 'in_progress' || cardStatus === 'tb_pending' || cardStatus === 'mapping_pending' || cardStatus === 'statements_pending')) ||
                (statusFilter === 'in_progress' && (cardStatus === 'in_progress' || cardStatus === 'tb_pending' || cardStatus === 'mapping_pending' || cardStatus === 'statements_pending')) ||
                (statusFilter === 'completed' && cardStatus === 'completed');
            var matchSearch = !query || cardSearch.indexOf(query) !== -1;

            if (matchEntity && matchStatus && matchSearch) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        }

        if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);

    /* ---- Entity tabs ---- */
    var entityTabs = document.querySelectorAll('.v2-assign-entity-tab');
    for (var t = 0; t < entityTabs.length; t++) {
        entityTabs[t].addEventListener('click', function() {
            for (var j = 0; j < entityTabs.length; j++) entityTabs[j].classList.remove('active');
            this.classList.add('active');
            applyFilters();
        });
    }

    /* ---- Metric filters ---- */
    var metricBtns = document.querySelectorAll('.v2-assign-metric');
    for (var m = 0; m < metricBtns.length; m++) {
        metricBtns[m].addEventListener('click', function() {
            for (var j = 0; j < metricBtns.length; j++) metricBtns[j].classList.remove('active');
            this.classList.add('active');
            applyFilters();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
