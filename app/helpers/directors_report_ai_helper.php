<?php

require_once __DIR__ . '/figure_helper.php';

function getDirectorsReportSectionDefinitions(): array
{
    return [
        'intro' => "Board's Report",
        'financial_highlights' => 'Financial Highlights',
        'state_of_affairs' => "State of the Company's Affairs",
        'change_in_business_nature' => 'Change in the Nature of Business, if any',
        'dividend_reserve' => 'Dividend and Transfer to Reserves',
        'material_changes_after_year_end' => 'Material Changes and Commitments Affecting Financial Position',
        'directors_kmp' => 'Directors and Key Managerial Personnel',
        'board_meetings' => 'Number of Meetings of the Board',
        'directors_responsibility' => "Directors' Responsibility Statement",
        'conservation_energy' => 'Conservation of Energy',
        'technology_absorption' => 'Technology Absorption',
        'forex_earnings_outgo' => 'Foreign Exchange Earnings and Outgo',
        'risk_management' => 'Risk Management Policy',
        'deposits' => 'Deposits',
        'significant_orders' => 'Significant and Material Orders Passed by Regulators/Courts/Tribunals',
        'internal_financial_controls' => 'Adequacy of Internal Financial Controls',
        'subsidiaries_jv_associates' => 'Subsidiaries, Joint Ventures and Associate Companies',
        'annual_return_extract' => 'Extract of Annual Return',
        'auditors' => 'Auditors',
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
        'financial_highlights' => "- Revenue from operations for the year stood at " . format_inr_number($revenue) . " as against " . format_inr_number($previousRevenue) . " in the previous year.\n- Profit after tax for the year stood at " . format_inr_number($profitAfterTax) . " as against " . format_inr_number($previousPat) . " in the previous year.\n- Net worth as at year end stood at " . format_inr_number($netWorth) . " as against " . format_inr_number($previousNetWorth) . " in the previous year.",
        'state_of_affairs' => "The Company continued its business operations during the year. The accompanying financial statements and notes to accounts present the operating results and financial position of the Company for the year under review.",
        'change_in_business_nature' => "There was no change in the nature of business of the Company during the year under review.",
        'dividend_reserve' => "The Board may record its decision regarding declaration of dividend and transfer to reserves based on the approved financial results.",
        'material_changes_after_year_end' => "No material changes and commitments affecting the financial position of the Company have occurred between the end of the financial year to which these financial statements relate and the date of this report.",
        'directors_kmp' => "{$signatoryOne}" . ($signatoryTwo !== '' ? " and {$signatoryTwo}" : '') . " continued to act as Directors of the Company during the year. Any changes in directorships or key managerial personnel may be recorded here before finalisation.",
        'board_meetings' => "The Board of Directors met the required number of times during the financial year under review, and the gap between any two meetings did not exceed the period prescribed under the Companies Act, 2013. The number of meetings and attendance of each Director may be recorded here before finalisation.",
        'directors_responsibility' => buildDirectorsResponsibilityStatement($fyEndDate),
        'conservation_energy' => "The Company's operations do not involve activities having a significant impact on energy conservation. However, wherever possible, the Company strives to conserve energy.",
        'technology_absorption' => "The Company has not imported any technology during the year under review, and there is no expenditure incurred on research and development during the year.",
        'forex_earnings_outgo' => "Foreign Exchange Earnings: Nil\nForeign Exchange Outgo: Nil",
        'risk_management' => "The Company has developed and implemented a risk management policy for identification of elements of risk, if any, which in the opinion of the Board may threaten the existence of the Company. The Board periodically reviews the risk management policy so that the risk is kept under control.",
        'deposits' => "The Company has not accepted any deposits covered under Chapter V of the Companies Act, 2013 during the year under review.",
        'significant_orders' => "There were no significant and material orders passed by the regulators or courts or tribunals impacting the going concern status and the Company's operations in future.",
        'internal_financial_controls' => "The Company has an adequate internal financial control system commensurate with the size and nature of its business.",
        'subsidiaries_jv_associates' => "The Company does not have any subsidiary, joint venture or associate company during the year under review.",
        'annual_return_extract' => "Corporate Identification Number (CIN): {$cin}\nRegistered Office: {$registeredAddress}\n\nShareholders holding more than 5% of shares (Note 1 of the financial statements):\n{$shareholderSummary}\n\nPrincipal business activities of the Company may be recorded here.",
        'auditors' => trim("The statutory auditor details appearing in the financial statements may be referred to here." . ($auditorFirm !== '' || $auditorName !== '' ? " Current auditor details: {$auditorFirm}" . ($auditorFirm !== '' && $auditorName !== '' ? ', ' : '') . "{$auditorName}." : '')),
        'secretarial_standards' => "The Company has complied with the applicable provisions of Secretarial Standards on Meetings of the Board of Directors (SS-1) and General Meetings (SS-2) issued by the Institute of Company Secretaries of India.",
        'acknowledgement' => "The Board places on record its appreciation for the support received from stakeholders, employees, customers, bankers, and statutory authorities.",
    ];
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
