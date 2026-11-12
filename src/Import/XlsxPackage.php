<?php

declare(strict_types=1);

namespace Obx\Node\Import;

/**
 * Reads the zip container of an .xlsx workbook in pure PHP (zlib only, no ext-zip), under the
 * fixed limits of docs/import-format.md §5.6 steps 2 to 4. Parts are inflated into memory,
 * counting the bytes actually inflated; nothing is written to disk.
 */
final class XlsxPackage
{
    public const MAX_ENTRIES = 1000;
    public const MAX_TOTAL_BYTES = 268435456; // 256 MiB
    public const RATIO = 100;
    public const RATIO_FLOOR = 10485760; // 10 MiB

    /** @var array<string, array{name: string, method: int, crc: int, csize: int, usize: int, offset: int}> lower(name) => entry */
    private array $byName = [];
    /** @var list<string> entry names (no folders) */
    public array $names = [];
    private int $inflated = 0;
    /** @var resource */
    private $fh;
    private int $size;

    /** @param resource $fh */
    private function __construct($fh, int $size)
    {
        $this->fh = $fh;
        $this->size = $size;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public static function open(string $path): self
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new WorkbookError('workbook_invalid', 'the file cannot be read');
        }
        $size = (int) filesize($path);
        $pkg = new self($fh, $size);
        // Step 2: signature.
        $head = (string) fread($fh, 8);
        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new WorkbookError('workbook_invalid', 'the file is an encrypted workbook or a legacy .xls file (OLE compound file); save it as an Excel Workbook (.xlsx) without a password');
        }
        if (strlen($head) < 4 || substr($head, 0, 4) !== "PK\x03\x04") {
            throw new WorkbookError('workbook_invalid', 'the file is not an .xlsx workbook (not a zip file)');
        }
        // Step 3: a readable central directory without encrypted entries.
        $pkg->readDirectory();
        // Step 4: zip bomb limits from the declared sizes.
        if ($pkg->count > self::MAX_ENTRIES) {
            throw new WorkbookError('workbook_too_large', $pkg->count . ' zip entries (limit ' . self::MAX_ENTRIES . ')');
        }
        $total = 0;
        foreach ($pkg->all as $e) {
            $total += $e['usize'];
            if ($e['usize'] > self::RATIO_FLOOR && $e['usize'] > self::RATIO * max($e['csize'], 1)) {
                throw new WorkbookError('workbook_too_large', $e['name'] . ' expands from ' . $e['csize'] . ' to ' . $e['usize'] . ' bytes (more than ' . self::RATIO . ' times)');
            }
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new WorkbookError('workbook_too_large', 'the parts expand to ' . $total . ' bytes (limit ' . self::MAX_TOTAL_BYTES . ')');
        }
        $pkg->all = [];
        return $pkg;
    }

    /** Number of entries that are not folders. */
    private int $count = 0;
    /** @var list<array{name: string, method: int, crc: int, csize: int, usize: int, offset: int}> */
    private array $all = [];

    private function bytes(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > $this->size) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (truncated)');
        }
        if ($length === 0) {
            return '';
        }
        fseek($this->fh, $offset);
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($this->fh, min(1048576, $length - strlen($out)));
            if ($chunk === false || $chunk === '') {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (truncated)');
            }
            $out .= $chunk;
        }
        return $out;
    }

    private function readDirectory(): void
    {
        $tailLen = min($this->size, 65557);
        $tail = $this->bytes($this->size - $tailLen, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) - $pos < 22) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (no central directory)');
        }
        $eocdAt = $this->size - $tailLen + $pos;
        $e = unpack('vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset/vcomment', substr($tail, $pos + 4, 18));
        if ($e === false) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (no central directory)');
        }
        $entries = (int) $e['entries'];
        $cdSize = (int) $e['cdsize'];
        $cdOffset = (int) $e['cdoffset'];
        if ($entries === 0xFFFF || $cdSize === 0xFFFFFFFF || $cdOffset === 0xFFFFFFFF) {
            // Zip64 end of central directory locator, 20 bytes before.
            if ($eocdAt < 20) {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (zip64 directory missing)');
            }
            $loc = $this->bytes($eocdAt - 20, 20);
            if (substr($loc, 0, 4) !== "PK\x06\x07") {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (zip64 directory missing)');
            }
            $at = self::u64(substr($loc, 8, 8));
            $rec = $this->bytes($at, 56);
            if (substr($rec, 0, 4) !== "PK\x06\x06") {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (zip64 directory damaged)');
            }
            $entries = self::u64(substr($rec, 32, 8));
            $cdSize = self::u64(substr($rec, 40, 8));
            $cdOffset = self::u64(substr($rec, 48, 8));
        }
        if ($cdOffset + $cdSize > $this->size) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (central directory outside the file)');
        }
        fseek($this->fh, $cdOffset);
        $read = 0;
        for ($i = 0; $i < $entries; $i++) {
            $fixed = $this->take(46, $read, $cdSize);
            if (substr($fixed, 0, 4) !== "PK\x01\x02") {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (central directory damaged)');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vint/Vext/Voffset', substr($fixed, 4));
            if ($h === false) {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (central directory damaged)');
            }
            $name = $this->take((int) $h['namelen'], $read, $cdSize);
            $extra = $this->take((int) $h['extralen'], $read, $cdSize);
            $this->take((int) $h['commentlen'], $read, $cdSize);
            $usize = (int) $h['usize'];
            $csize = (int) $h['csize'];
            $offset = (int) $h['offset'];
            if ($usize === 0xFFFFFFFF || $csize === 0xFFFFFFFF || $offset === 0xFFFFFFFF) {
                [$usize, $csize, $offset] = self::zip64Extra($extra, $usize, $csize, $offset);
            }
            if (((int) $h['flags'] & 1) === 1) {
                throw new WorkbookError('workbook_invalid', 'the zip entry ' . $name . ' is encrypted; save the workbook without a password');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $this->count++;
            if ($this->count > self::MAX_ENTRIES) {
                continue; // keep reading the directory (step 3), refuse in step 4
            }
            $entry = ['name' => $name, 'method' => (int) $h['method'], 'crc' => (int) $h['crc'], 'csize' => $csize, 'usize' => $usize, 'offset' => $offset];
            $this->all[] = $entry;
            $this->byName[strtolower($name)] = $entry;
            $this->names[] = $name;
        }
    }

    /** Reads $n bytes of the central directory at the current position. */
    private function take(int $n, int &$read, int $limit): string
    {
        if ($n === 0) {
            return '';
        }
        $read += $n;
        if ($read > $limit) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (central directory damaged)');
        }
        $s = (string) fread($this->fh, $n);
        if (strlen($s) !== $n) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (truncated)');
        }
        return $s;
    }

    private static function u64(string $b): int
    {
        $p = unpack('Vlo/Vhi', $b);
        if ($p === false || $p['hi'] > 0x7FFFFFFF) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (size out of range)');
        }
        return ((int) $p['hi'] << 32) | (int) $p['lo'];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function zip64Extra(string $extra, int $usize, int $csize, int $offset): array
    {
        $i = 0;
        while ($i + 4 <= strlen($extra)) {
            $hdr = unpack('vid/vlen', substr($extra, $i, 4));
            $id = (int) ($hdr['id'] ?? 0);
            $len = (int) ($hdr['len'] ?? 0);
            $data = substr($extra, $i + 4, $len);
            if ($id === 1) {
                $p = 0;
                if ($usize === 0xFFFFFFFF && strlen($data) >= $p + 8) {
                    $usize = self::u64(substr($data, $p, 8));
                    $p += 8;
                }
                if ($csize === 0xFFFFFFFF && strlen($data) >= $p + 8) {
                    $csize = self::u64(substr($data, $p, 8));
                    $p += 8;
                }
                if ($offset === 0xFFFFFFFF && strlen($data) >= $p + 8) {
                    $offset = self::u64(substr($data, $p, 8));
                }
                break;
            }
            $i += 4 + $len;
        }
        return [$usize, $csize, $offset];
    }

    /**
     * The inflated bytes of a part (name without leading "/", any case), or null when the zip
     * has no such entry. Stops with workbook_too_large when the entry inflates beyond its
     * declared size or all parts read so far beyond 256 MiB.
     */
    public function read(string $name): ?string
    {
        $e = $this->byName[strtolower(ltrim($name, '/'))] ?? null;
        if ($e === null) {
            return null;
        }
        $local = $this->bytes($e['offset'], 30);
        if (substr($local, 0, 4) !== "PK\x03\x04") {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is damaged)');
        }
        $l = unpack('vnamelen/vextralen', substr($local, 26, 4));
        $start = $e['offset'] + 30 + (int) ($l['namelen'] ?? 0) + (int) ($l['extralen'] ?? 0);
        if ($start + $e['csize'] > $this->size) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is truncated)');
        }
        $tooLarge = fn () => new WorkbookError('workbook_too_large', $e['name'] . ' expands beyond its declared size');
        $crc = hash_init('crc32b');
        $out = '';
        $count = function (string $piece) use (&$out, $e, $crc, $tooLarge): void {
            $n = strlen($piece);
            $this->inflated += $n;
            if (strlen($out) + $n > $e['usize'] || $this->inflated > self::MAX_TOTAL_BYTES) {
                throw $tooLarge();
            }
            hash_update($crc, $piece);
            $out .= $piece;
        };
        fseek($this->fh, $start);
        $left = $e['csize'];
        if ($e['method'] === 0) {
            while ($left > 0) {
                $chunk = (string) fread($this->fh, min(65536, $left));
                if ($chunk === '') {
                    throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is truncated)');
                }
                $left -= strlen($chunk);
                $count($chunk);
            }
        } elseif ($e['method'] === 8) {
            $ctx = inflate_init(ZLIB_ENCODING_RAW);
            if ($ctx === false) {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read');
            }
            while ($left > 0) {
                $chunk = (string) fread($this->fh, min(8192, $left));
                if ($chunk === '') {
                    throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is truncated)');
                }
                $left -= strlen($chunk);
                $piece = @inflate_add($ctx, $chunk, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH);
                if ($piece === false) {
                    throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is damaged)');
                }
                $count($piece);
            }
            if ($e['csize'] === 0) {
                throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is damaged)');
            }
        } else {
            throw new WorkbookError('workbook_invalid', 'the zip entry ' . $e['name'] . ' uses an unsupported compression method');
        }
        if (strlen($out) !== $e['usize'] || hexdec(hash_final($crc)) !== $e['crc']) {
            throw new WorkbookError('workbook_invalid', 'the zip cannot be read (' . $e['name'] . ' is damaged)');
        }
        return $out;
    }
}
