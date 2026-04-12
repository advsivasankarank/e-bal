<?php
function renderCompanyNotes($notes) {
?>
<h3>Notes to Accounts</h3>

<?php foreach ($notes as $note): ?>
    <h4><?= $note['title'] ?></h4>
    <p><?= $note['content'] ?></p>
<?php endforeach; ?>

<?php } ?>