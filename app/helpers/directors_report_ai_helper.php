<?php

require_once __DIR__ . '/figure_helper.php';

function getDirectorsReportSectionDefinitions(): array
{
    return [
        'intro' => "Board's Report",
        'financial_highlights' => 'Business Outlook and Performance Highlights',
        'board_meetings' => 'Number of Board Meetings',
        'directors_responsibility' => "Directors' Responsibility Statement",
        'auditors' => 'Statutory Auditors',
        'cost_audit_applicability' => 'Cost Auditors',
        'secretarial_audit_applicability' => 'Secretarial Audit',
        'loans_guarantees_investments' => 'Particulars of Loans, Guarantees or Investments',
        'related_party_transactions' => 'Particulars of Related Party Transactions',
        'amount_to_reserves' => 'Amount Proposed to Carry to Any Reserves',
        'dividend_recommendation' => 'Amount Recommended for Payment of Dividend',
        'material_changes_after_year_end' => 'Material Changes and Commitments Affecting Financial Position',
        'conservation_energy' => 'Conservation of Energy',
        'technology_absorption' => 'Technology Absorption',
        'forex_earnings_outgo' => 'Foreign Exchange Earnings and Outgo',
        'risk_management' => 'Risk Management Policy',
        'subsidiaries_jv_associates' => 'Report on Subsidiaries, Joint Ventures and Associate Companies',
        'change_in_business_nature' => 'Change in the Nature of Business, if any',
        'state_of_affairs' => "State of the Company's Affairs",
        'directors_kmp' => 'Directors and Key Managerial Personnel',
        'deposits' => 'Details Relating to Deposits',
        'significant_orders' => 'Significant and Material Orders Passed by Regulators/Courts/Tribunals',
        'internal_financial_controls' => 'Adequacy of Internal Financial Controls',
        'iepf_transfer' => 'Transfer of Amount to Investor Education and Protection Fund',
        'equity_shares_differential_rights' => 'Details of Issue of Equity Shares with Differential Rights',
        'sweat_equity_shares' => 'Details of Issue of Sweat Equity Shares',
        'esop_details' => 'Details of Issue of Employee Stock Options',
        'share_capital_debenture_rules' => 'Details under Section 43 read with Rule 16(4) of the Share Capital and Debentures Rules, 2014',
        'employee_relations' => 'Employee Relations',
        'annual_return_extract' => 'Extract of Annual Return',
        'secretarial_standards' => 'Compliance with Secretarial Standards',
        'acknowledgement' => 'Acknowledgement',
    ];
}

/**
 * The financial-year end date in "31st March, 20XX" style, parsed from a
 * "YYYY-YYYY" FY label (e.g. "2024-2025" -> "31st March, 2025"). Falls back
 * to the raw label if it doesn't match the expected shape.
 */
function directorsReportFyEndDate(string $fyName): string
{
    if (preg_match('/(\d{4})\s*-\s*(\d{4})/', $fyName, $matches)) {
        return '31st March, ' . $matches[2];
    }

    return $fyName;
}

/**
 * Directors' Responsibility Statement, Section 134(5) of the Companies Act,
 * 2013 -- points (a) to (e) only. Point (f) (internal financial controls)
 * is scoped to listed entities and is deliberately omitted here.
 */
function buildDirectorsResponsibilityStatement(string $fyEndDate): string
{
    return "In accordance with the provisions of Section 134(5) of the Companies Act, 2013, the Directors confirm that:\n\n"
        . "a) in the preparation of the annual accounts for the financial year ended {$fyEndDate}, the applicable accounting standards had been followed along with proper explanation relating to material departures;\n\n"
        . "b) the directors had selected such accounting policies and applied them consistently and made judgments and estimates that are reasonable and prudent so as to give a true and fair view of the state of affairs of the Company as at {$fyEndDate} and of the profit/loss of the Company for that period;\n\n"
        . "c) the directors had taken proper and sufficient care for the maintenance of adequate accounting records in accordance with the provisions of the Companies Act, 2013 for safeguarding the assets of the Company and for preventing and detecting fraud and other irregularities;\n\n"
        . "d) the directors had prepared the annual accounts on a going concern basis; and\n\n"
        . "e) the directors had devised proper systems to ensure compliance with the provisions of all applicable laws and that such systems were adequate and operating effectively.";
}

/**
 * "Financial Performance" table shown at the top of the Directors' Report,
 * matching the format used in practically-filed reports: Income from
 * Operations / Other Income / Total Revenue, then an expense breakup,
 * ending in Profit Before Tax / Tax / Profit After Tax. Computed directly
 * from the same P&L figures used in the Statement of Profit and Loss, so
 * it always ties to the filed financial statements rather than being a
 * second, independently-typed figure.
 *
 * @return array<int, array{label: string, current: float, previous: float, bold?: bool}>
 */
function buildDirectorsReportFinancialPerformanceRows(array $data): array
{
    return [
        ['label' => 'Income from Operations', 'current' => (float) ($data['revenue'] ?? 0), 'previous' => (float) ($data['prev_revenue'] ?? 0)],
        ['label' => 'Other Income', 'current' => (float) ($data['other_income'] ?? 0), 'previous' => (float) ($data['prev_other_income'] ?? 0)],
        ['label' => 'Total Revenue', 'current' => (float) ($data['total_income'] ?? 0), 'previous' => (float) ($data['prev_total_income'] ?? 0), 'bold' => true],
        ['label' => 'Cost of Materials Consumed / Purchase of Stock-in-Trade', 'current' => (float) ($data['materials'] ?? 0) + (float) ($data['purchase_stock'] ?? 0), 'previous' => (float) ($data['prev_materials'] ?? 0) + (float) ($data['prev_purchase_stock'] ?? 0)],
        ['label' => 'Changes in Inventories of Finished Goods, Work-in-Progress and Stock-in-Trade', 'current' => (float) ($data['inventory_change'] ?? 0), 'previous' => (float) ($data['prev_inventory_change'] ?? 0)],
        ['label' => 'Employee Benefits Expense', 'current' => (float) ($data['employee_cost'] ?? 0), 'previous' => (float) ($data['prev_employee_cost'] ?? 0)],
        ['label' => 'Finance Cost', 'current' => (float) ($data['finance_cost'] ?? 0), 'previous' => (float) ($data['prev_finance_cost'] ?? 0)],
        ['label' => 'Depreciation and Amortisation Expense', 'current' => (float) ($data['depreciation'] ?? 0), 'previous' => (float) ($data['prev_depreciation'] ?? 0)],
        ['label' => 'Other Expenses', 'current' => (float) ($data['other_expenses'] ?? 0), 'previous' => (float) ($data['prev_other_expenses'] ?? 0)],
        ['label' => 'Profit Before Tax', 'current' => (float) ($data['pbt'] ?? 0), 'previous' => (float) ($data['prev_pbt'] ?? 0), 'bold' => true],
        ['label' => 'Tax Expense', 'current' => (float) ($data['tax'] ?? 0), 'previous' => (float) ($data['prev_tax'] ?? 0)],
        ['label' => 'Profit / (Loss) After Tax', 'current' => (float) ($data['pat'] ?? 0), 'previous' => (float) ($data['prev_pat'] ?? 0), 'bold' => true],
    ];
}

function buildDirectorsReportFallbackSections(array $fs, string $companyName, string $fyName, array $shareholders = []): array
{
    $data = $fs['data'] ?? [];
    $companyMeta = $fs['company_meta'] ?? [];
    $profitAfterTax = (float) ($data['pat'] ?? 0);
    $previousPat = (float) ($data['prev_pat'] ?? 0);
    $revenue = (float) ($data['revenue'] ?? 0);
    $previousRevenue = (float) ($data['prev_revenue'] ?? 0);
    $netWorth = (float) (($data['share_capital'] ?? 0) + ($data['reserves'] ?? 0));
    $previousNetWorth = (float) (($data['prev_share_capital'] ?? 0) + ($data['prev_reserves'] ?? 0));
    $signatoryOne = trim((string) ($companyMeta['signatory_1_name'] ?? 'Director'));
    $signatoryTwo = trim((string) ($companyMeta['signatory_2_name'] ?? 'Director'));
    $auditorName = trim((string) ($companyMeta['auditor_name'] ?? ''));
    $auditorFirm = trim((string) ($companyMeta['auditor_firm'] ?? ''));
    $cin = trim((string) ($companyMeta['cin'] ?? ''));
    $registeredAddress = trim((string) ($companyMeta['registered_address'] ?? ''));
    $fyEndDate = directorsReportFyEndDate($fyName);

    $shareholderLines = [];
    foreach ($shareholders as $shareholder) {
        $name = trim((string) ($shareholder['name'] ?? ''));
        $shares = (float) ($shareholder['shares'] ?? 0);
        if ($name === '') {
            continue;
        }
        $shareholderLines[] = '- ' . $name . ': ' . number_format($shares, 0) . ' shares';
    }
    $shareholderSummary = $shareholderLines !== []
        ? implode("\n", $shareholderLines)
        : 'Refer to Note 1 (Share Capital) of the financial statements for full shareholding details.';

    return [
        'intro' => "Your Directors are pleased to present their report together with the audited financial statements of {$companyName} for the financial year ended {$fyName}.",
        'financial_highlights' => "The Company continued its business operations during the year. The financial highlights for the year are set out below and should be read together with the accompanying financial statements and notes to accounts.\n\nRevenue from operations for the year stood at " . format_inr_number($revenue) . " as against " . format_inr_number($previousRevenue) . " in the previous year. Profit after tax for the year stood at " . format_inr_number($profitAfterTax) . " as against " . format_inr_number($previousPat) . " in the previous year. Net worth as at year end stood at " . format_inr_number($netWorth) . " as against " . format_inr_number($previousNetWorth) . " in the previous year.",
        'state_of_affairs' => "The Company continued its business operations during the year. The accompanying financial statements and notes to accounts present the operating results and financial position of the Company for the year under review.",
        'change_in_business_nature' => "There was no change in the nature of business of the Company during the year under review.",
        'amount_to_reserves' => "The balance of net profit in the Statement of Profit and Loss for the year ended {$fyEndDate} has been carried to Reserves and Surplus in the Balance Sheet as at {$fyEndDate}.",
        'dividend_recommendation' => "Your Directors have not recommended any dividend on Equity Shares for the year under review.",
        'material_changes_after_year_end' => "No material changes and commitments affecting the financial position of the Company have occurred between the end of the financial year to which these financial statements relate and the date of this report.",
        'directors_kmp' => "{$signatoryOne}" . ($signatoryTwo !== '' ? " and {$signatoryTwo}" : '') . " continued to act as Directors of the Company during the year. Any changes in directorships or key managerial personnel may be recorded here before finalisation.",
        'board_meetings' => "The Board of Directors met the required number of times during the financial year under review, and the gap between any two meetings did not exceed the period prescribed under the Companies Act, 2013. The number of meetings and attendance of each Director may be recorded here before finalisation.",
        'directors_responsibility' => buildDirectorsResponsibilityStatement($fyEndDate),
        'conservation_energy' => "There is no captive generation of energy. The Company's operations do not involve activities having a significant impact on energy conservation. However, wherever possible, the Company strives to conserve energy.",
        'technology_absorption' => "The Company has not imported any technology during the year under review, and there is no expenditure incurred on research and development during the year.",
        'forex_earnings_outgo' => "- Foreign Exchange Earned through Direct Export: Nil\n- Other Foreign Exchange Earnings: Nil\n- Total Foreign Exchange Earned: Nil\n- Foreign Exchange Outgo: Nil",
        'risk_management' => "The Board considers that there is no significant risk involved in the affairs of the Company. Where there exist insurable risks, they are adequately insured to the extent considered necessary.",
        'deposits' => "The Company has not accepted any deposits covered under Chapter V of the Companies Act, 2013 during the year under review.",
        'significant_orders' => "There were no significant and material orders passed by the regulators or courts or tribunals impacting the going concern status and the Company's operations in future.",
        'internal_financial_controls' => "A proper and adequate system of internal control, commensurate with the size and nature of its business, is integral to the Company's governance. Adequate documentation of policies, guidelines, authorities and approval procedures are in place for controlling important functions of the Company.",
        'subsidiaries_jv_associates' => "The Company does not have any subsidiary, joint venture or associate company during the year under review.",
        'loans_guarantees_investments' => "The Company has not made any loan or investment, or given any guarantee, or provided any security under Section 186 of the Companies Act, 2013 during the year under review.",
        'related_party_transactions' => "The Company has not entered into any related party transactions as defined under Section 188 of the Companies Act, 2013 during the year under review.",
        /* Cost audit and Secretarial Audit applicability are threshold-driven --
           turnover / paid-up capital / net worth under the Companies (Cost
           Records and Audit) Rules, 2014 and Section 204 of the Companies Act,
           2013 respectively. This app has no such threshold data wired in, so
           the fallback text must prompt the CA to confirm the position rather
           than assert non-applicability by default -- an unedited report
           should visibly show the gap, not silently claim compliance. */
        'cost_audit_applicability' => "[Confirm: cost audit applicability under the Companies (Cost Records and Audit) Rules, 2014 has not been assessed by this system -- verify against the Company's turnover and net worth for the year ended {$fyEndDate} before finalising.]",
        'secretarial_audit_applicability' => "[Confirm: Secretarial Audit applicability under Section 204 of the Companies Act, 2013 has not been assessed by this system -- verify against the Company's paid-up share capital and turnover for the year ended {$fyEndDate} before finalising.]",
        'iepf_transfer' => "The Company does not have any funds lying unpaid or unclaimed for a period of seven years. There were no funds required to be transferred to the Investor Education and Protection Fund (IEPF) during the year under review.",
        'equity_shares_differential_rights' => "The Company has not issued any equity shares with differential voting rights during the year under review.",
        'sweat_equity_shares' => "The Company has not issued any sweat equity shares during the year under review.",
        'esop_details' => "The Company has not issued any shares to its employees under any Employee Stock Option Scheme during the year under review.",
        'share_capital_debenture_rules' => "The Company has not issued any shares or debentures requiring disclosure under Section 43 of the Companies Act, 2013 read with Rule 16(4) of the Companies (Share Capital and Debentures) Rules, 2014 during the year under review.",
        'employee_relations' => "Employee relations continued to remain cordial during the year under review.",
        'annual_return_extract' => "Corporate Identification Number (CIN): {$cin}\nRegistered Office: {$registeredAddress}\n\nShareholders holding more than 5% of shares (Note 1 of the financial statements):\n{$shareholderSummary}\n\nPrincipal business activities of the Company may be recorded here.",
        'auditors' => trim("The Board recommends the appointment/re-appointment of the statutory auditor pursuant to Section 139 of the Companies Act, 2013, subject to ratification by the shareholders where applicable. The Auditors' Report does not contain any qualification, reservation or adverse remark; the notes to accounts and auditors' remarks are self-explanatory and do not call for any further comment from the Board." . ($auditorFirm !== '' || $auditorName !== '' ? " Current auditor details: {$auditorFirm}" . ($auditorFirm !== '' && $auditorName !== '' ? ', ' : '') . "{$auditorName}." : '')),
        'secretarial_standards' => "The Company has complied with the applicable provisions of Secretarial Standards on Meetings of the Board of Directors (SS-1) and General Meetings (SS-2) issued by the Institute of Company Secretaries of India.",
        'acknowledgement' => "The Board places on record its appreciation for the support received from stakeholders, employees, customers, bankers, and statutory authorities.",
    ];
}

/**
 * Loads this year's saved Directors' Report sections, falling back to the
 * generated boilerplate wherever no section has been saved yet. Shared by
 * the on-screen editor (public/directors_report.php) and the PDF/DOCX
 * export path (public/report_download.php) so both always show identical
 * content for a given company/FY.
 *
 * @return array{definitions: array<string,string>, sections: array<string,string>}
 */
function loadDirectorsReportSections(array $manualBundle, array $fs, string $companyName, string $fyName, array $shareholders = []): array
{
    $sectionDefinitions = getDirectorsReportSectionDefinitions();
    $draftSections = [];
    foreach ($sectionDefinitions as $key => $title) {
        $draftSections[$key] = (string) ($manualBundle['saved_current']['directors_report_' . $key] ?? '');
    }

    $hasSavedSections = array_filter($draftSections, static fn ($value) => trim((string) $value) !== '') !== [];
    if (!$hasSavedSections) {
        $draftSections = buildDirectorsReportFallbackSections($fs, $companyName, $fyName, $shareholders);
    }

    return ['definitions' => $sectionDefinitions, 'sections' => $draftSections];
}

function combineDirectorsReportSections(array $sections, string $companyName): string
{
    $definitions = getDirectorsReportSectionDefinitions();
    $parts = ["DIRECTORS' REPORT", '', "To,", "The Members of {$companyName}", ''];

    $noteNo = 1;
    foreach ($definitions as $key => $title) {
        $content = trim((string) ($sections[$key] ?? ''));
        $parts[] = $noteNo . '. ' . $title;
        $parts[] = $content;
        $parts[] = '';
        $noteNo++;
    }

    $parts[] = 'For and on behalf of the Board of Directors';
    return trim(implode("\n", $parts));
}

function requestDirectorsReportAi(array $payload): array
{
    if (DIRECTORS_REPORT_AI_URL === '') {
        return [
            'ok' => false,
            'message' => 'External AI connector is not configured, so e-BAL used the built-in directors report draft.',
        ];
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (DIRECTORS_REPORT_AI_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . DIRECTORS_REPORT_AI_TOKEN;
    }

    $ch = curl_init(DIRECTORS_REPORT_AI_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'message' => 'AI request failed: ' . $error];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status >= 400) {
        return ['ok' => false, 'message' => 'AI request returned HTTP ' . $status . '.'];
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'AI response was not valid JSON.'];
    }

    $draft = trim((string) ($payload['draft'] ?? $payload['text'] ?? ''));
    $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
    if ($draft === '' && $sections === []) {
        return ['ok' => false, 'message' => 'AI response did not include a directors report draft.'];
    }

    return ['ok' => true, 'draft' => $draft, 'sections' => $sections];
}

function generateDirectorsReportDraft(array $fs, string $companyName, string $fyName, array $shareholders = []): array
{
    $fallbackSections = buildDirectorsReportFallbackSections($fs, $companyName, $fyName, $shareholders);
    $fallbackDraft = combineDirectorsReportSections($fallbackSections, $companyName);

    $aiResult = requestDirectorsReportAi([
        'company_name' => $companyName,
        'financial_year' => $fyName,
        'entity_category' => $fs['entity_category'] ?? '',
        'summary' => $fs['data'] ?? [],
        'notes' => $fs['notes'] ?? [],
        'company_meta' => $fs['company_meta'] ?? [],
        'sections_required' => getDirectorsReportSectionDefinitions(),
    ]);

    if (($aiResult['ok'] ?? false) === true) {
        $sections = $fallbackSections;
        foreach (($aiResult['sections'] ?? []) as $key => $value) {
            if (array_key_exists($key, $sections) && trim((string) $value) !== '') {
                $sections[$key] = trim((string) $value);
            }
        }
        if (trim((string) ($aiResult['draft'] ?? '')) !== '' && ($aiResult['sections'] ?? []) === []) {
            $sections['intro'] = trim((string) $aiResult['draft']);
        }

        return [
            'draft' => combineDirectorsReportSections($sections, $companyName),
            'sections' => $sections,
            'source' => 'AI Connector',
        ];
    }

    return [
        'draft' => $fallbackDraft,
        'sections' => $fallbackSections,
        'source' => 'Built-in Draft',
        'message' => (string) ($aiResult['message'] ?? 'e-BAL used the built-in directors report draft.'),
    ];
}
