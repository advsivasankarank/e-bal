<?php
/**
 * e-BAL V2 — Application Shell Footer
 *
 * Closes the main content area, sidebar, and layout wrappers.
 * Loads app_v2.js.
 */
?>
        </div> <!-- .v2-content -->
    </main>

</div> <!-- .v2-layout -->

<script>var ebalBridgeUrl = <?= json_encode($bridgeUrl ?? 'http://127.0.0.1:9123') ?>;</script>
<script src="<?= BASE_URL ?>asset/js/app_v2.js?v=<?= htmlspecialchars($v2JsVersion ?? (string) time()) ?>"></script>
<script src="<?= BASE_URL ?>asset/js/bridge_connectivity.js?v=<?= htmlspecialchars($bridgeJsVersion ?? (string) time()) ?>"></script>
</body>
</html>
