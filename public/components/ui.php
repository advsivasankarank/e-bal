<?php
/**
 * e-BAL Enterprise UI — Reusable Component Library
 *
 * Every internal page includes this file and calls these functions
 * instead of writing inline HTML for common UI patterns.
 *
 * Design tokens from app_v2.css are used via CSS variables.
 * All spacing, typography, and colors are centralized here.
 */

/* ============================================================
   BREADCRUMB
   ============================================================ */
function uiBreadcrumb(array $items): string
{
    $html = '<nav class="ui-breadcrumb">';
    foreach ($items as $i => $item) {
        if ($i > 0) {
            $html .= '<span class="ui-breadcrumb-sep">›</span>';
        }
        if (isset($item['href']) && $item['href'] !== '') {
            $html .= '<a href="' . htmlspecialchars($item['href']) . '" class="ui-breadcrumb-link">' . htmlspecialchars($item['label']) . '</a>';
        } else {
            $html .= '<span class="ui-breadcrumb-current">' . htmlspecialchars($item['label']) . '</span>';
        }
    }
    $html .= '</nav>';
    return $html;
}

/* ============================================================
   PAGE HERO (title + subtitle)
   ============================================================ */
function uiPageHero(string $title, string $subtitle = ''): string
{
    $html = '<div class="v2-page-title">';
    $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
    if ($subtitle !== '') {
        $html .= '<p>' . htmlspecialchars($subtitle) . '</p>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   SECTION TAG (pill above title)
   ============================================================ */
function uiSectionTag(string $label, string $icon = ''): string
{
    $html = '<div class="v2-section-tag">';
    if ($icon !== '') {
        $html .= '<span>' . $icon . '</span>';
    }
    $html .= htmlspecialchars($label);
    $html .= '</div>';
    return $html;
}

/* ============================================================
   CONTEXT CARD (active company/FY info)
   ============================================================ */
function uiContextCard(array $context): string
{
    $company = $context['company'] ?? 'Not Selected';
    $fy = $context['fy'] ?? 'Not Selected';
    $entityType = $context['entity_type'] ?? '';
    $profile = (int) ($context['profile'] ?? 0);
    $status = $context['status'] ?? '';
    $editUrl = $context['edit_url'] ?? '';

    $pctColor = $profile >= 80 ? 'var(--success)' : ($profile >= 40 ? 'var(--warning)' : 'var(--danger)');
    $statusColor = $status === 'Reports Ready' ? 'var(--success)' : 'var(--warning)';

    $html = '<div class="ui-context-card">';
    $html .= '<div class="ui-context-card-body">';
    $html .= '<div class="ui-context-card-info">';
    $html .= '<div class="ui-context-card-avatar">' . strtoupper(substr($company, 0, 1)) . '</div>';
    $html .= '<div>';
    $html .= '<div class="ui-context-card-name">' . htmlspecialchars($company) . '</div>';
    $html .= '<div class="ui-context-card-meta">';
    if ($entityType !== '') {
        $html .= '<span>' . htmlspecialchars($entityType) . '</span><span class="ui-sep">•</span>';
    }
    $html .= '<span>' . htmlspecialchars($fy) . '</span>';
    $html .= '</div></div></div>';
    $html .= '<div class="ui-context-card-right">';
    $html .= '<div class="ui-context-card-profile">';
    $html .= '<div class="ui-profile-ring" style="border-color:' . $pctColor . ';color:' . $pctColor . ';">' . $profile . '%</div>';
    $html .= '<div class="ui-profile-label">Profile</div>';
    $html .= '</div>';
    if ($status !== '') {
        $html .= '<div class="ui-context-card-status">';
        $html .= '<div style="font-size:.72rem;font-weight:600;color:' . $statusColor . ';">' . htmlspecialchars($status) . '</div>';
        $html .= '<div style="font-size:.68rem;color:var(--muted);">Status</div>';
        $html .= '</div>';
    }
    if ($editUrl !== '') {
        $html .= '<a href="' . htmlspecialchars($editUrl) . '" class="v2-btn v2-btn--outline" style="font-size:.78rem;">Edit Profile</a>';
    }
    $html .= '</div></div></div>';
    return $html;
}

/* ============================================================
   KPI CARDS (4-column grid)
   ============================================================ */
function uiKpiCards(array $cards): string
{
    $html = '<div class="ui-kpi-grid">';
    foreach ($cards as $card) {
        $value = htmlspecialchars((string) ($card['value'] ?? ''));
        $label = htmlspecialchars((string) ($card['label'] ?? ''));
        $href = $card['href'] ?? '';
        $color = $card['color'] ?? 'var(--brand)';
        $tag = ($href !== '') ? 'a' : 'div';
        $hrefAttr = ($href !== '') ? ' href="' . htmlspecialchars($href) . '"' : '';
        $html .= '<' . $tag . ' class="ui-kpi-card"' . $hrefAttr . '>';
        $html .= '<div class="ui-kpi-value" style="color:' . $color . ';">' . $value . '</div>';
        $html .= '<div class="ui-kpi-label">' . $label . '</div>';
        $html .= '</' . $tag . '>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   ACTION CARDS (grid of action buttons)
   ============================================================ */
function uiActionCards(array $actions): string
{
    $html = '<div class="ui-actions-grid">';
    foreach ($actions as $action) {
        $label = htmlspecialchars((string) ($action['label'] ?? ''));
        $desc = htmlspecialchars((string) ($action['desc'] ?? ''));
        $href = $action['href'] ?? '#';
        $icon = $action['icon'] ?? '';
        $color = $action['color'] ?? 'var(--brand)';
        $disabled = !empty($action['disabled']);

        $html .= '<a href="' . htmlspecialchars($href) . '" class="ui-action-card' . ($disabled ? ' ui-action-card--disabled' : '') . '">';
        $html .= '<div class="ui-action-icon" style="background:' . str_replace('var(', '', str_replace(')', '', $color)) . '15;color:' . $color . ';">' . $icon . '</div>';
        $html .= '<div class="ui-action-text">';
        $html .= '<div class="ui-action-label">' . $label . '</div>';
        $html .= '<div class="ui-action-desc">' . $desc . '</div>';
        $html .= '</div></a>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   STAT CARD (single stat with number + label)
   ============================================================ */
function uiStatCard(string $value, string $label, string $color = 'var(--brand)', string $href = ''): string
{
    $tag = ($href !== '') ? 'a' : 'div';
    $hrefAttr = ($href !== '') ? ' href="' . htmlspecialchars($href) . '"' : '';
    return '<' . $tag . ' class="ui-stat-card"' . $hrefAttr . '>'
        . '<div class="ui-stat-value" style="color:' . $color . ';">' . htmlspecialchars($value) . '</div>'
        . '<div class="ui-stat-label">' . htmlspecialchars($label) . '</div>'
        . '</' . $tag . '>';
}

/* ============================================================
   STATUS BADGE
   ============================================================ */
function uiStatusBadge(string $label, string $variant = 'default'): string
{
    $variantClass = 'ui-badge--' . $variant;
    return '<span class="ui-badge ' . $variantClass . '">' . htmlspecialchars($label) . '</span>';
}

/* ============================================================
   MODAL
   Usage: echo uiModalStart('myModal', 'Title'); ... body ...; echo uiModalEnd();
   Show/hide from JS the same way as before:
     document.getElementById('myModal').style.display = 'flex';  // show
     document.getElementById('myModal').style.display = 'none';  // hide
   A start/end pair (not a single function taking a body string) because
   callers typically build the body from interleaved PHP conditionals,
   not a string they can assemble upfront.
   ============================================================ */
function uiModalStart(string $id, string $title): string
{
    $idAttr = htmlspecialchars($id, ENT_QUOTES);
    return '<div id="' . $idAttr . '" class="ui-modal-overlay" onclick="if(event.target===this)this.style.display=\'none\'">'
        . '<div class="ui-modal-panel">'
        . '<div class="ui-modal-header"><h3>' . htmlspecialchars($title) . '</h3>'
        . '<button type="button" class="ui-modal-close" onclick="document.getElementById(\'' . $idAttr . '\').style.display=\'none\'">&times;</button></div>'
        . '<div class="ui-modal-body">';
}

function uiModalEnd(): string
{
    return '</div></div></div>';
}

/* ============================================================
   TABLE (styled wrapper)
   ============================================================ */
function uiTableStart(array $columns, string $id = ''): string
{
    $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '';
    $html = '<div class="ui-table-wrap"><table class="ui-table"' . $idAttr . '><thead><tr>';
    foreach ($columns as $col) {
        $html .= '<th>' . htmlspecialchars($col) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    return $html;
}

function uiTableEnd(): string
{
    return '</tbody></table></div>';
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
function uiEmptyState(string $icon, string $title, string $message, string $actionLabel = '', string $actionHref = ''): string
{
    $html = '<div class="v2-empty">';
    $html .= '<div class="v2-empty-icon">' . $icon . '</div>';
    $html .= '<h3>' . htmlspecialchars($title) . '</h3>';
    $html .= '<p>' . htmlspecialchars($message) . '</p>';
    if ($actionLabel !== '' && $actionHref !== '') {
        $html .= '<a href="' . htmlspecialchars($actionHref) . '" class="v2-btn v2-btn--primary">' . htmlspecialchars($actionLabel) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   ALERT
   ============================================================ */
function uiAlert(string $message, string $type = 'info'): string
{
    $typeClass = 'ui-alert--' . $type;
    $icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'error' => '❌'];
    $icon = $icons[$type] ?? 'ℹ️';
    return '<div class="ui-alert ' . $typeClass . '"><span class="ui-alert-icon">' . $icon . '</span><span>' . htmlspecialchars($message) . '</span></div>';
}

/* ============================================================
   PROGRESS STEPS
   ============================================================ */
function uiProgressSteps(array $steps): string
{
    $total = count($steps);
    $done = 0;
    foreach ($steps as $s) {
        if (!empty($s['done'])) $done++;
    }
    $pct = $total > 0 ? round(($done / $total) * 100) : 0;

    $html = '<div class="ui-progress">';
    $html .= '<div class="ui-progress-bar"><div class="ui-progress-fill" style="width:' . $pct . '%;"></div></div>';
    $html .= '<div class="ui-progress-labels">';
    $html .= '<span class="ui-progress-done">' . $done . ' of ' . $total . ' complete</span>';
    $html .= '<span class="ui-progress-pct">' . $pct . '%</span>';
    $html .= '</div>';
    $html .= '<div class="ui-progress-steps">';
    foreach ($steps as $step) {
        $doneClass = !empty($step['done']) ? 'ui-step--done' : 'ui-step--pending';
        $html .= '<div class="ui-step ' . $doneClass . '">';
        $html .= '<div class="ui-step-check">' . (!empty($step['done']) ? '✓' : '') . '</div>';
        $html .= '<span>' . htmlspecialchars($step['label'] ?? '') . '</span>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

/* ============================================================
   ACTIVITY FEED (recent items)
   ============================================================ */
function uiActivityFeed(array $items): string
{
    if (empty($items)) {
        return '<div class="ui-empty" style="padding:24px;text-align:center;color:var(--muted);font-size:.82rem;">No recent activity.</div>';
    }
    $html = '<div class="ui-activity-feed">';
    foreach ($items as $item) {
        $icon = $item['icon'] ?? '📋';
        $label = htmlspecialchars((string) ($item['label'] ?? ''));
        $date = $item['date'] ?? '';
        $dateStr = $date !== '' ? date('d M Y', strtotime($date)) : '';
        $html .= '<div class="ui-activity-item">';
        $html .= '<div class="ui-activity-icon">' . $icon . '</div>';
        $html .= '<div class="ui-activity-text">';
        $html .= '<div class="ui-activity-label">' . $label . '</div>';
        $html .= '<div class="ui-activity-date">' . htmlspecialchars($dateStr) . '</div>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   RECENT LIST (list of items with action button)
   ============================================================ */
function uiRecentList(array $items, string $viewAllHref = '', string $viewAllLabel = 'View All'): string
{
    $html = '<div class="ui-recent-list">';
    if ($viewAllHref !== '') {
        $html .= '<div class="ui-recent-header">';
        $html .= '<div class="ui-recent-title">Recent</div>';
        $html .= '<a href="' . htmlspecialchars($viewAllHref) . '" class="ui-recent-viewall">' . htmlspecialchars($viewAllLabel) . '</a>';
        $html .= '</div>';
    }
    if (empty($items)) {
        $html .= '<div style="padding:24px;text-align:center;color:var(--muted);font-size:.82rem;">No items yet.</div>';
    } else {
        foreach ($items as $item) {
            $html .= '<div class="ui-recent-item">';
            $html .= '<div class="ui-recent-item-info">';
            $html .= '<div class="ui-recent-item-name">' . htmlspecialchars($item['name'] ?? '') . '</div>';
            $html .= '<div class="ui-recent-item-meta">';
            if (!empty($item['meta'])) {
                $html .= '<span>' . htmlspecialchars($item['meta']) . '</span>';
            }
            if (!empty($item['badge'])) {
                $html .= '<span class="ui-sep">•</span>' . uiStatusBadge($item['badge']['label'] ?? '', $item['badge']['variant'] ?? 'default');
            }
            $html .= '</div></div>';
            if (!empty($item['action_href'])) {
                $html .= '<a href="' . htmlspecialchars($item['action_href']) . '" class="v2-btn v2-btn--outline" style="font-size:.72rem;padding:4px 10px;">' . htmlspecialchars($item['action_label'] ?? 'Open') . '</a>';
            }
            $html .= '</div>';
        }
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   SECTION CARD (generic card with title + content)
   ============================================================ */
function uiSectionCard(string $title, string $content, string $icon = ''): string
{
    $html = '<div class="ui-section-card">';
    $html .= '<div class="ui-section-card-header">';
    if ($icon !== '') {
        $html .= '<div class="ui-section-card-icon">' . $icon . '</div>';
    }
    $html .= '<div class="ui-section-card-title">' . htmlspecialchars($title) . '</div>';
    $html .= '</div>';
    $html .= '<div class="ui-section-card-body">' . $content . '</div>';
    $html .= '</div>';
    return $html;
}

/* ============================================================
   FORM FIELD
   ============================================================ */
function uiFormField(string $name, string $label, string $type = 'text', string $value = '', string $placeholder = '', bool $required = false, string $help = ''): string
{
    $html = '<div class="ui-field">';
    $html .= '<label for="' . htmlspecialchars($name) . '" class="ui-field-label">' . htmlspecialchars($label) . '</label>';
    if ($type === 'select') {
        // For select, pass options as the value param
    } else {
        $html .= '<input type="' . htmlspecialchars($type) . '" id="' . htmlspecialchars($name) . '" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '"' . ($required ? ' required' : '') . ' class="ui-input">';
    }
    if ($help !== '') {
        $html .= '<div class="ui-field-help">' . htmlspecialchars($help) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   BUTTONS
   ============================================================ */
function uiButton(string $label, string $href = '', string $variant = 'primary', string $icon = '', string $extra = ''): string
{
    $tag = ($href !== '') ? 'a' : 'button';
    $hrefAttr = ($href !== '') ? ' href="' . htmlspecialchars($href) . '"' : '';
    $class = 'v2-btn v2-btn--' . $variant;
    $html = '<' . $tag . ' class="' . $class . '"' . $hrefAttr . ' ' . $extra . '>';
    if ($icon !== '') {
        $html .= '<span>' . $icon . '</span>';
    }
    $html .= htmlspecialchars($label);
    $html .= '</' . $tag . '>';
    return $html;
}

/* ============================================================
   WORKSPACE WRAPPER (opens content area)
   ============================================================ */
function uiWorkspaceStart(): string
{
    return '<div class="v2-content">';
}

function uiWorkspaceEnd(): string
{
    return '</div>';
}

/* ============================================================
   GRID LAYOUTS
   ============================================================ */
function uiGrid(int $cols, string $gap = '14px'): string
{
    return '<div class="ui-grid" style="grid-template-columns:repeat(' . $cols . ',1fr);gap:' . $gap . ';">';
}

function uiGridEnd(): string
{
    return '</div>';
}

function uiTwoCol(): string
{
    return '<div class="ui-two-col">';
}

function uiTwoColEnd(): string
{
    return '</div>';
}
