<?php
/**
 * Workflow Navigation Helper — e-BAL
 *
 * Provides Previous / Next navigation data for workflow-aware pages.
 * Lightweight helper — does not change workflow engine logic.
 */

/**
 * Workflow stages in order.
 */
function getWorkflowStages(): array
{
    return [
        'gateway'      => ['label' => 'Entity Selection',           'position' => 1],
        'fy_console'   => ['label' => 'Financial Year Console',    'position' => 2],
        'data'         => ['label' => 'Data Console',              'position' => 3],
        'statements'   => ['label' => 'Financial Statement Console', 'position' => 4],
        'deliverables' => ['label' => 'Deliverables',              'position' => 5],
    ];
}

/**
 * Map current page script/directory to a workflow stage key.
 */
function resolveWorkflowStage(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $dir    = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script === 'dashboard_company.php' || $script === 'entity_select.php' || $script === 'entity_create.php' || $script === 'entity_edit.php') {
        return 'gateway';
    }
    if ($script === 'fy_manager.php' || $script === 'fy_workspace.php') {
        return 'fy_console';
    }
    if ($dir === 'data' || $script === 'data' || $dir === 'data_console') {
        return 'data';
    }
    if ($dir === 'statements' || $script === 'statements') {
        return 'statements';
    }
    if ($dir === 'review' || $script === 'review') {
        return 'review';
    }
    if ($dir === 'deliverables' || $script === 'deliverables') {
        return 'deliverables';
    }
    if ($script === 'reports.php') {
        return 'reports';
    }
    if ($script === 'settings.php') {
        return 'settings';
    }

    return '';
}

/**
 * Compute workflow navigation for current page.
 *
 * Returns array with:
 *   current_label, previous_label, previous_url,
 *   next_label, next_url, next_status, blocking_reason, is_next_enabled
 */
function getWorkflowNavigation(PDO $pdo, int $companyId, int $fyId): array
{
    $stages = getWorkflowStages();
    $currentStage = resolveWorkflowStage();

    $companyParam = $companyId > 0 ? '&entity_id=' . $companyId : '';
    $fyParam      = $fyId > 0 ? '&fy_id=' . $fyId : '';

    $defaultNav = [
        'current_label'   => '',
        'previous_label'  => '',
        'previous_url'    => '',
        'next_label'      => '',
        'next_url'        => '',
        'next_status'     => 'ready',
        'blocking_reason' => '',
        'is_next_enabled' => true,
    ];

    $stageToUrl = [
        'gateway'      => BASE_URL . 'dashboard_company.php',
        'fy_console'   => BASE_URL . 'fy_workspace.php' . $companyParam,
        'data'         => BASE_URL . 'data/index.php' . $companyParam . $fyParam,
        'statements'   => BASE_URL . 'statements/financials.php' . $companyParam . $fyParam,
        'deliverables' => BASE_URL . 'deliverables/index.php' . $companyParam . $fyParam,
    ];

    if (!isset($stages[$currentStage])) {
        return $defaultNav;
    }

    $pos = $stages[$currentStage]['position'];

    // Determine previous stage
    $prevStage = null;
    foreach ($stages as $key => $stage) {
        if ($stage['position'] === $pos - 1) {
            $prevStage = $key;
            break;
        }
    }

    // Determine next stage
    $nextStage = null;
    foreach ($stages as $key => $stage) {
        if ($stage['position'] === $pos + 1) {
            $nextStage = $key;
            break;
        }
    }

    // Compute blocking reason for next stage
    $blockingReason = '';
    $nextEnabled = true;

    if ($companyId > 0 && $fyId > 0) {
        try {
            $wf = getWorkflow($companyId, $fyId);

            if ($nextStage === 'data' && empty($wf['tally_fetched'])) {
                $blockingReason = 'Fetch Trial Balance before proceeding to Data Console.';
                $nextEnabled = false;
            } elseif ($nextStage === 'statements' && (empty($wf['mapping_completed']) || empty($wf['tally_fetched']))) {
                $blockingReason = 'Complete mapping and trial balance import before generating statements.';
                $nextEnabled = false;
            } elseif ($nextStage === 'deliverables' && empty($wf['balance_sheet_prepared'])) {
                $blockingReason = 'Financial statements must be prepared before generating deliverables.';
                $nextEnabled = false;
            }
        } catch (Throwable $e) {
            // Silently ignore — navigation still works
        }
    } elseif ($nextStage !== null && $nextStage !== 'gateway') {
        $nextEnabled = false;
        $blockingReason = 'Select an Entity and Financial Year first.';
    }

    return [
        'current_label'   => $stages[$currentStage]['label'],
        'previous_label'  => $prevStage ? $stages[$prevStage]['label'] : '',
        'previous_url'    => $prevStage ? ($stageToUrl[$prevStage] ?? '#') : '',
        'next_label'      => $nextStage ? $stages[$nextStage]['label'] : '',
        'next_url'        => $nextStage ? ($stageToUrl[$nextStage] ?? '#') : '',
        'next_status'     => $blockingReason !== '' ? 'blocked' : 'ready',
        'blocking_reason' => $blockingReason,
        'is_next_enabled' => $nextEnabled,
    ];
}

/**
 * Render workflow navigation bar HTML.
 *
 * @param array $nav Output of getWorkflowNavigation()
 * @return string HTML
 */
function renderWorkflowNavigation(array $nav): string
{
    if (empty($nav['current_label'])) {
        return '';
    }

    $html = '<div class="v2-workflow-nav">';
    $html .= '<div class="v2-wf-current"><span class="v2-wf-current-label">Current:</span> ' . htmlspecialchars($nav['current_label']) . '</div>';
    $html .= '<div class="v2-wf-controls">';

    if ($nav['previous_label'] !== '' && $nav['previous_url'] !== '') {
        $html .= '<a class="v2-wf-btn v2-wf-btn--prev" href="' . htmlspecialchars($nav['previous_url']) . '">&#8592; ' . htmlspecialchars($nav['previous_label']) . '</a>';
    } else {
        $html .= '<span class="v2-wf-btn v2-wf-btn--prev v2-wf-btn--disabled">&#8592; Start</span>';
    }

    if ($nav['next_label'] !== '' && $nav['next_url'] !== '' && $nav['is_next_enabled']) {
        $html .= '<a class="v2-wf-btn v2-wf-btn--next" href="' . htmlspecialchars($nav['next_url']) . '">' . htmlspecialchars($nav['next_label']) . ' &#8594;</a>';
    } elseif ($nav['next_label'] !== '' && !$nav['is_next_enabled']) {
        $html .= '<span class="v2-wf-btn v2-wf-btn--next v2-wf-btn--disabled">' . htmlspecialchars($nav['next_label']) . ' &#8594;</span>';
        if ($nav['blocking_reason'] !== '') {
            $html .= '<span class="v2-wf-block-reason">' . htmlspecialchars($nav['blocking_reason']) . '</span>';
        }
    } else {
        $html .= '<span class="v2-wf-btn v2-wf-btn--next v2-wf-btn--disabled">End of workflow</span>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
