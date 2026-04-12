<?php

require_once __DIR__ . '/../../xml_engine/tally_connector.php';
require_once __DIR__ . '/../helpers/xml_sanitizer.php';

class TallyBridgeService
{
    private string $xmlUrl;

    public function __construct(array $config = [])
    {
        $this->xmlUrl = $config['xml_url'] ?? 'http://127.0.0.1:9000';
    }

    public function health(): array
    {
        $context = fetchTallyLiveContext();

        return [
            'ok' => $context !== null,
            'transport' => 'xml',
            'xml_url' => $this->xmlUrl,
            'live_context' => $context,
        ];
    }

    public function company(): array
    {
        $context = fetchTallyLiveContext();

        return [
            'ok' => $context !== null,
            'company' => $context['company_name'] ?? '',
            'period_from' => $context['period_from'] ?? '',
            'period_to' => $context['period_to'] ?? '',
        ];
    }

    public function ledgerMaster(): array
    {
        $xml = <<<'XML'
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>LedgerList</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="LedgerList">
      <TYPE>Ledger</TYPE>
      <FETCH>Name, Parent</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
XML;

        $response = fetchFromTally($xml);
        if ($response === false || trim((string) $response) === '') {
            return [
                'ok' => false,
                'message' => 'Tally did not return a ledger master response.',
                'rows' => [],
            ];
        }

        $xmlObj = $this->parseXml($response);
        if ($xmlObj === null) {
            return [
                'ok' => false,
                'message' => 'Invalid XML returned for ledger master.',
                'rows' => [],
            ];
        }

        $rows = [];
        $nodes = $xmlObj->xpath("//*[local-name()='LEDGER']");

        foreach ($nodes as $node) {
            $name = trim((string) ($node['NAME'] ?? $node->NAME ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'ledger_name' => $name,
                'parent_group' => trim((string) ($node->PARENT ?? '')),
            ];
        }

        return [
            'ok' => true,
            'count' => count($rows),
            'rows' => $rows,
        ];
    }

    public function trialBalance(string $fyLabel): array
    {
        $range = $this->fyRangeFromLabel($fyLabel);
        if ($range === null) {
            return [
                'ok' => false,
                'message' => 'Invalid financial year label.',
                'rows' => [],
            ];
        }

        return $this->trialBalanceViaXml($range, $fyLabel);
    }

    private function trialBalanceViaXml(array $range, string $fyLabel): array
    {
        $xml = str_replace(
            ['__FROM__', '__TO__'],
            [$range['from_display'], $range['to_display']],
            <<<'XML'
<ENVELOPE>
  <HEADER>
    <TALLYREQUEST>Export Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <EXPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>Trial Balance</REPORTNAME>
        <STATICVARIABLES>
          <SVFROMDATE>__FROM__</SVFROMDATE>
          <SVTODATE>__TO__</SVTODATE>
          <ISLEDGERWISE>Yes</ISLEDGERWISE>
          <SVEXPORTFORMAT>XML</SVEXPORTFORMAT>
        </STATICVARIABLES>
      </REQUESTDESC>
    </EXPORTDATA>
  </BODY>
</ENVELOPE>
XML
        );

        $response = fetchFromTally($xml);
        if ($response === false || trim((string) $response) === '') {
            return [
                'ok' => false,
                'message' => 'Tally did not return a Trial Balance response.',
                'rows' => [],
                'raw_xml' => '',
            ];
        }

        $xmlObj = $this->parseXml($response);
        if ($xmlObj === null) {
            return [
                'ok' => false,
                'message' => 'Invalid XML returned for Trial Balance.',
                'rows' => [],
                'raw_xml' => '',
            ];
        }

        $rows = $this->extractTrialBalanceRows($xmlObj);

        return [
            'ok' => true,
            'mode' => 'xml_report',
            'fy' => $fyLabel,
            'count' => count($rows),
            'rows' => $rows,
            'raw_xml' => sanitizeTallyXML($response),
        ];
    }

    private function parseXml(string $rawXml): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xmlObj = simplexml_load_string(sanitizeTallyXML($rawXml));
        if ($xmlObj === false) {
            libxml_clear_errors();
            return null;
        }

        return $xmlObj;
    }

    private function fyRangeFromLabel(string $fyLabel): ?array
    {
        if (!preg_match('/^(\d{4})-(\d{4})$/', trim($fyLabel), $matches)) {
            return null;
        }

        return [
            'from_display' => '01-Apr-' . $matches[1],
            'to_display' => '31-Mar-' . $matches[2],
        ];
    }

    private function extractTrialBalanceRows(SimpleXMLElement $xmlObj): array
    {
        $rows = [];

        $nameNodes = $xmlObj->xpath("//*[local-name()='DSPACCNAME']");
        $infoNodes = $xmlObj->xpath("//*[local-name()='DSPACCINFO']");

        if (!empty($nameNodes) && !empty($infoNodes)) {
            $total = min(count($nameNodes), count($infoNodes));

            for ($i = 0; $i < $total; $i++) {
                $name = trim((string) ($nameNodes[$i]->DSPDISPNAME ?? ''));
                $dr = (float) ($infoNodes[$i]->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
                $cr = (float) ($infoNodes[$i]->DSPCLCRAMT->DSPCLCRAMTA ?? 0);

                if ($name === '' || ($dr == 0.0 && $cr == 0.0)) {
                    continue;
                }

                $rows[] = [
                    'ledger_name' => $name,
                    'parent_group' => '',
                    'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
                    'type' => $dr != 0.0 ? 'DR' : 'CR',
                ];
            }

            return $rows;
        }

        $lineNodes = $xmlObj->xpath("//*[local-name()='DSPACCLINE']");
        foreach ($lineNodes as $node) {
            $this->walkTrialBalanceNode($node, $rows);
        }

        return $rows;
    }

    private function walkTrialBalanceNode(SimpleXMLElement $node, array &$rows): void
    {
        $name = trim((string) ($node->DSPACCNAME->DSPDISPNAME ?? ''));

        $dr = (float) ($node->DSPACCINFO->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
        $cr = (float) ($node->DSPACCINFO->DSPCLCRAMT->DSPCLCRAMTA ?? 0);

        if ($name !== '' && ($dr != 0.0 || $cr != 0.0)) {
            $rows[] = [
                'ledger_name' => $name,
                'parent_group' => '',
                'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
                'type' => $dr != 0.0 ? 'DR' : 'CR',
            ];
        }

        if (isset($node->GRPEXplosion->DSPACCLINE)) {
            foreach ($node->GRPEXplosion->DSPACCLINE as $childNode) {
                $this->walkTrialBalanceNode($childNode, $rows);
            }
        }

        if (isset($node->GRPEXPLOSION->DSPACCLINE)) {
            foreach ($node->GRPEXPLOSION->DSPACCLINE as $childNode) {
                $this->walkTrialBalanceNode($childNode, $rows);
            }
        }
    }
}
