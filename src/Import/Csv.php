<?php

declare(strict_types=1);

namespace Obx\Node\Import;

use Obx\Node\Http\ApiError;

/**
 * Canonical CSV rules (docs/import-format.md §1.1): UTF-8 with or without BOM, RFC 4180
 * quoting, "," or ";" (or tab) detected from the header row, header names matched
 * case-insensitively with spaces and "-" as "_".
 */
final class Csv
{
    /**
     * @return array{headers: list<string>, raw_headers: list<string>, rows: list<array{row: int, values: array<string, string>}>, delimiter: string}
     */
    public static function read(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new ApiError('import_invalid', 'The CSV file cannot be read.');
        }
        $first = (string) fgets($fh);
        if ($first === '') {
            fclose($fh);
            throw new ApiError('import_invalid', 'The CSV file is empty; a header row is required.');
        }
        $bom = str_starts_with($first, "\xEF\xBB\xBF");
        if (!mb_check_encoding(substr($first, $bom ? 3 : 0), 'UTF-8')) {
            fclose($fh);
            throw new ApiError('import_invalid', 'The CSV file is not UTF-8. Save it as "CSV UTF-8".');
        }
        $delimiter = self::detectDelimiter($bom ? substr($first, 3) : $first);
        rewind($fh);
        if ($bom) {
            fread($fh, 3);
        }
        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            fclose($fh);
            throw new ApiError('import_invalid', 'The CSV file has no header row.');
        }
        $raw = array_map(static fn ($h) => trim((string) $h), $header);
        $headers = array_map([self::class, 'normalizeHeader'], $raw);
        $rows = [];
        $n = 0;
        while (($values = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            $n++;
            $assoc = [];
            $empty = true;
            foreach ($headers as $i => $h) {
                if ($h === '') {
                    continue;
                }
                $v = trim((string) ($values[$i] ?? ''));
                if ($v !== '') {
                    $empty = false;
                }
                if (!isset($assoc[$h]) || $assoc[$h] === '') {
                    $assoc[$h] = $v;
                }
            }
            if ($empty) {
                continue;
            }
            $rows[] = ['row' => $n, 'values' => $assoc];
        }
        fclose($fh);
        return ['headers' => $headers, 'raw_headers' => $raw, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    public static function detectDelimiter(string $line): string
    {
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $inQuotes = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && isset($counts[$c])) {
                $counts[$c]++;
            }
        }
        if ($counts["\t"] > $counts[','] && $counts["\t"] > $counts[';']) {
            return "\t";
        }
        return $counts[';'] > $counts[','] ? ';' : ',';
    }

    /** "Style Number" -> style_number; "Name:NL-be" -> name:nl-BE; aliases applied. */
    public static function normalizeHeader(string $h): string
    {
        $h = trim($h);
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $lang = null;
        if (str_contains($h, ':')) {
            [$h, $lang] = explode(':', $h, 2);
            $lang = trim($lang);
        }
        $base = strtolower(trim($h));
        $base = (string) preg_replace('/[\s\-]+/', '_', $base);
        $aliases = [
            'style' => 'style_number',
            'ean' => 'gtin',
            'barcode' => 'gtin',
            'colour_code' => 'color_code',
            'colour_name' => 'color_name',
        ];
        $base = $aliases[$base] ?? $base;
        if ($lang === null || $lang === '') {
            return $base;
        }
        if ($base === 'attr') {
            return 'attr:' . strtolower((string) preg_replace('/[\s\-]+/', '_', $lang));
        }
        $parts = explode('-', str_replace('_', '-', $lang));
        $tag = strtolower($parts[0]) . (isset($parts[1]) ? '-' . strtoupper($parts[1]) : '');
        return $base . ':' . $tag;
    }

    /** Formula-injection-safe cell (docs/security.md §6). */
    public static function cell(?string $v): string
    {
        $v = (string) $v;
        if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^[+-]?\d+([.,]\d+)?$/', $v)) {
            $v = "'" . $v;
        }
        return $v;
    }

    /** @param list<string|null> $cells */
    public static function line(array $cells, string $delimiter): string
    {
        $out = [];
        foreach ($cells as $c) {
            $c = self::cell($c);
            if ($c === '' || !preg_match('/[' . preg_quote($delimiter, '/') . '"\r\n]/', $c)) {
                $out[] = $c;
            } else {
                $out[] = '"' . str_replace('"', '""', $c) . '"';
            }
        }
        return implode($delimiter, $out) . "\r\n";
    }
}
