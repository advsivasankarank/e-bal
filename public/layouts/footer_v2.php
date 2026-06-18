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

<script src="<?= BASE_URL ?>asset/js/app_v2.js?v=<?= htmlspecialchars($v2JsVersion ?? (string) time()) ?>"></script>
</body>
</html>
