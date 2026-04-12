<?php

function getDirectorsReportSectionDefinitions(): array
{
    return [
        'intro' => "Board's Report",
        'financial_highlights' => 'Financial Highlights',
        'state_of_affairs' => "State of the Company's Affairs",
        'dividend_reserve' => 'Dividend and Transfer to Reserves',
        'directors_kmp' => 'Directors and Key Managerial Personnel',
        'auditors' => 'Auditors',
        'directors_responsibility' => "Directors' Responsibility Statement",
        'acknowledgement' => 'Acknowledgement',
    ];
}

function buildDirectorsReportFallbackSections(array $fs, string $companyName, string $fyName): array
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

    return [
        'intro' => "Your Directors are pleased to present their report together with the audited financial statements of {$companyName} for the financial year ended {$fyName}.",
        'financial_highlights' => "- Revenue from operations for the year stood at " . number_format($revenue, 2) . " as against " . number_format($previousRevenue, 2) . " in the previous year.\n- Profit after tax for the year stood at " . number_format($profitAfterTax, 2) . " as against " . number_format($previousPat, 2) . " in the previous year.\n- Net worth as at year end stood at " . number_format($netWorth, 2) . " as against " . number_format($previousNetWorth, 2) . " in the previous year.",
        'state_of_affairs' => "The Company continued its business operations during the year. The accompanying financial statements and notes to accounts present the operating results and financial position of the Company for the year under review.",
        'dividend_reserve' => "The Board may record its decision regarding declaration of dividend and transfer to reserves based on the approved financial results.",
        'directors_kmp' => "{$signatoryOne}" . ($signatoryTwo !== '' ? " and {$signatoryTwo}" : '') . " continued to act as Directors of the Company during the year. Any changes in directorships or key managerial personnel may be recorded here before finalisation.",
        'auditors' => trim("The statutory auditor details appearing in the financial statements may be referred to here." . ($auditorFirm !== '' || $auditorName !== '' ? " Current auditor details: {$auditorFirm}" . ($auditorFirm !== '' && $auditorName !== '' ? ', ' : '') . "{$auditorName}." : '')),
        'directors_responsibility' => "The Directors confirm that the applicable accounting standards have been followed, accounting policies have been applied consistently, proper accounting records have been maintained, and the annual accounts have been prepared on a going concern basis.",
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

function generateDirectorsReportDraft(array $fs, string $companyName, string $fyName): array
{
    $fallbackSections = buildDirectorsReportFallbackSections($fs, $companyName, $fyName);
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
