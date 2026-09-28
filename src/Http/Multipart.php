<?php

declare(strict_types=1);

namespace Obx\Node\Http;

/**
 * Streaming multipart/form-data parser. Used when PHP's own parsing is switched off
 * (enable_post_data_reading = Off, set in public/.user.ini and .htaccess), because PHP keeps
 * only the last of several parts with the same name (e.g. repeated "images") and caps the
 * number of files (max_file_uploads). File parts are streamed to temporary files.
 */
final class Multipart
{
    public const MAX_PARTS = 20000;
    public const MAX_FIELD_BYTES = 64 * 1024 * 1024;

    /**
     * @param resource $in
     * @return array{0: array<string, mixed>, 1: array<string, list<array{name: string, tmp: string, size: int, type: string}>>}
     */
    public static function parse($in, string $contentType, string $tmpDir, int $maxTotal): array
    {
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m)) {
            throw new ApiError('invalid_request', 'The multipart request has no boundary.');
        }
        $boundary = '--' . ($m[1] !== '' ? $m[1] : $m[2]);
        $delim = "\r\n" . $boundary;
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }
        $fields = [];
        $files = [];
        $buf = '';
        $total = 0;
        $read = static function () use ($in, &$buf, &$total, $maxTotal): bool {
            if (feof($in)) {
                return false;
            }
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                return !feof($in);
            }
            $total += strlen($chunk);
            if ($total > $maxTotal) {
                throw new ApiError('payload_too_large', 'The upload is larger than ' . $maxTotal . ' bytes.');
            }
            $buf .= $chunk;
            return true;
        };
        // Skip the preamble up to the first boundary line.
        while (($pos = strpos($buf, $boundary)) === false) {
            if (!$read()) {
                throw new ApiError('invalid_request', 'The multipart body is empty or malformed.');
            }
        }
        $buf = substr($buf, $pos + strlen($boundary));
        $parts = 0;
        while (true) {
            while (strlen($buf) < 2 && $read()) {
            }
            if (str_starts_with($buf, '--')) {
                break; // closing boundary
            }
            if (++$parts > self::MAX_PARTS) {
                throw new ApiError('limit_exceeded', 'Too many parts in one upload.');
            }
            // Part headers.
            while (($hend = strpos($buf, "\r\n\r\n")) === false) {
                if (!$read()) {
                    throw new ApiError('invalid_request', 'A multipart part has no headers.');
                }
            }
            $rawHeaders = substr($buf, 0, $hend);
            $buf = substr($buf, $hend + 4);
            $name = null;
            $filename = null;
            $type = 'application/octet-stream';
            foreach (preg_split('/\r\n/', ltrim($rawHeaders, "\r\n")) as $line) {
                if (stripos($line, 'content-disposition:') === 0) {
                    if (preg_match('/\bname="([^"]*)"/i', $line, $nm)) {
                        $name = $nm[1];
                    }
                    if (preg_match('/\bfilename\*=UTF-8\'\'([^;]+)/i', $line, $fm)) {
                        $filename = rawurldecode($fm[1]);
                    } elseif (preg_match('/\bfilename="([^"]*)"/i', $line, $fm)) {
                        $filename = $fm[1];
                    }
                } elseif (stripos($line, 'content-type:') === 0) {
                    $type = trim(substr($line, 13));
                }
            }
            $isFile = $filename !== null;
            $fh = null;
            $tmp = null;
            $value = '';
            $size = 0;
            if ($isFile) {
                $tmp = (string) tempnam($tmpDir, 'mp');
                $fh = fopen($tmp, 'wb');
            }
            // Body up to the next delimiter.
            while (true) {
                $pos = strpos($buf, $delim);
                if ($pos !== false) {
                    $data = substr($buf, 0, $pos);
                    $buf = substr($buf, $pos + strlen($delim));
                    $last = true;
                } else {
                    // Keep enough bytes to recognise a delimiter split across chunks.
                    $keep = strlen($delim) - 1;
                    $data = strlen($buf) > $keep ? substr($buf, 0, -$keep) : '';
                    $buf = strlen($buf) > $keep ? substr($buf, -$keep) : $buf;
                    $last = false;
                }
                $size += strlen($data);
                if ($isFile) {
                    fwrite($fh, $data);
                } else {
                    if ($size > self::MAX_FIELD_BYTES) {
                        throw new ApiError('payload_too_large', 'A form field is too large.');
                    }
                    $value .= $data;
                }
                if ($last) {
                    break;
                }
                if (!$read()) {
                    if ($fh !== null) {
                        fclose($fh);
                        @unlink((string) $tmp);
                    }
                    throw new ApiError('invalid_request', 'The multipart body ended unexpectedly.');
                }
            }
            if ($name === null || $name === '') {
                if ($fh !== null) {
                    fclose($fh);
                    @unlink((string) $tmp);
                }
                continue;
            }
            $key = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
            if ($isFile) {
                fclose($fh);
                if ($size === 0 && $filename === '') {
                    @unlink((string) $tmp);
                    continue;
                }
                $files[$key][] = ['name' => (string) $filename, 'tmp' => (string) $tmp, 'size' => $size, 'type' => $type];
            } elseif (isset($fields[$key])) {
                $fields[$key] = array_merge((array) $fields[$key], [$value]);
            } else {
                $fields[$key] = $value;
            }
        }
        return [$fields, $files];
    }
}
