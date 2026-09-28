<?php

declare(strict_types=1);

namespace Obx\Node\Zip;

/**
 * Streaming zip writer (method "store": images are already compressed). Writes to any stream,
 * so downloads start immediately and need no temporary copy. Zip64 records are added when
 * sizes or offsets pass 4 GiB. File names are UTF-8 (general purpose bit 11).
 */
final class ZipWriter
{
    /** @var resource */
    private $out;
    private int $offset = 0;
    /** @var list<array{name: string, crc: int, size: int, offset: int, time: int, date: int}> */
    private array $entries = [];
    /** @var array<string, int> */
    private array $names = [];

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->out = $stream;
    }

    /** Makes a name unique inside the archive ("a.jpg" -> "a (2).jpg"). */
    public function uniqueName(string $name): string
    {
        $name = self::sanitize($name);
        if (!isset($this->names[$name])) {
            $this->names[$name] = 1;
            return $name;
        }
        $n = ++$this->names[$name];
        $dot = strrpos($name, '.');
        $slash = strrpos($name, '/');
        if ($dot !== false && ($slash === false || $dot > $slash)) {
            $candidate = substr($name, 0, $dot) . ' (' . $n . ')' . substr($name, $dot);
        } else {
            $candidate = $name . ' (' . $n . ')';
        }
        return $this->uniqueName($candidate);
    }

    /** Entry paths: "/" separators, no "..", no absolute paths, no control characters. */
    public static function sanitize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            $seg = (string) preg_replace('/[\x00-\x1F\x7F:*?"<>|]/', '_', $seg);
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $parts[] = $seg;
        }
        return $parts === [] ? 'file' : implode('/', $parts);
    }

    public function addFile(string $name, string $path): void
    {
        $crc = (int) hexdec((string) hash_file('crc32b', $path));
        $size = (int) filesize($path);
        $this->begin($name, $crc, $size);
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            $chunk = fread($fh, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->write($chunk);
        }
        fclose($fh);
    }

    public function addString(string $name, string $data): void
    {
        $this->begin($name, (int) hexdec(hash('crc32b', $data)), strlen($data));
        $this->write($data);
    }

    private function begin(string $name, int $crc, int $size): void
    {
        [$time, $date] = self::dosTime(time());
        $zip64 = $size >= 0xFFFFFFFF;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $size, $size) : '';
        $header = pack('VvvvvvVVVvv',
            0x04034b50,
            $zip64 ? 45 : 20,
            0x0800,
            0,
            $time,
            $date,
            $crc,
            $zip64 ? 0xFFFFFFFF : $size,
            $zip64 ? 0xFFFFFFFF : $size,
            strlen($name),
            strlen($extra)
        );
        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset, 'time' => $time, 'date' => $date];
        $this->write($header . $name . $extra);
    }

    private function write(string $data): void
    {
        fwrite($this->out, $data);
        $this->offset += strlen($data);
    }

    public function finish(): void
    {
        $cdStart = $this->offset;
        foreach ($this->entries as $e) {
            $needSize = $e['size'] >= 0xFFFFFFFF;
            $needOffset = $e['offset'] >= 0xFFFFFFFF;
            $extra = '';
            if ($needSize || $needOffset) {
                $fields = ($needSize ? pack('PP', $e['size'], $e['size']) : '') . ($needOffset ? pack('P', $e['offset']) : '');
                $extra = pack('vv', 0x0001, strlen($fields)) . $fields;
            }
            $this->write(pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                0x031E,
                ($needSize || $needOffset) ? 45 : 20,
                0x0800,
                0,
                $e['time'],
                $e['date'],
                $e['crc'],
                $needSize ? 0xFFFFFFFF : $e['size'],
                $needSize ? 0xFFFFFFFF : $e['size'],
                strlen($e['name']),
                strlen($extra),
                0,
                0,
                0,
                0100644 << 16,
                $needOffset ? 0xFFFFFFFF : $e['offset']
            ) . $e['name'] . $extra);
        }
        $cdSize = $this->offset - $cdStart;
        $count = count($this->entries);
        if ($count >= 0xFFFF || $cdStart >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF) {
            $zip64End = $this->offset;
            $this->write(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cdSize, $cdStart));
            $this->write(pack('VVPV', 0x07064b50, 0, $zip64End, 1));
            $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
            return;
        }
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdSize, $cdStart, 0));
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function bytes(): int
    {
        return $this->offset;
    }

    /** @return array{0: int, 1: int} */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];
        }
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
