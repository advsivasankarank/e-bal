<?php
/**
 * e-BAL — Fixed Asset Register & Depreciation Schedule (Schedule II)
 *
 * Companies Act 2013, Schedule II Part C prescribes useful lives (not
 * depreciation rates directly) for classes of assets; the WDV rate is
 * derived from that useful life using the standard formula
 * Rate = 1 - (ResidualValue / Cost) ^ (1 / UsefulLife), and Note 3 to
 * Schedule II requires pro-rata depreciation (by days) for assets
 * purchased or sold/discarded during the year.
 *
 * Per-asset (or per-PPE-ledger, for companies that maintain one Tally
 * ledger per asset -- common practice, confirmed against real client
 * data) records live in the fixed_assets table. Each row can originate
 * from a prior-year Excel upload (opening balances only), from syncing
 * Tally's already-classified PPE/CWIP ledgers (this year's additions/
 * disposals), or manual entry.
 */

require_once __DIR__ . '/../engines/classification_engine.php';

function ensureFixedAssetRegisterSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fixed_assets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            asset_category VARCHAR(100) NOT NULL DEFAULT '',
            asset_description VARCHAR(255) NOT NULL DEFAULT '',
            source_ledger_name VARCHAR(255) NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            opening_gross_block DECIMAL(18,2) NOT NULL DEFAULT 0,
            opening_accumulated_depreciation DECIMAL(18,2) NOT NULL DEFAULT 0,
            additions_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
            addition_date DATE NULL,
            disposals_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
            disposal_date DATE NULL,
            is_disposed TINYINT(1) NOT NULL DEFAULT 0,
            useful_life_years DECIMAL(6,2) NULL,
            residual_value_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
            depreciation_method VARCHAR(10) NOT NULL DEFAULT 'SLM',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id),
            KEY idx_source_ledger (company_id, fy_id, source_ledger_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fixed_asset_excel_imports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL DEFAULT '',
            row_count INT NOT NULL DEFAULT 0,
            imported_by INT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Schedule II, Part C useful lives (selected common classes). Not
 * exhaustive -- Schedule II lists many sub-classes (e.g. plant used in
 * specific industries with shorter lives); this covers the general/default
 * life for each broad category named in the app's UI. A CA can always
 * override useful_life_years per asset, which is why this is only ever
 * used as a *default*, never enforced.
 */
function scheduleIIUsefulLifeTable(): array
{
    return [
        'Buildings (RCC, other than factory)' => 60,
        'Buildings (other than RCC)' => 30,
        'Factory Buildings' => 30,
        'Plant & Machinery (general)' => 15,
        'Furniture and Fixtures' => 10,
        'Office Equipment' => 5,
        'Computers and Data Processing Units (Servers)' => 6,
        'Computers and Data Processing Units (End User Devices)' => 3,
        'Vehicles (Motor Cars, other than for hire)' => 8,
        'Vehicles (used in a business of running on hire)' => 6,
        'Intangible Assets' => 5,
        'Other' => 10,
    ];
}

function scheduleIIUsefulLife(string $category): array
{
    $table = scheduleIIUsefulLifeTable();

    if (isset($table[$category]) && $category !== 'Intangible Assets' && $category !== 'Other') {
        return ['years' => (float) $table[$category], 'is_prescribed' => true];
    }

    /* Intangible assets are governed by AS 26 (amortised over useful life
       estimated by management), not Schedule II, which applies only to
       tangible property, plant and equipment -- there is no "prescribed"
       life for these, or for an unclassified "Other" category. */
    return ['years' => (float) ($table[$category] ?? 10), 'is_prescribed' => false];
}

function fixedAssetCategoryList(): array
{
    return array_keys(scheduleIIUsefulLifeTable());
}

function getFixedAssets(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureFixedAssetRegisterSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM fixed_assets WHERE company_id = ? AND fy_id = ? ORDER BY asset_category, asset_description');
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * WDV rate derived from useful life and residual value, using the same
 * formula MCA's own Schedule II rate tables were derived from:
 * Rate = 1 - (ResidualValue / Cost) ^ (1 / UsefulLife)
 */
function computeWdvRate(float $usefulLifeYears, float $residualValuePct): float
{
    if ($usefulLifeYears <= 0.0) {
        return 0.0;
    }
    $residualFraction = max(0.0, min(1.0, $residualValuePct / 100));
    if ($residualFraction <= 0.0) {
        // A literal 0% residual makes the formula degenerate (rate = 100%);
        // Schedule II itself never intends full write-off in year one, so
        // floor the residual fraction at a nominal 1% for rate purposes only.
        $residualFraction = 0.01;
    }
    return 1 - ($residualFraction ** (1 / $usefulLifeYears));
}

/**
 * Fraction of the year (by days) an amount was actually held, for pro-rata
 * depreciation per Note 3 to Schedule II. $eventDate is the addition or
 * disposal date; null means "held the whole year" (opening balance with no
 * disposal, i.e. full 365/366 days).
 */
function proRataYearFraction(string $fyStart, string $fyEnd, ?string $eventDate, bool $isAddition): float
{
    $start = new DateTimeImmutable($fyStart);
    $end = new DateTimeImmutable($fyEnd);
    $totalDays = max(1, $end->diff($start)->days + 1);

    if ($eventDate === null || $eventDate === '') {
        return 1.0;
    }

    $event = new DateTimeImmutable($eventDate);
    if ($isAddition) {
        // Held from addition date to FY end.
        $from = $event > $start ? $event : $start;
        if ($from > $end) {
            return 0.0;
        }
        $heldDays = $end->diff($from)->days + 1;
    } else {
        // Held from FY start to disposal date.
        $to = $event < $end ? $event : $end;
        if ($to < $start) {
            return 0.0;
        }
        $heldDays = $to->diff($start)->days + 1;
    }

    return max(0.0, min(1.0, $heldDays / $totalDays));
}

/**
 * Core per-asset depreciation computation for the year, per Schedule II:
 * SLM = (Cost - Residual Value) / Useful Life, pro-rated by days held.
 * WDV = Opening WDV x Rate, with additions pro-rated from their addition
 * date and no depreciation charged on disposed assets beyond their
 * disposal date. Depreciation is capped so the closing WDV never falls
 * below the residual value (Schedule II does not permit depreciating an
 * asset below its residual value).
 */
function computeAssetDepreciation(array $asset, string $fyStart, string $fyEnd): array
{
    $openingGross = (float) ($asset['opening_gross_block'] ?? 0);
    $openingAccumDep = (float) ($asset['opening_accumulated_depreciation'] ?? 0);
    $openingWdv = $openingGross - $openingAccumDep;
    $additions = (float) ($asset['additions_during_year'] ?? 0);
    $disposalsCost = (float) ($asset['disposals_during_year'] ?? 0);
    $isDisposed = !empty($asset['is_disposed']);
    $method = strtoupper((string) ($asset['depreciation_method'] ?? 'SLM'));
    $residualPct = (float) ($asset['residual_value_pct'] ?? 5);
    $usefulLife = (float) ($asset['useful_life_years'] ?? 0);
    if ($usefulLife <= 0.0) {
        $usefulLife = scheduleIIUsefulLife((string) ($asset['asset_category'] ?? ''))['years'];
    }

    $closingGross = $openingGross + $additions - $disposalsCost;
    $residualValue = $closingGross * ($residualPct / 100);

    $depreciationOnOpening = 0.0;
    $depreciationOnAdditions = 0.0;

    if (!$isDisposed || ($asset['disposal_date'] ?? '') === '') {
        if ($method === 'WDV') {
            $rate = computeWdvRate($usefulLife, $residualPct);
            $depreciationOnOpening = $openingWdv * $rate;
            if ($additions > 0.0) {
                $additionFraction = proRataYearFraction($fyStart, $fyEnd, $asset['addition_date'] ?? null, true);
                $depreciationOnAdditions = $additions * $rate * $additionFraction;
            }
        } else { // SLM
            $depreciableAmount = max(0.0, $openingGross - $residualValue);
            $fullYearOpeningDep = $usefulLife > 0 ? $depreciableAmount / $usefulLife : 0.0;
            $openingFraction = proRataYearFraction($fyStart, $fyEnd, null, true);
            $depreciationOnOpening = $fullYearOpeningDep * $openingFraction;

            if ($additions > 0.0) {
                $additionDepreciableAmount = max(0.0, $additions - ($additions * $residualPct / 100));
                $fullYearAdditionDep = $usefulLife > 0 ? $additionDepreciableAmount / $usefulLife : 0.0;
                $additionFraction = proRataYearFraction($fyStart, $fyEnd, $asset['addition_date'] ?? null, true);
                $depreciationOnAdditions = $fullYearAdditionDep * $additionFraction;
            }
        }
    } else {
        /* Disposed asset: depreciate only up to the disposal date, on the
           opening WDV -- no depreciation is charged for the period after
           disposal, since the asset no longer exists in the Company's
           books from that date. */
        $disposalFraction = proRataYearFraction($fyStart, $fyEnd, $asset['disposal_date'] ?? null, false);
        if ($method === 'WDV') {
            $rate = computeWdvRate($usefulLife, $residualPct);
            $depreciationOnOpening = $openingWdv * $rate * $disposalFraction;
        } else {
            $depreciableAmount = max(0.0, $openingGross - $residualValue);
            $fullYearOpeningDep = $usefulLife > 0 ? $depreciableAmount / $usefulLife : 0.0;
            $depreciationOnOpening = $fullYearOpeningDep * $disposalFraction;
        }
    }

    $totalDepreciation = $depreciationOnOpening + $depreciationOnAdditions;

    // Never depreciate below the residual value of what's still on the books.
    $maxDepreciable = max(0.0, ($openingWdv + $additions) - $residualValue);
    $totalDepreciation = min($totalDepreciation, $maxDepreciable);
    $totalDepreciation = max(0.0, $totalDepreciation);

    $closingAccumDep = $openingAccumDep + $totalDepreciation;
    /* On disposal, the disposed asset's own accumulated depreciation is
       removed from the ledger along with its gross block -- approximated
       here as removing accumulated depreciation (opening balance PLUS this
       year's pre-disposal charge, i.e. everything accumulated against the
       disposed portion up to the point it left the books) in the same
       proportion as the disposed cost bears to opening gross block, since
       asset-level disposal detail (which specific units were disposed)
       isn't tracked. Using only the opening balance here (without this
       year's charge) would leave a residual accumulated-depreciation
       balance with no corresponding asset, producing a negative closing
       WDV for a fully-disposed asset. */
    if ($disposalsCost > 0.0 && $openingGross > 0.0) {
        $disposedAccumDepShare = ($openingAccumDep + $depreciationOnOpening) * ($disposalsCost / $openingGross);
        $closingAccumDep = $openingAccumDep + $totalDepreciation - $disposedAccumDepShare;
    }

    $closingWdv = $closingGross - $closingAccumDep;

    return [
        'opening_gross_block' => $openingGross,
        'additions' => $additions,
        'disposals' => $disposalsCost,
        'closing_gross_block' => $closingGross,
        'opening_accumulated_depreciation' => $openingAccumDep,
        'depreciation_for_year' => round($totalDepreciation, 2),
        'closing_accumulated_depreciation' => round($closingAccumDep, 2),
        'closing_wdv' => round($closingWdv, 2),
        'useful_life_years' => $usefulLife,
        'depreciation_method' => $method,
    ];
}

/**
 * Aggregates every fixed_assets row for a company/FY into a Schedule III
 * -format PPE note: opening/additions/disposals/closing gross block,
 * accumulated depreciation, and net block, grouped by asset category.
 */
function computeDepreciationSchedule(PDO $pdo, int $company_id, int $fy_id, string $fyStart, string $fyEnd): array
{
    $assets = getFixedAssets($pdo, $company_id, $fy_id);

    $byCategory = [];
    $totals = [
        'opening_gross_block' => 0.0, 'additions' => 0.0, 'disposals' => 0.0, 'closing_gross_block' => 0.0,
        'opening_accumulated_depreciation' => 0.0, 'depreciation_for_year' => 0.0, 'closing_accumulated_depreciation' => 0.0,
        'closing_wdv' => 0.0,
    ];

    foreach ($assets as $asset) {
        $category = trim((string) ($asset['asset_category'] ?? '')) ?: 'Uncategorised';
        $computed = computeAssetDepreciation($asset, $fyStart, $fyEnd);

        if (!isset($byCategory[$category])) {
            $byCategory[$category] = [
                'category' => $category,
                'opening_gross_block' => 0.0, 'additions' => 0.0, 'disposals' => 0.0, 'closing_gross_block' => 0.0,
                'opening_accumulated_depreciation' => 0.0, 'depreciation_for_year' => 0.0, 'closing_accumulated_depreciation' => 0.0,
                'closing_wdv' => 0.0,
                'asset_count' => 0,
            ];
        }

        foreach ($totals as $key => $_) {
            $byCategory[$category][$key] += $computed[$key];
            $totals[$key] += $computed[$key];
        }
        $byCategory[$category]['asset_count']++;
    }

    ksort($byCategory);

    $methodsUsed = array_values(array_unique(array_map(
        static fn (array $a): string => strtoupper((string) ($a['depreciation_method'] ?? 'SLM')),
        $assets
    )));
    $hasExcelImport = false;
    foreach ($assets as $asset) {
        if (($asset['source'] ?? '') === 'excel_import') {
            $hasExcelImport = true;
            break;
        }
    }

    return [
        'by_category' => array_values($byCategory),
        'totals' => $totals,
        'asset_count' => count($assets),
        'has_data' => $assets !== [],
        'methods_used' => $methodsUsed,
        'has_excel_import' => $hasExcelImport,
    ];
}

/**
 * Parses an uploaded prior-year depreciation-schedule Excel file. Expected
 * columns (case-insensitive header match, order-independent): Asset
 * Category, Description, Opening Gross Block, Opening Accumulated
 * Depreciation, Useful Life (Years), Method. Only the first two are
 * mandatory -- the rest default sensibly (SLM, Schedule II life for the
 * category) so a bare "category + amount" sheet is still usable.
 */
function parseFixedAssetExcelUpload(string $filePath): array
{
    require_once __DIR__ . '/../../vendor/autoload.php';

    $rows = [];
    $errors = [];

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
    } catch (Throwable $e) {
        return ['rows' => [], 'errors' => ['Could not read the Excel file: ' . $e->getMessage()]];
    }

    if (count($data) < 2) {
        return ['rows' => [], 'errors' => ['The file has no data rows below the header.']];
    }

    $headerRow = array_map(static fn ($v) => strtolower(trim((string) $v)), $data[0]);
    $colIndex = static function (array $header, array $candidates): ?int {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $header, true);
            if ($idx !== false) {
                return $idx;
            }
        }
        return null;
    };

    $catCol = $colIndex($headerRow, ['asset category', 'category']);
    $descCol = $colIndex($headerRow, ['description', 'asset description', 'asset']);
    $openGrossCol = $colIndex($headerRow, ['opening gross block', 'opening cost', 'gross block']);
    $openDepCol = $colIndex($headerRow, ['opening accumulated depreciation', 'accumulated depreciation', 'depreciation']);
    $lifeCol = $colIndex($headerRow, ['useful life (years)', 'useful life', 'life']);
    $methodCol = $colIndex($headerRow, ['method', 'depreciation method']);

    if ($catCol === null) {
        $errors[] = 'Could not find an "Asset Category" column in the header row.';
        return ['rows' => [], 'errors' => $errors];
    }

    for ($i = 1; $i < count($data); $i++) {
        $row = $data[$i];
        $category = trim((string) ($row[$catCol] ?? ''));
        if ($category === '') {
            continue; // skip blank rows
        }

        $openGross = (float) ($openGrossCol !== null ? ($row[$openGrossCol] ?? 0) : 0);
        $openDep = (float) ($openDepCol !== null ? ($row[$openDepCol] ?? 0) : 0);

        if ($openDep > $openGross) {
            $errors[] = 'Row ' . ($i + 1) . " (\"{$category}\"): accumulated depreciation (₹" . number_format($openDep, 2)
                . ') exceeds opening gross block (₹' . number_format($openGross, 2) . ') -- skipped.';
            continue;
        }

        $rows[] = [
            'asset_category' => $category,
            'asset_description' => $descCol !== null ? trim((string) ($row[$descCol] ?? '')) : $category,
            'opening_gross_block' => $openGross,
            'opening_accumulated_depreciation' => $openDep,
            'useful_life_years' => $lifeCol !== null && (float) ($row[$lifeCol] ?? 0) > 0 ? (float) $row[$lifeCol] : null,
            'depreciation_method' => $methodCol !== null && strtoupper(trim((string) ($row[$methodCol] ?? ''))) === 'WDV' ? 'WDV' : 'SLM',
        ];
    }

    if ($rows === [] && $errors === []) {
        $errors[] = 'No usable rows found -- check that "Asset Category" values are filled in.';
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function saveFixedAssetExcelImport(PDO $pdo, int $company_id, int $fy_id, array $rows, ?int $userId, string $originalFilename): void
{
    ensureFixedAssetRegisterSchema($pdo);

    $insert = $pdo->prepare("
        INSERT INTO fixed_assets
            (company_id, fy_id, asset_category, asset_description, source, opening_gross_block, opening_accumulated_depreciation, useful_life_years, depreciation_method)
        VALUES (?, ?, ?, ?, 'excel_import', ?, ?, ?, ?)
    ");
    foreach ($rows as $row) {
        $insert->execute([
            $company_id,
            $fy_id,
            $row['asset_category'],
            $row['asset_description'],
            $row['opening_gross_block'],
            $row['opening_accumulated_depreciation'],
            $row['useful_life_years'],
            $row['depreciation_method'],
        ]);
    }

    $logStmt = $pdo->prepare("
        INSERT INTO fixed_asset_excel_imports (company_id, fy_id, original_filename, row_count, imported_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $logStmt->execute([$company_id, $fy_id, $originalFilename, count($rows), $userId]);
}

/**
 * Pulls this year's classified PPE/CWIP ledgers (already mapped via
 * ReconHub, same data the Balance Sheet's Fixed Assets note uses) and
 * creates a candidate fixed_assets row for any ledger not already
 * represented in the register -- so the register can be seeded from
 * what's already in Tally rather than requiring the CA to retype every
 * asset. This year's ledger balance movement (closing - opening, per the
 * TB) is treated as additions (or disposals, if negative); the resulting
 * rows still need asset_category/useful_life confirmed by the CA before
 * they contribute a meaningful depreciation figure.
 */
function syncFixedAssetsFromTallyClassification(PDO $pdo, int $company_id, int $fy_id, array $classified): array
{
    ensureFixedAssetRegisterSchema($pdo);

    $existingStmt = $pdo->prepare("SELECT source_ledger_name FROM fixed_assets WHERE company_id = ? AND fy_id = ? AND source_ledger_name IS NOT NULL");
    $existingStmt->execute([$company_id, $fy_id]);
    $existing = array_flip(array_map('strval', $existingStmt->fetchAll(PDO::FETCH_COLUMN)));

    $insert = $pdo->prepare("
        INSERT INTO fixed_assets
            (company_id, fy_id, asset_category, asset_description, source_ledger_name, source, opening_gross_block, additions_during_year, disposals_during_year)
        VALUES (?, ?, '', ?, ?, 'tally', ?, ?, ?)
    ");

    $created = 0;
    foreach (['ppe', 'cwip'] as $code) {
        foreach (($classified['schedule_items'][$code]['rows'] ?? []) as $row) {
            $ledgerName = trim((string) ($row['ledger_name'] ?? ''));
            if ($ledgerName === '' || isset($existing[$ledgerName])) {
                continue;
            }

            $current = (float) ($row['amount'] ?? 0);
            $previous = (float) ($row['previous_amount'] ?? 0);
            $movement = $current - $previous;

            $insert->execute([
                $company_id,
                $fy_id,
                $ledgerName,
                $ledgerName,
                $previous,
                $movement > 0 ? $movement : 0,
                $movement < 0 ? abs($movement) : 0,
            ]);
            $existing[$ledgerName] = true;
            $created++;
        }
    }

    return ['created' => $created];
}

function updateFixedAssetRow(PDO $pdo, int $company_id, int $fy_id, int $assetId, array $fields): void
{
    $allowed = ['asset_category', 'asset_description', 'useful_life_years', 'residual_value_pct', 'depreciation_method', 'is_disposed', 'disposal_date', 'addition_date'];
    $sets = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $fields)) {
            $sets[] = "{$field} = ?";
            $params[] = $fields[$field];
        }
    }
    if ($sets === []) {
        return;
    }
    $params[] = $company_id;
    $params[] = $fy_id;
    $params[] = $assetId;
    $pdo->prepare('UPDATE fixed_assets SET ' . implode(', ', $sets) . ' WHERE company_id = ? AND fy_id = ? AND id = ?')->execute($params);
}

function deleteFixedAssetRow(PDO $pdo, int $company_id, int $fy_id, int $assetId): void
{
    $pdo->prepare('DELETE FROM fixed_assets WHERE company_id = ? AND fy_id = ? AND id = ?')->execute([$company_id, $fy_id, $assetId]);
}
