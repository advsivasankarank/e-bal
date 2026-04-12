<?php
require_once __DIR__ . '/../app/bootstrap.php';

$message = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $company_name = trim($_POST['company_name']);
    $cin = trim($_POST['cin']);

    if ($company_name != "") {

        $stmt = $pdo->prepare("INSERT INTO companies (company_name, cin) VALUES (?, ?)");
        $stmt->execute([$company_name, $cin]);

        $message = "Company created successfully!";
    } else {
        $message = "Company Name is required!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Company</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        input { padding: 8px; margin: 5px; width: 300px; }
        button { padding: 10px 15px; }
        .msg { margin: 10px 0; color: green; }
        .error { color: red; }
    </style>
</head>
<body>

<h2>Create Company</h2>

<?php if ($message): ?>
    <div class="msg"><?php echo $message; ?></div>
<?php endif; ?>

<form method="POST">

    <label>Company Name</label><br>
    <input type="text" name="company_name" required><br>

    <label>CIN (Optional)</label><br>
    <input type="text" name="cin"><br>

    <button type="submit">Create Company</button>

</form>

<br>
<a href="company_list.php">View Companies</a>

</body>
</html>