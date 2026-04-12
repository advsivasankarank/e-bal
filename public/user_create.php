<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/layouts/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = trim((string) ($_POST['role'] ?? 'staff'));
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Name, email, and password are required.';
    }

    if ($userId > 0 && !canAddUser($userId, $pdo)) {
        $errors[] = 'User limit reached. Upgrade your plan.';
    }

    if ($errors === []) {
        $ownerId = getOwnerUserId($pdo, $userId);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, company_owner_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $email, $hash, $role, $ownerId]);
        $_SESSION['success'] = 'User created successfully.';
        header('Location: user_create.php');
        exit;
    }
}
?>

<div class="page-title">Create User</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="error-box">
        <?php foreach ($errors as $err): ?>
            <p><?= htmlspecialchars($err) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="card section-card" style="max-width:520px;">
    <div class="form-group">
        <label for="name">Full Name</label>
        <input id="name" name="name" type="text" required>
    </div>
    <div class="form-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required>
    </div>
    <div class="form-group">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>
    </div>
    <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
        </select>
    </div>
    <button class="btn-primary" type="submit">Create User</button>
</form>

<?php
unset($_SESSION['success']);
require_once __DIR__ . '/layouts/footer.php';
?>
