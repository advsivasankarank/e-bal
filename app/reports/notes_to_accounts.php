<?php

function renderNotesToAccounts(array $notesData): void
{
    if ($notesData === []) {
        return;
    }
    ?>
    <section class="report-section">
        <h2>Notes to Accounts</h2>

        <?php $noteNo = 1; ?>
        <?php foreach ($notesData as $note): ?>
            <article class="note-block">
                <h3>Note <?= $noteNo ?>: <?= htmlspecialchars((string) ($note['title'] ?? 'Note')) ?></h3>

                <?php if (!empty($note['table'])): ?>
                    <table>
                        <tr>
                            <?php foreach (($note['headers'] ?? []) as $header): ?>
                                <th><?= htmlspecialchars((string) $header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($note['table'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= is_numeric($cell) ? number_format((float) $cell, 2) : htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <?php if (!empty($note['text'])): ?>
                    <p><?= nl2br(htmlspecialchars((string) $note['text'])) ?></p>
                <?php endif; ?>
            </article>
            <?php $noteNo++; ?>
        <?php endforeach; ?>
    </section>
    <?php
}
