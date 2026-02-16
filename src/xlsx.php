<?php

declare(strict_types=1);

function mydump_xlsx_write(string $path, array $worksheets): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to write XLSX files.');
    }

    if (empty($worksheets)) {
        $worksheets = ['Sheet1' => []];
    }

    $sheetNames = mydump_xlsx_unique_sheet_names(array_keys($worksheets));
    $sheets = [];
    $sheetIndex = 1;
    foreach ($worksheets as $name => $rows) {
        $safeName = $sheetNames[(string) $name];
        $sheets[] = [
            'index' => $sheetIndex,
            'name' => $safeName,
            'rows' => is_array($rows) ? $rows : [],
        ];
        $sheetIndex++;
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Unable to create XLSX file: {$path}");
    }

    $zip->addFromString('[Content_Types].xml', mydump_xlsx_content_types_xml($sheets));
    $zip->addFromString('_rels/.rels', mydump_xlsx_root_rels_xml());
    $zip->addFromString('xl/workbook.xml', mydump_xlsx_workbook_xml($sheets));
    $zip->addFromString('xl/_rels/workbook.xml.rels', mydump_xlsx_workbook_rels_xml($sheets));
    $zip->addFromString('xl/styles.xml', mydump_xlsx_styles_xml());

    foreach ($sheets as $sheet) {
        $sheetPath = 'xl/worksheets/sheet' . $sheet['index'] . '.xml';
        $zip->addFromString($sheetPath, mydump_xlsx_sheet_xml((array) $sheet['rows']));
    }

    $zip->close();
}

function mydump_xlsx_read(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to read XLSX files.');
    }
    if (!is_file($path)) {
        throw new RuntimeException("XLSX file not found: {$path}");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Unable to open XLSX file: {$path}");
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml === false) {
        $zip->close();
        throw new RuntimeException('Invalid XLSX: missing xl/workbook.xml');
    }

    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml === false) {
        $zip->close();
        throw new RuntimeException('Invalid XLSX: missing xl/_rels/workbook.xml.rels');
    }

    $sharedStrings = mydump_xlsx_read_shared_strings($zip);
    $relationshipMap = mydump_xlsx_read_workbook_rels($relsXml);
    $sheetDefs = mydump_xlsx_read_workbook_sheets($workbookXml);

    $result = [];
    foreach ($sheetDefs as $sheetDef) {
        $rid = $sheetDef['rid'];
        if (!isset($relationshipMap[$rid])) {
            continue;
        }
        $target = $relationshipMap[$rid];
        $sheetPath = mydump_xlsx_normalize_workbook_target($target);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            continue;
        }
        $result[$sheetDef['name']] = mydump_xlsx_read_sheet_rows($sheetXml, $sharedStrings);
    }

    $zip->close();
    return $result;
}

function mydump_xlsx_content_types_xml(array $sheets): string
{
    $overrides = [];
    foreach ($sheets as $sheet) {
        $idx = (int) $sheet['index'];
        $overrides[] = '<Override PartName="/xl/worksheets/sheet' . $idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', $overrides)
        . '</Types>';
}

function mydump_xlsx_root_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function mydump_xlsx_workbook_xml(array $sheets): string
{
    $parts = [];
    foreach ($sheets as $sheet) {
        $idx = (int) $sheet['index'];
        $name = mydump_xlsx_escape_attr((string) $sheet['name']);
        $parts[] = '<sheet name="' . $name . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $parts) . '</sheets>'
        . '</workbook>';
}

function mydump_xlsx_workbook_rels_xml(array $sheets): string
{
    $parts = [];
    foreach ($sheets as $sheet) {
        $idx = (int) $sheet['index'];
        $parts[] = '<Relationship Id="rId' . $idx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $parts)
        . '</Relationships>';
}

function mydump_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><sz val="12"/><color rgb="FF000000"/><name val="Monaco"/></font>'
        . '</fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';
}

function mydump_xlsx_sheet_xml(array $rows): string
{
    $xmlRows = [];
    $rowNumber = 1;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $row = [(string) $row];
        }

        $cellsXml = [];
        $col = 1;
        foreach ($row as $value) {
            $cellRef = mydump_xlsx_column_ref($col) . $rowNumber;
            $text = mydump_xlsx_escape_text((string) ($value ?? ''));
            $cellsXml[] = '<c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . $text . '</t></is></c>';
            $col++;
        }

        $xmlRows[] = '<row r="' . $rowNumber . '">' . implode('', $cellsXml) . '</row>';
        $rowNumber++;
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '</worksheet>';
}

function mydump_xlsx_read_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $dom = new DOMDocument();
    if (@$dom->loadXML($xml) === false) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    foreach ($xp->query('//s:si') as $si) {
        $text = '';
        foreach ($xp->query('.//s:t', $si) as $t) {
            $text .= $t->textContent;
        }
        $strings[] = $text;
    }
    return $strings;
}

function mydump_xlsx_read_workbook_rels(string $relsXml): array
{
    $dom = new DOMDocument();
    if (@$dom->loadXML($relsXml) === false) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $map = [];
    foreach ($xp->query('//r:Relationship') as $rel) {
        $id = (string) $rel->attributes->getNamedItem('Id')?->nodeValue;
        $target = (string) $rel->attributes->getNamedItem('Target')?->nodeValue;
        if ($id !== '' && $target !== '') {
            $map[$id] = $target;
        }
    }
    return $map;
}

function mydump_xlsx_read_workbook_sheets(string $workbookXml): array
{
    $dom = new DOMDocument();
    if (@$dom->loadXML($workbookXml) === false) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $xp->registerNamespace('s', $mainNs);
    $xp->registerNamespace('r', $relNs);

    $sheetDefs = [];
    foreach ($xp->query('//s:sheet') as $sheet) {
        $name = (string) $sheet->attributes->getNamedItem('name')?->nodeValue;
        $rid = (string) $sheet->getAttributeNS($relNs, 'id');
        if ($rid === '') {
            $rid = (string) $sheet->getAttribute('r:id');
        }
        if ($name !== '' && $rid !== '') {
            $sheetDefs[] = ['name' => $name, 'rid' => $rid];
        }
    }

    return $sheetDefs;
}

function mydump_xlsx_normalize_workbook_target(string $target): string
{
    $target = ltrim($target, '/');
    if (str_starts_with($target, 'xl/')) {
        return $target;
    }
    if (str_starts_with($target, 'worksheets/')) {
        return 'xl/' . $target;
    }
    if (str_starts_with($target, '../')) {
        $target = preg_replace('#^\.\./#', '', $target);
        return 'xl/' . $target;
    }
    return 'xl/' . $target;
}

function mydump_xlsx_read_sheet_rows(string $sheetXml, array $sharedStrings): array
{
    $dom = new DOMDocument();
    if (@$dom->loadXML($sheetXml) === false) {
        return [];
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = [];
    foreach ($xp->query('//s:sheetData/s:row') as $rowNode) {
        $rowValues = [];
        $maxCol = 0;

        foreach ($xp->query('./s:c', $rowNode) as $cellNode) {
            $ref = (string) $cellNode->attributes->getNamedItem('r')?->nodeValue;
            $colIndex = mydump_xlsx_col_index_from_ref($ref);
            if ($colIndex < 1) {
                $colIndex = count($rowValues) + 1;
            }
            $maxCol = max($maxCol, $colIndex);

            $type = strtolower((string) $cellNode->attributes->getNamedItem('t')?->nodeValue);
            $value = '';
            if ($type === 's') {
                $idx = (int) mydump_xlsx_first_xpath_text($xp, $cellNode, './s:v');
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlinestr') {
                foreach ($xp->query('./s:is//s:t', $cellNode) as $t) {
                    $value .= $t->textContent;
                }
            } elseif ($type === 'str') {
                $value = mydump_xlsx_first_xpath_text($xp, $cellNode, './s:v');
            } else {
                $value = mydump_xlsx_first_xpath_text($xp, $cellNode, './s:v');
            }

            $rowValues[$colIndex] = $value;
        }

        if ($maxCol === 0) {
            $rows[] = [];
            continue;
        }

        $normalized = [];
        for ($i = 1; $i <= $maxCol; $i++) {
            $normalized[] = $rowValues[$i] ?? '';
        }
        $rows[] = $normalized;
    }

    return $rows;
}

function mydump_xlsx_first_xpath_text(DOMXPath $xp, DOMNode $context, string $expr): string
{
    $nodes = $xp->query($expr, $context);
    if ($nodes === false || $nodes->length === 0) {
        return '';
    }
    return (string) $nodes->item(0)?->textContent;
}

function mydump_xlsx_col_index_from_ref(string $ref): int
{
    if (!preg_match('/^([A-Z]+)\d+$/i', $ref, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index;
}

function mydump_xlsx_column_ref(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function mydump_xlsx_unique_sheet_names(array $names): array
{
    $result = [];
    $used = [];

    foreach ($names as $original) {
        $candidate = preg_replace('/[\[\]\*\/\\\\\?\:]/', '_', (string) $original);
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            $candidate = 'Sheet';
        }
        $candidate = mydump_xlsx_substr($candidate, 0, 31);

        $base = $candidate;
        $i = 1;
        while (isset($used[strtolower($candidate)])) {
            $suffix = '_' . $i;
            $maxBase = 31 - strlen($suffix);
            $candidate = mydump_xlsx_substr($base, 0, max(1, $maxBase)) . $suffix;
            $i++;
        }

        $used[strtolower($candidate)] = true;
        $result[(string) $original] = $candidate;
    }

    return $result;
}

function mydump_xlsx_escape_text(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mydump_xlsx_escape_attr(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mydump_xlsx_substr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        if ($length === null) {
            return mb_substr($value, $start);
        }
        return mb_substr($value, $start, $length);
    }

    if ($length === null) {
        return substr($value, $start);
    }
    return substr($value, $start, $length);
}
