<?php

declare(strict_types=1);

namespace Obx\Node\Support;

/**
 * Logging to PHP's error log (the host's error log on shared hosting), to a file, or to a
 * stream such as php://stderr (Docker, Cloud Run). Two formats:
 *   text  "[obx ERROR] message (request abc123)"      (default, readable in a hosting panel)
 *   json  one JSON object per line: ts, level, msg, request_id and any extra fields
 *         (OBX_LOG_FORMAT=json; the Docker image uses it)
 * Messages are scrubbed of anything that looks like a secret.
 */
final class Log
{
    /** Request id of the request being handled, added to every line. */
    public ?string $requestId = null;
    /** @var list<string> captured lines (tests) */
    public array $captured = [];
    public bool $capture = false;

    public function __construct(private ?string $file = null, private string $format = 'text')
    {
        $this->format = $format === 'json' ? 'json' : 'text';
    }

    public function format(): string
    {
        return $this->format;
    }

    /** @param array<string, mixed> $fields */
    public function info(string $message, array $fields = []): void
    {
        $this->write('INFO', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function notice(string $message, array $fields = []): void
    {
        $this->write('NOTICE', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function warning(string $message, array $fields = []): void
    {
        $this->write('WARNING', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    public function error(string $message, ?\Throwable $e = null, array $fields = []): void
    {
        if ($e !== null) {
            $fields['exception'] = get_class($e);
            $fields['at'] = basename($e->getFile()) . ':' . $e->getLine();
            if ($this->format === 'text') {
                $message .= ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
                unset($fields['exception'], $fields['at']);
            }
        }
        $this->write('ERROR', $message, $fields);
    }

    public static function scrub(string $message): string
    {
        $message = (string) preg_replace('/\b(obx[icsrt]_|whsec_)[A-Za-z0-9_\-]+/', '$1…', $message);
        $message = (string) preg_replace('/(Bearer|Basic)\s+\S+/i', '$1 …', $message);
        return (string) preg_replace('/([?&](sig|exp|act|token|key|X-Amz-Signature|X-Amz-Credential)=)[^&\s]+/i', '$1…', $message);
    }

    /** @param array<string, mixed> $fields */
    private function write(string $level, string $message, array $fields): void
    {
        $message = self::scrub($message);
        if ($this->format === 'json') {
            $record = ['ts' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)), 'level' => strtolower($level), 'msg' => $message];
            if ($this->requestId !== null) {
                $record['request_id'] = $this->requestId;
            }
            foreach ($fields as $k => $v) {
                $record[$k] = is_string($v) ? self::scrub($v) : $v;
            }
            $line = (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $extra = '';
            foreach ($fields as $k => $v) {
                $extra .= ' ' . $k . '=' . (is_scalar($v) || $v === null ? self::scrub(var_export($v, true)) : self::scrub((string) json_encode($v)));
            }
            $line = '[obx ' . $level . '] ' . $message . $extra . ($this->requestId !== null ? ' (request ' . $this->requestId . ')' : '');
        }
        if ($this->capture) {
            $this->captured[] = $line;
            return;
        }
        if ($this->file === null || $this->file === '') {
            error_log($line);
            return;
        }
        if (str_starts_with($this->file, 'php://')) {
            $fh = @fopen($this->file, 'ab');
            if ($fh !== false) {
                @fwrite($fh, $line . "\n");
                @fclose($fh);
            }
            return;
        }
        @file_put_contents($this->file, ($this->format === 'text' ? gmdate('c') . ' ' : '') . $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
