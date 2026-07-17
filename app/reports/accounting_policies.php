<?php

/**
 * $depreciationInfo, when supplied by notes_company.php from the computed
 * Depreciation Schedule (app/helpers/fixed_asset_helper.php), lets the
 * Depreciation policy line disclose what was ACTUALLY done (method(s) in
 * use, whether opening balances came from an uploaded prior-year schedule)
 * rather than a generic assertion the engine may not back up -- the same
 * "policy text must match what the code actually computes" principle
 * already applied to the Deferred Tax and Cash Flow Statement policy lines.
 * Shape: ['methods_used' => ['SLM','WDV',...], 'has_excel_import' => bool].
 * Null (or omitted) falls back to the original generic wording, used when
 * no Asset Register has been populated for this company/FY yet.
 */
function getAccountingPolicies(string $entity, ?array $depreciationInfo = null): array
{
    switch ($entity) {
        case 'corporate':
            return [
                'Basis of Preparation: The financial statements have been prepared on the accrual basis of accounting under the historical cost convention, in accordance with the applicable Accounting Standards notified under Section 133 of the Companies Act, 2013 read with the Companies (Accounts) Rules, 2014, and are presented in the format prescribed under Schedule III (Division I) to the Companies Act, 2013.',
                'Use of Estimates: The preparation of financial statements requires management to make estimates and assumptions that affect the reported amounts of assets, liabilities, income and expenses, and disclosure of contingent liabilities as at the date of the financial statements. Actual results could differ from these estimates; differences, if any, are recognised in the period in which they are known.',
                'Revenue Recognition (AS 9): Revenue from sale of goods is recognised when the significant risks and rewards of ownership are transferred to the buyer. Revenue from services is recognised on rendering of the service. Interest income is recognised on a time-proportion basis; dividend income is recognised when the right to receive payment is established.',
                'Property, Plant & Equipment (AS 10): Property, plant and equipment are stated at cost of acquisition or construction, less accumulated depreciation and impairment losses, if any. Cost includes the purchase price and directly attributable costs of bringing the asset to its working condition for intended use.',
                buildDepreciationPolicyStatement($depreciationInfo),
                'Intangible Assets (AS 26): Intangible assets are recognised at cost and are carried at cost less accumulated amortisation and impairment losses, if any.',
                'Impairment of Assets (AS 28): The carrying amount of assets is reviewed at each balance sheet date for indications of impairment. An impairment loss is recognised in the Statement of Profit and Loss wherever the carrying amount of an asset exceeds its recoverable amount.',
                'Investments (AS 13): Long-term investments are stated at cost, less provision for diminution other than temporary in nature. Current investments are carried at the lower of cost and fair value.',
                'Inventories (AS 2): Inventories are valued at the lower of cost and net realisable value. Cost is determined on a FIFO / weighted average basis and includes all costs of purchase, conversion and other costs incurred in bringing the inventories to their present location and condition.',
                'Foreign Currency Transactions (AS 11): Transactions in foreign currency are recorded at the exchange rate prevailing on the date of the transaction. Monetary assets and liabilities denominated in foreign currency are restated at the year-end exchange rate, and the resulting exchange differences are recognised in the Statement of Profit and Loss.',
                'Employee Benefits (AS 15): Short-term employee benefits are recognised as an expense in the period in which the related service is rendered. Contributions to defined contribution plans (e.g. Provident Fund) are charged to the Statement of Profit and Loss. Defined benefit obligations (e.g. gratuity), where applicable, are determined based on actuarial valuation.',
                'Borrowing Costs (AS 16): Borrowing costs directly attributable to the acquisition or construction of a qualifying asset are capitalised as part of the cost of that asset. All other borrowing costs are recognised as an expense in the period in which they are incurred.',
                'Taxes on Income (AS 22): Tax expense comprises current tax and deferred tax. Current tax is measured based on taxable income for the year, computed in accordance with the provisions of the Income-tax Act, 1961. Deferred tax is recognised on timing differences between accounting income and taxable income, subject to the consideration of prudence; the deferred tax asset/liability balances carried in these financial statements are as recorded in the Company\'s books and have not been independently recomputed by this system -- refer Note on Deferred Tax for this disclosure.',
                'Provisions, Contingent Liabilities and Contingent Assets (AS 29): A provision is recognised when the Company has a present obligation as a result of a past event, it is probable that an outflow of resources will be required to settle the obligation, and a reliable estimate can be made of the amount of the obligation. Contingent liabilities are disclosed by way of notes; contingent assets are neither recognised nor disclosed in the financial statements.',
                'Cash and Cash Equivalents: Cash and cash equivalents comprise cash on hand, demand deposits with banks, and other short-term, highly liquid investments with an original maturity of three months or less that are readily convertible into known amounts of cash.',
                'Cash Flow Statement: A separate Statement of Cash Flows has not been presented with these financial statements. Where the Company does not qualify for the small company / One Person Company exemption under the proviso to Section 2(40) of the Companies Act, 2013, a Cash Flow Statement (prepared under AS 3) should be appended separately -- this determination depends on the Company\'s paid-up capital, turnover and borrowings for the year and should be confirmed before finalisation.',
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

function buildDepreciationPolicyStatement(?array $depreciationInfo): string
{
    if ($depreciationInfo === null || empty($depreciationInfo['methods_used'])) {
        /* No Asset Register populated for this company/FY yet -- generic
           wording only, since the engine has nothing more specific to
           disclose (falls back to a flat Trial Balance ledger figure). */
        return 'Depreciation: Depreciation on property, plant and equipment is provided on the straight-line / written-down value method at the rates and in the manner prescribed under Schedule II to the Companies Act, 2013, based on the useful life of the assets.';
    }

    $methods = $depreciationInfo['methods_used'];
    $methodLabel = static function (string $m): string {
        return $m === 'WDV' ? 'Written-Down Value (WDV)' : 'Straight-Line Method (SLM)';
    };
    $methodText = count($methods) > 1
        ? implode(' and ', array_map($methodLabel, $methods)) . ', as applicable to each class of asset,'
        : $methodLabel($methods[0]);

    $openingSource = !empty($depreciationInfo['has_excel_import'])
        ? ' Opening balances for the current year have been derived from a prior-year depreciation schedule uploaded by the preparer.'
        : '';

    return 'Depreciation: Depreciation on property, plant and equipment is provided on the basis of useful lives prescribed under Schedule II of the Companies Act, 2013, using the '
        . $methodText . ', with pro-rata depreciation for assets acquired or disposed during the year.' . $openingSource;
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
