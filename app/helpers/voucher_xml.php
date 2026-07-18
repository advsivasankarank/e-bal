<?php

function buildVoucherCollectionXml(string $fromDate, string $toDate, string $voucherType = ''): string
{
    $typeFilter = '';
    if ($voucherType !== '') {
        $typeFilter = "<VOUCHERTYPENAME>{$voucherType}</VOUCHERTYPENAME>";
    }

    return <<<XML
<ENVELOPE>
    <HEADER>
        <VERSION>1</VERSION>
        <TALLYREQUEST>Export</TALLYREQUEST>
        <TYPE>Collection</TYPE>
        <ID>VoucherCollection</ID>
    </HEADER>
    <BODY>
        <DESC>
            <STATICVARIABLES>
                <SVFROMDATE>{$fromDate}</SVFROMDATE>
                <SVTODATE>{$toDate}</SVTODATE>
                <SVEXPORTFORMAT>\$\$SysName:XML</SVEXPORTFORMAT>
            </STATICVARIABLES>
            <TDL>
                <TDLMESSAGE>
                    <COLLECTION NAME="VoucherCollection">
                        <TYPE>Voucher</TYPE>
                        <CHILDOF>\$\$VchVouchers</CHILDOF>
                        <FETCH>GUID, VoucherTypeName, VoucherNumber, Date, EffectiveDate, Narration, PartyLedgerName, IsOptional, IsCancelled, AlterDate, EnteredDate, LedgerEntries</FETCH>
                        {$typeFilter}
                    </COLLECTION>
                </TDLMESSAGE>
            </TDL>
        </DESC>
    </BODY>
</ENVELOPE>
XML;
}

function fetchVouchersViaXml(string $fromDate, string $toDate, ?string $voucherType = null): ?array
{
    require_once __DIR__ . '/../../xml_engine/tally_connector.php';
    require_once __DIR__ . '/xml_sanitizer.php';

    if ($voucherType !== null && $voucherType !== '') {
        return fetchVouchersViaXmlSingleType($fromDate, $toDate, $voucherType);
    }

    /* A Fixed Asset addition/disposal can only ever appear as one of these
       types (see classifyFixedAssetVoucherType() in fixed_asset_helper.php
       for why Payment/Receipt/Contra/Credit Note/Debit Note are kept, not
       just Purchase/Sales/Journal). Stock Journal and Physical Stock are
       inventory-item movements, not Fixed Asset ledger entries, and are
       typically the highest-volume voucher types for a trading/
       manufacturing company -- excluding them mirrors the same fix made
       on the Smart Bridge's own client-side fetch (tally_bridge_exe/
       ui_app.py), where fetching all ten types for a full year timed out
       against real company data even at 90 seconds. */
    $allVouchers = [];
    $voucherTypes = ['Payment', 'Receipt', 'Sales', 'Purchase', 'Journal', 'Contra', 'Credit Note', 'Debit Note'];

    foreach ($voucherTypes as $type) {
        $result = fetchVouchersViaXmlSingleType($fromDate, $toDate, $type);
        if ($result !== null) {
            foreach ($result as $v) {
                $guid = $v['tally_guid'];
                if (!isset($allVouchers[$guid])) {
                    $allVouchers[$guid] = $v;
                }
            }
        }
    }

    return !empty($allVouchers) ? array_values($allVouchers) : [];
}

/**
 * Fetches ONE voucher type's raw, unparsed Tally response -- purely for
 * diagnostics when a sync legitimately returns zero vouchers. A response
 * with no <VOUCHER> nodes is indistinguishable, at the parsed-array level,
 * between "Tally genuinely has none of this type in range" and "Tally
 * returned a LINEERROR / rejection we're silently swallowing as zero
 * results" -- this surfaces the raw bytes so a human can tell which.
 */
function fetchRawTallyVoucherResponseSample(string $fromDate, string $toDate): ?string
{
    require_once __DIR__ . '/../../xml_engine/tally_connector.php';

    $fromDisplay = date('d M Y', strtotime($fromDate)) ?: $fromDate;
    $toDisplay = date('d M Y', strtotime($toDate)) ?: $toDate;
    $xml = buildVoucherCollectionXml($fromDisplay, $toDisplay, 'Purchase');
    $response = fetchFromTally($xml);

    if ($response === false) {
        return null;
    }

    return (string) $response;
}

function fetchVouchersViaXmlSingleType(string $fromDate, string $toDate, string $voucherType): ?array
{
    $fromDisplay = date('d M Y', strtotime($fromDate)) ?: $fromDate;
    $toDisplay = date('d M Y', strtotime($toDate)) ?: $toDate;

    $xml = buildVoucherCollectionXml($fromDisplay, $toDisplay, $voucherType);
    $response = fetchFromTally($xml);

    if ($response === false || trim($response) === '') {
        return null;
    }

    $response = sanitizeTallyXML($response);
    libxml_use_internal_errors(true);
    $xmlObj = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NONET);
    if ($xmlObj === false) {
        libxml_clear_errors();
        return null;
    }

    $voucherNodes = $xmlObj->xpath("//*[local-name()='VOUCHER']");
    if (empty($voucherNodes)) {
        return [];
    }

    $vouchers = [];
    foreach ($voucherNodes as $node) {
        $guid = trim((string) ($node['GUID'] ?? $node->GUID ?? ''));
        if ($guid === '') {
            continue;
        }

        $voucher = parseXmlVoucherNode($node);
        if ($voucher !== null) {
            $vouchers[$guid] = $voucher;
        }
    }

    return array_values($vouchers);
}

function parseXmlVoucherNode(SimpleXMLElement $node): ?array
{
    $guid = trim((string) ($node['GUID'] ?? $node->GUID ?? ''));
    if ($guid === '') {
        return null;
    }

    $dateRaw = trim((string) ($node->DATE ?? ''));
    $date = '';
    if ($dateRaw !== '') {
        $ts = strtotime($dateRaw);
        $date = $ts ? date('Y-m-d', $ts) : $dateRaw;
    }

    $effDateRaw = trim((string) ($node->EFFECTIVEDATE ?? ''));
    $effectiveDate = '';
    if ($effDateRaw !== '') {
        $ts = strtotime($effDateRaw);
        $effectiveDate = $ts ? date('Y-m-d', $ts) : $effDateRaw;
    }

    $alterRaw = trim((string) ($node->ALTERDATE ?? $node->ALTEREDDATE ?? ''));
    $alteredDate = null;
    if ($alterRaw !== '') {
        $ts = strtotime($alterRaw);
        if ($ts) {
            $alteredDate = date('Y-m-d H:i:s', $ts);
        }
    }

    $enterRaw = trim((string) ($node->ENTEREDDATE ?? ''));
    $createdDate = null;
    if ($enterRaw !== '') {
        $ts = strtotime($enterRaw);
        if ($ts) {
            $createdDate = date('Y-m-d H:i:s', $ts);
        }
    }

    $entries = [];
    $allocNodes = $node->xpath("*[local-name()='ALLLEDGERENTRIES.LIST']/*[local-name()='LEDGERENTRY']");
    if (empty($allocNodes)) {
        $allocNodes = $node->xpath("*[local-name()='ALLLEDGERENTRIES']/*[local-name()='LEDGERENTRY']");
    }
    if (empty($allocNodes)) {
        $allocNodes = $node->xpath(".//LEDGERENTRY");
    }

    foreach ($allocNodes as $entry) {
        $ledgerName = trim((string) ($entry->LEDGERNAME ?? ''));
        if ($ledgerName === '') {
            continue;
        }
        $amount = (float) ($entry->AMOUNT ?? 0);
        $entries[] = [
            'ledger_name' => $ledgerName,
            'parent_group' => trim((string) ($entry->PARENT ?? '')),
            'amount' => abs($amount),
            'dr_cr' => $amount >= 0 ? 'DR' : 'CR',
            'bill_type' => trim((string) ($entry->BILLTYPE ?? '')),
            'bill_name' => trim((string) ($entry->BILLNAME ?? '')),
            'cost_centre' => trim((string) ($entry->COSTCENTRE ?? '')),
        ];
    }

    return [
        'tally_guid' => $guid,
        'master_id' => 0,
        'voucher_type' => trim((string) ($node->VOUCHERTYPENAME ?? '')),
        'voucher_number' => trim((string) ($node->VOUCHERNUMBER ?? '')),
        'date' => $date,
        'effective_date' => $effectiveDate,
        'narration' => trim((string) ($node->NARRATION ?? '')),
        'party_ledger_name' => trim((string) ($node->PARTYLEDGERNAME ?? '')),
        'is_optional' => (int) ($node->ISOPTIONAL ?? 0),
        'is_cancelled' => (int) ($node->ISCANCELLED ?? 0),
        'altered_date' => $alteredDate,
        'created_date' => $createdDate,
        'source' => 'xml',
        'entries' => $entries,
    ];
}
