<?php
function currentUserId(): int
{
    return (int)($_SESSION['uid'] ?? 0);
}

function currentUserRole(): string
{
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    return match ($role) {
        'admin', 'super_admin', 'super admin' => 'super_admin',
        'seller', 'agent' => 'agent',
        default => $role,
    };
}

function requireRole(array $allowedRoles): void
{
    if (currentUserId() < 1) {
        http_response_code(401);
        exit('Sign in to access this page.');
    }

    $roles = array_map(static function ($role): string {
        $role = strtolower(trim((string)$role));
        return match ($role) {
            'admin', 'super_admin', 'super admin' => 'super_admin',
            'seller', 'agent' => 'agent',
            default => $role,
        };
    }, $allowedRoles);
    if (!in_array(currentUserRole(), $roles, true)) {
        http_response_code(403);
        exit('You are not authorized to access this page.');
    }

    if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'seller') {
        $_SESSION['role'] = 'agent';
    }

    global $con;
    if (isset($con) && $con instanceof mysqli) {
        $statement = $con->prepare('SELECT role, is_active FROM user WHERE user_id = ? LIMIT 1');
        if (!$statement) {
            http_response_code(503);
            exit('Account access is temporarily unavailable.');
        }
        $userId = currentUserId();
        $statement->bind_param('i', $userId);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();
        $statement->close();
        $databaseRole = strtolower(trim((string)($user['role'] ?? '')));
        $databaseRole = match ($databaseRole) {
            'admin', 'super_admin', 'super admin' => 'super_admin',
            'seller', 'agent' => 'agent',
            default => $databaseRole,
        };
        if (!$user
            || (int)$user['is_active'] !== 1
            || $databaseRole !== currentUserRole()) {
            $_SESSION = [];
            session_destroy();
            http_response_code(403);
            exit('Account access has changed. Sign in again.');
        }
    }
}

function dashboardPathForRole(string $role): ?string
{
    return match (strtolower(trim($role))) {
        'buyer' => 'index.php',
        'seller', 'agent' => 'agent_dashboard.php',
        'admin', 'super_admin', 'super admin' => 'admin/dashboard.php',
        default => null,
    };
}
?>
