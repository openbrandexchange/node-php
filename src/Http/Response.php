<?php

declare(strict_types=1);

namespace Obx\Node\Http;

use Obx\Node\Support\Json;

/**
 * An HTTP response: a string body, a file (optionally a byte range), or a streaming callback.
 */
final class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    public array $cookies = [];
    public string $body = '';
    public ?string $file = null;
    public int $fileOffset = 0;
    public ?int $fileLength = null;
    /** @var (callable(): void)|null */
    public $stream = null;
    /** @var list<string> temp files to delete after sending */
    public array $cleanup = [];

    /** @param array<string, string> $headers */
    public function __construct(public int $status = 200, string $body = '', array $headers = [])
    {
        $this->body = $body;
        foreach ($headers as $k => $v) {
            $this->header($k, $v);
        }
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self($status, Json::encode($data));
        $r->header('Content-Type', 'application/json; charset=utf-8');
        return $r;
    }

    public static function problem(ApiError $e, ?string $instance = null): self
    {
        $r = new self($e->status, Json::encode($e->problem($instance)));
        $r->header('Content-Type', 'application/problem+json; charset=utf-8');
        foreach ($e->headers as $k => $v) {
            $r->header($k, $v);
        }
        if ($e->retryAfter !== null) {
            $r->header('Retry-After', (string) $e->retryAfter);
        }
        return $r;
    }

    public static function empty(int $status = 204): self
    {
        return new self($status);
    }

    public static function text(string $text, string $type = 'text/plain; charset=utf-8', int $status = 200): self
    {
        $r = new self($status, $text);
        $r->header('Content-Type', $type);
        return $r;
    }

    public static function redirect(string $location, int $status = 302): self
    {
        $r = new self($status);
        $r->header('Location', $location);
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[self::canonical($name)] = $value;
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[self::canonical($name)] ?? null;
    }

    public function cookie(string $name, string $value, int $maxAge, bool $secure, string $path = '/', bool $httpOnly = true): self
    {
        $c = rawurlencode($name) . '=' . rawurlencode($value) . '; Path=' . $path . '; SameSite=Lax';
        if ($maxAge >= 0) {
            $c .= '; Max-Age=' . $maxAge;
        }
        if ($maxAge === 0) {
            $c .= '; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($httpOnly) {
            $c .= '; HttpOnly';
        }
        if ($secure) {
            $c .= '; Secure';
        }
        $this->cookies[] = $c;
        return $this;
    }

    private static function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }

    /** Body bytes for tests (reads files / runs streams into a buffer). */
    public function content(): string
    {
        if ($this->file !== null) {
            $fh = fopen($this->file, 'rb');
            fseek($fh, $this->fileOffset);
            $data = $this->fileLength === null ? stream_get_contents($fh) : ($this->fileLength > 0 ? fread($fh, $this->fileLength) : '');
            fclose($fh);
            return (string) $data;
        }
        if ($this->stream !== null) {
            ob_start();
            ($this->stream)();
            return (string) ob_get_clean();
        }
        return $this->body;
    }

    public function jsonBody(): mixed
    {
        return Json::decode($this->content());
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->cookies as $c) {
                header('Set-Cookie: ' . $c, false);
            }
        }
        if ($this->file !== null) {
            $fh = fopen($this->file, 'rb');
            if ($fh !== false) {
                fseek($fh, $this->fileOffset);
                $left = $this->fileLength ?? (filesize($this->file) - $this->fileOffset);
                while ($left > 0 && !feof($fh)) {
                    $chunk = fread($fh, (int) min(1024 * 1024, $left));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $left -= strlen($chunk);
                    if (connection_aborted()) {
                        break;
                    }
                }
                fclose($fh);
            }
        } elseif ($this->stream !== null) {
            ($this->stream)();
        } else {
            echo $this->body;
        }
        foreach ($this->cleanup as $f) {
            @unlink($f);
        }
    }
}
