<?php
declare(strict_types=1);

/**
 * Minimal, dependency-free XLSX generator for professional shop reports.
 *
 * Builds a valid OOXML spreadsheet package from the structured report array
 * produced by ReportService::generateReport(). Because the zip extension is
 * not available in this environment, the ZIP container is assembled by hand
 * using zlib's gzdeflate() (raw DEFLATE) which every spreadsheet reader
 * (Excel, LibreOffice, Google Sheets) accepts.
 */
class ExcelReportService
{
    /** @var string[] part name => XML content */
    private array $parts = [];

    private array $meta = [];
    private array $summary = [];
    private array $sections = [];

    /** sheet names already used (Excel forbids duplicates) */
    private array $usedNames = [];

    public function render(array $report): string
    {
        $this->parts = [];
        $this->usedNames = [];
        $this->meta = $report['meta'] ?? [];
        $this->summary = $report['summary'] ?? [];
        $this->sections = $report['sections'] ?? [];

        if (!function_exists('gzdeflate')) {
            throw new RuntimeException('The zlib extension is required to generate XLSX reports.');
        }

        $sheetCount = 1;
        $rels = [];
        $sheetsXml = [];

        // 1) Summary sheet
        $summarySheetName = $this->uniqueSheetName('Summary');
        $sheetsXml[] = $this->buildSummarySheet();
        $rels[] = ['id' => 'rId1', 'sheet' => $summarySheetName, 'file' => 'worksheets/sheet1.xml'];

        // 2) One sheet per report section
        foreach ($this->sections as $i => $section) {
            $sheetCount++;
            $index = $sheetCount;
            $name = $this->uniqueSheetName((string)($section['title'] ?? 'Section ' . $i));
            $sheetsXml[] = $this->buildSectionSheet($section);
            $rels[] = ['id' => 'rId' . $index, 'sheet' => $name, 'file' => 'worksheets/sheet' . $index . '.xml'];
        }

        $this->addPart('[Content_Types].xml', $this->contentTypesXml($sheetCount));
        $this->addPart('_rels/.rels', $this->rootRelsXml());
        $this->addPart('xl/workbook.xml', $this->workbookXml($rels));
        $this->addPart('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($rels));
        $this->addPart('xl/styles.xml', $this->stylesXml());
        foreach ($sheetsXml as $i => $xml) {
            $this->addPart('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }
        $this->addPart('docProps/core.xml', $this->corePropsXml());
        $this->addPart('docProps/app.xml', $this->appPropsXml($rels));

        return $this->buildZip();
    }

    // ── Workbook parts ─────────────────────────────────────────────────────

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i .
                '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            $overrides .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
            '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
            '</Relationships>';
    }

    private function workbookXml(array $rels): string
    {
        $sheets = '';
        $sheetIndex = 1;
        foreach ($rels as $r) {
            $sheets .= '<sheet name="' . $this->xml($r['sheet']) . '" sheetId="' . $sheetIndex .
                '" r:id="' . $r['id'] . '"/>';
            $sheetIndex++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' . $sheets . '</sheets>' .
            '</workbook>';
    }

    private function workbookRelsXml(array $rels): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId' . (count($rels) + 1) .
            '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($rels as $r) {
            $out .= '<Relationship Id="' . $r['id'] .
                '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' .
                $r['file'] . '"/>';
        }
        return $out . '</Relationships>';
    }

    private function stylesXml(): string
    {
        $currency = htmlspecialchars($this->currencyLabel(), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $moneyFmt = '&quot;' . $currency . '&quot; #,##0';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<numFmts count="3">' .
            '<numFmt numFmtId="164" formatCode="#,##0"/>' .
            '<numFmt numFmtId="165" formatCode="#,##0.00"/>' .
            '<numFmt numFmtId="166" formatCode="' . $moneyFmt . '"/>' .
            '</numFmts>' .
            '<fonts count="4">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="14"/><color rgb="FFB8860B"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="12"/><color rgb="FF996515"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FFB8860B"/></patternFill></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF5EFE2"/></patternFill></fill>' .
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/><diagonal/></border>' .
            '<border><left style="thin"><color rgb="FFDDD6C8"/></left><right style="thin"><color rgb="FFDDD6C8"/></right><top style="thin"><color rgb="FFDDD6C8"/></top><bottom style="thin"><color rgb="FFDDD6C8"/></bottom><diagonal/></border>' .
            '</borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="12">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>' .
            '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="165" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="166" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>' .
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';
    }

    private function corePropsXml(): string
    {
        $store = (string)($this->meta['store_name'] ?? 'Mpeli Outfit Store');
        $title = (string)($this->meta['title'] ?? 'Report');
        $genAt = (string)($this->meta['generated_at'] ?? date('Y-m-d H:i:s'));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" ' .
            'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" ' .
            'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:title>' . $this->xml($store . ' - ' . $title) . '</dc:title>' .
            '<dc:creator>' . $this->xml((string)($this->meta['generated_by'] ?? '')) . '</dc:creator>' .
            '<cp:lastModifiedBy>' . $this->xml((string)($this->meta['generated_by'] ?? '')) . '</cp:lastModifiedBy>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c', strtotime($genAt)) . '</dcterms:created>' .
            '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:modified>' .
            '</cp:coreProperties>';
    }

    private function appPropsXml(array $rels): string
    {
        $titles = '';
        foreach ($rels as $r) {
            $titles .= '<vt:lpstr>' . $this->xml($r['sheet']) . '</vt:lpstr>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" ' .
            'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
            '<Application>Mpeli Outfit Store</Application>' .
            '<Title>' . $this->xml((string)($this->meta['title'] ?? 'Report')) . '</Title>' .
            '<Company>' . $this->xml((string)($this->meta['store_name'] ?? '')) . '</Company>' .
            '<HeadingPairs><vt:vector size="2" baseType="variant">' .
            '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>' .
            '<vt:variant><vt:i4>' . count($rels) . '</vt:i4></vt:variant>' .
            '</vt:vector></HeadingPairs>' .
            '<TitlesOfParts><vt:vector size="' . count($rels) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>' .
            '</Properties>';
    }

    // ── Sheets ─────────────────────────────────────────────────────────────

    private function buildSummarySheet(): string
    {
        $rows = [];

        $rows[] = [['s', (string)($this->meta['store_name'] ?? 'Mpeli Outfit Store'), 5]];
        $rows[] = [['s', strtoupper((string)($this->meta['title'] ?? 'Report')), 5]];
        $rows[] = [['s', '', 0]];
        $rows[] = [
            ['s', 'Report Period:', 0],
            ['s', $this->periodLabel(), 0],
        ];
        $rows[] = [
            ['s', 'Generated By:', 0],
            ['s', (string)($this->meta['generated_by'] ?? ''), 0],
        ];
        $rows[] = [
            ['s', 'User Role:', 0],
            ['s', (($this->meta['role'] ?? '') === 'OWNER') ? 'Owner / Admin' : 'Seller', 0],
        ];
        $rows[] = [
            ['s', 'Generation Date / Time:', 0],
            ['s', (string)($this->meta['generated_at'] ?? ''), 0],
        ];
        $rows[] = [['s', '', 0]];
        $rows[] = [['s', 'Summary', 5]];

        $labels = [
            'transactions' => ['Total Transactions', false],
            'items_sold' => ['Total Items Sold', false],
            'revenue' => ['Revenue / Sales', true],
            'discounts' => ['Discounts Given', true],
            'buying_cost' => ['Buying Cost', true],
            'gross_profit' => ['Gross Profit', true],
            'expenses' => ['Expenses', true],
            'net_profit' => ['Net Profit', true],
            'avg_sale' => ['Average Sale Value', true],
        ];
        $idx = 0;
        foreach ($labels as $key => [$label, $money]) {
            if (!array_key_exists($key, $this->summary)) {
                continue;
            }
            $value = $this->summary[$key] ?? 0;
            $zebra = $idx % 2 === 1;
            $style = $zebra ? 6 : 11;
            $numStyle = $zebra ? 7 : 2;
            if ($money) {
                $numStyle = $zebra ? 9 : 4;
            }
            $rows[] = [
                ['s', $label, $style],
                ['n', (float)$value, $numStyle],
            ];
            $idx++;
        }

        $wA = 14;
        $wB = 18;
        foreach ($rows as $cells) {
            if (isset($cells[0]) && $cells[0][0] === 's') {
                $wA = max($wA, strlen((string)$cells[0][1]) + 1);
            }
            if (isset($cells[1])) {
                if ($cells[1][0] === 's') {
                    $wB = max($wB, strlen((string)$cells[1][1]) + 1);
                } else {
                    $style = (int)($cells[1][2] ?? 0);
                    $money = in_array($style, [4, 9], true);
                    $disp = $money
                        ? 'TSH ' . number_format((float)$cells[1][1], 0, '.', ',')
                        : (string)$cells[1][1];
                    $wB = max($wB, strlen($disp) + 1);
                }
            }
        }
        $widths = [min(40, $wA), min(60, $wB)];
        return $this->sheetXml($rows, $widths, null);
    }

    private function buildSectionSheet(array $section): string
    {
        $rows = [];
        $title = (string)($section['title'] ?? 'Section');
        $columns = $section['columns'] ?? [];
        $data = $section['rows'] ?? [];

        $rows[] = [['s', strtoupper($title), 10]];
        $rows[] = [['s', 'Period: ' . $this->periodLabel(), 0]];

        // header
        $header = [];
        foreach ($columns as $col) {
            $header[] = ['s', (string)($col['label'] ?? ''), 1];
        }
        if (count($header) === 0) {
            $header[] = ['s', 'Value', 1];
        }
        $rows[] = $header;

        $headerRow = 3;
        $autoFilterRef = null;
        if (count($data) === 0) {
            $rows[] = [['s', 'No records for this period.', 0]];
        } else {
            $autoFilterRef = 'A' . $headerRow . ':' . $this->colLetter(count($columns) - 1) . ($headerRow + count($data));
        }

        foreach ($data as $di => $row) {
            $zebra = $di % 2 === 1;
            $cells = [];
            foreach ($columns as $i => $col) {
                $value = $row[$i] ?? null;
                if ($value === null || $value === '') {
                    $cells[] = ['s', '', $zebra ? 6 : 11];
                } elseif (is_bool($value)) {
                    $cells[] = ['s', $value ? 'Yes' : 'No', $zebra ? 6 : 11];
                } elseif ($this->isNumberCell($value, $col)) {
                    $base = ($col['money'] ?? false) ? 4 : (is_float($value) ? 3 : 2);
                    $cells[] = ['n', (float)$value, $zebra ? $base + 5 : $base];
                } else {
                    $cells[] = ['s', (string)$value, $zebra ? 6 : 11];
                }
            }
            $rows[] = $cells;
        }

        // subsections (e.g. Expenses by Category)
        $subsections = $section['subsections'] ?? [];
        foreach ($subsections as $sub) {
            $rows[] = [['s', '', 0]];
            $rows[] = [['s', (string)($sub['title'] ?? ''), 10]];
            $subCols = $sub['columns'] ?? [];
            $subHeader = [];
            foreach ($subCols as $col) {
                $subHeader[] = ['s', (string)($col['label'] ?? ''), 1];
            }
            $rows[] = $subHeader;
            foreach (($sub['rows'] ?? []) as $di => $subRow) {
                $zebra = $di % 2 === 1;
                $cells = [];
                foreach ($subCols as $i => $col) {
                    $value = $subRow[$i] ?? null;
                    if ($value === null || $value === '') {
                        $cells[] = ['s', '', $zebra ? 6 : 11];
                    } elseif ($this->isNumberCell($value, $col)) {
                        $base = ($col['money'] ?? false) ? 4 : (is_float($value) ? 3 : 2);
                        $cells[] = ['n', (float)$value, $zebra ? $base + 5 : $base];
                    } else {
                        $cells[] = ['s', (string)$value, $zebra ? 6 : 11];
                    }
                }
                $rows[] = $cells;
            }
        }

        $widths = $this->sectionWidths($section);
        $colCount = count($columns);
        $minUnits = 0.0;
        foreach ($columns as $col) {
            $minUnits += ceil(((float)($col['min'] ?? self::defaultMinPt($col))) / 6.2);
        }
        $landscape = $colCount >= 6 || $minUnits > 92.0;
        return $this->sheetXml($rows, $widths, 4, $autoFilterRef, $landscape);
    }

    private function sheetXml(array $rows, array $widths, ?int $freezeRow, ?string $autoFilterRef = null, bool $landscape = false): string
    {
        $cols = '';
        if (count($widths) > 0) {
            foreach ($widths as $i => $w) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols = '<cols>' . $cols . '</cols>';
        }

        $view = '<sheetView workbookViewId="0" showGridLines="0"/>';
        if ($freezeRow !== null) {
            $view = '<sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) .
                '" activePane="bottomLeft" state="frozen"/></sheetView>';
        }

        $mergeRanges = [];
        $totalW = array_sum($widths);
        $sheetData = '';
        foreach ($rows as $r => $cells) {
            $rowXml = '';
            $maxLines = 1;
            $titleRow = false;
            $effWidths = $widths;
            if (count($cells) === 1 && $cells[0][0] === 's' && (string)$cells[0][1] !== '') {
                $mergeRanges[] = 'A' . ($r + 1) . ':' . $this->colLetter(max(1, count($widths) - 1)) . ($r + 1);
                $effWidths = $totalW > 0 ? [$totalW] : [9];
            }
            for ($c = 0; $c < count($cells); $c++) {
                $cell = $cells[$c];
                if ($cell[1] === '' && $cell[0] === 's' && $cell[2] === 0) {
                    continue;
                }
                $ref = $this->colLetter($c) . ($r + 1);
                if ($cell[0] === 'n') {
                    $rowXml .= '<c r="' . $ref . '" s="' . $cell[2] . '"><v>' . $this->num($cell[1]) . '</v></c>';
                } else {
                    $style = $cell[2] > 0 ? ' s="' . $cell[2] . '"' : '';
                    $rowXml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' .
                        $this->xml((string)$cell[1]) . '</t></is></c>';
                    $w = $effWidths[$c] ?? 9;
                    $maxLines = max($maxLines, $this->wrappedLines((string)$cell[1], (float)$w));
                }
                if ($cell[2] === 5 || $cell[2] === 10) {
                    $titleRow = true;
                }
            }
            if ($rowXml !== '') {
                $ht = max($titleRow ? 24 : 16, $maxLines * 15);
                $heightAttr = $ht > 15 ? ' ht="' . $ht . '" customHeight="1"' : '';
                $sheetData .= '<row r="' . ($r + 1) . '"' . $heightAttr . '>' . $rowXml . '</row>';
            }
        }

        $autoFilter = $autoFilterRef !== null ? '<autoFilter ref="' . $autoFilterRef . '"/>' : '';
        $mergeXml = count($mergeRanges) > 0
            ? '<mergeCells count="' . count($mergeRanges) . '">' .
              implode('', array_map(fn (string $rng): string => '<mergeCell ref="' . $rng . '"/>', $mergeRanges)) .
              '</mergeCells>'
            : '';

        $orientation = $landscape ? 'landscape' : 'portrait';
        $pageSetup = '<pageSetup paperSize="9" orientation="' . $orientation .
            '" fitToWidth="1" fitToHeight="0" horizontalDpi="200" verticalDpi="200"/>';
        $printOpts = '<printOptions horizontalCentered="1"/>';
        $margins = '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheetViews>' . $view . '</sheetViews>' .
            '<sheetFormatPr defaultRowHeight="15"/>' .
            $cols .
            '<sheetData>' . $sheetData . '</sheetData>' .
            $autoFilter .
            $mergeXml .
            $printOpts .
            $pageSetup .
            $margins .
            '</worksheet>';
    }

    private function sectionWidths(array $section): array
    {
        $columns = $section['columns'] ?? [];
        $data = $section['rows'] ?? [];
        $subsections = $section['subsections'] ?? [];

        $units = [];
        $minUnits = [];
        $flex = [];
        foreach ($columns as $i => $col) {
            $isMoney = (bool)($col['money'] ?? false);
            $units[$i] = $this->cellCharLen((string)($col['label'] ?? ''), $isMoney) + 2;
            $minUnits[$i] = max(8.0, ceil(((float)($col['min'] ?? self::defaultMinPt($col))) / 6.2));
            $flex[$i] = (float)($col['flex'] ?? self::defaultFlexPt($col));
        }

        foreach ($data as $row) {
            foreach ($row as $i => $value) {
                if (!isset($columns[$i])) {
                    continue;
                }
                $units[$i] = max($units[$i], $this->cellCharLen($value, (bool)($columns[$i]['money'] ?? false)) + 2);
            }
        }
        // Subsection rows are placed in the same leading columns of the sheet,
        // so their content must also fit inside those column widths.
        foreach ($subsections as $sub) {
            foreach (($sub['columns'] ?? []) as $i => $col) {
                $units[$i] = max($units[$i] ?? 0, $this->cellCharLen((string)($col['label'] ?? ''), (bool)($col['money'] ?? false)) + 2);
                $minUnits[$i] = max($minUnits[$i] ?? 8, ceil(((float)($col['min'] ?? self::defaultMinPt($col))) / 6.2));
                $flex[$i] = (float)($col['flex'] ?? self::defaultFlexPt($col));
            }
            foreach (($sub['rows'] ?? []) as $row) {
                foreach (($sub['columns'] ?? []) as $i => $col) {
                    $units[$i] = max($units[$i] ?? 0, $this->cellCharLen($row[$i] ?? '', (bool)($col['money'] ?? false)) + 2);
                }
            }
        }

        // Aim for a sensible printable target and give the spare width to the
        // flexible columns instead of inflating narrow ones.
        $target = count($columns) >= 6 ? 125.0 : 92.0;
        $totalUnits = array_sum($units);
        $widths = [];
        if ($totalUnits < $target) {
            $leftover = $target - $totalUnits;
            $sumFlex = max(1.0, array_sum($flex));
            foreach ($columns as $i => $col) {
                $widths[$i] = min(60.0, max($minUnits[$i], $units[$i] + $leftover * $flex[$i] / $sumFlex));
            }
        } else {
            foreach ($columns as $i => $col) {
                $widths[$i] = min(60.0, max($minUnits[$i], $units[$i]));
            }
        }
        return $widths;
    }

    private function cellCharLen($value, bool $isMoney): int
    {
        if ($isMoney && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return strlen($this->currencyLabel() . ' ' . $this->fmtNum((float)$value));
        }
        return strlen((string)$value);
    }

    private function currencyLabel(): string
    {
        return (string)($this->meta['currency'] ?? 'TSH');
    }

    public static function defaultMinPt(array $col): float
    {
        if (!empty($col['money'])) {
            return 70.0;
        }
        $label = strtolower((string)($col['label'] ?? ''));
        if (self::isNarrow($label)) {
            return 38.0;
        }
        if (str_contains($label, 'date') || str_contains($label, 'time')) {
            return 60.0;
        }
        return 56.0;
    }

    public static function defaultFlexPt(array $col): float
    {
        if (!empty($col['money'])) {
            return 1.6;
        }
        $label = strtolower((string)($col['label'] ?? ''));
        if (self::isNarrow($label)) {
            return 0.7;
        }
        if (str_contains($label, 'date') || str_contains($label, 'time')) {
            return 1.0;
        }
        if (str_contains($label, 'product')
            || str_contains($label, 'description')
            || str_contains($label, 'note')
            || str_contains($label, 'customer')
            || str_contains($label, 'category')
            || str_contains($label, 'seller')) {
            return 2.4;
        }
        return 1.4;
    }

    private static function isNarrow(string $label): bool
    {
        return str_contains($label, 'qty')
            || str_contains($label, 'items')
            || str_contains($label, 'stock')
            || str_contains($label, 'reorder')
            || str_contains($label, 'transactions');
    }

    private function wrappedLines(string $value, float $width): int
    {
        if ($value === '') {
            return 1;
        }
        $charsPerLine = max(4.0, $width - 0.5);
        return (int)max(1, ceil(strlen($value) / $charsPerLine));
    }

    private function isNumberCell($value, array $col): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return false;
        }
        // Receipt numbers, phone numbers and padded codes must stay as text.
        if ($this->looksLikeText((string)($col['label'] ?? ''))) {
            return false;
        }
        if ($this->hasLeadingZero($value)) {
            return false;
        }
        return true;
    }

    private function hasLeadingZero(string $s): bool
    {
        return strlen($s) > 1 && $s[0] === '0' && $s[1] !== '.';
    }

    // ── ZIP assembly ───────────────────────────────────────────────────────

    private function addPart(string $name, string $content): void
    {
        $this->parts[$name] = $content;
    }

    private function buildZip(): string
    {
        $data = '';
        $central = [];
        $cdOffset = 0;

        foreach ($this->parts as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $deflated = (string)gzdeflate($content, 6);
            $csize = strlen($deflated);
            $offset = strlen($data);

            $data .= pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0x0800,
                8,
                0,
                0,
                $crc,
                $csize,
                $size,
                strlen($name),
                0
            );
            $data .= $name;
            $data .= $deflated;

            $central[] = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0x0800,
                8,
                0,
                0,
                $crc,
                $csize,
                $size,
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;
        }

        $cdStart = strlen($data);
        $cd = implode('', $central);

        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->parts),
            count($this->parts),
            strlen($cd),
            $cdStart,
            0
        );

        return $data . $cd . $eocd;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function uniqueSheetName(string $name): string
    {
        $name = trim(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $name) ?? '');
        $name = $name === '' ? 'Sheet' : $name;
        $name = mb_substr($name, 0, 31);
        $base = $name;
        $n = 2;
        while (isset($this->usedNames[$name])) {
            $suffix = ' ' . $n++;
            $name = mb_substr($base, 0, 31 - strlen($suffix)) . $suffix;
        }
        $this->usedNames[$name] = true;
        return $name;
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        }
        return $letter;
    }

    private function periodLabel(): string
    {
        $start = (string)($this->meta['period_start'] ?? '');
        $end = (string)($this->meta['period_end'] ?? '');
        if ($start === '' || $end === '') {
            return 'All Time';
        }
        $format = fn (string $d): string => date('d F Y', strtotime($d));
        return $format($start) . ' - ' . $format($end);
    }

    private function fmtNum($value): string
    {
        return number_format((float)$value, 0, '.', ',');
    }

    private function num($value): string
    {
        $f = (float)$value;
        if (abs($f) < 1e15 && $f === floor($f)) {
            return (string)(int)$f;
        }
        $out = rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.');
        return $out === '' || $out === '-' ? '0' : $out;
    }

    private function looksLikeText(string $label): bool
    {
        return str_contains(strtolower($label), 'receipt') || str_contains(strtolower($label), 'phone');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
