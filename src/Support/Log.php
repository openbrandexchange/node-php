<?php

declare(strict_types=1);

namespace Obx\Node\Support;

/**
 * Logging to PHP's error log (stderr in Docker / Cloud Run, the host's error log on shared
 * hosting) or to a file. Messages are scrubbed of anything that looks like a secret.
 */
final class Log
{
    public function __construct(private ?string $file = null)
    {
    }

    public function notice(string $message): void
    {
        $this->write('NOTICE', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function error(string $message, ?\Throwable $e = null): void
    {
        if ($e !== null) {
            $message .= ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }
        $this->write('ERROR', $message);
    }

    public static function scrub(string $message): string
    {
        $message = (string) preg_replace('/\b(obx[icsrt]_|whsec_)[A-Za-z0-9_\-]+/', '$1…', $message);
        $message = (string) preg_replace('/(Bearer|Basic)\s+\S+/i', '$1 …', $message);
        return (string) preg_replace('/([?&](sig|exp|act|token|X-Amz-Signature)=)[^&\s]+/i', '$1…', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = '[obx ' . $level . '] ' . self::scrub($message);
        if ($this->file !== null && $this->file !== '') {
            @file_put_contents($this->file, gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
            return;
        }
        error_log($line);
    }
}
