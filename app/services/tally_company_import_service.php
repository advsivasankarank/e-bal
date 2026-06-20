<?php
/**
 * Tally Company Import Service
 *
 * Connects to Tally Bridge (localhost:9123) to:
 * - List available companies
 * - Fetch company details
 * - Map Tally fields to e-BAL entity fields
 * - Detect duplicates
 */

class TallyCompanyImportService
{
    private string $bridgeUrl;
    private string $bridgeToken;
    private PDO $pdo;
    private int $ownerId;

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo = $pdo;
        $this->ownerId = $ownerId;
        $this->bridgeUrl = defined('TALLY_BRIDGE_URL') && TALLY_BRIDGE_URL !== ''
            ? TALLY_BRIDGE_URL
            : 'http://127.0.0.1:9123';
        $this->bridgeToken = defined('TALLY_BRIDGE_TOKEN') ? TALLY_BRIDGE_TOKEN : '';
    }

    /**
     * Check if Tally bridge is reachable
     */
    public function healthCheck(): array
    {
        $response = $this->bridgeRequest('GET', '/health');
        if ($response === null) {
            return ['ok' => false, 'message' => 'Tally Bridge is not reachable. Start the e-BAL Smart Bridge application.'];
        }
        return ['ok' => true, 'data' => $response];
    }

    /**
     * List all available companies from Tally
     */
    public function listCompanies(): array
    {
        $response = $this->bridgeRequest('GET', '/companies');
        if ($response === null) {
            return ['ok' => false, 'message' => 'Failed to connect to Tally Bridge.'];
        }
        if (!isset($response['companies']) || !is_array($response['companies'])) {
            return ['ok' => false, 'message' => 'No companies found in Tally.'];
        }
        return ['ok' => true, 'companies' => $response['companies']];
    }

    /**
     * Fetch detailed company data from Tally
     */
    public function fetchCompanyDetail(string $tallyCompanyName): array
    {
        $response = $this->bridgeRequest('POST', '/company', ['company_name' => $tallyCompanyName]);
        if ($response === null) {
            return ['ok' => false, 'message' => 'Failed to fetch company details from Tally.'];
        }
        if (!isset($response['company']) || !is_array($response['company'])) {
            return ['ok' => false, 'message' => 'Invalid response from Tally.'];
        }

        $company = $response['company'];
        $mapped = $this->mapFields($company);

        return ['ok' => true, 'raw' => $company, 'mapped' => $mapped];
    }

    /**
     * Map Tally company fields to e-BAL entity fields
     */
    private function mapFields(array $tally): array
    {
        /* Entity Name */
        $name = $tally['name'] ?? $tally['mailing_name'] ?? '';

        /* Category detection */
        $category = 'non_corporate';
        $cin = strtoupper(trim((string) ($tally['cin'] ?? '')));
        $llpCode = strtoupper(trim((string) ($tally['llpin'] ?? $tally['llp_code'] ?? '')));
        $pan = strtoupper(trim((string) ($tally['pan'] ?? '')));
        $gstin = strtoupper(trim((string) ($tally['gstin'] ?? '')));

        if ($cin !== '' && preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}/', $cin)) {
            $category = 'corporate';
        } elseif ($llpCode !== '') {
            $category = 'llp';
        }

        /* Address */
        $addressParts = [];
        if (!empty($tally['address_lines']) && is_array($tally['address_lines'])) {
            $addressParts = $tally['address_lines'];
        } elseif (!empty($tally['address'])) {
            $addressParts = array_filter(explode("\n", $tally['address']));
        }
        $pinCode = trim((string) ($tally['pin_code'] ?? $tally['pincode'] ?? ''));
        if ($pinCode !== '') {
            $addressParts[] = $pinCode;
        }
        $registeredAddress = implode("\n", array_map('trim', $addressParts));

        /* State */
        $stateName = trim((string) ($tally['state_name'] ?? $tally['state'] ?? ''));
        $stateCode = $this->resolveStateCode($stateName);

        /* Contact */
        $email = trim((string) ($tally['email'] ?? $tally['official_email'] ?? ''));
        $mobile = trim((string) ($tally['mobile'] ?? $tally['mobile_no'] ?? ''));
        $phone = trim((string) ($tally['phone'] ?? $tally['telephone'] ?? ''));
        $website = trim((string) ($tally['website'] ?? ''));

        /* Company Type */
        $companyType = trim((string) ($tally['company_type'] ?? ''));

        return [
            'name' => $name,
            'category' => $category,
            'cin' => $cin,
            'llp_code' => $llpCode,
            'pan' => $pan,
            'gstin' => $gstin,
            'registered_address' => $registeredAddress,
            'state_code' => $stateCode,
            'state_name' => $stateName,
            'email' => $email,
            'mobile' => $mobile,
            'phone' => $phone,
            'website' => $website,
            'company_type' => $companyType,
        ];
    }

    /**
     * Check for duplicate entities
     */
    public function checkDuplicates(array $mapped): array
    {
        $duplicates = [];
        $checks = [];

        if (!empty($mapped['name'])) {
            $checks[] = ['field' => 'name', 'label' => 'Entity Name', 'value' => $mapped['name']];
        }
        if (!empty($mapped['pan'])) {
            $checks[] = ['field' => 'pan', 'label' => 'PAN', 'value' => $mapped['pan']];
        }
        if (!empty($mapped['gstin'])) {
            $checks[] = ['field' => 'gstin', 'label' => 'GSTIN', 'value' => $mapped['gstin']];
        }
        if (!empty($mapped['cin'])) {
            $checks[] = ['field' => 'cin', 'label' => 'CIN', 'value' => $mapped['cin']];
        }
        if (!empty($mapped['llp_code'])) {
            $checks[] = ['field' => 'llp_code', 'label' => 'LLPIN', 'value' => $mapped['llp_code']];
        }

        foreach ($checks as $check) {
            $field = $check['field'];
            $value = $check['value'];

            if ($field === 'name') {
                $stmt = $this->pdo->prepare("SELECT id, name FROM companies WHERE name = ? AND owner_user_id = ? LIMIT 1");
                $stmt->execute([$value, $this->ownerId]);
            } elseif ($field === 'pan') {
                $stmt = $this->pdo->prepare("SELECT id, name FROM companies WHERE pan = ? AND owner_user_id = ? LIMIT 1");
                $stmt->execute([$value, $this->ownerId]);
            } elseif ($field === 'gstin') {
                $stmt = $this->pdo->prepare("SELECT id, name FROM companies WHERE gstin = ? AND owner_user_id = ? LIMIT 1");
                $stmt->execute([$value, $this->ownerId]);
            } elseif ($field === 'cin') {
                $stmt = $this->pdo->prepare("SELECT id, name FROM companies WHERE cin = ? AND owner_user_id = ? LIMIT 1");
                $stmt->execute([$value, $this->ownerId]);
            } elseif ($field === 'llp_code') {
                $stmt = $this->pdo->prepare("SELECT id, name FROM companies WHERE llp_code = ? AND owner_user_id = ? LIMIT 1");
                $stmt->execute([$value, $this->ownerId]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $duplicates[] = [
                    'field' => $check['label'],
                    'value' => $value,
                    'existing_id' => (int) $row['id'],
                    'existing_name' => $row['name'],
                ];
            }
        }

        return $duplicates;
    }

    /**
     * Make HTTP request to Tally Bridge
     */
    private function bridgeRequest(string $method, string $path, array $body = []): ?array
    {
        $url = rtrim($this->bridgeUrl, '/') . $path;

        $headers = ['Content-Type: application/json'];
        if ($this->bridgeToken !== '') {
            $headers[] = 'X-Bridge-Token: ' . $this->bridgeToken;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve state name to state code
     */
    private function resolveStateCode(string $stateName): string
    {
        $stateMap = [
            'ANDHRA PRADESH' => '37', 'ARUNACHAL PRADESH' => '12', 'ASSAM' => '18',
            'BIHAR' => '10', 'CHHATTISGARH' => '22', 'GOA' => '30', 'GUJARAT' => '24',
            'HARYANA' => '06', 'HIMACHAL PRADESH' => '02', 'JHARKHAND' => '20',
            'KARNATAKA' => '29', 'KERALA' => '32', 'MADHYA PRADESH' => '23',
            'MAHARASHTRA' => '27', 'MANIPUR' => '14', 'MEGHALAYA' => '17',
            'MIZORAM' => '15', 'NAGALAND' => '13', 'ODISHA' => '21',
            'PUNJAB' => '03', 'RAJASTHAN' => '08', 'SIKKIM' => '11',
            'TAMIL NADU' => '33', 'TELANGANA' => '36', 'TRIPURA' => '16',
            'UTTAR PRADESH' => '09', 'UTTARAKHAND' => '05', 'WEST BENGAL' => '19',
            'DELHI' => '07', 'JAMMU AND KASHMIR' => '01', 'LADAKH' => '38',
            'CHANDIGARH' => '04', 'DADRA AND NAGAR HAVELI' => '26',
            'DAMAN AND DIU' => '25', 'LAKSHADWEEP' => '31',
            'PUDUCHERRY' => '34', 'ANDAMAN AND NICOBAR ISLANDS' => '35',
        ];

        $normalized = strtoupper(trim($stateName));
        return $stateMap[$normalized] ?? '';
    }
}
