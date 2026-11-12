<?php

declare(strict_types=1);

namespace Obx\Node\Import;

/**
 * Excel workbook (.xlsx) to the rows of products.csv or assets.csv, exactly as
 * docs/import-format.md §5 says (the spec's tools/xlsx.mjs is the reference). Only stored
 * values are read: no formula is calculated, no macro run, nothing fetched. XML is read with
 * XMLReader without network access; a DOCTYPE in any part is refused, so no entity other than
 * the five XML ones is ever expanded.
 */
final class Xlsx
{
    public const MAX_ROW = 100001;
    public const MAX_COL = 1000;
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_STRICT = 'http://purl.oclc.org/ooxml/spreadsheetml/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL_OFFICE_DOCUMENT = self::NS_REL . '/officeDocument';
    private const REL_WORKSHEET = self::NS_REL . '/worksheet';
    private const REL_STYLES = self::NS_REL . '/styles';
    private const REL_SHARED_STRINGS = self::NS_REL . '/sharedStrings';
    private const MAIN_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template.main+xml',
    ];
    private const ROLES = [
        'products' => ['sheet' => 'products', 'title' => 'Products', 'keys' => ['style_number', 'style'], 'file' => 'products.xlsx'],
        'assets' => ['sheet' => 'assets', 'title' => 'Assets', 'keys' => ['file'], 'file' => 'assets.xlsx'],
    ];
    private const BUILTIN_DATE = [14, 15, 16, 17, 27, 28, 29, 30, 31, 34, 35, 36, 50, 51, 52, 53, 54, 57, 58];
    private const BUILTIN_DATETIME = [18, 19, 20, 21, 22, 32, 33, 45, 46, 47, 55, 56];
    /** Out-of-limit cells remembered for the ordering of §5.6 step 9 (more are certainly too large). */
    private const MAX_OUTSIDE = 100000;

    /** Whether this PHP can read workbooks (zlib and XMLReader). */
    public static function available(): bool
    {
        return function_exists('inflate_init') && class_exists(\XMLReader::class);
    }

    /** Whether an upload is a workbook (§5.1): the name decides, or the content type of a part without a name. */
    public static function isWorkbook(string $name, ?string $type = null): bool
    {
        if ($name !== '') {
            return (bool) preg_match('/\.xlsx$/i', $name);
        }
        return strtolower(trim(explode(';', (string) $type)[0])) === self::CONTENT_TYPE;
    }

    /** Other spreadsheet files, answered with unsupported_media_type (§5.1). */
    public static function isOtherSpreadsheet(string $name, ?string $type = null): bool
    {
        if ($name !== '') {
            return (bool) preg_match('/\.(xlsm|xls|xlsb|xltx|xltm|ods|numbers)$/i', $name);
        }
        return in_array(strtolower(trim(explode(';', (string) $type)[0])), [
            'application/vnd.ms-excel',
            'application/vnd.ms-excel.sheet.macroenabled.12',
            'application/vnd.ms-excel.sheet.binary.macroenabled.12',
            'application/vnd.oasis.opendocument.spreadsheet',
        ], true);
    }

    /**
     * Converts a workbook into rows. Throws WorkbookError for a workbook that cannot be imported.
     *
     * @param 'products'|'assets' $role
     * @return array{sheet: string, header: list<string>, records: array<int, list<string>>, last: int, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     *   header: row 1 (W cells); records: Excel row number => W cells for the non-empty data rows
     *   (2 to last); errors / warnings: ImportRowErrors of the conversion
     */
    public static function convert(string $path, string $role): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            return self::doConvert($path, $role);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @return array{sheet: string, header: list<string>, records: array<int, list<string>>, last: int, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} */
    private static function doConvert(string $path, string $role): array
    {
        $r = self::ROLES[$role];
        if (!self::available()) {
            throw new WorkbookError('workbook_invalid', 'this server cannot read Excel files (the PHP zlib or xmlreader extension is missing); save the sheet as CSV UTF-8');
        }
        $pkg = XlsxPackage::open($path);
        $ctText = $pkg->read('[Content_Types].xml');
        $ct = $ctText === null ? null : self::contentTypes($ctText);
        unset($ctText);
        // Step 5: macros, ActiveX, external links, data connections; never inflated.
        foreach ($pkg->names as $name) {
            $n = strtolower($name);
            $parts = explode('/', $n);
            if (end($parts) === 'vbaproject.bin') {
                throw new WorkbookError('workbook_unsafe', 'the workbook contains macros (' . $name . '); save it as an Excel Workbook (.xlsx) without them');
            }
            foreach (['xl/macrosheets/' => 'macro sheets', 'xl/activex/' => 'ActiveX controls', 'xl/externallinks/' => 'links to other files'] as $pre => $what) {
                if (str_starts_with($n, $pre)) {
                    throw new WorkbookError('workbook_unsafe', 'the workbook contains ' . $what . ' (' . $name . '); save it as an Excel Workbook (.xlsx) without them');
                }
            }
            if ($n === 'xl/connections.xml') {
                throw new WorkbookError('workbook_unsafe', 'the workbook contains data connections (' . $name . '); save it as an Excel Workbook (.xlsx) without them');
            }
        }
        foreach ($ct['all'] ?? [] as $t) {
            if (preg_match('/macroenabled|vbaproject/i', $t)) {
                throw new WorkbookError('workbook_unsafe', 'the workbook is macro-enabled (' . $t . '); save it as an Excel Workbook (.xlsx)');
            }
        }
        // Step 6: structure.
        if ($ct === null) {
            throw new WorkbookError('workbook_invalid', '[Content_Types].xml is missing');
        }
        $rootRels = $pkg->read('_rels/.rels');
        if ($rootRels === null) {
            throw new WorkbookError('workbook_invalid', '_rels/.rels is missing');
        }
        $main = null;
        foreach (self::rels($rootRels, '_rels/.rels') as $rel) {
            if ($rel['type'] === self::REL_OFFICE_DOCUMENT && !$rel['external']) {
                $main = $rel;
                break;
            }
        }
        if ($main === null) {
            throw new WorkbookError('workbook_invalid', 'the package has no main document');
        }
        $wbPart = self::resolve('/', $main['target']);
        $type = self::typeOf($ct, $wbPart);
        if (!in_array($type, self::MAIN_TYPES, true)) {
            throw new WorkbookError('workbook_invalid', 'the main document is ' . ($type ?? 'missing') . ', not an Excel workbook');
        }
        $wbText = $pkg->read($wbPart);
        if ($wbText === null) {
            throw new WorkbookError('workbook_invalid', $wbPart . ' is missing');
        }
        $wb = self::workbook($wbText, $wbPart);
        unset($wbText);
        $relsPart = self::relsOf($wbPart);
        $relsText = $pkg->read($relsPart);
        $rels = $relsText === null ? [] : self::rels($relsText, $relsPart);
        // Step 7: external relationships of the workbook.
        foreach ($rels as $rel) {
            if ($rel['external']) {
                $kind = explode('/', $rel['type']);
                throw new WorkbookError('workbook_unsafe', 'the workbook refers to an external resource (' . end($kind) . ')');
            }
        }
        $relById = [];
        $stylesRel = null;
        $ssRel = null;
        foreach ($rels as $rel) {
            $relById[$rel['id']] = $rel;
            if ($rel['type'] === self::REL_STYLES && $stylesRel === null) {
                $stylesRel = $rel;
            }
            if ($rel['type'] === self::REL_SHARED_STRINGS && $ssRel === null) {
                $ssRel = $rel;
            }
        }
        $styles = self::styles($stylesRel !== null ? $pkg->read(self::resolve($wbPart, $stylesRel['target'])) : null);
        $shared = self::sharedStrings($ssRel !== null ? $pkg->read(self::resolve($wbPart, $ssRel['target'])) : null);
        $ctx = ['styles' => $styles, 'date1904' => $wb['date1904'], 'shared' => $shared];

        // Step 8: the sheet.
        $visible = [];
        foreach ($wb['sheets'] as $s) {
            $rel = $relById[$s['rid']] ?? null;
            if ($s['state'] !== 'visible' || $rel === null || $rel['type'] !== self::REL_WORKSHEET) {
                continue;
            }
            $visible[] = $s + ['part' => self::resolve($wbPart, $rel['target'])];
        }
        $readSheet = static function (array $s) use ($pkg): string {
            $text = $pkg->read($s['part']);
            if ($text === null) {
                throw new WorkbookError('workbook_invalid', $s['part'] . ' is missing');
            }
            return $text;
        };
        $chosen = null;
        foreach ($visible as $s) {
            if (strtolower(self::trim($s['name'])) === $r['sheet']) {
                $chosen = $s;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($visible as $s) {
                $sheet = self::sheet($readSheet($s), $s['part'], $ctx, true);
                $row1 = $sheet['cells'][1] ?? [];
                foreach ($row1 as $v) {
                    if (is_array($v) && $v[0] === 'x') {
                        throw new WorkbookError('workbook_invalid', $v[1]);
                    }
                }
                foreach ($row1 as $v) {
                    if (is_string($v) && in_array(self::normalizeKey($v), $r['keys'], true)) {
                        $chosen = $s;
                        break 2;
                    }
                }
            }
        }
        if ($chosen === null) {
            throw new WorkbookError('workbook_sheet_missing', 'no visible sheet is named ' . $r['title'] . ' or has a ' . $r['keys'][0] . ' column in row 1');
        }

        // Steps 9 and 10: the cells.
        $sheet = self::sheet($readSheet($chosen), $chosen['part'], $ctx, false);
        unset($pkg, $shared, $ctx);
        return self::rowsOf($sheet, $r['file'], (string) $chosen['name']);
    }

    /**
     * @param array{cells: array<int, array<int, string|array{0: string, 1: string, 2?: string}>>, outside: list<array{0: int, 1: int, 2: ?string}>, overflow: bool, merges: list<array{0: int, 1: int, 2: int, 3: int}>} $sheet
     * @return array{sheet: string, header: list<string>, records: array<int, list<string>>, last: int, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    private static function rowsOf(array $sheet, string $file, string $name): array
    {
        $cells = $sheet['cells'];
        $outside = $sheet['outside'];
        // Merged cells: only the top-left cell keeps its value.
        foreach ($sheet['merges'] as [$c1, $r1, $c2, $r2]) {
            foreach (array_keys($cells) as $rn) {
                if ($rn < $r1 || $rn > $r2) {
                    continue;
                }
                foreach (array_keys($cells[$rn]) as $cn) {
                    if ($cn >= $c1 && $cn <= $c2 && ($rn !== $r1 || $cn !== $c1)) {
                        unset($cells[$rn][$cn]);
                    }
                }
            }
            foreach ($outside as $i => [$rn, $cn]) {
                if ($rn >= $r1 && $rn <= $r2 && $cn >= $c1 && $cn <= $c2 && ($rn !== $r1 || $cn !== $c1)) {
                    unset($outside[$i]);
                }
            }
        }
        // Step 9 in reading order (rows, then columns): an unreadable cell is workbook_invalid,
        // a non-empty cell outside the limits workbook_too_large, whichever comes first.
        usort($outside, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $first = $outside[0] ?? null;
        $outsideError = static function (array $o): WorkbookError {
            if ($o[2] !== null) {
                return new WorkbookError('workbook_invalid', $o[2]);
            }
            $ref = self::colLetter($o[1]) . $o[0];
            return $o[0] > self::MAX_ROW
                ? new WorkbookError('workbook_too_large', 'the sheet has values below row ' . self::MAX_ROW . ' (cell ' . $ref . '); at most ' . (self::MAX_ROW - 1) . ' data rows')
                : new WorkbookError('workbook_too_large', 'the sheet has values right of column ' . self::colLetter(self::MAX_COL) . ' (cell ' . $ref . ')');
        };
        ksort($cells);
        foreach ($cells as $rn => &$row) {
            ksort($row);
            foreach ($row as $cn => $v) {
                if (is_array($v) && $v[0] === 'x') {
                    if ($first !== null && ($first[0] < $rn || ($first[0] === $rn && $first[1] < $cn))) {
                        throw $outsideError($first);
                    }
                    throw new WorkbookError('workbook_invalid', $v[1]);
                }
            }
        }
        unset($row);
        if ($first !== null) {
            throw $outsideError($first);
        }
        if ($sheet['overflow']) {
            throw new WorkbookError('workbook_too_large', 'the sheet has values below row ' . self::MAX_ROW . ' or right of column ' . self::colLetter(self::MAX_COL));
        }

        $row1 = $cells[1] ?? [];
        $width = 0;
        foreach ($row1 as $cn => $v) {
            if (is_string($v) && $v !== '') {
                $width = max($width, $cn);
            }
        }
        $header = [];
        for ($c = 1; $c <= $width; $c++) {
            $header[] = is_string($row1[$c] ?? null) ? $row1[$c] : '';
        }
        $errors = [];
        $error = static function (int $rn, int $cn, string $column, array $v) use ($file): array {
            $column = self::trim($column);
            return [
                'file' => $file,
                'row' => $rn,
                'column' => $column !== '' ? $column : null,
                'severity' => 'error',
                'obx_error' => 'validation_failed',
                'detail' => self::locator($rn, $column, $cn) . 'the cell holds ' . $v[2],
                'value' => $v[1],
            ];
        };
        foreach ($row1 as $cn => $v) {
            if (is_array($v) && $cn <= $width) {
                $errors[] = $error(1, $cn, '', $v);
            }
        }
        $last = 1;
        $stray = [];
        foreach ($cells as $rn => $row) {
            foreach ($row as $cn => $_) {
                if ($cn > $width) {
                    $stray[$cn] = true;
                } elseif ($rn > 1) {
                    $last = max($last, $rn);
                }
            }
        }
        $records = [];
        foreach ($cells as $rn => $row) {
            if ($rn < 2 || $rn > $last) {
                continue;
            }
            $bad = false;
            $out = array_fill(0, $width, '');
            foreach ($row as $cn => $v) {
                if ($cn > $width) {
                    continue;
                }
                if (is_array($v)) {
                    $errors[] = $error($rn, $cn, $header[$cn - 1], $v);
                    $bad = true;
                } else {
                    $out[$cn - 1] = $v;
                }
            }
            if (!$bad) {
                $records[$rn] = $out;
            }
        }
        ksort($stray);
        $warnings = [];
        foreach (array_keys($stray) as $cn) {
            $warnings[] = [
                'file' => $file,
                'row' => null,
                'column' => null,
                'severity' => 'warning',
                'obx_error' => 'validation_failed',
                'detail' => 'column ' . self::colLetter($cn) . ': has values but no header in row 1; ignored',
                'value' => null,
            ];
        }
        return ['sheet' => $name, 'header' => $header, 'records' => $records, 'last' => $last, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Every row from row 1 to the last data row (empty rows and rows emptied by a conversion
     * error included): the "rows" of the fixtures.
     *
     * @param array{header: list<string>, records: array<int, list<string>>, last: int} $result
     * @return list<list<string>>
     */
    public static function rows(array $result): array
    {
        $out = [$result['header']];
        $empty = array_fill(0, count($result['header']), '');
        for ($rn = 2; $rn <= $result['last']; $rn++) {
            $out[] = $result['records'][$rn] ?? $empty;
        }
        return $out;
    }

    /** @param list<list<string>> $rows canonical CSV (§5.3): ",", CRLF after every row, quotes only where needed */
    public static function canonicalCsv(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $out .= implode(',', array_map(static fn (string $s) => preg_match('/[",\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s, $row)) . "\r\n";
        }
        return $out;
    }

    /** §5.5 locator: "row 12, column ean (C): ", "row 12: ", "column ean (C): " or "column M: ". */
    public static function locator(?int $row, ?string $header, ?int $col): string
    {
        if ($row !== null && $col !== null) {
            return 'row ' . $row . ', column ' . ($header !== null && $header !== '' ? $header . ' ' : '') . '(' . self::colLetter($col) . '): ';
        }
        if ($row !== null) {
            return 'row ' . $row . ': ';
        }
        if ($col !== null) {
            return 'column ' . ($header !== null && $header !== '' ? $header . ' (' . self::colLetter($col) . ')' : self::colLetter($col)) . ': ';
        }
        return '';
    }

    public static function colLetter(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)) . $s;
        }
        return $s;
    }

    private static function colNumber(string $letters): int
    {
        if (strlen($letters) > 8) {
            return PHP_INT_MAX >> 8; // far right of every limit
        }
        $n = 0;
        foreach (str_split($letters) as $c) {
            $n = $n * 26 + ord($c) - 64;
        }
        return $n;
    }

    /** Trims like JavaScript's String.prototype.trim (Unicode white space and the BOM). */
    public static function trim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $s);
    }

    /** The header normalisation used to find the key column of a sheet (§5.2). */
    private static function normalizeKey(string $h): string
    {
        return str_replace([' ', '-'], '_', strtolower(self::trim($h)));
    }

    // ================================================================ XML

    /** Opens an XML part: well-formed, no DOCTYPE, no network. */
    private static function reader(string $xml, string $part): \XMLReader
    {
        if ($xml === '') {
            throw new WorkbookError('workbook_invalid', $part . ' is not well-formed XML (empty)');
        }
        libxml_clear_errors();
        $r = new \XMLReader();
        if (!@$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new WorkbookError('workbook_invalid', $part . ' is not well-formed XML');
        }
        return $r;
    }

    /** Next node; false at the end. Throws on a DOCTYPE or an XML error. */
    private static function next(\XMLReader $r, string $part): bool
    {
        if (@$r->read()) {
            if ($r->nodeType === \XMLReader::DOC_TYPE) {
                throw new WorkbookError('workbook_invalid', $part . ' has a DOCTYPE; it is never read');
            }
            return true;
        }
        self::checkErrors($part);
        return false;
    }

    private static function checkErrors(string $part): void
    {
        foreach (libxml_get_errors() as $e) {
            if ($e->level >= LIBXML_ERR_ERROR) {
                libxml_clear_errors();
                throw new WorkbookError('workbook_invalid', $part . ' is not well-formed XML (' . trim($e->message) . ' on line ' . $e->line . ')');
            }
        }
        libxml_clear_errors();
    }

    private static function isText(\XMLReader $r): bool
    {
        return in_array($r->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE], true);
    }

    /** @return array{defaults: array<string, string>, overrides: array<string, string>, all: list<string>} */
    private static function contentTypes(string $xml): array
    {
        $defaults = [];
        $overrides = [];
        $all = [];
        $r = self::reader($xml, '[Content_Types].xml');
        while (self::next($r, '[Content_Types].xml')) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'Default') {
                $t = (string) $r->getAttribute('ContentType');
                $defaults[strtolower((string) $r->getAttribute('Extension'))] = $t;
                $all[] = $t;
            } elseif ($r->localName === 'Override') {
                $t = (string) $r->getAttribute('ContentType');
                $overrides[strtolower((string) $r->getAttribute('PartName'))] = $t;
                $all[] = $t;
            }
        }
        $r->close();
        return ['defaults' => $defaults, 'overrides' => $overrides, 'all' => $all];
    }

    /** @param array{defaults: array<string, string>, overrides: array<string, string>, all: list<string>} $ct */
    private static function typeOf(array $ct, string $part): ?string
    {
        $parts = explode('.', $part);
        return $ct['overrides'][strtolower($part)] ?? $ct['defaults'][strtolower(end($parts))] ?? null;
    }

    /** @return list<array{id: string, type: string, target: string, external: bool}> */
    private static function rels(string $xml, string $part): array
    {
        $out = [];
        $r = self::reader($xml, $part);
        while (self::next($r, $part)) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'Relationship') {
                $out[] = [
                    'id' => (string) $r->getAttribute('Id'),
                    'type' => (string) $r->getAttribute('Type'),
                    'target' => (string) $r->getAttribute('Target'),
                    'external' => $r->getAttribute('TargetMode') === 'External',
                ];
            }
        }
        $r->close();
        return $out;
    }

    /** Resolves a relationship target against its source part ("/" first when absolute). */
    private static function resolve(string $source, string $target): string
    {
        $base = str_starts_with($target, '/') ? [] : array_values(array_filter(array_slice(explode('/', $source), 0, -1), static fn ($x) => $x !== ''));
        foreach (explode('/', ltrim($target, '/')) as $seg) {
            if ($seg === '..') {
                array_pop($base);
            } elseif ($seg !== '.' && $seg !== '') {
                $base[] = $seg;
            }
        }
        return '/' . implode('/', $base);
    }

    private static function relsOf(string $part): string
    {
        $s = explode('/', $part);
        $f = array_pop($s);
        return implode('/', $s) . '/_rels/' . $f . '.rels';
    }

    /** @return array{sheets: list<array{name: string, state: string, rid: string}>, date1904: bool} */
    private static function workbook(string $xml, string $part): array
    {
        $sheets = [];
        $date1904 = false;
        $root = true;
        $r = self::reader($xml, $part);
        while (self::next($r, $part)) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($root) {
                $root = false;
                if ($r->localName !== 'workbook' || $r->namespaceURI !== self::NS_MAIN) {
                    throw new WorkbookError('workbook_invalid', $r->namespaceURI === self::NS_STRICT
                        ? 'the workbook is saved as Strict Open XML; save it as an Excel Workbook (.xlsx)'
                        : $part . ' is not a SpreadsheetML workbook');
                }
            }
            if ($r->localName === 'workbookPr') {
                $v = $r->getAttribute('date1904');
                $date1904 = $v === '1' || $v === 'true';
            } elseif ($r->localName === 'sheet') {
                $sheets[] = [
                    'name' => (string) $r->getAttribute('name'),
                    'state' => $r->getAttribute('state') ?? 'visible',
                    'rid' => (string) $r->getAttributeNs('id', self::NS_REL),
                ];
            }
        }
        $r->close();
        return ['sheets' => $sheets, 'date1904' => $date1904];
    }

    /** @return array{xfs: list<int>, fmts: array<int, string>} */
    private static function styles(?string $xml): array
    {
        $fmts = [];
        $xfs = [];
        if ($xml === null) {
            return ['xfs' => $xfs, 'fmts' => $fmts];
        }
        $inXfs = false;
        $r = self::reader($xml, 'styles');
        while (self::next($r, 'styles')) {
            if ($r->nodeType === \XMLReader::ELEMENT) {
                if ($r->localName === 'numFmt') {
                    $fmts[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode');
                } elseif ($r->localName === 'cellXfs') {
                    $inXfs = !$r->isEmptyElement;
                } elseif ($r->localName === 'xf' && $inXfs) {
                    $xfs[] = (int) ($r->getAttribute('numFmtId') ?? 0);
                }
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
                $inXfs = false;
            }
        }
        $r->close();
        return ['xfs' => $xfs, 'fmts' => $fmts];
    }

    /** @return list<string> */
    private static function sharedStrings(?string $xml): array
    {
        $out = [];
        if ($xml === null) {
            return $out;
        }
        $r = self::reader($xml, 'sharedStrings');
        while (self::next($r, 'sharedStrings')) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                $out[] = $r->isEmptyElement ? '' : self::richText($r, 'si', 'sharedStrings');
            }
        }
        $r->close();
        return $out;
    }

    /**
     * Text of <si> or <is> (reader on its start tag): <t> directly inside and <t> inside <r>
     * joined; phonetic runs <rPh> are ignored.
     */
    private static function richText(\XMLReader $r, string $end, string $part): string
    {
        $buf = '';
        $inPh = 0;
        $inT = false;
        while (self::next($r, $part)) {
            $type = $r->nodeType;
            if ($type === \XMLReader::ELEMENT) {
                if ($r->isEmptyElement) {
                    continue;
                }
                if ($r->localName === 'rPh') {
                    $inPh++;
                } elseif ($r->localName === 't' && $inPh === 0) {
                    $inT = true;
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                $name = $r->localName;
                if ($name === $end) {
                    break;
                }
                if ($name === 'rPh') {
                    $inPh--;
                } elseif ($name === 't') {
                    $inT = false;
                }
            } elseif ($inT && self::isText($r)) {
                $buf .= $r->value;
            }
        }
        return self::unescape($buf);
    }

    /**
     * The cells of a worksheet, converted (§5.4): row => col => text, or [ 'e', value, reason ]
     * for a conversion error, or [ 'x', detail ] for a cell that makes the workbook invalid.
     * Empty cells are left out. Cells outside the limits are kept as coordinates only.
     *
     * @param array{styles: array{xfs: list<int>, fmts: array<int, string>}, date1904: bool, shared: list<string>} $ctx
     * @return array{cells: array<int, array<int, string|array{0: string, 1: string, 2?: string}>>, outside: list<array{0: int, 1: int, 2: ?string}>, overflow: bool, merges: list<array{0: int, 1: int, 2: int, 3: int}>}
     */
    private static function sheet(string $xml, string $part, array $ctx, bool $onlyRow1): array
    {
        $cells = [];
        $outside = [];
        $overflow = false;
        $merges = [];
        $row = 0;
        $col = 0;
        $cell = null;
        $field = null;
        $r = self::reader($xml, $part);
        unset($xml);
        $store = static function (int $row, int $col, array $c) use (&$cells, &$outside, &$overflow, $ctx, $onlyRow1): void {
            $v = self::cellValue($c, $ctx);
            if ($v === '') {
                unset($cells[$row][$col], $outside[$row . ',' . $col]);
                return;
            }
            if (!$onlyRow1 && ($row > self::MAX_ROW || $col > self::MAX_COL)) {
                $key = $row . ',' . $col;
                if (!isset($outside[$key]) && count($outside) >= self::MAX_OUTSIDE) {
                    $overflow = true;
                    return;
                }
                $outside[$key] = [$row, $col, is_array($v) && $v[0] === 'x' ? $v[1] : null];
                return;
            }
            $cells[$row][$col] = $v;
        };
        while (self::next($r, $part)) {
            $type = $r->nodeType;
            if ($type === \XMLReader::ELEMENT) {
                $name = $r->localName;
                if ($name === 'row') {
                    $rr = $r->getAttribute('r');
                    $row = $rr !== null && $rr !== '' ? (int) $rr : $row + 1;
                    $col = 0;
                    if ($onlyRow1 && $row > 1) {
                        break;
                    }
                } elseif ($name === 'c') {
                    $ref = $r->getAttribute('r');
                    if ($ref !== null && preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                        $col = self::colNumber($m[1]);
                        $row = (int) $m[2];
                    } else {
                        $col++;
                    }
                    $cell = ['t' => $r->getAttribute('t') ?? 'n', 's' => (int) ($r->getAttribute('s') ?? 0), 'v' => null, 'f' => null, 'is' => null];
                    if ($r->isEmptyElement) {
                        if (!$onlyRow1 || $row === 1) {
                            $store($row, $col, $cell);
                        }
                        $cell = null;
                    }
                } elseif ($cell !== null && ($name === 'v' || $name === 'f')) {
                    $cell[$name] = '';
                    $field = $r->isEmptyElement ? null : $name;
                } elseif ($cell !== null && $name === 'is') {
                    $cell['is'] = $r->isEmptyElement ? '' : self::richText($r, 'is', $part);
                } elseif ($name === 'mergeCell') {
                    if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string) $r->getAttribute('ref'), $m)) {
                        $merges[] = [self::colNumber($m[1]), (int) $m[2], self::colNumber($m[3]), (int) $m[4]];
                    }
                }
            } elseif ($type === \XMLReader::END_ELEMENT) {
                $name = $r->localName;
                if ($name === 'c' && $cell !== null) {
                    if (!$onlyRow1 || $row === 1) {
                        $store($row, $col, $cell);
                    }
                    $cell = null;
                    $field = null;
                } elseif ($name === 'v' || $name === 'f') {
                    $field = null;
                }
            } elseif ($field !== null && $cell !== null && self::isText($r)) {
                $cell[$field] .= $r->value;
            }
        }
        $r->close();
        self::checkErrors($part);
        return ['cells' => $cells, 'outside' => array_values($outside), 'overflow' => $overflow, 'merges' => $merges];
    }

    /**
     * One cell (§5.4): '' (empty), the text, ['e', value, reason] (conversion error) or
     * ['x', detail] (workbook_invalid when the cell is read).
     *
     * @param array{t: string, s: int, v: ?string, f: ?string, is: ?string} $c
     * @param array{styles: array{xfs: list<int>, fmts: array<int, string>}, date1904: bool, shared: list<string>} $ctx
     * @return string|array{0: string, 1: string, 2?: string}
     */
    private static function cellValue(array $c, array $ctx): string|array
    {
        if ($c['f'] !== null && $c['v'] === null && $c['t'] !== 'inlineStr') {
            return ['e', '=' . $c['f'], 'a formula without a saved result'];
        }
        $v = $c['v'];
        switch ($c['t']) {
            case 's':
                if ($v === null || self::trim($v) === '') {
                    return '';
                }
                $t = self::trim($v);
                if (!is_numeric($t) || (float) $t != floor((float) $t) || (float) $t < 0 || (float) $t >= count($ctx['shared'])) {
                    return ['x', 'shared string ' . $v . ' does not exist'];
                }
                return $ctx['shared'][(int) $t];
            case 'inlineStr':
                return $c['is'] ?? '';
            case 'str':
                return self::unescape($v ?? '');
            case 'b':
                return $v === '1' ? 'true' : ($v === '0' ? 'false' : '');
            case 'e':
                return ['e', $v ?? '', 'an error value'];
            case 'd':
                if ($v === null || $v === '') {
                    return '';
                }
                $t = self::isoCellText($v);
                return $t ?? ['e', $v, 'a date that cannot be read'];
            default:
                if ($v === null || self::trim($v) === '') {
                    return '';
                }
                $id = $ctx['styles']['xfs'][$c['s']] ?? 0;
                $code = $ctx['styles']['fmts'][$id] ?? null;
                $kind = self::dateKind($id, $code);
                if ($kind !== null) {
                    return self::serialText($v, $kind, $ctx['date1904']) ?? ['e', $v, 'a date out of range (before 1900-03-01, or before 1904-01-01 in the 1904 date system)'];
                }
                $t = self::numberText($v);
                if ($t === null) {
                    return ['x', 'a number cell holds "' . mb_substr($v, 0, 50) . '"'];
                }
                $w = self::zeroPad($code);
                if ($w > 0 && ctype_digit($t) && strlen($t) < $w) {
                    $t = str_pad($t, $w, '0', STR_PAD_LEFT);
                }
                return $t;
        }
    }

    /** §5.4 escapes: _xHHHH_ is the character U+HHHH, one pass from left to right. */
    public static function unescape(string $s): string
    {
        if (!str_contains($s, '_x')) {
            return $s;
        }
        return (string) preg_replace_callback('/_x([dD][89abAB][0-9A-Fa-f]{2})__x([dD][c-fC-F][0-9A-Fa-f]{2})_|_x([0-9A-Fa-f]{4})_/', static function (array $m): string {
            if (($m[3] ?? '') === '') {
                $hi = (int) hexdec($m[1]);
                $lo = (int) hexdec($m[2]);
                return (string) mb_chr(0x10000 + (($hi - 0xD800) << 10) + ($lo - 0xDC00), 'UTF-8');
            }
            $cp = (int) hexdec($m[3]);
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                return "\u{FFFD}";
            }
            return (string) mb_chr($cp, 'UTF-8');
        }, $s);
    }

    // ================================================================ numbers and dates

    /**
     * The text of a number cell (§5.4): the exact decimal value of <v>, rounded to 15
     * significant digits (halves away from zero), without exponent or trailing zeros.
     * Null when <v> is not a number.
     */
    public static function numberText(string $lex): ?string
    {
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', self::trim($lex), $m)) {
            return null;
        }
        $intPart = $m[2];
        $fracPart = $m[3] ?? '';
        if ($intPart === '' && $fracPart === '') {
            return null;
        }
        $neg = $m[1] === '-';
        $all = $intPart . $fracPart;
        $digits = ltrim($all, '0');
        $z = strlen($all) - strlen($digits);
        $exp = $m[4] ?? '';
        if (strlen(ltrim(ltrim($exp, '+-'), '0')) > 4) {
            return null; // far beyond what a double holds (an exponent of 10 000 or more)
        }
        $point = strlen($intPart) + (int) $exp - $z;
        if ($digits === '') {
            return '0';
        }
        if (strlen($digits) > 15) {
            $kept = array_map('intval', str_split(substr($digits, 0, 15)));
            if ($digits[15] >= '5') {
                $i = 14;
                while ($i >= 0) {
                    if ($kept[$i] === 9) {
                        $kept[$i] = 0;
                        $i--;
                    } else {
                        $kept[$i]++;
                        break;
                    }
                }
                if ($i < 0) {
                    array_unshift($kept, 1);
                    array_pop($kept);
                    $point++;
                }
            }
            $digits = implode('', $kept);
        }
        $digits = rtrim($digits, '0');
        if ($digits === '') {
            return '0';
        }
        if ($point <= 0) {
            $int = '0';
            $frac = str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $int = $digits . str_repeat('0', $point - strlen($digits));
            $frac = '';
        } else {
            $int = substr($digits, 0, $point);
            $frac = substr($digits, $point);
        }
        return ($neg ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
    }

    /** First section of a format code with its literals removed (§5.4). */
    public static function formatSkeleton(string $code): string
    {
        $out = '';
        $n = strlen($code);
        for ($i = 0; $i < $n; $i++) {
            $c = $code[$i];
            if ($c === '"') {
                $j = strpos($code, '"', $i + 1);
                $i = $j === false ? $n : $j;
                continue;
            }
            if ($c === '\\' || $c === '_' || $c === '*') {
                $i++;
                continue;
            }
            if ($c === '[') {
                $j = strpos($code, ']', $i + 1);
                $inner = $j === false ? '' : substr($code, $i + 1, $j - $i - 1);
                if (preg_match('/^(h+|m+|s+)$/i', $inner)) {
                    $out .= $inner;
                }
                $i = $j === false ? $n : $j;
                continue;
            }
            if ($c === ';') {
                break;
            }
            $out .= $c;
        }
        return $out;
    }

    /** 'date', 'datetime' or null for a number format (§5.4). */
    public static function dateKind(int $id, ?string $code): ?string
    {
        if (in_array($id, self::BUILTIN_DATE, true)) {
            return 'date';
        }
        if (in_array($id, self::BUILTIN_DATETIME, true)) {
            return 'datetime';
        }
        if ($code === null) {
            return null;
        }
        $sk = self::formatSkeleton($code);
        if (!preg_match('/[dmyhs]/i', $sk)) {
            return null;
        }
        return preg_match('/[hs]/i', $sk) ? 'datetime' : 'date';
    }

    /** Zero padding: a format code of only zeros (at least two) pads to that many digits. */
    public static function zeroPad(?string $code): int
    {
        return $code !== null && preg_match('/^0{2,}$/', $code) ? strlen($code) : 0;
    }

    /** A date serial as ISO text (§5.4); null for a serial the rules do not accept. */
    public static function serialText(string $lex, string $kind, bool $date1904): ?string
    {
        $t = self::trim($lex);
        if (!is_numeric($t)) {
            return null;
        }
        $v = (float) $t;
        if (!is_finite($v) || ($date1904 ? $v < 0 : $v < 61) || $v >= 2958466) {
            return null; // 2958466 is 10000-01-01, beyond Excel's last date
        }
        $days = (int) floor($v);
        $x = ($v - $days) * 86400;
        $secs = (int) floor($x);
        if ($x - $secs >= 0.5) {
            $secs++;
        }
        if ($secs >= 86400) {
            $days++;
            $secs -= 86400;
        }
        $epoch = $date1904 ? -2082844800 : -2209161600; // 1904-01-01, 1899-12-30
        $ts = $epoch + $days * 86400;
        if ($kind === 'date') {
            return gmdate('Y-m-d', $ts);
        }
        return gmdate('Y-m-d', $ts) . 'T' . sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs, 60) % 60, $secs % 60) . 'Z';
    }

    /** A t="d" cell (ISO 8601 text). */
    public static function isoCellText(string $lex): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]00:00)?)?$/', self::trim($lex), $m)) {
            return null;
        }
        $h = $m[2] ?? '';
        $s = ($m[4] ?? '') !== '' ? $m[4] : '00';
        if ($h === '' || ($h === '00' && $m[3] === '00' && $s === '00')) {
            return $m[1];
        }
        return $m[1] . 'T' . $h . ':' . $m[3] . ':' . $s . 'Z';
    }
}
