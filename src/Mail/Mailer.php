<?php

declare(strict_types=1);

namespace Obx\Node\Mail;

/**
 * Sends e-mail with PHP mail() (what shared hosting offers), SMTP, or not at all.
 * Transport "memory" keeps messages in $sent (tests); "none" disables e-mail (the admin then
 * copies invitation links by hand).
 */
final class Mailer
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    public array $sent = [];

    /** @var (callable(): string)|null sender name, e.g. the brand's display name */
    public $fromNameResolver = null;

    /** @param array<string, mixed> $smtp */
    public function __construct(
        private string $transport,
        private string $from,
        private string $fromName,
        private array $smtp = [],
    ) {
    }

    public function configured(): bool
    {
        return $this->transport !== 'none' && $this->from !== '';
    }

    public function send(string $to, string $subject, string $text, string $html): bool
    {
        if (!$this->configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        if ($this->transport === 'memory') {
            $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
            return true;
        }
        $boundary = 'obx' . bin2hex(random_bytes(12));
        $name = $this->fromNameResolver !== null ? (string) ($this->fromNameResolver)() : $this->fromName;
        $name = str_replace(["\r", "\n"], '', $name);
        $display = preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . addcslashes($name, '"\\') . '"' : self::encodeHeader($name);
        $fromHeader = $display . ' <' . $this->from . '>';
        $headers = [
            'From' => $fromHeader,
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted' => 'auto-generated',
        ];
        $body = '--' . $boundary . "\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($text)) .
            '--' . $boundary . "\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($html)) .
            '--' . $boundary . "--\r\n";
        try {
            if ($this->transport === 'smtp') {
                $smtp = new Smtp(
                    (string) ($this->smtp['host'] ?? ''),
                    (int) ($this->smtp['port'] ?? 587),
                    (string) ($this->smtp['encryption'] ?? 'tls'),
                    $this->smtp['user'] ?? null,
                    $this->smtp['password'] ?? null,
                );
                $all = $headers + [
                    'To' => $to,
                    'Subject' => self::encodeHeader($subject),
                    'Date' => date('r'),
                    'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $this->from)[1] ?? 'obx.local') . '>',
                ];
                $raw = '';
                foreach ($all as $k => $v) {
                    $raw .= $k . ': ' . $v . "\r\n";
                }
                $smtp->send($this->from, $to, $raw . "\r\n" . $body);
                return true;
            }
            $h = '';
            foreach ($headers as $k => $v) {
                $h .= $k . ': ' . $v . "\r\n";
            }
            return @mail($to, self::encodeHeader($subject), $body, rtrim($h), '-f' . $this->from);
        } catch (\Throwable $e) {
            error_log('obx: e-mail to a recipient failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
