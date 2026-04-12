<?php
function renderPolicies($policies) {
?>
<h3>Significant Accounting Policies</h3>

<ul>
<?php foreach ($policies as $p): ?>
    <li><?= $p ?></li>
<?php endforeach; ?>
</ul>

<?php } ?>