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

<script src="<?= BASE_URL ?>asset/js/app.js"></script>
</body>
</html>
