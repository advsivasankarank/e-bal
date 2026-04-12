<?php

function renderNonCorporateFinancials(array $report): void
{
    ?>
    <section class="report-sheet">
        <h1>Non-Corporate Financial Statements</h1>

        <section class="report-section">
            <h2>Balance Sheet</h2>
            <?php foreach (($report['balance_sheet'] ?? []) as $majorSection => $subsections): ?>
                <h3><?= htmlspecialchars((string) $majorSection) ?></h3>
                <?php foreach ($subsections as $subheading => $rows): ?>
                    <h4><?= htmlspecialchars((string) $subheading) ?></h4>
                    <table>
                        <tr>
                            <th>Particulars</th>
                            <th>Current Year</th>
                            <th>Previous Year</th>
                        </tr>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['label'] ?? '')) ?></td>
                                <td><?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                                <td><?= number_format((float) ($row['previous_amount'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </section>

        <section class="report-section">
            <h2>Income and Expenditure / Profit and Loss</h2>
            <table>
                <tr>
                    <th>Particulars</th>
                    <th>Current Year</th>
                    <th>Previous Year</th>
                </tr>
                <?php foreach (($report['pnl'] ?? []) as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['label'] ?? '')) ?></td>
                        <td><?= number_format((float) ($row['amount'] ?? 0), 2) ?></td>
                        <td><?= number_format((float) ($row['previous_amount'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </section>

        <?php renderNotesToAccounts($report['notes'] ?? []); ?>
        <?php renderAccountingPolicies($report['policies'] ?? []); ?>
    </section>
    <?php
}
