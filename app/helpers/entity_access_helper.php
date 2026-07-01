<?php
/**
 * e-BAL — Commercial Entity Access Helper
 *
 * Centralized access-control for entity visibility in a multi-tenant SaaS context.
 *
 * Role Model:
 *   superadmin — sees ALL entities (platform owner)
 *   admin      — sees entities in their workspace/account
 *   staff      — sees only assigned entities (requires assignment table)
 *
 * Current implementation:
 *   - superadmin: sees all companies (no owner_user_id filter)
 *   - admin: sees companies where owner_user_id matches their resolved owner ID
 *   - staff: sees companies where owner_user_id matches their resolved owner ID
 *             (assignment model not yet implemented — reports limitation)
 *
 * Limitation:
 *   No workspace_id / account_id / assignment table exists yet.
 *   Admin/staff visibility falls back to owner_user_id matching.
 *   Company 6 (owner_user_id=NULL) is visible to superadmin only.
 */

/**
 * Get the current user's role.
 */
function getCurrentUserRole(PDO $pdo): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return '';

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return strtolower((string) $stmt->fetchColumn());
}

/**
 * Check if current user is superadmin.
 */
function entityAccessIsSuperAdmin(PDO $pdo): bool
{
    return getCurrentUserRole($pdo) === 'superadmin';
}

/**
 * Check if current user is admin.
 */
function entityAccessIsAdmin(PDO $pdo): bool
{
    return getCurrentUserRole($pdo) === 'admin';
}

/**
 * Check if current user is staff.
 */
function entityAccessIsStaff(PDO $pdo): bool
{
    return getCurrentUserRole($pdo) === 'staff';
}

/**
 * Get the resolved owner ID for the current user.
 * For superadmin, returns 0 (meaning "see all").
 * For admin/staff, returns the owner_user_id they belong to.
 */
function getResolvedOwnerId(PDO $pdo): int
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return -1;

    $stmt = $pdo->prepare("SELECT role, company_owner_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return -1;

    $role = strtolower((string) $user['role']);

    /* Superadmin sees everything — return 0 as sentinel */
    if ($role === 'superadmin') {
        return 0;
    }

    /* Admin/Staff: use company_owner_id if set, otherwise fall back to user id */
    $ownerId = (int) ($user['company_owner_id'] ?? 0);
    return $ownerId > 0 ? $ownerId : $userId;
}

/**
 * Get all entities accessible to the current user.
 *
 * @param PDO    $pdo     Database connection
 * @param array  $options Optional filters: ['include_archived' => bool]
 * @return array          Array of company rows
 */
function getAccessibleEntities(PDO $pdo, array $options = []): array
{
    $includeArchived = !empty($options['include_archived']);
    $ownerId = getResolvedOwnerId($pdo);

    /* Superadmin (ownerId == 0): see all companies */
    if ($ownerId === 0) {
        $sql = "SELECT c.id, c.name, c.category, c.pan, c.cin, c.llp_code,
                       c.profile_completeness, c.created_at, c.updated_at,
                       (SELECT COUNT(*) FROM financial_years fy WHERE fy.company_id = c.id) AS fy_count,
                       (SELECT COUNT(*) FROM workflow_status ws WHERE ws.company_id = c.id AND ws.tally_fetched = 1) AS has_data
                FROM companies c
                ORDER BY c.name ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Admin/Staff: see companies where owner_user_id matches resolved owner */
    /* NOTE: NULL owner_user_id entities are NOT visible to admin/staff */
    /* They are only visible to superadmin. This prevents cross-tenant leakage. */
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.category, c.pan, c.cin, c.llp_code,
               c.profile_completeness, c.created_at, c.updated_at,
               (SELECT COUNT(*) FROM financial_years fy WHERE fy.company_id = c.id) AS fy_count,
               (SELECT COUNT(*) FROM workflow_status ws WHERE ws.company_id = c.id AND ws.tally_fetched = 1) AS has_data
        FROM companies c
        WHERE c.owner_user_id = ?
        ORDER BY c.name ASC
    ");
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if the current user can view a specific entity.
 */
function canViewEntity(PDO $pdo, int $entityId): bool
{
    if ($entityId <= 0) return false;

    $ownerId = getResolvedOwnerId($pdo);

    /* Superadmin can view anything */
    if ($ownerId === 0) {
        $stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ?");
        $stmt->execute([$entityId]);
        return (bool) $stmt->fetch();
    }

    /* Admin/Staff: must own the entity */
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND owner_user_id = ?");
    $stmt->execute([$entityId, $ownerId]);
    return (bool) $stmt->fetch();
}

/**
 * Check if the current user can edit a specific entity.
 */
function canEditEntity(PDO $pdo, int $entityId): bool
{
    /* For now, edit permission mirrors view permission */
    /* In future, add permission checks for staff role */
    return canViewEntity($pdo, $entityId);
}

/**
 * Check if the current user can archive a specific entity.
 */
function canArchiveEntity(PDO $pdo, int $entityId): bool
{
    $role = getCurrentUserRole($pdo);

    /* Staff cannot archive unless assignment model grants permission */
    if ($role === 'staff') {
        return false; /* Conservative: staff cannot archive */
    }

    /* Superadmin and admin can archive if they can view */
    return canViewEntity($pdo, $entityId);
}

/**
 * Check if the current user can create entities.
 */
function canCreateEntity(PDO $pdo): bool
{
    $role = getCurrentUserRole($pdo);

    /* Staff cannot create entities */
    if ($role === 'staff') {
        return false;
    }

    /* Superadmin and admin can create */
    return in_array($role, ['superadmin', 'admin'], true);
}

/**
 * Validate entity access and redirect if not permitted.
 * Returns true if access is allowed, false if redirected.
 */
function validateEntityAccessOrRedirect(PDO $pdo, int $entityId, string $action = 'view'): bool
{
    if ($entityId <= 0) {
        header("Location: " . BASE_URL . "dashboard_company.php");
        exit;
    }

    $allowed = match($action) {
        'view'    => canViewEntity($pdo, $entityId),
        'edit'    => canEditEntity($pdo, $entityId),
        'archive' => canArchiveEntity($pdo, $entityId),
        default   => canViewEntity($pdo, $entityId),
    };

    if (!$allowed) {
        $_SESSION['error'] = 'You do not have permission to access this entity.';
        header("Location: " . BASE_URL . "dashboard_company.php");
        exit;
    }

    return true;
}
