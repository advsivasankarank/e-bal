<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (!isset($_GET['company_id'])) {
    die("Invalid access. Company not selected.");
}

$company_id = intval($_GET['company_id']);

// Fetch company details
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$company_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    die("Company not found.");
}

$message = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $fy_start = $_POST['fy_start'];
    $fy_end = $_POST['fy_end'];

    if ($fy_start && $fy_end) {

        $stmt = $pdo->prepare("
            INSERT INTO financial_years (company_id, fy_start, fy_end)
            VALUES (?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$company_id, $fy_start, $fy_end]);

        $fy_id = $stmt->fetchColumn();

        // Redirect to Tally Fetch
        header("Location: tally_fetch.php?company_id=$company_id&fy_id=$fy_id");
        exit;

    } else {
        $message = "Please select Financial Year properly.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Select Financial Year</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        input { padding: 8px; margin: 5px; width: 250px; }
        button { padding: 10px 15px; }
        .msg { color: red; margin: 10px 0; }
    </style>
</head>
<body>

<h2>Select Financial Year</h2>

<h3>Company: <?php echo htmlspecialchars($company['company_name']); ?></h3>

<?php if ($message): ?>
    <div class="msg"><?php echo $message; ?></div>
<?php endif; ?>

<form method="POST">

    <label>Financial Year Start</label><br>
    <input type="date" name="fy_start" required><br>

    <label>Financial Year End</label><br>
    <input type="date" name="fy_end" required><br>

    <button type="submit">Proceed to Fetch from Tally</button>

</form>

<br>
<a href="company_list.php">← Back to Company List</a>

</body>
</html>