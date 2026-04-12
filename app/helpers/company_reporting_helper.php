<?php

function ensureCompanyReportingColumns(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);

    $required = [
        'company_type' => "ALTER TABLE companies ADD COLUMN company_type VARCHAR(120) NULL AFTER category",
        'noncorp_subcategory' => "ALTER TABLE companies ADD COLUMN noncorp_subcategory VARCHAR(120) NULL AFTER company_type",
        'cin' => "ALTER TABLE companies ADD COLUMN cin VARCHAR(50) NULL AFTER noncorp_subcategory",
        'llp_code' => "ALTER TABLE companies ADD COLUMN llp_code VARCHAR(50) NULL AFTER cin",
        'pan' => "ALTER TABLE companies ADD COLUMN pan VARCHAR(20) NULL AFTER llp_code",
        'registered_address' => "ALTER TABLE companies ADD COLUMN registered_address TEXT NULL AFTER pan",
        'branch_address' => "ALTER TABLE companies ADD COLUMN branch_address TEXT NULL AFTER registered_address",
        'state_code' => "ALTER TABLE companies ADD COLUMN state_code VARCHAR(10) NULL AFTER branch_address",
        'official_email' => "ALTER TABLE companies ADD COLUMN official_email VARCHAR(255) NULL AFTER state_code",
        'mobile_no' => "ALTER TABLE companies ADD COLUMN mobile_no VARCHAR(30) NULL AFTER official_email",
        'address' => "ALTER TABLE companies ADD COLUMN address TEXT NULL AFTER mobile_no",
        'phone' => "ALTER TABLE companies ADD COLUMN phone VARCHAR(30) NULL AFTER address",
        'statutory_auditor_name' => "ALTER TABLE companies ADD COLUMN statutory_auditor_name VARCHAR(255) NULL AFTER phone",
        'statutory_auditor_firm' => "ALTER TABLE companies ADD COLUMN statutory_auditor_firm VARCHAR(255) NULL AFTER statutory_auditor_name",
        'statutory_auditor_frn' => "ALTER TABLE companies ADD COLUMN statutory_auditor_frn VARCHAR(120) NULL AFTER statutory_auditor_firm",
        'statutory_auditor_membership_no' => "ALTER TABLE companies ADD COLUMN statutory_auditor_membership_no VARCHAR(120) NULL AFTER statutory_auditor_frn",
        'signatory_1_name' => "ALTER TABLE companies ADD COLUMN signatory_1_name VARCHAR(255) NULL AFTER statutory_auditor_membership_no",
        'signatory_1_designation' => "ALTER TABLE companies ADD COLUMN signatory_1_designation VARCHAR(255) NULL AFTER signatory_1_name",
        'signatory_1_custom_designation' => "ALTER TABLE companies ADD COLUMN signatory_1_custom_designation VARCHAR(255) NULL AFTER signatory_1_designation",
        'signatory_1_id_no' => "ALTER TABLE companies ADD COLUMN signatory_1_id_no VARCHAR(120) NULL AFTER signatory_1_custom_designation",
        'signatory_1_signing_authority' => "ALTER TABLE companies ADD COLUMN signatory_1_signing_authority VARCHAR(255) NULL AFTER signatory_1_id_no",
        'signatory_1_is_signing' => "ALTER TABLE companies ADD COLUMN signatory_1_is_signing TINYINT(1) NOT NULL DEFAULT 1 AFTER signatory_1_signing_authority",
        'signatory_2_name' => "ALTER TABLE companies ADD COLUMN signatory_2_name VARCHAR(255) NULL AFTER signatory_1_is_signing",
        'signatory_2_designation' => "ALTER TABLE companies ADD COLUMN signatory_2_designation VARCHAR(255) NULL AFTER signatory_2_name",
        'signatory_2_custom_designation' => "ALTER TABLE companies ADD COLUMN signatory_2_custom_designation VARCHAR(255) NULL AFTER signatory_2_designation",
        'signatory_2_id_no' => "ALTER TABLE companies ADD COLUMN signatory_2_id_no VARCHAR(120) NULL AFTER signatory_2_custom_designation",
        'signatory_2_signing_authority' => "ALTER TABLE companies ADD COLUMN signatory_2_signing_authority VARCHAR(255) NULL AFTER signatory_2_id_no",
        'signatory_2_is_signing' => "ALTER TABLE companies ADD COLUMN signatory_2_is_signing TINYINT(1) NOT NULL DEFAULT 1 AFTER signatory_2_signing_authority",
    ];

    foreach ($required as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function getIndianStateOptions(): array
{
    return [
        'AN' => 'Andaman and Nicobar Islands',
        'AP' => 'Andhra Pradesh',
        'AR' => 'Arunachal Pradesh',
        'AS' => 'Assam',
        'BR' => 'Bihar',
        'CH' => 'Chandigarh',
        'CG' => 'Chhattisgarh',
        'DN' => 'Dadra and Nagar Haveli and Daman and Diu',
        'DL' => 'Delhi',
        'GA' => 'Goa',
        'GJ' => 'Gujarat',
        'HR' => 'Haryana',
        'HP' => 'Himachal Pradesh',
        'JH' => 'Jharkhand',
        'JK' => 'Jammu and Kashmir',
        'KA' => 'Karnataka',
        'KL' => 'Kerala',
        'LA' => 'Ladakh',
        'LD' => 'Lakshadweep',
        'MP' => 'Madhya Pradesh',
        'MH' => 'Maharashtra',
        'MN' => 'Manipur',
        'ML' => 'Meghalaya',
        'MZ' => 'Mizoram',
        'NL' => 'Nagaland',
        'OD' => 'Odisha',
        'PY' => 'Puducherry',
        'PB' => 'Punjab',
        'RJ' => 'Rajasthan',
        'SK' => 'Sikkim',
        'TN' => 'Tamil Nadu',
        'TS' => 'Telangana',
        'TR' => 'Tripura',
        'UP' => 'Uttar Pradesh',
        'UK' => 'Uttarakhand',
        'WB' => 'West Bengal',
    ];
}

function getCorporateCompanyTypeOptions(): array
{
    return [
        'PLC' => 'Public Limited Company',
        'PTC' => 'Private Limited Company',
        'OPC' => 'One Person Company',
        'SGC' => 'Section 8 Company',
        'FTC' => 'Foreign Company',
        'GAP' => 'Government Company',
        'GOI' => 'Government of India',
    ];
}

function getNonCorporateSubcategoryOptions(): array
{
    return [
        'individual' => 'Individual',
        'sole_proprietorship' => 'Sole Proprietorship',
        'partnership' => 'Partnership',
        'huf' => 'HUF',
        'association_trust' => 'Association / Trust',
    ];
}

function getNonCorporateDesignationOptions(): array
{
    return [
        'proprietor' => 'Proprietor',
        'partner' => 'Partner',
        'karta' => 'Karta',
        'trustee' => 'Trustee',
        'self' => 'Self',
        'custom' => 'Custom',
    ];
}

function parseCinDetails(string $cin): array
{
    $cin = strtoupper(trim($cin));
    if (!preg_match('/^[LU][0-9]{5}[A-Z]{2}[0-9]{4}(PLC|PTC|OPC|SGC|FTC|GAP|GOI)[0-9]{6}$/', $cin)) {
        return [];
    }

    return [
        'listing_status' => substr($cin, 0, 1),
        'industry_code' => substr($cin, 1, 5),
        'state_code' => substr($cin, 6, 2),
        'incorporation_year' => substr($cin, 8, 4),
        'company_type_code' => substr($cin, 12, 3),
        'registration_number' => substr($cin, 15, 6),
    ];
}

function getDefaultDesignationForCategory(string $category): string
{
    $category = strtolower(str_replace(['-', ' '], '_', $category));

    return match ($category) {
        'corporate' => 'Director',
        'llp' => 'Designated Partner',
        default => 'Authorised Signatory',
    };
}

function normalizeCompanyFormData(array $input): array
{
    $category = strtolower(trim((string) ($input['category'] ?? '')));
    $category = str_replace(['-', ' '], '_', $category);

    $data = [
        'name' => trim((string) ($input['name'] ?? '')),
        'category' => $category,
        'company_type' => trim((string) ($input['company_type'] ?? '')),
        'noncorp_subcategory' => trim((string) ($input['noncorp_subcategory'] ?? '')),
        'cin' => strtoupper(trim((string) ($input['cin'] ?? ''))),
        'llp_code' => strtoupper(trim((string) ($input['llp_code'] ?? ''))),
        'pan' => strtoupper(trim((string) ($input['pan'] ?? ''))),
        'registered_address' => trim((string) ($input['registered_address'] ?? '')),
        'branch_address' => trim((string) ($input['branch_address'] ?? '')),
        'state_code' => strtoupper(trim((string) ($input['state_code'] ?? ''))),
        'official_email' => trim((string) ($input['official_email'] ?? '')),
        'mobile_no' => trim((string) ($input['mobile_no'] ?? '')),
        'statutory_auditor_name' => trim((string) ($input['statutory_auditor_name'] ?? '')),
        'statutory_auditor_firm' => trim((string) ($input['statutory_auditor_firm'] ?? '')),
        'statutory_auditor_frn' => trim((string) ($input['statutory_auditor_frn'] ?? '')),
        'statutory_auditor_membership_no' => trim((string) ($input['statutory_auditor_membership_no'] ?? '')),
        'signatory_1_name' => trim((string) ($input['signatory_1_name'] ?? '')),
        'signatory_1_designation' => trim((string) ($input['signatory_1_designation'] ?? '')),
        'signatory_1_custom_designation' => trim((string) ($input['signatory_1_custom_designation'] ?? '')),
        'signatory_1_id_no' => trim((string) ($input['signatory_1_id_no'] ?? '')),
        'signatory_1_signing_authority' => trim((string) ($input['signatory_1_signing_authority'] ?? '')),
        'signatory_1_is_signing' => !empty($input['signatory_1_is_signing']) ? 1 : 0,
        'signatory_2_name' => trim((string) ($input['signatory_2_name'] ?? '')),
        'signatory_2_designation' => trim((string) ($input['signatory_2_designation'] ?? '')),
        'signatory_2_custom_designation' => trim((string) ($input['signatory_2_custom_designation'] ?? '')),
        'signatory_2_id_no' => trim((string) ($input['signatory_2_id_no'] ?? '')),
        'signatory_2_signing_authority' => trim((string) ($input['signatory_2_signing_authority'] ?? '')),
        'signatory_2_is_signing' => !empty($input['signatory_2_is_signing']) ? 1 : 0,
    ];

    if ($data['category'] === 'corporate' && $data['cin'] !== '') {
        $cinDetails = parseCinDetails($data['cin']);
        if ($cinDetails !== []) {
            $data['state_code'] = $data['state_code'] !== '' ? $data['state_code'] : $cinDetails['state_code'];
            $data['company_type'] = $data['company_type'] !== '' ? $data['company_type'] : $cinDetails['company_type_code'];
        }
    }

    if ($data['category'] !== 'corporate') {
        $data['cin'] = '';
        if ($data['category'] !== 'llp') {
            $data['company_type'] = '';
        }
    }

    if ($data['category'] !== 'llp') {
        $data['llp_code'] = '';
    }

    if ($data['category'] !== 'non_corporate') {
        $data['pan'] = '';
        $data['noncorp_subcategory'] = '';
    }

    $defaultDesignation = getDefaultDesignationForCategory($data['category']);
    if ($data['signatory_1_designation'] === '') {
        $data['signatory_1_designation'] = $defaultDesignation;
    }
    if ($data['signatory_2_designation'] === '') {
        $data['signatory_2_designation'] = $defaultDesignation;
    }

    $data['address'] = $data['registered_address'];
    $data['phone'] = $data['mobile_no'];

    return $data;
}

function validateCompanyFormData(array $data): array
{
    $errors = [];

    if ($data['name'] === '') {
        $errors[] = 'Company name is required';
    }

    if (!in_array($data['category'], ['corporate', 'llp', 'non_corporate'], true)) {
        $errors[] = 'Invalid company category';
    }

    if ($data['category'] === 'corporate') {
        if ($data['cin'] === '') {
            $errors[] = 'CIN is required for Corporate';
        } elseif (parseCinDetails($data['cin']) === []) {
            $errors[] = 'CIN format is invalid';
        }
    }

    if ($data['category'] === 'llp' && $data['llp_code'] === '') {
        $errors[] = 'LLPIN is required for LLP';
    }

    if ($data['category'] === 'non_corporate') {
        if ($data['noncorp_subcategory'] === '') {
            $errors[] = 'Select a non-corporate sub category';
        }
        if ($data['pan'] === '') {
            $errors[] = 'PAN is required for Non-Corporate entities';
        }
    }

    if ($data['registered_address'] === '') {
        $errors[] = 'Registered address is required';
    }

    if ($data['official_email'] !== '' && !filter_var($data['official_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Official email is invalid';
    }

    if ($data['category'] === 'corporate') {
        if ($data['signatory_1_name'] === '' || $data['signatory_2_name'] === '') {
            $errors[] = 'Minimum two directors are required for Corporate';
        }
    } elseif ($data['category'] === 'llp') {
        if ($data['signatory_1_name'] === '') {
            $errors[] = 'Minimum one designated partner is required for LLP';
        }
        if ($data['signatory_1_is_signing'] !== 1 && $data['signatory_2_is_signing'] !== 1) {
            $errors[] = 'Select at least one signing partner for LLP';
        }
    } else {
        if ($data['signatory_1_name'] === '') {
            $errors[] = 'Name of the person signing the report is required';
        }
        if ($data['signatory_1_is_signing'] !== 1 && $data['signatory_2_is_signing'] !== 1) {
            $errors[] = 'Select at least one signing authority';
        }
    }

    return $errors;
}

function getCompanyReportingMeta(PDO $pdo, int $companyId): array
{
    ensureCompanyReportingColumns($pdo);

    $stmt = $pdo->prepare("
        SELECT
            name,
            category,
            company_type,
            noncorp_subcategory,
            statutory_auditor_name,
            statutory_auditor_firm,
            statutory_auditor_frn,
            statutory_auditor_membership_no,
            signatory_1_name,
            signatory_1_designation,
            signatory_1_custom_designation,
            signatory_1_id_no,
            signatory_1_signing_authority,
            signatory_1_is_signing,
            signatory_2_name,
            signatory_2_designation,
            signatory_2_custom_designation,
            signatory_2_id_no,
            signatory_2_signing_authority,
            signatory_2_is_signing
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $category = strtolower((string) ($row['category'] ?? ''));
    $category = str_replace(['-', ' '], '_', $category);
    $defaultDesignation = getDefaultDesignationForCategory($category);

    $signatory1Designation = (string) ($row['signatory_1_designation'] ?? '');
    if ($signatory1Designation === 'custom') {
        $signatory1Designation = (string) ($row['signatory_1_custom_designation'] ?? '');
    }

    $signatory2Designation = (string) ($row['signatory_2_designation'] ?? '');
    if ($signatory2Designation === 'custom') {
        $signatory2Designation = (string) ($row['signatory_2_custom_designation'] ?? '');
    }

    return [
        'company_name' => (string) ($row['name'] ?? ''),
        'auditor_name' => (string) ($row['statutory_auditor_name'] ?? ''),
        'auditor_firm' => (string) ($row['statutory_auditor_firm'] ?? ''),
        'auditor_frn' => (string) ($row['statutory_auditor_frn'] ?? ''),
        'auditor_membership_no' => (string) ($row['statutory_auditor_membership_no'] ?? ''),
        'signatory_1_name' => (string) ($row['signatory_1_name'] ?? ''),
        'signatory_1_designation' => $signatory1Designation !== '' ? $signatory1Designation : $defaultDesignation,
        'signatory_1_id_no' => (string) ($row['signatory_1_id_no'] ?? ''),
        'signatory_1_signing_authority' => (string) ($row['signatory_1_signing_authority'] ?? ''),
        'signatory_1_is_signing' => (int) ($row['signatory_1_is_signing'] ?? 0),
        'signatory_2_name' => (string) ($row['signatory_2_name'] ?? ''),
        'signatory_2_designation' => $signatory2Designation !== '' ? $signatory2Designation : $defaultDesignation,
        'signatory_2_id_no' => (string) ($row['signatory_2_id_no'] ?? ''),
        'signatory_2_signing_authority' => (string) ($row['signatory_2_signing_authority'] ?? ''),
        'signatory_2_is_signing' => (int) ($row['signatory_2_is_signing'] ?? 0),
    ];
}
