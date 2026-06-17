<?php
/**
 * Grace Period UI Helper
 * Provides HTML components for displaying grace period warnings and status
 */

require_once __DIR__ . '/plan_helper.php';

/**
 * Get license grace period display status
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array ['status' => string, 'message' => string, 'severity' => string, 'days_remaining' => int]
 *         status: 'active', 'expiring_soon', 'grace_period', 'expired'
 *         severity: 'success', 'warning', 'danger'
 */
function getGracePeriodDisplayStatus(PDO $pdo, int $userId): array
{
    ensurePlanTables($pdo);
    ensureGracePeriodSchema($pdo);

    $license = getActiveLicense($pdo, $userId);
    if (!$license) {
        return [
            'status' => 'expired',
            'message' => 'Your subscription has expired. Please renew immediately.',
            'severity' => 'danger',
            'days_remaining' => 0,
        ];
    }

    $licenseId = (int) $license['id'];
    $expiresAt = (string) ($license['expires_at'] ?? '');
    $today = date('Y-m-d');
    $daysUntilExpiry = 0;

    if ($expiresAt !== '') {
        $today_ts = strtotime($today);
        $expiry_ts = strtotime($expiresAt);
        $daysUntilExpiry = (int) ceil(($expiry_ts - $today_ts) / 86400);
    }

    $licenseStatus = getLicenseStatus($pdo, $licenseId);

    if ($licenseStatus === 'active') {
        if ($daysUntilExpiry <= 7 && $daysUntilExpiry > 0) {
            return [
                'status' => 'expiring_soon',
                'message' => sprintf('Your subscription expires in %d day%s. Renew now to avoid interruption.', $daysUntilExpiry, $daysUntilExpiry === 1 ? '' : 's'),
                'severity' => 'warning',
                'days_remaining' => $daysUntilExpiry,
            ];
        }
        return [
            'status' => 'active',
            'message' => 'Your subscription is active.',
            'severity' => 'success',
            'days_remaining' => $daysUntilExpiry,
        ];
    } elseif ($licenseStatus === 'grace_period') {
        $daysRemaining = getGraceDaysRemaining($pdo, $licenseId);
        return [
            'status' => 'grace_period',
            'message' => sprintf('Your subscription has expired. You have %d day%s remaining to renew before losing access.', $daysRemaining, $daysRemaining === 1 ? '' : 's'),
            'severity' => 'danger',
            'days_remaining' => $daysRemaining,
        ];
    } else {
        return [
            'status' => 'expired',
            'message' => 'Your subscription has expired. Please renew immediately to regain access.',
            'severity' => 'danger',
            'days_remaining' => 0,
        ];
    }
}

/**
 * Render grace period warning banner HTML
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return string HTML banner (empty string if no warning needed)
 */
function renderGracePeriodBanner(PDO $pdo, int $userId): string
{
    $status = getGracePeriodDisplayStatus($pdo, $userId);

    if ($status['status'] === 'active' && $status['severity'] === 'success') {
        return ''; // No banner needed for fully active licenses
    }

    $severityClass = match ($status['severity']) {
        'warning' => 'banner-warning',
        'danger' => 'banner-danger',
        default => 'banner-info',
    };

    $icon = match ($status['status']) {
        'expiring_soon' => '⏰',
        'grace_period' => '⚠️',
        'expired' => '🚫',
        default => 'ℹ️',
    };

    return <<<HTML
<div class="grace-period-banner {$severityClass}">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">{$icon}</span>
        <div style="flex: 1;">
            <strong>{$status['message']}</strong>
            <div style="font-size: 12px; margin-top: 6px; opacity: 0.9;">
                HTML;

    if ($status['status'] === 'grace_period' || $status['status'] === 'expiring_soon') {
        $html .= '<a href="' . BASE_URL . 'upgrade.php" style="color: inherit; text-decoration: underline; font-weight: 600;">Renew now →</a>';
    }

    $html .= <<<HTML
            </div>
        </div>
    </div>
</div>
HTML;

    return $html;
}

/**
 * Get inline grace period status badge HTML
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return string HTML badge or empty string
 */
function renderGracePeriodBadge(PDO $pdo, int $userId): string
{
    $status = getGracePeriodDisplayStatus($pdo, $userId);

    if ($status['status'] === 'active') {
        return '<span class="badge badge-success">Active</span>';
    } elseif ($status['status'] === 'expiring_soon') {
        return '<span class="badge badge-warning">Expiring in ' . $status['days_remaining'] . ' day' . ($status['days_remaining'] === 1 ? '' : 's') . '</span>';
    } elseif ($status['status'] === 'grace_period') {
        return '<span class="badge badge-danger">Grace Period: ' . $status['days_remaining'] . ' day' . ($status['days_remaining'] === 1 ? '' : 's') . ' left</span>';
    } else {
        return '<span class="badge badge-danger">Expired</span>';
    }
}

/**
 * Get CSS styles for grace period components
 * @return string CSS
 */
function getGracePeriodStyles(): string
{
    return <<<CSS
<style>
    .grace-period-banner {
        margin: 16px 0;
        padding: 16px;
        border-radius: 8px;
        border-left: 4px solid;
    }
    
    .grace-period-banner.banner-warning {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
    }
    
    .grace-period-banner.banner-danger {
        background: #fee2e2;
        border-color: #ef4444;
        color: #991b1b;
    }
    
    .grace-period-banner.banner-info {
        background: #dbeafe;
        border-color: #3b82f6;
        color: #1e40af;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .badge-success {
        background: #d1fae5;
        color: #065f46;
    }
    
    .badge-warning {
        background: #fef3c7;
        color: #92400e;
    }
    
    .badge-danger {
        background: #fee2e2;
        color: #991b1b;
    }
</style>
CSS;
}
