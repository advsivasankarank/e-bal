<?php

function renderNotesToAccounts($notesData) {
?>

<h2>Notes to Accounts</h2>

<?php
$noteNo = 1;

foreach ($notesData as $noteKey => $note) {
?>
    <div class="note-block">
        <h3>Note <?= $noteNo ?> – <?= htmlspecialchars($note['title']) ?></h3>

        <?php if (!empty($note['table'])): ?>
            <table border="1" width="100%">
                <tr>
                    <?php foreach ($note['headers'] as $header): ?>
                        <th><?= htmlspecialchars($header) ?></th>
                    <?php endforeach; ?>
                </tr>

                <?php foreach ($note['table'] as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <?php if (!empty($note['text'])): ?>
            <p><?= nl2br(htmlspecialchars($note['text'])) ?></p>
        <?php endif; ?>

    </div>
    <br>
<?php
    $noteNo++;
}
?>

<?php } ?>