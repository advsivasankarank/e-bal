<?php
/**
 * Shared manual-input POST handlers for the two independent, parallel
 * Financial Statement Console implementations: public/statements/financials.php
 * and public/reports.php. Both render the same "Manual Adjustments" form
 * (share capital by type, shareholders, disclosure notes, stock/tax figures)
 * and previously kept their own independent copies of this save logic --
 * every new field had to be added to both by hand, the exact class of bug
 * behind several real fixes this session (Note 1/Note 2 porting gaps, the
 * workflow-flag divergence fixed separately). Both pages now call these
 * functions instead of maintaining their own copy; a new field only needs
 * to be added here (plus both templates) rather than in two save handlers.
 *
 * These functions read $_POST directly (matching the calling pages' own
 * style) and do the database writes only -- each page still owns its own
 * CSRF check and post-save redirect, since those differ per page.
 */

require_once __DIR__ . '/report_manual_helper.php';
require_once __DIR__ . '/share_capital_helper.php';

/** Manual-input keys collected by the "Manual Adjustments" form on both pages. */
function manualCompanyNoteFieldKeys(): array
{
    return [
        'share_capital_authorised',
        'share_capital_issued',
        'share_capital_paidup',
        'note2_opening_profit_loss',
        'note16_opening_raw_materials',
        'note16_closing_raw_materials',
        'note24_opening_finished_goods',
        'note24_opening_work_in_progress',
        'note24_closing_finished_goods',
        'note24_closing_work_in_progress',
        'note24_opening_stock_in_trade',
        'note24_closing_stock_in_trade',
        'tax_provision',
        'note_disclosure_cl',
        'note_disclosure_com',
        'note_disclosure_msme',
        'note_disclosure_rpt',
    ];
}

function saveManualCompanyNoteFromPost(PDO $pdo, int $company_id, int $fy_id, array $manualBundle): void
{
    $classifiedForManualSave = getClassifiedData($pdo, $company_id, $fy_id);

    $postedManualInputs = [];
    foreach (manualCompanyNoteFieldKeys() as $key) {
        $postedManualInputs[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    /* Opening figures for Note 24/16 fall back to last year's own Closing
       when left blank this year -- Closing never carries forward (see
       detectOpeningClosingPairGaps() in report_validation_helper.php for
       why that asymmetry matters). */
    $derivedOpeningFinishedGoods = $manualBundle['previous']['note24_closing_finished_goods'] ?? $manualBundle['current']['note24_opening_finished_goods'] ?? '';
    $derivedOpeningWip = $manualBundle['previous']['note24_closing_work_in_progress'] ?? $manualBundle['current']['note24_opening_work_in_progress'] ?? '';
    $derivedOpeningStockInTrade = $manualBundle['previous']['note24_closing_stock_in_trade'] ?? $manualBundle['current']['note24_opening_stock_in_trade'] ?? '';
    $derivedOpeningRawMaterials = $manualBundle['previous']['note16_closing_raw_materials'] ?? $manualBundle['current']['note16_opening_raw_materials'] ?? '';

    $note2OpeningBalance = trim((string) ($postedManualInputs['note2_opening_profit_loss'] ?? ''));
    $note2ClosingBalance = '';
    if ($note2OpeningBalance !== '') {
        $note2ClosingBalance = (string) (
            (float) $note2OpeningBalance
            + buildCompanyProfitAfterTax($classifiedForManualSave, $postedManualInputs, $manualBundle['previous'] ?? [])
        );
    }

    saveManualInputs($pdo, $company_id, $fy_id, [
        'share_capital_authorised' => $postedManualInputs['share_capital_authorised'],
        'share_capital_issued' => $postedManualInputs['share_capital_issued'],
        'share_capital_paidup' => $postedManualInputs['share_capital_paidup'],
        'note2_opening_profit_loss' => $note2OpeningBalance,
        'note2_closing_profit_loss' => $note2ClosingBalance,
        'note16_opening_raw_materials' => $postedManualInputs['note16_opening_raw_materials'] !== '' ? $postedManualInputs['note16_opening_raw_materials'] : (string) $derivedOpeningRawMaterials,
        'note16_closing_raw_materials' => $postedManualInputs['note16_closing_raw_materials'],
        'note24_opening_finished_goods' => $postedManualInputs['note24_opening_finished_goods'] !== '' ? $postedManualInputs['note24_opening_finished_goods'] : (string) $derivedOpeningFinishedGoods,
        'note24_opening_work_in_progress' => $postedManualInputs['note24_opening_work_in_progress'] !== '' ? $postedManualInputs['note24_opening_work_in_progress'] : (string) $derivedOpeningWip,
        'note24_closing_finished_goods' => $postedManualInputs['note24_closing_finished_goods'],
        'note24_closing_work_in_progress' => $postedManualInputs['note24_closing_work_in_progress'],
        'note24_opening_stock_in_trade' => $postedManualInputs['note24_opening_stock_in_trade'] !== '' ? $postedManualInputs['note24_opening_stock_in_trade'] : (string) $derivedOpeningStockInTrade,
        'note24_closing_stock_in_trade' => $postedManualInputs['note24_closing_stock_in_trade'],
        'tax_provision' => $postedManualInputs['tax_provision'],
        'note_disclosure_cl' => $postedManualInputs['note_disclosure_cl'],
        'note_disclosure_com' => $postedManualInputs['note_disclosure_com'],
        'note_disclosure_msme' => $postedManualInputs['note_disclosure_msme'],
        'note_disclosure_rpt' => $postedManualInputs['note_disclosure_rpt'],
    ]);

    saveShareCapitalClassesFromPost($pdo, $company_id, $fy_id);
    saveShareholdersFromPost($pdo, $company_id, $fy_id);
}

function saveShareCapitalClassesFromPost(PDO $pdo, int $company_id, int $fy_id): void
{
    $shareClassTypes = $_POST['share_class_type'] ?? [];
    $shareClassFaceValues = $_POST['share_class_face_value'] ?? [];
    $shareClassAuthorisedShares = $_POST['share_class_authorised_shares'] ?? [];
    $shareClassOpeningShares = $_POST['share_class_opening_shares'] ?? [];
    $shareClassIssuedDuringYear = $_POST['share_class_issued_during_year'] ?? [];
    $shareClassBoughtBackDuringYear = $_POST['share_class_bought_back_during_year'] ?? [];
    $shareClassClosingShares = $_POST['share_class_closing_shares'] ?? [];

    $rows = [];
    foreach ($shareClassTypes as $index => $shareType) {
        $rows[] = [
            'share_type' => $shareType,
            'face_value' => $shareClassFaceValues[$index] ?? 0,
            'authorised_shares' => $shareClassAuthorisedShares[$index] ?? 0,
            'opening_shares' => $shareClassOpeningShares[$index] ?? 0,
            'issued_during_year' => $shareClassIssuedDuringYear[$index] ?? 0,
            'bought_back_during_year' => $shareClassBoughtBackDuringYear[$index] ?? 0,
            'closing_shares' => $shareClassClosingShares[$index] ?? 0,
        ];
    }
    saveShareCapitalClasses($pdo, $company_id, $fy_id, $rows);
}

function saveShareholdersFromPost(PDO $pdo, int $company_id, int $fy_id): void
{
    $shareholderNames = $_POST['shareholder_name'] ?? [];
    $shareholderShares = $_POST['shareholder_shares'] ?? [];

    $rows = [];
    foreach ($shareholderNames as $index => $name) {
        $rows[] = [
            'name' => $name,
            'shares' => $shareholderShares[$index] ?? 0,
        ];
    }
    saveShareholders($pdo, $company_id, $fy_id, $rows);
}

function carryForwardShareCapitalFromPrevious(
    PDO $pdo,
    int $company_id,
    int $fy_id,
    array $manualBundle,
    array $prevShareCapitalClasses,
    array $prevShareholders
): void {
    saveManualInputs($pdo, $company_id, $fy_id, [
        'share_capital_authorised' => (string) ($manualBundle['previous']['share_capital_authorised'] ?? ''),
        'share_capital_issued' => (string) ($manualBundle['previous']['share_capital_issued'] ?? ''),
        'share_capital_paidup' => (string) ($manualBundle['previous']['share_capital_paidup'] ?? ''),
    ]);
    saveShareCapitalClasses($pdo, $company_id, $fy_id, array_map(
        static fn (array $row): array => [
            'share_type' => $row['share_type'],
            'face_value' => $row['face_value'],
            'authorised_shares' => $row['authorised_shares'],
            'opening_shares' => $row['closing_shares'],
            'issued_during_year' => 0,
            'bought_back_during_year' => 0,
            'closing_shares' => $row['closing_shares'],
        ],
        $prevShareCapitalClasses
    ));
    saveShareholders($pdo, $company_id, $fy_id, array_map(
        static fn (array $row): array => ['name' => $row['name'], 'shares' => $row['shares']],
        $prevShareholders
    ));
}

function confirmNote2OpeningBalanceFromCandidate(PDO $pdo, int $company_id, int $fy_id, array $manualBundle): void
{
    $classifiedForConfirm = getClassifiedData($pdo, $company_id, $fy_id);
    $confirmCandidate = detectProfitLossLedgerOpeningCandidate($classifiedForConfirm);
    if ($confirmCandidate === null) {
        return;
    }

    $confirmOpening = (string) $confirmCandidate['amount'];
    $confirmClosing = (string) (
        $confirmCandidate['amount']
        + buildCompanyProfitAfterTax($classifiedForConfirm, $manualBundle['current'] ?? [], $manualBundle['previous'] ?? [])
    );

    saveManualInputs($pdo, $company_id, $fy_id, [
        'note2_opening_profit_loss' => $confirmOpening,
        'note2_closing_profit_loss' => $confirmClosing,
    ]);
}
