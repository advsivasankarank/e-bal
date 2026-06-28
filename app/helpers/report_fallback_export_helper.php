<?php

function reportPlainTextFromHtml(string $html): string
{
    $text = html_entity_decode(strip_tags(preg_replace('/<\/(p|div|tr|h[1-6]|section|table)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string) $text);
}

function createFallbackPdf(string $html, string $title): string
{
    $lines = preg_split('/\R/', wordwrap(reportPlainTextFromHtml($html), 92, "\n", true));
    $lines = array_slice(array_filter(array_map('trim', $lines ?: [])), 0, 220);
    array_unshift($lines, $title, '');

    $content = "BT\n/F1 10 Tf\n50 790 Td\n14 TL\n";
    foreach ($lines as $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $content .= '(' . $safe . ") Tj\nT*\n";
    }
    $content .= "ET";

    $objects = [];
    $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
    $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
    $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj\n";
    $objects[] = "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
    $objects[] = "5 0 obj << /Length " . strlen($content) . " >> stream\n" . $content . "\nendstream endobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    $path = sys_get_temp_dir() . '/ebal_export_' . uniqid('', true) . '.pdf';
    file_put_contents($path, $pdf);
    return $path;
}

function createFallbackDocx(string $html): string
{
    $path = sys_get_temp_dir() . '/ebal_export_' . uniqid('', true) . '.docx';
    if (!class_exists('ZipArchive')) {
        file_put_contents($path, $html);
        return $path;
    }

    $paragraphs = preg_split('/\R+/', reportPlainTextFromHtml($html)) ?: [];
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;
        $body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($paragraph, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
    }

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '<w:sectPr/></w:body></w:document>');
    $zip->close();
    return $path;
}

function createFallbackXlsx(string $html): string
{
    $path = sys_get_temp_dir() . '/ebal_export_' . uniqid('', true) . '.xlsx';
    if (!class_exists('ZipArchive')) {
        file_put_contents($path, reportPlainTextFromHtml($html));
        return $path;
    }

    $rows = array_slice(array_filter(array_map('trim', preg_split('/\R+/', reportPlainTextFromHtml($html)) ?: [])), 0, 1000);
    $sheetData = '';
    $r = 1;
    foreach ($rows as $row) {
        $sheetData .= '<row r="' . $r . '"><c r="A' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($row, ENT_XML1, 'UTF-8') . '</t></is></c></row>';
        $r++;
    }

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Financial Statements" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetData . '</sheetData></worksheet>');
    $zip->close();
    return $path;
}
