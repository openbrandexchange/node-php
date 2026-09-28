<?php

declare(strict_types=1);

namespace Obx\Node\Import;

use Obx\Node\Http\ApiError;

/**
 * Zip reading with zip-slip and zip-bomb protection (docs/import-format.md §3,
 * docs/security.md §6). Entries are never extracted to paths derived from their names.
 */
final class SafeZip
{
    public const MAX_ENTRIES = 100000;

    /**
     * Lists the usable entries (index => name). Throws import_invalid for unsafe archives.
     *
     * @return array<int, string>
     */
    public static function entries(string $path, int $maxTotalBytes): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ApiError('import_invalid', 'This server cannot read zip files (the PHP zip extension is missing). Upload the CSV and images separately.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new ApiError('import_invalid', 'The file is not a readable zip archive.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ApiError('import_invalid', 'The zip has more than ' . self::MAX_ENTRIES . ' entries.');
            }
            $total = 0;
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new ApiError('import_invalid', 'The zip archive is damaged.');
                }
                $name = (string) $stat['name'];
                self::assertSafeName($name);
                $opsys = 0;
                $attr = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
                    $mode = ($attr >> 16) & 0170000;
                    if ($mode === 0120000) {
                        throw new ApiError('import_invalid', 'The zip contains a symbolic link ("' . $name . '"); that is not allowed.');
                    }
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $total += (int) $stat['size'];
                if ($total > $maxTotalBytes) {
                    throw new ApiError('import_invalid', 'The zip unpacks to more than ' . $maxTotalBytes . ' bytes.');
                }
                if (self::ignored($name)) {
                    continue;
                }
                $out[$i] = $name;
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    public static function assertSafeName(string $name): void
    {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || str_contains($n, "\0") || str_starts_with($n, '/') || preg_match('#^[A-Za-z]:#', $n)) {
            throw new ApiError('import_invalid', 'The zip contains an unsafe path ("' . $name . '").');
        }
        foreach (explode('/', $n) as $seg) {
            if ($seg === '..') {
                throw new ApiError('import_invalid', 'The zip contains an unsafe path ("' . $name . '").');
            }
            $base = strtoupper(explode('.', $seg)[0]);
            if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $base)) {
                throw new ApiError('import_invalid', 'The zip contains a device name ("' . $name . '").');
            }
        }
    }

    public static function ignored(string $name): bool
    {
        $n = str_replace('\\', '/', $name);
        if (str_starts_with($n, '__MACOSX/') || str_contains($n, '/__MACOSX/')) {
            return true;
        }
        $base = basename($n);
        return $base === '' || $base[0] === '.' || $base === 'Thumbs.db' || $base === 'desktop.ini';
    }

    /**
     * Extracts the given entries into $dir under generated names.
     *
     * @param array<int, string> $entries index => name
     * @return list<array{name: string, path: string, bytes: int}>
     */
    public static function extractMany(string $zipPath, array $entries, string $dir, int $maxBytes): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new ApiError('import_invalid', 'The zip archive cannot be read.');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $out = [];
        try {
            foreach ($entries as $index => $name) {
                $in = $zip->getStream($name);
                if ($in === false) {
                    throw new ApiError('import_invalid', 'Zip entry "' . $name . '" cannot be read.');
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $target = $dir . '/z' . $index . ($ext !== '' && preg_match('/^[a-z0-9]{1,5}$/', $ext) ? '.' . $ext : '');
                $fh = fopen($target, 'wb');
                $bytes = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $maxBytes) {
                        fclose($in);
                        fclose($fh);
                        @unlink($target);
                        throw new ApiError('import_invalid', 'Zip entry "' . $name . '" is larger than the upload limit.');
                    }
                    fwrite($fh, $chunk);
                }
                fclose($in);
                fclose($fh);
                $out[] = ['name' => str_replace('\\', '/', $name), 'path' => $target, 'bytes' => $bytes];
            }
        } finally {
            $zip->close();
        }
        return $out;
    }

    /** Extracts one entry by index into $target, enforcing $maxBytes. */
    public static function extract(string $zipPath, int $index, string $target, int $maxBytes): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new ApiError('import_invalid', 'The zip archive cannot be read.');
        }
        try {
            $name = $zip->getNameIndex($index);
            $in = $name !== false ? $zip->getStream($name) : false;
            if ($in === false) {
                throw new ApiError('import_invalid', 'A zip entry cannot be read.');
            }
            $out = fopen($target, 'wb');
            $bytes = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    fclose($in);
                    fclose($out);
                    @unlink($target);
                    throw new ApiError('import_invalid', 'A zip entry is larger than the upload limit.');
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
            return $bytes;
        } finally {
            $zip->close();
        }
    }
}
