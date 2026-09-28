<?php

declare(strict_types=1);

namespace Obx\Node\Mail;

/**
 * Minimal SMTP client: EHLO, STARTTLS or implicit TLS, AUTH LOGIN, one recipient per message.
 */
final class Smtp
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private ?string $user,
        private ?string $password,
    ) {
    }

    public function send(string $from, string $to, string $message): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new \RuntimeException('SMTP connection failed: ' . $errstr);
        }
        $this->sock = $sock;
        stream_set_timeout($sock, 30);
        try {
            $this->expect(220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->cmd($ehlo, 250);
            if ($this->encryption === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed.');
                }
                $this->cmd($ehlo, 250);
            }
            if ($this->user !== null && $this->user !== '') {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($this->user), 334);
                $this->cmd(base64_encode((string) $this->password), 235);
            }
            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', 354);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message));
            fwrite($sock, $data . "\r\n.\r\n");
            $this->expect(250);
            $this->cmd('QUIT', 221);
        } finally {
            fclose($sock);
            $this->sock = null;
        }
    }

    /** @param int|list<int> $code */
    private function cmd(string $line, int|array $code): void
    {
        fwrite($this->sock, $line . "\r\n");
        $this->expect($code);
    }

    /** @param int|list<int> $code */
    private function expect(int|array $code): void
    {
        $codes = (array) $code;
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $got = (int) substr($resp, 0, 3);
        if (!in_array($got, $codes, true)) {
            throw new \RuntimeException('SMTP server answered ' . $got . '.');
        }
    }
}
