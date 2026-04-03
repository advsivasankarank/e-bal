<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../layouts/header.php';

// Fetch companies
$stmt = $pdo->query("SELECT * FROM companies ORDER BY id DESC");
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Company List</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        a.button {
            padding: 6px 10px;
            background: #007BFF;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
        }
        .top-bar {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<h2>Company List</h2>

<div class="top-bar">
    <a href="company_create.php" class="button">+ Create New Company</a>
</div>

<?php if (count($companies) > 0): ?>

<table>
    <tr>
        <th>ID</th>
        <th>Company Name</th>
        <th>CIN</th>
        <th>Created At</th>
        <th>Action</th>
    </tr>

    <?php foreach ($companies as $c): ?>
    <tr>
        <td><?php echo $c['id']; ?></td>
        <td><?php echo htmlspecialchars($c['company_name']); ?></td>
        <td><?php echo htmlspecialchars($c['cin']); ?></td>
        <td><?php echo $c['created_at']; ?></td>
        <td>
            <a class="button" href="fy_create.php?company_id=<?php echo $c['id']; ?>">
                Select
            </a>
        </td>
    </tr>
    <?php endforeach; ?>

</table>

<?php else: ?>

<p>No companies found. Please create one.</p>

<?php endif; ?>

<?php
$back_link = "index.php";
$next_link = "fy_create.php";
require_once __DIR__ . '/../layouts/navigation.php';
require_once __DIR__ . '/../layouts/footer.php';
?>

</body>
</html>
