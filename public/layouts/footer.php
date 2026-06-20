<?php
/* V2 mode: delegate to V2 footer */
if (!empty($_SESSION['v2_mode'])) {
    require_once __DIR__ . '/footer_v2.php';
    return;
}
?>
</div> <!-- page-wrapper -->

<div class="footer">
    &copy; <?= date('Y') ?> e-BAL | Structured Balance Sheet Tool by E Tax Advisors Private Limited
</div>

<script>var ebalBridgeUrl = <?= json_encode($bridgeUrl ?? 'http://127.0.0.1:9123') ?>;</script>
<script src="<?= BASE_URL ?>asset/js/app.js"></script>
<script src="<?= BASE_URL ?>asset/js/bridge_connectivity.js?v=<?= htmlspecialchars($bridgeJsVersion ?? (string) time()) ?>"></script>
</body>
</html>
