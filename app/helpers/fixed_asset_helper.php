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
require_once __DIR__ . '/voucher_sync.php';

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

function fixedAssetColumnExists(PDO $pdo, string $columnName): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND column_name = ? LIMIT 1");
    $stmt->execute([$columnName]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Adds the columns needed for voucher-level Tally sync (each fixed_assets
 * row created from a voucher records which voucher it came from, so a
 * re-sync is idempotent and the CA can trace every figure back to its
 * source transaction). Added defensively via information_schema checks
 * rather than "ADD COLUMN IF NOT EXISTS" -- matches the pattern used by
 * report_manual_helper.php's manualInputColumnExists() -- since this needs
 * to run safely against an already-populated table on every page load.
 */
function ensureFixedAssetVoucherColumns(PDO $pdo): void
{
    if (!fixedAssetColumnExists($pdo, 'tally_voucher_entry_id')) {
        $pdo->exec("ALTER TABLE fixed_assets ADD COLUMN tally_voucher_entry_id BIGINT NULL AFTER source_ledger_name");
    }
    if (!fixedAssetColumnExists($pdo, 'voucher_type')) {
        $pdo->exec("ALTER TABLE fixed_assets ADD COLUMN voucher_type VARCHAR(100) NULL AFTER tally_voucher_entry_id");
    }
    if (!fixedAssetColumnExists($pdo, 'voucher_classification')) {
        $pdo->exec("ALTER TABLE fixed_assets ADD COLUMN voucher_classification VARCHAR(20) NULL AFTER voucher_type");
    }
    if (!fixedAssetColumnExists($pdo, 'voucher_narration')) {
        $pdo->exec("ALTER TABLE fixed_assets ADD COLUMN voucher_narration VARCHAR(500) NULL AFTER voucher_classification");
    }

    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND index_name = 'uk_fa_voucher_entry' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        try {
            $pdo->exec("ALTER TABLE fixed_assets ADD UNIQUE KEY uk_fa_voucher_entry (company_id, fy_id, tally_voucher_entry_id)");
        } catch (PDOException $e) {
            // Best-effort -- if it already exists under a different check path, ignore.
        }
    }
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
    /* An explicit 0 (Land, or any other asset the source data marks as
       having no useful life) must NOT be treated the same as "not set at
       all" -- Land is genuinely never depreciated under Schedule II, and
       silently substituting a Schedule II default life for it would
       start charging depreciation on an asset that should never carry
       any. Both the SLM and WDV branches below already correctly produce
       zero depreciation for a genuine usefulLife of 0 (computeWdvRate()
       returns 0.0; the SLM branch's usefulLife>0 guard returns 0.0) --
       this fallback only needs to trigger when the field is truly unset. */
    $usefulLifeRaw = $asset['useful_life_years'] ?? null;
    if ($usefulLifeRaw === null || $usefulLifeRaw === '') {
        $usefulLife = scheduleIIUsefulLife((string) ($asset['asset_category'] ?? ''))['years'];
    } else {
        $usefulLife = (float) $usefulLifeRaw;
    }

    $closingGross = $openingGross + $additions - $disposalsCost;
    /* A voucher-synced disposal-only row (no opening balance or addition of
       its own -- the asset it's disposing of was added in a prior year or
       via a different row) can legitimately swing closingGross negative;
       that's fine for category-level aggregation (it correctly nets against
       the category's other rows), but must not leak into the residual-value/
       depreciation formulas below, which would otherwise derive a phantom
       depreciation charge from a negative "cost" base. */
    $residualValue = max(0.0, $closingGross) * ($residualPct / 100);

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
/**
 * Registry of recognised prior-year fixed-asset Excel formats -- every
 * CA/client hands over a differently-shaped export, and this app needs to
 * fit as many of those as reasonably possible without hand-holding for
 * each new company. Each entry is tried in order against the raw sheet
 * data; the first whose 'detect' callback returns true owns the parse.
 * Order matters: more structurally distinctive formats (a real audited
 * Note 11 export, unmistakable from its title cell) must be tried before
 * generic ones (a plain header-row template) that could otherwise
 * false-match a differently-labelled sheet.
 *
 * To support a new client's export shape: add an entry here with a
 * `detect` callback that's specific enough not to misfire on other
 * formats, and a `parse` callback returning ['rows' => [...], 'errors' =>
 * [...]] in the same shape produced by every other format. Nothing else
 * in the upload pipeline needs to change.
 */
function getFixedAssetExcelFormats(): array
{
    return [
        [
            'name' => 'audited_note11',
            'label' => 'Audited Schedule III Note 11 (Property, Plant and Equipment) export',
            /* A genuine audited Note 11 export (Gross Block / Accumulated
               Depreciation / Net Block column groups, multi-row merged
               headers, category-grouping rows) is a fundamentally
               different shape from a simple flat template -- reliably
               identified by its distinctive title cell. */
            'detect' => static function (array $data): bool {
                $titleCell = trim((string) ($data[0][0] ?? ''));
                return stripos($titleCell, 'property') !== false
                    && stripos($titleCell, 'plant') !== false
                    && stripos($titleCell, 'equipment') !== false;
            },
            'parse' => 'parseScheduleIIINote11Excel',
        ],
        [
            'name' => 'flat_template',
            'label' => 'Flat spreadsheet with an "Asset Category" column header',
            /* Deliberately generic: any sheet whose header row (row 1)
               contains a recognisable "Asset Category" column, in any
               column position. This is the catch-all for a CA's own
               hand-built template, so it's tried last -- a more specific
               format above should claim a file before this one gets the
               chance to (mis)interpret it. */
            'detect' => static function (array $data): bool {
                $headerRow = array_map(static fn ($v) => strtolower(trim((string) $v)), $data[0] ?? []);
                return in_array('asset category', $headerRow, true) || in_array('category', $headerRow, true);
            },
            'parse' => 'parseFixedAssetFlatTemplateExcel',
        ],
    ];
}

function parseFixedAssetExcelUpload(string $filePath): array
{
    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        /* setReadDataOnly() skips loading cell styles/formatting/merged-cell
           metadata entirely -- for a real-world audited Schedule III export
           (heavy multi-row merged headers, formatting throughout), this is
           the difference between comfortably fitting in a constrained
           shared-hosting PHP memory_limit and hitting it. Confirmed against
           a real 3,146-row Note 11 export that pushed close to a typical
           web-facing memory_limit even before this change. */
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
    } catch (Throwable $e) {
        return ['rows' => [], 'errors' => ['Could not read the Excel file: ' . $e->getMessage()]];
    }

    if (count($data) < 2) {
        return ['rows' => [], 'errors' => ['The file has no data rows below the header.']];
    }

    foreach (getFixedAssetExcelFormats() as $format) {
        if (($format['detect'])($data)) {
            $result = ($format['parse'])($data);
            $result['format'] = $format['name'];
            return $result;
        }
    }

    $knownFormats = implode('; ', array_map(static fn ($f) => $f['label'], getFixedAssetExcelFormats()));
    return [
        'rows' => [],
        'errors' => [
            "This file's format wasn't recognised. Currently supported: {$knownFormats}. "
            . 'If this is a genuine prior-year depreciation schedule in a different layout, forward it to support so this format can be added.',
        ],
    ];
}

/**
 * A simple, hand-built flat template: one header row, one asset per data
 * row, columns identified by header text rather than fixed position (so
 * column order doesn't matter). This is the fallback format for a CA who
 * doesn't have a real Tally/audited export handy and is happy to fill in
 * a plain spreadsheet instead.
 */
function parseFixedAssetFlatTemplateExcel(array $data): array
{
    $rows = [];
    $errors = [];

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

/**
 * Parses a real, audited Schedule III Note 11 (Property, Plant and
 * Equipment) export -- the working paper a CA would actually have on
 * hand for a company's first year in this app, not a hand-typed simple
 * template. Column positions (0-indexed, matching the standard layout
 * confirmed against a real 3,146-row export):
 *   1  = Description, 4 = Useful Life (Years), 6 = Opening Gross Block,
 *   18 = Opening Accumulated Depreciation.
 *
 * The one reliable signal that a row is a genuine depreciable asset (as
 * opposed to a category-grouping row like "Tangible assets"/"Own
 * Assets"/"Leased Assets", a "Sub Total"/"Total (A)"/"P.Y Total" row, or
 * a Capital Work-in-Progress / "Intangible assets under Development"
 * line) is that ONLY real asset rows carry a numeric Useful Life --
 * confirmed against the real export, where every one of those other row
 * types leaves that column blank. This one check does all the
 * filtering; no row-number or keyword heuristics needed. Parsing stops
 * entirely at the "Current Year Total" row, since real-world exports of
 * this kind are sometimes followed by an unrelated second workpaper
 * appended to the same sheet.
 *
 * Below that "Current Year Total" row, this same kind of export commonly
 * carries a SECOND workpaper -- "Statement showing assets wise
 * calculation of depreciation..." -- one block per named asset-type,
 * itemising the individual physical units that make it up (purchase
 * date, original cost, WDV as at the prior year-end). This is the only
 * place a real purchase/put-to-use date exists in the file, so it's used
 * to enrich the opening-balance rows above with per-unit dates -- but the
 * audited top-of-sheet totals (already reconciled to the signed prior-
 * year financials) remain authoritative for the money figures: each
 * asset-type's individual units are scaled so their sum ties exactly to
 * that asset-type's own audited opening gross/accumulated-depreciation,
 * never the other way round (confirmed against the real export that this
 * detail workpaper is a live working file, not a frozen audited
 * snapshot -- its per-item totals drift from the summary by a few
 * percent in places).
 */
function parseScheduleIIINote11Excel(array $data): array
{
    $topRows = [];
    $errors = [];

    $cleanNumber = static function ($value): string {
        // Large aggregate figures in this export format can come through
        // with a literal embedded newline from Excel's own text wrapping
        // (e.g. "10,04,22,692.\n37") -- stripped here along with the
        // Indian-numbering commas before any numeric cast.
        return str_replace([',', "\n", "\r", ' '], '', (string) $value);
    };

    foreach ($data as $row) {
        $description = trim((string) ($row[1] ?? ''));
        if ($description === '') {
            continue;
        }
        if (stripos($description, 'current year total') !== false) {
            break;
        }

        $usefulLifeClean = $cleanNumber($row[4] ?? '');
        /* A blank/non-numeric Useful Life is what distinguishes a category
           header, subtotal, or CWIP/intangible-under-development row from
           a genuine asset -- but a genuine asset can legitimately have a
           useful life of exactly 0 (Land, confirmed against the real
           export: "Land" carries "0" here, since it is never depreciated
           under Schedule II). Only the "not a number at all" case should
           be excluded; 0 itself must be let through and preserved as-is
           (see computeAssetDepreciation()'s matching fix, which only
           falls back to a Schedule II default when useful_life_years is
           truly unset, not when it's an explicit 0). */
        if ($usefulLifeClean === '' || !is_numeric($usefulLifeClean)) {
            continue;
        }
        $usefulLife = (float) $usefulLifeClean;

        $openingGross = (float) $cleanNumber($row[6] ?? 0);
        $openingAccumDep = (float) $cleanNumber($row[18] ?? 0);

        if ($openingAccumDep > $openingGross) {
            $errors[] = "Row \"{$description}\": accumulated depreciation (₹" . number_format($openingAccumDep, 2)
                . ') exceeds opening gross block (₹' . number_format($openingGross, 2) . ') -- skipped.';
            continue;
        }

        $topRows[normalizeFixedAssetName($description)] = [
            'asset_category' => guessFixedAssetCategoryFromDescription($description),
            'asset_description' => $description,
            'opening_gross_block' => $openingGross,
            'opening_accumulated_depreciation' => $openingAccumDep,
            'useful_life_years' => $usefulLife,
            /* The imported opening accumulated depreciation figures in a
               real audited export are near-universally the result of the
               WDV method at Schedule II rates (the standard practice) --
               used here only as this app's own going-forward default for
               each row; the CA can still change it per-row like any
               other imported asset. */
            'depreciation_method' => 'WDV',
        ];
    }

    if ($topRows === [] && $errors === []) {
        $errors[] = 'No individual depreciable asset rows found in this Note 11 export (only category headers, subtotals, or Capital Work-in-Progress/Intangible-assets-under-development entries, which are not depreciated under Schedule II).';
    }

    $detailBlocks = parseFixedAssetDetailBlocks($data);
    $rows = expandFixedAssetRowsWithDetail($topRows, $detailBlocks, $errors);

    return ['rows' => $rows, 'errors' => $errors];
}

function normalizeFixedAssetName(string $name): string
{
    return trim((string) preg_replace('/\s+/', ' ', $name));
}

/**
 * Parses the "Statement showing assets wise calculation of depreciation"
 * workpaper that commonly follows the Note 11 summary on the same sheet
 * -- one repeating block per named asset-type, each itemising its
 * individual physical units. Column positions inside a block vary (a
 * "Written off from retained earning" column is sometimes inserted,
 * shifting everything after "Original cost" left by one), so columns are
 * located per-block by matching the "Particulars" header row's own text
 * rather than assumed at fixed offsets -- confirmed necessary against
 * the real export, where a naive fixed-offset read silently pulled the
 * wrong figures for some blocks. Metadata rows (asset name / useful
 * life / group) also occasionally wrap across an extra row for long
 * names, or land in a single crammed cell instead of separate columns --
 * handled by concatenating all metadata-row text and extracting with a
 * regex instead of reading fixed cells.
 *
 * Returns [normalizedName => ['group' => string, 'units' => [['cost' =>
 * float, 'wdv' => float, 'purchase_date' => ?string], ...]]].
 */
function parseFixedAssetDetailBlocks(array $data): array
{
    $blocks = [];
    $highestIdx = count($data) - 1;

    $cleanNumber = static function ($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace([',', "\n", "\r", ' '], '', (string) $value);
        return is_numeric($clean) ? (float) $clean : null;
    };
    $findCol = static function (array $headerRow, array $needles): ?int {
        foreach ($headerRow as $c => $v) {
            $text = strtolower(str_replace("\n", ' ', (string) $v));
            foreach ($needles as $needle) {
                if (stripos($text, $needle) !== false) {
                    return $c;
                }
            }
        }
        return null;
    };

    $i = 0;
    while ($i <= $highestIdx) {
        /* Block start is keyed off "Name of Asset" (present in every block,
           either as its own cell or as a prefix inside a crammed/merged
           cell), not the "Statement showing assets wise calculation..."
           title above it -- confirmed against a real export where that
           title only appears once per printed "page" of the original
           report, not before every individual block. Two blocks can
           follow each other directly (block A's "Total" row immediately
           followed by block B's "Name of Asset" row with no title
           in between), and keying off the title alone silently skipped
           every block that started this way -- a real, previously
           undetected data-loss bug, not a naming inconsistency in the
           source file. */
        $col0 = trim((string) ($data[$i][0] ?? ''));
        if (stripos($col0, 'Name of Asset') !== 0) {
            $i++;
            continue;
        }

        $particularsIdx = null;
        for ($scan = $i; $scan <= $i + 8 && $scan <= $highestIdx; $scan++) {
            if (strcasecmp(trim((string) ($data[$scan][0] ?? '')), 'Particulars') === 0) {
                $particularsIdx = $scan;
                break;
            }
        }
        if ($particularsIdx === null) {
            $i++;
            continue;
        }

        $metaText = '';
        for ($mr = $i; $mr < $particularsIdx; $mr++) {
            foreach (($data[$mr] ?? []) as $v) {
                if ($v !== null && $v !== '') {
                    $metaText .= ' ' . (string) $v;
                }
            }
        }
        $assetName = '';
        $groupOfAsset = '';
        if (preg_match('/Name of Asset\s+(.+?)\s+Useful Life \(In Years\)\s+([\d.]+)/is', $metaText, $m)) {
            $assetName = normalizeFixedAssetName($m[1]);
        }
        if (preg_match('/Group of asset\s+(.+?)\s+Shift Type/is', $metaText, $m2)) {
            $groupOfAsset = normalizeFixedAssetName($m2[1]);
        }

        $particularsRow = $data[$particularsIdx] ?? [];
        $origCostCol = $findCol($particularsRow, ['original cost']);
        $openingWdvCol = $findCol($particularsRow, ['opening wdv']);
        $purchaseDateCol = $findCol($particularsRow, ['date of purchase']);

        $dr = $particularsIdx + 2; // skip the numbered index row
        while ($dr <= $highestIdx) {
            $col0 = trim((string) ($data[$dr][0] ?? ''));
            if ($col0 === '') {
                $dr++;
                continue;
            }
            if (strcasecmp($col0, 'Total') === 0) {
                break;
            }
            if ($assetName !== '' && $origCostCol !== null && $openingWdvCol !== null) {
                $cost = $cleanNumber($data[$dr][$origCostCol] ?? null) ?? 0.0;
                $wdv = $cleanNumber($data[$dr][$openingWdvCol] ?? null) ?? 0.0;
                $purchaseSerial = $purchaseDateCol !== null ? $cleanNumber($data[$dr][$purchaseDateCol] ?? null) : null;
                $purchaseDate = null;
                if ($purchaseSerial !== null && $purchaseSerial > 0) {
                    try {
                        $purchaseDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($purchaseSerial)->format('Y-m-d');
                    } catch (Throwable $e) {
                        $purchaseDate = null;
                    }
                }
                $key = $assetName;
                if (!isset($blocks[$key])) {
                    $blocks[$key] = ['group' => $groupOfAsset, 'units' => []];
                }
                $blocks[$key]['units'][] = ['cost' => $cost, 'wdv' => $wdv, 'purchase_date' => $purchaseDate];
            }
            $dr++;
        }
        $i = $dr + 1;
    }

    return $blocks;
}

/**
 * Best-effort mapping from the detail workpaper's free-text "Group of
 * asset" to this app's Schedule II category list -- falls back to the
 * existing description-based guess (finer-grained, e.g. distinguishing
 * RCC vs non-RCC Buildings or Servers vs End User computers) before
 * falling back to a group-level default. Cosmetic only, like
 * guessFixedAssetCategoryFromDescription() -- useful_life_years always
 * comes from the audited summary, never derived from this mapping.
 */
function mapFixedAssetGroupToCategory(string $group, string $assetName): string
{
    $byDescription = guessFixedAssetCategoryFromDescription($assetName);
    if ($byDescription !== '') {
        return $byDescription;
    }

    $g = strtolower($group);
    $map = [
        'plant and machinery' => 'Plant & Machinery (general)',
        'electrical installations and equipment' => 'Plant & Machinery (general)',
        'laboratory equipment' => 'Plant & Machinery (general)',
        'medical equipment' => 'Plant & Machinery (general)',
        'office equipment' => 'Office Equipment',
        'computers and data processing units' => 'Computers and Data Processing Units (End User Devices)',
        'furniture and fittings' => 'Furniture and Fixtures',
        'motor vehicles' => 'Vehicles (Motor Cars, other than for hire)',
        'buildings' => 'Buildings (RCC, other than factory)',
        'land' => '',
    ];
    if (isset($map[$g])) {
        return $map[$g];
    }

    /* No specific keyword or sub-group matched (common when a company
       hasn't bothered creating sub-groups in Tally and just parks every
       asset directly under "Fixed Assets" itself, or the sub-group name
       doesn't match any rule above -- e.g. hospital/medical equipment like
       imaging plates, ventilators, solar power backup units). Rather than
       leave the category blank and force manual classification of every
       single row, default to Schedule II's general equipment bucket -- the
       CA can still correct any individual row in the Classify Assets
       table. Land is the one deliberate exception: it's never depreciated,
       so guessing a depreciable category for it would be a real error, not
       just an inconvenience -- left blank to force a genuine manual look. */
    if (str_contains($g, 'land')) {
        return '';
    }
    return 'Plant & Machinery (general)';
}

/**
 * Expands each audited Note 11 summary row into one register row per
 * individual physical unit found for it in the detail workpaper (real
 * purchase dates), scaling each unit's cost/WDV so the group's sum ties
 * exactly to that row's own audited opening_gross_block/
 * opening_accumulated_depreciation -- see parseScheduleIIINote11Excel()'s
 * docblock for why the detail workpaper's own totals aren't used
 * directly. Asset-type names present in the summary but with no matching
 * detail units keep today's single-aggregate-row behaviour unchanged
 * (e.g. Land, or any name the detail workpaper doesn't itemise). Detail
 * blocks with no matching summary row at all are units the audited
 * opening balance doesn't include (most likely purchased after the
 * opening date) -- surfaced as a warning rather than silently imported,
 * since injecting an unaudited figure into the opening balance would be
 * a real accounting error, not a convenience.
 */
function expandFixedAssetRowsWithDetail(array $topRows, array $detailBlocks, array &$errors): array
{
    $rows = [];
    $unmatchedNames = [];

    /* A CA's own source file isn't always internally consistent between its
       two sheets -- confirmed against a real export where one asset's name
       carried a stray missing space in the detail workpaper ("...Diamond.T
       RDG511" vs "...Diamond.TRDG511") but was otherwise identical. Only a
       whitespace-only difference is safe to auto-resolve here; anything
       else (e.g. "Water Heater" vs "Stainless Steel Water Heater") is a
       genuine wording difference that could just as easily be two distinct
       assets, so it's left unmatched and surfaced as a note instead of
       guessed at. */
    $collapsedDetailIndex = [];
    $ambiguousCollapsed = [];
    foreach (array_keys($detailBlocks) as $detailName) {
        $collapsed = preg_replace('/\s+/', '', $detailName);
        if (isset($collapsedDetailIndex[$collapsed]) && $collapsedDetailIndex[$collapsed] !== $detailName) {
            $ambiguousCollapsed[$collapsed] = true;
        }
        $collapsedDetailIndex[$collapsed] = $detailName;
    }

    $consumedDetailKeys = [];

    foreach ($topRows as $normalizedName => $topRow) {
        $detailKey = isset($detailBlocks[$normalizedName]) ? $normalizedName : null;
        if ($detailKey === null) {
            $collapsed = preg_replace('/\s+/', '', $normalizedName);
            if (isset($collapsedDetailIndex[$collapsed]) && empty($ambiguousCollapsed[$collapsed])) {
                $detailKey = $collapsedDetailIndex[$collapsed];
            }
        }
        $detail = $detailKey !== null ? $detailBlocks[$detailKey] : null;
        if ($detailKey !== null) {
            $consumedDetailKeys[$detailKey] = true;
        }
        $units = $detail['units'] ?? [];

        if ($units === []) {
            $rows[] = $topRow;
            if ($detailBlocks !== []) {
                $unmatchedNames[] = $topRow['asset_description'];
            }
            continue;
        }

        $detailSumCost = array_sum(array_column($units, 'cost'));
        $detailSumAccumDep = array_sum(array_map(static fn ($u) => max(0.0, $u['cost'] - $u['wdv']), $units));

        $costRatio = $detailSumCost > 0.01 ? ($topRow['opening_gross_block'] / $detailSumCost) : 0.0;
        $depRatio = $detailSumAccumDep > 0.01 ? ($topRow['opening_accumulated_depreciation'] / $detailSumAccumDep) : null;

        $category = mapFixedAssetGroupToCategory((string) ($detail['group'] ?? ''), $topRow['asset_description']);
        $multiUnit = count($units) > 1;

        $runningGross = 0.0;
        $runningAccumDep = 0.0;
        $unitRows = [];
        foreach ($units as $idx => $unit) {
            $scaledGross = round($unit['cost'] * $costRatio, 2);
            if ($depRatio !== null) {
                $scaledAccumDep = round(max(0.0, $unit['cost'] - $unit['wdv']) * $depRatio, 2);
            } else {
                $scaledAccumDep = $topRow['opening_gross_block'] > 0.01
                    ? round($scaledGross * ($topRow['opening_accumulated_depreciation'] / $topRow['opening_gross_block']), 2)
                    : 0.0;
            }
            $scaledAccumDep = max(0.0, min($scaledAccumDep, $scaledGross));
            $runningGross += $scaledGross;
            $runningAccumDep += $scaledAccumDep;

            $description = $multiUnit
                ? $topRow['asset_description'] . ($unit['purchase_date'] !== null ? ' (purchased ' . $unit['purchase_date'] . ')' : ' (unit ' . ($idx + 1) . ')')
                : $topRow['asset_description'];

            $unitRows[] = [
                'asset_category' => $category !== '' ? $category : $topRow['asset_category'],
                'asset_description' => $description,
                'opening_gross_block' => $scaledGross,
                'opening_accumulated_depreciation' => $scaledAccumDep,
                'useful_life_years' => $topRow['useful_life_years'],
                'depreciation_method' => $topRow['depreciation_method'],
                'addition_date' => $unit['purchase_date'],
            ];
        }

        // Rounding from per-unit scaling can leave a few paise of drift --
        // corrected on the largest unit in the group (never the last one
        // positionally, which can be a tiny-value row that a few paise of
        // correction would push negative) so the group ties exactly to the
        // audited row it was expanded from.
        if ($unitRows !== []) {
            $anchorIdx = 0;
            $anchorGross = $unitRows[0]['opening_gross_block'];
            foreach ($unitRows as $idx => $unitRow) {
                if ($unitRow['opening_gross_block'] > $anchorGross) {
                    $anchorGross = $unitRow['opening_gross_block'];
                    $anchorIdx = $idx;
                }
            }
            $unitRows[$anchorIdx]['opening_gross_block'] = round($unitRows[$anchorIdx]['opening_gross_block'] + ($topRow['opening_gross_block'] - $runningGross), 2);
            $unitRows[$anchorIdx]['opening_accumulated_depreciation'] = round($unitRows[$anchorIdx]['opening_accumulated_depreciation'] + ($topRow['opening_accumulated_depreciation'] - $runningAccumDep), 2);
        }

        /* Defensive final clamp: in a group made up entirely of very small
           units (a generic sundry-items bucket with many low-value lines),
           the anchor correction above can still nudge that group's largest
           unit a paisa or two below zero. Never let a row go negative or
           carry more accumulated depreciation than its own gross block --
           worth a few paise of imprecision in that rare case, which is
           immaterial to a rupee-rounded financial statement. */
        foreach ($unitRows as &$unitRow) {
            $unitRow['opening_gross_block'] = max(0.0, $unitRow['opening_gross_block']);
            $unitRow['opening_accumulated_depreciation'] = max(0.0, min($unitRow['opening_accumulated_depreciation'], $unitRow['opening_gross_block']));
        }
        unset($unitRow);

        array_push($rows, ...$unitRows);
    }

    $orphanNames = array_diff_key($detailBlocks, $consumedDetailKeys);
    if ($orphanNames !== []) {
        $orphanTotal = 0.0;
        $sample = [];
        foreach ($orphanNames as $name => $detail) {
            $cost = array_sum(array_column($detail['units'], 'cost'));
            $orphanTotal += $cost;
            if (count($sample) < 10) {
                $sample[] = $name . ' (₹' . number_format($cost, 2) . ')';
            }
        }
        if ($orphanTotal > 0.01) {
            $errors[] = count($orphanNames) . ' asset(s) totalling ₹' . number_format($orphanTotal, 2)
                . ' appear in the individual-asset detail workpaper but not in the audited Note 11 opening balance summary '
                . '(likely purchased after the opening date) -- NOT imported; review and add manually if needed: '
                . implode(', ', $sample) . (count($orphanNames) > 10 ? ', ...' : '');
        }
    }

    if ($unmatchedNames !== []) {
        $errors[] = count($unmatchedNames) . ' asset(s) could not be linked to a purchase date because their name in the '
            . 'individual-asset detail workpaper doesn\'t match their name in the Note 11 summary closely enough to safely '
            . 'pair automatically -- the audited opening balance figure is unaffected, only the purchase date is missing: '
            . implode(', ', array_slice($unmatchedNames, 0, 10)) . (count($unmatchedNames) > 10 ? ', ...' : '');
    }

    return $rows;
}

/**
 * Best-effort Schedule II category guess from an asset's description --
 * cosmetic only (useful_life_years always comes directly from the
 * imported file, never derived from this category), so a wrong guess
 * has no effect on the actual depreciation math, only on which group the
 * asset is shown under in the Fixed Assets note until the CA corrects
 * it. Deliberately conservative: falls back to blank (not a guessed
 * "Plant & Machinery") when nothing matches confidently, consistent with
 * the existing "CA classifies in the UI" pattern for Tally-synced assets.
 */
function guessFixedAssetCategoryFromDescription(string $description): string
{
    $d = strtolower($description);
    $rules = [
        'Buildings (RCC, other than factory)' => ['building', 'civil work'],
        'Computers and Data Processing Units (Servers)' => ['server'],
        'Computers and Data Processing Units (End User Devices)' => ['computer', 'laptop', 'desktop'],
        'Vehicles (Motor Cars, other than for hire)' => ['vehicle', 'car', 'motor cycle', 'scooter', 'bike'],
        'Furniture and Fixtures' => ['furniture', 'fixture', 'chair', 'almirah', 'cot', 'trolley'],
        'Office Equipment' => ['air condition', 'fan', 'refrigerator', 'ups', 'stabilizer'],
    ];
    foreach ($rules as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($d, $keyword)) {
                return $category;
            }
        }
    }
    return '';
}

function saveFixedAssetExcelImport(PDO $pdo, int $company_id, int $fy_id, array $rows, ?int $userId, string $originalFilename): void
{
    ensureFixedAssetRegisterSchema($pdo);

    $insert = $pdo->prepare("
        INSERT INTO fixed_assets
            (company_id, fy_id, asset_category, asset_description, source, opening_gross_block, opening_accumulated_depreciation, useful_life_years, depreciation_method, addition_date)
        VALUES (?, ?, ?, ?, 'excel_import', ?, ?, ?, ?, ?)
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
            $row['addition_date'] ?? null,
        ]);
    }

    $logStmt = $pdo->prepare("
        INSERT INTO fixed_asset_excel_imports (company_id, fy_id, original_filename, row_count, imported_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $logStmt->execute([$company_id, $fy_id, $originalFilename, count($rows), $userId]);
}

/**
 * Fixed-Asset/CWIP ledger names for this company/FY, as already classified
 * via ReconHub -- the same set the Balance Sheet's Fixed Assets note uses.
 * Shared by the voucher sync (to scope which ledgers' vouchers count) and
 * by the Excel-vs-Tally reconciliation check.
 */
function getFixedAssetLedgerNames(array $classified): array
{
    $names = [];
    foreach (['ppe', 'cwip'] as $code) {
        foreach (($classified['schedule_items'][$code]['rows'] ?? []) as $row) {
            $name = trim((string) ($row['ledger_name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
    }
    return array_keys($names);
}

/**
 * Buckets a Tally voucher type into what it means for the asset register.
 * 'purchase'/'sale' are the expected, common cases; 'depreciation_journal'
 * catches journal entries that touch a Fixed Asset ledger for a
 * revaluation/write-down rather than a genuine purchase or disposal (these
 * are excluded from the auto-synced additions/disposals -- surfaced
 * separately for the CA to review and enter manually if needed, since
 * treating a revaluation journal as a "purchase" would silently corrupt
 * the depreciation base). 'other' covers Payment/Receipt/Contra vouchers
 * that legitimately capitalise or dispose of an asset without a formal
 * Purchase/Sales voucher (common for cash purchases without a GST bill) --
 * kept in, since excluding them would silently miss real additions.
 */
function classifyFixedAssetVoucherType(string $voucherType, string $ledgerName = '', bool $isCredit = false): string
{
    if (stripos($voucherType, 'purchase') !== false) {
        return 'purchase';
    }
    if (stripos($voucherType, 'sales') !== false || stripos($voucherType, 'sale') !== false) {
        return 'sale';
    }
    if (stripos($voucherType, 'depreciation') !== false) {
        return 'depreciation_journal';
    }
    /* Some companies don't track individual assets in Tally -- they group
       them by useful life on ledgers literally named e.g. "Depreciation -
       3 Years Useful", classified as PPE in ReconHub since that's the only
       ledger carrying the asset's balance. A CREDIT to such a ledger is the
       year-end depreciation write-off against that balance, not a genuine
       disposal -- confirmed against a real company where every 31-Mar
       Journal voucher crediting these ledgers was a depreciation run, not
       an asset sale. Only applies to credits: a DEBIT into the same ledger
       is a genuine addition being grouped under that useful-life bucket. */
    if ($isCredit && stripos($ledgerName, 'deprec') !== false) {
        return 'depreciation_journal';
    }
    return 'other';
}

/**
 * Real, voucher-level Tally sync for the Fixed Asset Register -- NOT a
 * Trial Balance fetch. Pulls this year's actual Purchase/Journal/Payment/
 * Sales/Receipt vouchers that debit or credit a Fixed-Asset/CWIP-classified
 * ledger, and creates one fixed_assets row PER VOUCHER (not per ledger),
 * so each addition/disposal is pro-rated from its own real Tally voucher
 * date -- not a single ledger-level balance movement. Opening balances are
 * never touched here; they only ever come from the Excel upload, per this
 * app's Excel-only opening balance policy.
 *
 * Idempotent: re-running the sync updates existing rows (matched by their
 * source voucher_entries.id) rather than duplicating them, so it's safe to
 * re-sync after new vouchers are entered in Tally.
 */
function syncFixedAssetVouchersFromTally(PDO $pdo, int $company_id, int $fy_id, array $classified, string $fyStart, string $fyEnd): array
{
    ensureFixedAssetRegisterSchema($pdo);
    ensureFixedAssetVoucherColumns($pdo);

    $ledgerNames = getFixedAssetLedgerNames($classified);
    if ($ledgerNames === []) {
        return ['ok' => false, 'message' => 'No ledgers are classified as Property, Plant & Equipment or Capital Work-in-Progress yet -- classify them in ReconHub first.', 'created' => 0, 'updated' => 0, 'excluded' => []];
    }

    /* Deliberately does NOT call syncVouchersIncremental() -- that function
       tries an ODBC connection, then a direct XML request FROM THIS SERVER
       to Tally, both of which only work when the web server and Tally sit
       on the same local network. On a real hosted deployment, Tally runs
       on the CA's own machine behind their router/firewall, which this
       server can never reach directly (confirmed in production: every such
       attempt reports "0 vouchers ... via xml"). The only connection that
       actually works is the Smart Bridge desktop app PUSHING data it
       fetched locally to bridge_voucher.php, which lands in the same
       vouchers/voucher_entries tables -- so this just reads whatever the
       bridge has already pushed for the period, instead of attempting a
       doomed live pull of its own. */
    ensureVoucherTables($pdo);
    $voucherCountStmt = $pdo->prepare('SELECT COUNT(*), MAX(source), MAX(synced_at) FROM vouchers WHERE company_id = ? AND fy_id = ? AND date BETWEEN ? AND ?');
    $voucherCountStmt->execute([$company_id, $fy_id, $fyStart, $fyEnd]);
    [$totalVouchersForPeriod, $lastVoucherSource, $lastVoucherPushedAt] = $voucherCountStmt->fetch(PDO::FETCH_NUM);
    $totalVouchersForPeriod = (int) $totalVouchersForPeriod;

    if ($totalVouchersForPeriod === 0) {
        return [
            'ok' => false,
            'message' => "No vouchers have been synced from Tally for {$fyStart} to {$fyEnd} yet -- open the eBAL Smart Bridge app on the machine running Tally and click Sync there first (this page can only read what the bridge has already pushed; it can't reach Tally directly).",
            'created' => 0,
            'updated' => 0,
            'excluded' => [],
        ];
    }

    $voucherSyncResult = [
        'source' => $lastVoucherSource ?: 'bridge',
        'total' => $totalVouchersForPeriod,
    ];

    /* Carry the asset category forward from any prior row on the same
       ledger (an earlier voucher this year, or a category the CA already
       set), so a second purchase against a ledger the CA has already
       classified doesn't come back blank. */
    $categoryStmt = $pdo->prepare("SELECT source_ledger_name, asset_category FROM fixed_assets WHERE company_id = ? AND fy_id = ? AND source_ledger_name IS NOT NULL AND asset_category <> '' ORDER BY id");
    $categoryStmt->execute([$company_id, $fy_id]);
    $categoryByLedger = [];
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $categoryByLedger[(string) $row['source_ledger_name']] = (string) $row['asset_category'];
    }

    /* Auto-category, so a fresh sync doesn't need manual classification for
       every ledger before it's usable -- guessed from the ledger name first
       (finer-grained, e.g. distinguishing Servers from End User computers),
       falling back to the ledger's own Tally parent group via the same
       mapping the Excel detail-block import uses. Still just a starting
       guess: the Classify Assets table below stays fully editable per row. */
    $placeholders = implode(',', array_fill(0, count($ledgerNames), '?'));
    $groupStmt = $pdo->prepare("
        SELECT tlm.ledger_name, tlm.parent_group
        FROM tally_ledger_master tlm
        INNER JOIN (
            SELECT company_id, ledger_name, MIN(id) AS min_id
            FROM tally_ledger_master
            WHERE company_id = ? AND ledger_name IN ({$placeholders})
            GROUP BY company_id, ledger_name
        ) dedup ON dedup.min_id = tlm.id
    ");
    $groupStmt->execute(array_merge([$company_id], $ledgerNames));
    $groupByLedger = [];
    foreach ($groupStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groupByLedger[(string) $row['ledger_name']] = (string) $row['parent_group'];
    }
    $entryStmt = $pdo->prepare("
        SELECT ve.id AS entry_id, ve.voucher_id, ve.ledger_name, ve.amount, ve.dr_cr,
               v.voucher_type, v.voucher_number, v.date, v.narration, v.is_cancelled
        FROM voucher_entries ve
        INNER JOIN vouchers v ON v.id = ve.voucher_id
        WHERE ve.company_id = ? AND v.fy_id = ? AND ve.ledger_name IN ({$placeholders})
              AND v.date BETWEEN ? AND ? AND v.is_cancelled = 0
        ORDER BY v.date, ve.id
    ");
    $entryStmt->execute(array_merge([$company_id, $fy_id], $ledgerNames, [$fyStart, $fyEnd]));
    $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

    /* A batch depreciation journal in this company's Tally books one DEBIT
       line to a P&L ledger named e.g. "Depreciation - 15 Years Useful" and
       dozens of CREDIT lines directly against the individual FA-classified
       asset ledgers being written down -- confirmed live via bridge.log
       (voucher 9946: one debit to "Depreciation - 15 Years Useful", ~150
       credits to individual assets like "Water Heater", "AgVa Ventilator").
       The "Depreciation - ..." ledger itself is never fetched above (it's
       an expense ledger, not FA/CWIP-classified), so the only way to tell
       "this credit is a depreciation write-off" from "this credit is a
       genuine disposal" is to check whether the SAME voucher also touches
       a ledger named like that -- not the matched ledger's own name, which
       is just the asset (e.g. "Water Heater" doesn't contain "deprec"). */
    $depreciationVoucherIds = [];
    if ($entries !== []) {
        $voucherIds = array_values(array_unique(array_map(static fn (array $e): int => (int) $e['voucher_id'], $entries)));
        $vPlaceholders = implode(',', array_fill(0, count($voucherIds), '?'));
        $depStmt = $pdo->prepare("SELECT DISTINCT voucher_id FROM voucher_entries WHERE voucher_id IN ({$vPlaceholders}) AND ledger_name LIKE '%deprec%'");
        $depStmt->execute($voucherIds);
        $depreciationVoucherIds = array_map('intval', $depStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $depreciationVoucherIds = array_flip($depreciationVoucherIds);

    $upsert = $pdo->prepare("
        INSERT INTO fixed_assets
            (company_id, fy_id, asset_category, asset_description, source_ledger_name, source,
             tally_voucher_entry_id, voucher_type, voucher_classification, voucher_narration,
             opening_gross_block, additions_during_year, addition_date, disposals_during_year, disposal_date, is_disposed,
             depreciation_method)
        VALUES (?, ?, ?, ?, ?, 'tally_voucher', ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'WDV')
        ON DUPLICATE KEY UPDATE
            asset_category = IF(asset_category = '', VALUES(asset_category), asset_category),
            voucher_type = VALUES(voucher_type),
            voucher_narration = VALUES(voucher_narration),
            additions_during_year = VALUES(additions_during_year),
            addition_date = VALUES(addition_date),
            disposals_during_year = VALUES(disposals_during_year),
            disposal_date = VALUES(disposal_date),
            is_disposed = VALUES(is_disposed)
    ");

    /* Group non-depreciation entries by (voucher, ledger) before writing
       rows -- a single voucher can carry more than one line against the
       same FA ledger, e.g. a purchase debit plus a same-voucher credit for
       a discount/rounding adjustment (confirmed live: voucher 927 debited
       "Defibrillator 5" Rs.2,15,000 and separately credited it Rs.10,000
       in the same voucher). Treating each line independently created a
       spurious "disposal" alongside the real addition. Netting the group's
       DR total against its CR total gives the true addition/disposal for
       that ledger in that voucher, matching what actually happened
       (net Rs.2,05,000 addition here, not an addition plus a disposal). */
    $created = 0;
    $excluded = [];
    $groups = [];
    foreach ($entries as $entry) {
        $ledgerName = (string) $entry['ledger_name'];
        $isCredit = $entry['dr_cr'] !== 'DR';
        $classification = classifyFixedAssetVoucherType((string) $entry['voucher_type'], $ledgerName, $isCredit);
        if ($classification !== 'depreciation_journal' && $isCredit && isset($depreciationVoucherIds[(int) $entry['voucher_id']])) {
            $classification = 'depreciation_journal';
        }
        if ($classification === 'depreciation_journal') {
            $excluded[] = $entry + ['classification' => $classification];
            continue;
        }

        $groupKey = $entry['voucher_id'] . '|' . $ledgerName;
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = ['entries' => [], 'net' => 0.0, 'classification' => $classification];
        }
        $groups[$groupKey]['entries'][] = $entry;
        $groups[$groupKey]['net'] += $isCredit ? -(float) $entry['amount'] : (float) $entry['amount'];
    }

    foreach ($groups as $group) {
        $net = $group['net'];
        if (abs($net) < 0.005) {
            // Fully self-cancelling within the voucher -- no real addition or disposal happened.
            continue;
        }
        $representative = $group['entries'][0];
        foreach ($group['entries'] as $candidate) {
            if ((int) $candidate['entry_id'] < (int) $representative['entry_id']) {
                $representative = $candidate;
            }
        }

        $isAddition = $net > 0;
        $amount = abs($net);
        $ledgerName = (string) $representative['ledger_name'];
        $category = $categoryByLedger[$ledgerName] ?? mapFixedAssetGroupToCategory($groupByLedger[$ledgerName] ?? '', $ledgerName);

        $upsert->execute([
            $company_id,
            $fy_id,
            $category,
            $ledgerName . ' (' . $representative['voucher_type'] . ' #' . $representative['voucher_number'] . ')',
            $ledgerName,
            (int) $representative['entry_id'],
            $representative['voucher_type'],
            $group['classification'],
            $representative['narration'],
            $isAddition ? $amount : 0,
            $isAddition ? $representative['date'] : null,
            !$isAddition ? $amount : 0,
            !$isAddition ? $representative['date'] : null,
            !$isAddition ? 1 : 0,
        ]);
        $created++;
    }

    /* Diagnostics for the "0 matched" case -- the bridge has pushed
       vouchers for this period (guaranteed at this point, or the early
       return above would have fired), but none of them touched a
       classified Fixed Asset ledger. Surfaced so the CA doesn't have to
       guess whether that's genuinely true or a ledger-name mismatch --
       this app has no direct access to the CA's own Tally/production
       database to check by hand, so the diagnosis has to happen through
       what's visible on this page. */
    $sampleVoucherLedgerNames = [];
    $nearMatchLedgerNames = [];
    if ($entries === []) {
        $seenLedgersStmt = $pdo->prepare("
            SELECT DISTINCT ve.ledger_name
            FROM voucher_entries ve
            INNER JOIN vouchers v ON v.id = ve.voucher_id
            WHERE ve.company_id = ? AND v.fy_id = ? AND v.date BETWEEN ? AND ? AND v.is_cancelled = 0
            ORDER BY ve.ledger_name
            LIMIT 200
        ");
        $seenLedgersStmt->execute([$company_id, $fy_id, $fyStart, $fyEnd]);
        $sampleVoucherLedgerNames = array_map('strval', $seenLedgersStmt->fetchAll(PDO::FETCH_COLUMN));

        /* Case/whitespace-insensitive comparison against the classified
           list -- distinguishes "genuinely not classified as a Fixed
           Asset ledger" from "classified, but under a near-identical
           name" (trailing space, different case, double space), which is
           the actual bug this exists to catch. */
        $normalizedClassified = [];
        foreach ($ledgerNames as $classifiedName) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($classifiedName)));
            $normalizedClassified[$key] = $classifiedName;
        }
        foreach ($sampleVoucherLedgerNames as $seenName) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($seenName)));
            if (isset($normalizedClassified[$key]) && $normalizedClassified[$key] !== $seenName) {
                $nearMatchLedgerNames[] = [
                    'voucher_ledger_name' => $seenName,
                    'classified_ledger_name' => $normalizedClassified[$key],
                ];
            }
        }
    }

    $diagnostics = [
        'voucher_source' => $voucherSyncResult['source'] ?? null,
        'total_vouchers_from_tally' => (int) ($voucherSyncResult['total'] ?? 0),
        'fixed_asset_ledger_count' => count($ledgerNames),
        'matched_voucher_entries' => count($entries),
        'last_voucher_pushed_at' => $lastVoucherPushedAt,
        'sample_voucher_ledger_names' => array_slice($sampleVoucherLedgerNames, 0, 40),
        'near_match_ledger_names' => $nearMatchLedgerNames,
        'raw_response_sample' => null,
    ];

    return [
        'ok' => true,
        'message' => "Synced {$created} voucher-based asset transaction(s) from Tally.",
        'created' => $created,
        'updated' => 0,
        'excluded' => $excluded,
        'diagnostics' => $diagnostics,
    ];
}

/**
 * Rows created by the old ledger-balance-movement "Sync from Tally"
 * (source = 'tally', now removed in favour of syncFixedAssetVouchersFromTally()
 * above) carry a zero opening balance, no addition date, and a single
 * approximated per-ledger figure instead of per-voucher transactions --
 * they'll never self-correct since nothing writes that source value
 * anymore. Lets the CA clear them out in one action so the register can be
 * rebuilt cleanly from the voucher sync, instead of deleting hundreds of
 * rows one at a time.
 */
function countLegacyLedgerSyncedFixedAssets(PDO $pdo, int $company_id, int $fy_id): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fixed_assets WHERE company_id = ? AND fy_id = ? AND source = 'tally'");
    $stmt->execute([$company_id, $fy_id]);
    return (int) $stmt->fetchColumn();
}

function clearLegacyLedgerSyncedFixedAssets(PDO $pdo, int $company_id, int $fy_id): int
{
    $stmt = $pdo->prepare("DELETE FROM fixed_assets WHERE company_id = ? AND fy_id = ? AND source = 'tally'");
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->rowCount();
}

/**
 * Wipes every row in the register for this company/FY -- Excel-imported,
 * voucher-synced, and manually-entered alike -- for when the CA wants to
 * start completely over (wrong file uploaded, register got into a bad
 * state, etc.) rather than pick through rows individually. Unlike
 * clearLegacyLedgerSyncedFixedAssets(), this is intentionally
 * indiscriminate; the calling page must confirm with the user first.
 */
function resetFixedAssetRegister(PDO $pdo, int $company_id, int $fy_id): int
{
    $stmt = $pdo->prepare("DELETE FROM fixed_assets WHERE company_id = ? AND fy_id = ?");
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->rowCount();
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
