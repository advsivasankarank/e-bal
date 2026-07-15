<?php
/**
 * Standalone, framework-free assertion script (this repo has no PHPUnit/tests
 * infra — see composer.json). Run with: php tests/bs_diagnostics_nature_test.php
 *
 * Protects acceptance criterion 4 of the BS Diff Drill-down feature:
 * a ledger's nature must always be derived from its Tally parent group,
 * never from the note it currently happens to be mapped to. This is the
 * real bug class the feature exists to catch — see
 * app/helpers/bulk_mapping_helper.php's default rule that maps
 * "Branch / Divisions" ledgers to other_current_assets (an asset-nature
 * note) while app/helpers/parent_group_validation_helper.php classifies
 * that same parent group as liability nature.
 */

require_once __DIR__ . '/../app/helpers/bs_diagnostics_helper.php';

$failures = 0;
$passed = 0;

function bsDiagTestAssert(bool $condition, string $description): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS  $description\n";
    } else {
        $failures++;
        echo "  FAIL  $description\n";
    }
}

echo "BS Diagnostics — nature-from-Tally-group test\n";
echo "================================================\n";

/* 1. The real bug case: Branch / Divisions ledgers default-mapped to an
      asset-nature note (see bulk_mapping_helper.php) must be recognised
      as a liability-nature ledger from the Tally group alone. */
$parentGroup = 'Branch / Divisions';
$wronglyAssignedNote = 'other_current_assets';

bsDiagTestAssert(
    normalizeParentGroupNature($parentGroup) === 'liability',
    'Tally group "Branch / Divisions" is classified as liability nature'
);
bsDiagTestAssert(
    normalizeScheduleCodeNature($wronglyAssignedNote) === 'asset',
    'Note "other_current_assets" is classified as asset nature'
);
bsDiagTestAssert(
    isScheduleCodeAllowedForParentGroup($parentGroup, $wronglyAssignedNote) === false,
    'Ledger under "Branch / Divisions" mapped to "other_current_assets" is flagged as a conflict'
);

$conflict = buildParentGroupConflict('NMC - SH', $parentGroup, $wronglyAssignedNote);
bsDiagTestAssert(
    ($conflict['parent_group_nature'] ?? null) === 'liability',
    'buildParentGroupConflict() reports ledger_nature from the Tally group (liability)'
);
bsDiagTestAssert(
    ($conflict['schedule_code_nature'] ?? null) === 'asset',
    'buildParentGroupConflict() reports the current note\'s own nature (asset) separately'
);

/* 2. Nature must stay identical for the same Tally group regardless of
      which note the ledger currently happens to be mapped to — proving
      it is never derived from the current note. */
$currentNoteA = 'other_current_assets';
$currentNoteB = 'cash';
$conflictA = buildParentGroupConflict('Ledger A', $parentGroup, $currentNoteA);
$conflictB = buildParentGroupConflict('Ledger A', $parentGroup, $currentNoteB);
bsDiagTestAssert(
    $conflictA['parent_group_nature'] === $conflictB['parent_group_nature'],
    'ledger_nature is identical across different current-note assignments for the same Tally group'
);

/* 3. Guardrail: suggested-note candidates for a liability-nature ledger
      must themselves all be liability-nature notes — never guessed from
      the (wrong) current note, and never a note of a different nature. */
$candidates = bsDiagSuggestedNoteCandidates('liability', 'corporate');
bsDiagTestAssert(count($candidates) > 0, 'At least one liability-nature note candidate is found for corporate entities');
$allLiability = true;
foreach ($candidates as $candidate) {
    if (normalizeScheduleCodeNature($candidate['note_id']) !== 'liability') {
        $allLiability = false;
        break;
    }
}
bsDiagTestAssert($allLiability, 'Every suggested candidate note is itself liability-nature (guardrail never mixes natures)');

echo "================================================\n";
echo "$passed passed, $failures failed\n";

exit($failures > 0 ? 1 : 0);
