<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * Minimal dependency-free .xlsx writer for admin reports.
 *
 * Each sheet gets a bold, frozen header row with an auto-filter, so it opens in Excel,
 * Google Sheets and LibreOffice ready to sort and filter. Ints and floats are written as
 * numbers, everything else as text. Not a general spreadsheet library: no formulas,
 * merged cells or per-cell styling beyond the header.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, headers: list<string>, rows: list<list<mixed>>, widths: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     * @param  list<int>  $widths  Optional column widths (characters); missing ones are estimated
     */
    public function addSheet(string $name, array $headers, iterable $rows, array $widths = []): self
    {
        $materialised = [];
        foreach ($rows as $row) {
            $materialised[] = array_values($row);
        }

        $this->sheets[] = [
            'name' => $this->sheetName($name),
            'headers' => array_values($headers),
            'rows' => $materialised,
            'widths' => $widths,
        ];

        return $this;
    }

    /**
     * @return string Raw .xlsx bytes
     */
    public function toString(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An xlsx workbook needs at least one sheet.');
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the xlsx workbook.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the temporary xlsx archive.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/styles.xml', $this->styles());

            foreach ($this->sheets as $index => $sheet) {
                $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet));
            }

            $zip->close();

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Could not read the generated xlsx workbook.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array{name: string, headers: list<string>, rows: list<list<mixed>>, widths: list<int>}  $sheet
     */
    private function worksheet(array $sheet): string
    {
        $columnCount = max(1, count($sheet['headers']));
        $lastColumn = $this->columnLetter($columnCount - 1);
        $lastRow = count($sheet['rows']) + 1;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>';

        foreach ($this->columnWidths($sheet) as $index => $width) {
            $column = $index + 1;
            $xml .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $column, $column, $width);
        }

        $xml .= '</cols><sheetData>';
        $xml .= $this->row(1, $sheet['headers'], header: true);

        foreach ($sheet['rows'] as $offset => $row) {
            $xml .= $this->row($offset + 2, $row, header: false);
        }

        $xml .= '</sheetData>';
        $xml .= sprintf('<autoFilter ref="A1:%s%d"/>', $lastColumn, $lastRow);
        $xml .= '</worksheet>';

        return $xml;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function row(int $rowNumber, array $cells, bool $header): string
    {
        $xml = '<row r="'.$rowNumber.'">';

        foreach ($cells as $index => $value) {
            $ref = $this->columnLetter($index).$rowNumber;
            $style = $header ? ' s="1"' : '';

            if ($value === null || $value === '') {
                $xml .= '<c r="'.$ref.'"'.$style.'/>';

                continue;
            }

            if (! $header && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $style = is_float($value) ? ' s="2"' : '';
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';

                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }

            $xml .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    /**
     * @param  array{name: string, headers: list<string>, rows: list<list<mixed>>, widths: list<int>}  $sheet
     * @return list<int|float>
     */
    private function columnWidths(array $sheet): array
    {
        $widths = [];

        foreach ($sheet['headers'] as $index => $header) {
            if (isset($sheet['widths'][$index])) {
                $widths[] = $sheet['widths'][$index];

                continue;
            }

            $longest = mb_strlen($header) + 2;
            foreach (array_slice($sheet['rows'], 0, 200) as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$index] ?? '')));
            }

            $widths[] = min(60, max(10, $longest + 2));
        }

        return $widths;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function escape(string $value): string
    {
        // Drop control characters that are illegal in XML 1.0, then escape.
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheetName(string $name): string
    {
        $clean = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));

        return mb_substr($clean !== '' ? $clean : 'Sheet'.(count($this->sheets) + 1), 0, 31);
    }

    private function contentTypes(): string
    {
        $overrides = '';
        foreach (array_keys($this->sheets) as $index) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $sheets = '';
        $definedNames = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape($sheet['name']),
                $index + 1,
                $index + 1,
            );

            $lastColumn = $this->columnLetter(max(1, count($sheet['headers'])) - 1);
            $definedNames .= sprintf(
                '<definedName name="_xlnm._FilterDatabase" localSheetId="%d" hidden="1">\'%s\'!$A$1:$%s$%d</definedName>',
                $index,
                $this->escape(str_replace("'", "''", $sheet['name'])),
                $lastColumn,
                count($sheet['rows']) + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'
            .'<definedNames>'.$definedNames.'</definedNames>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        $relationships = '';
        foreach (array_keys($this->sheets) as $index) {
            $relationships .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1,
                $index + 1,
            );
        }

        $stylesId = count($this->sheets) + 1;
        $relationships .= '<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    /**
     * Style 0 = default, 1 = bold header on light grey, 2 = number with thousands separator and 2 decimals.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
