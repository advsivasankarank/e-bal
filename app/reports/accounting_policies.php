<?php

function getAccountingPolicies(string $entity): array
{
    switch ($entity) {
        case 'corporate':
            return [
                'The financial statements are prepared on the accrual basis of accounting and historical cost convention.',
                'Revenue and expenses are recognised in the period to which they relate.',
                'Property, plant and equipment are carried at cost less accumulated depreciation and impairment, if any.',
                'Inventories are valued at the lower of cost and net realisable value.',
            ];

        case 'llp':
            return [
                'The LLP financial statements are prepared on accrual basis and under the historical cost convention.',
                'Income and expenditure are recognised in the period to which they relate.',
                'Fixed assets are stated at cost less accumulated depreciation, where applicable.',
                'Closing inventories are valued at the lower of cost and net realisable value.',
            ];

        case 'non_corporate':
        default:
            return [
                'The financial statements are prepared on the accrual basis of accounting.',
                'Assets are stated at historical cost less depreciation or impairment, where applicable.',
                'Income and expenditure are recognised in the period in which they accrue.',
                'Inventories are valued at the lower of cost and net realisable value.',
            ];
    }
}

function renderAccountingPolicies(array $policies): void
{
    if ($policies === []) {
        return;
    }
    ?>
    <section class="report-section">
        <h2>Significant Accounting Policies</h2>
        <ol class="policy-list">
            <?php foreach ($policies as $policy): ?>
                <li><?= htmlspecialchars((string) $policy) ?></li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php
}
