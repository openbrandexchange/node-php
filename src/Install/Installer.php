<?php

declare(strict_types=1);

namespace Obx\Node\Install;

use Obx\Node\Config;
use Obx\Node\Db\Db;
use Obx\Node\Db\Schema;

/**
 * Web installer for ordinary web hosting: checks the server, asks for the database and the
 * public address, writes config/config.php and hands over to the setup wizard.
 *
 * Only reachable while config/config.php does not exist. To prove that the visitor is the
 * person who uploaded the files, it asks for the code in data/SETUP-CODE.txt (readable over
 * FTP, never over the web).
 */
final class Installer
{
    public function __construct(private string $root, private Config $config)
    {
    }

    /** @return list<array{label: string, ok: bool, required: bool, hint: string}> */
    public function checks(): array
    {
        $ini = static function (string $k): int {
            $v = trim((string) ini_get($k));
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $upload = min($ini('upload_max_filesize'), $ini('post_max_size') ?: PHP_INT_MAX);
        $data = $this->config->dataDir();
        return [
            ['label' => 'PHP 8.2 or newer (you have ' . PHP_VERSION . ')', 'ok' => PHP_VERSION_ID >= 80200, 'required' => true, 'hint' => 'Choose a newer PHP version in your hosting control panel.'],
            ['label' => 'PDO with SQLite or MySQL', 'ok' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), 'required' => true, 'hint' => 'Enable pdo_sqlite or pdo_mysql.'],
            ['label' => 'OpenSSL', 'ok' => extension_loaded('openssl'), 'required' => true, 'hint' => 'Enable the openssl extension.'],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true, 'hint' => 'Enable the mbstring extension.'],
            ['label' => 'Folder data/ is writable', 'ok' => is_dir($data) ? is_writable($data) : is_writable(dirname($data)), 'required' => true, 'hint' => 'Give the web server write access to the data folder (chmod 775).'],
            ['label' => 'Folder config/ is writable', 'ok' => is_writable(dirname($this->config->configFile())), 'required' => true, 'hint' => 'Give the web server write access to the config folder for this step.'],
            ['label' => 'GD with JPEG (image versions made on the server)', 'ok' => \Obx\Node\Images\Renderer::available(), 'required' => false, 'hint' => 'Without GD the web interface makes the web and thumbnail versions in the browser.'],
            ['label' => 'zip (zip uploads and imports)', 'ok' => class_exists(\ZipArchive::class), 'required' => false, 'hint' => 'Without it, upload CSV and images separately.'],
            ['label' => 'curl (scheduled imports and webhooks)', 'ok' => function_exists('curl_init'), 'required' => false, 'hint' => 'Enable curl to fetch imports from a web address or bucket.'],
            ['label' => 'Uploads up to 100 MB (now ' . round($upload / 1048576) . ' MB)', 'ok' => $upload >= 100 * 1048576, 'required' => false, 'hint' => 'Raise upload_max_filesize and post_max_size (public/.user.ini does this on most hosts).'],
        ];
    }

    private function code(): string
    {
        $file = $this->config->dataDir() . '/SETUP-CODE.txt';
        if (is_file($file) && preg_match('/([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})/', (string) file_get_contents($file), $m)) {
            return $m[1];
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        @mkdir($this->config->dataDir(), 0770, true);
        @file_put_contents($file, "Open Brand Exchange setup code: " . $code . "\nEnter it in the installer and in the setup wizard. This file is deleted after setup.\n");
        @chmod($file, 0600);
        error_log('[obx NOTICE] Setup code: ' . $code . ' (also in data/SETUP-CODE.txt)');
        return $code;
    }

    public function serve(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        header('Referrer-Policy: same-origin');
        if ($this->config->installed()) {
            echo $this->page('Already installed', '<p>This portal is installed. Open the <a href="./">portal</a>.</p>'
                . '<p>To start over, remove <code>config/config.php</code> and the <code>data</code> folder on the server.</p>');
            return;
        }
        $errors = [];
        $done = null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $code = $this->code();
        $post = $_POST;
        if ($method === 'POST' && $post === []) {
            // Form parsing may be switched off (enable_post_data_reading): read the body.
            parse_str((string) file_get_contents('php://input', false, null, 0, 65536), $post);
        }
        if ($method === 'POST') {
            [$errors, $done] = $this->install($post, $code);
        }
        echo $done ?? $this->form($post, $errors);
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: list<string>, 1: ?string}
     */
    public function install(array $in, string $code): array
    {
        $errors = [];
        foreach ($this->checks() as $c) {
            if ($c['required'] && !$c['ok']) {
                $errors[] = $c['label'] . ': ' . $c['hint'];
            }
        }
        $given = strtoupper(trim((string) ($in['setup_code'] ?? '')));
        if (!hash_equals($code, $given)) {
            $errors[] = 'The setup code is not correct. Open data/SETUP-CODE.txt with your FTP program or file manager.';
        }
        $url = rtrim(trim((string) ($in['public_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s/]+#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Enter the web address of the portal, e.g. https://brands.example.com.';
        }
        $dbKind = (string) ($in['db'] ?? 'sqlite');
        $dsn = 'sqlite:' . $this->config->dataDir() . '/obx.sqlite';
        $user = null;
        $pass = null;
        if ($dbKind === 'mysql') {
            $host = trim((string) ($in['db_host'] ?? 'localhost'));
            $port = (int) ($in['db_port'] ?? 3306) ?: 3306;
            $name = trim((string) ($in['db_name'] ?? ''));
            if ($host === '' || $name === '' || !preg_match('/^[A-Za-z0-9._\-]+$/', $host) || !preg_match('/^[A-Za-z0-9_\-$]+$/', $name)) {
                $errors[] = 'Enter the MySQL host and database name from your hosting control panel.';
            }
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
            $user = (string) ($in['db_user'] ?? '');
            $pass = (string) ($in['db_password'] ?? '');
        }
        $from = trim((string) ($in['mail_from'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The sender e-mail address is not valid.';
        }
        $smtpHost = trim((string) ($in['smtp_host'] ?? ''));
        if ($errors !== []) {
            return [$errors, null];
        }
        try {
            $db = new Db($dsn, $user, $pass);
            Schema::migrate($db);
        } catch (\Throwable $e) {
            return [['The database connection failed: ' . htmlspecialchars(\Obx\Node\Support\Log::scrub($e->getMessage())) . '.'], null];
        }
        $values = [
            'public_url' => $url,
            'secret_key' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='),
            'setup_code' => $code,
            'db_dsn' => $dsn,
            'db_user' => $user,
            'db_password' => $pass,
            'mail_transport' => $from === '' ? 'none' : ($smtpHost !== '' ? 'smtp' : 'mail'),
            'mail_from' => $from !== '' ? $from : null,
            'smtp_host' => $smtpHost !== '' ? $smtpHost : null,
            'smtp_port' => (int) ($in['smtp_port'] ?? 587) ?: 587,
            'smtp_user' => trim((string) ($in['smtp_user'] ?? '')) ?: null,
            'smtp_password' => (string) ($in['smtp_password'] ?? '') ?: null,
            'smtp_encryption' => in_array($in['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) ($in['smtp_encryption'] ?? 'tls') : 'tls',
        ];
        $php = "<?php\n// Written by the Open Brand Exchange installer on " . gmdate('Y-m-d H:i') . " UTC.\n// Keep this file private: it holds the database password and the secret key.\n\nreturn " . var_export($values, true) . ";\n";
        $target = $this->config->configFile();
        if (@file_put_contents($target . '.tmp', $php) === false || !@rename($target . '.tmp', $target)) {
            return [['config/config.php could not be written. Make the config folder writable and try again.'], null];
        }
        @chmod($target, 0640);
        $cronUrl = $url . '/cron?key=' . (new \Obx\Node\Security\Crypto($values['secret_key']))->derive('cron', 32);
        $e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<p class="ok">The portal is installed.</p>'
            . '<h2>Next: create your admin account</h2>'
            . '<p>Your setup code is <strong class="code">' . $e($code) . '</strong>. The setup wizard asks for it once.</p>'
            . '<p><a class="button" href="' . $e($url) . '/">Open the setup wizard</a></p>'
            . '<h2>Background work</h2>'
            . '<p>Imports, zip downloads and e-mails run in the background. Add one of these in your hosting control panel (Cron jobs), every minute or every 5 minutes:</p>'
            . '<pre>php ' . $e($this->root) . '/bin/obx cron</pre>'
            . '<p>or, if your host can only call a web address:</p><pre>' . $e($cronUrl) . '</pre>'
            . '<p>Without a cron job the portal still works: it catches up whenever an admin uses it.</p>';
        return [[], $this->page('Installed', $html)];
    }

    /** @param array<string, mixed> $in @param list<string> $errors */
    private function form(array $in, array $errors): string
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = str_ends_with($script, '/index.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
        $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($base !== '' && !str_starts_with($reqPath, $base)) {
            $base = str_ends_with($base, '/public') ? substr($base, 0, -7) : '';
        }
        $guess = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
        $checks = '';
        $blocking = false;
        foreach ($this->checks() as $c) {
            $blocking = $blocking || ($c['required'] && !$c['ok']);
            $checks .= '<li class="' . ($c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn')) . '">' . $e($c['label'])
                . ($c['ok'] ? '' : ' <small>' . $e($c['hint']) . '</small>') . '</li>';
        }
        $err = '';
        foreach ($errors as $m) {
            $err .= '<li>' . $m . '</li>';
        }
        $v = static fn (string $k, string $d = '') => $e($in[$k] ?? $d);
        $mysql = ($in['db'] ?? '') === 'mysql';
        $html = ($err !== '' ? '<ul class="errors">' . $err . '</ul>' : '')
            . '<h2>1. Server check</h2><ul class="checks">' . $checks . '</ul>'
            . '<form method="post" autocomplete="off">'
            . '<h2>2. Setup code</h2><p>We wrote a code to <code>data/SETUP-CODE.txt</code> on your server. Open it with your FTP program or the file manager of your host. This proves you are the owner.</p>'
            . '<label>Setup code<input name="setup_code" required value="' . $v('setup_code') . '" placeholder="ABCD-EFGH-JKLM"></label>'
            . '<h2>3. Web address</h2><label>Address of this portal<input name="public_url" required value="' . $v('public_url', $guess) . '"></label>'
            . '<h2>4. Database</h2>'
            . '<label class="radio"><input type="radio" name="db" value="sqlite"' . ($mysql ? '' : ' checked') . '> Simple: a database file in the data folder (fine for most brands)</label>'
            . '<label class="radio"><input type="radio" name="db" value="mysql"' . ($mysql ? ' checked' : '') . '> MySQL / MariaDB from my hosting account</label>'
            . '<fieldset><legend>MySQL (only when chosen above)</legend>'
            . '<label>Host<input name="db_host" value="' . $v('db_host', 'localhost') . '"></label>'
            . '<label>Port<input name="db_port" value="' . $v('db_port', '3306') . '"></label>'
            . '<label>Database name<input name="db_name" value="' . $v('db_name') . '"></label>'
            . '<label>User<input name="db_user" value="' . $v('db_user') . '"></label>'
            . '<label>Password<input type="password" name="db_password" value=""></label></fieldset>'
            . '<h2>5. E-mail (optional)</h2><p>Used for invitations and "your download is ready". Leave empty to copy invitation links yourself.</p>'
            . '<label>Sender address<input name="mail_from" type="email" value="' . $v('mail_from') . '" placeholder="portal@yourbrand.com"></label>'
            . '<fieldset><legend>SMTP server (optional; otherwise PHP mail() is used)</legend>'
            . '<label>SMTP host<input name="smtp_host" value="' . $v('smtp_host') . '"></label>'
            . '<label>Port<input name="smtp_port" value="' . $v('smtp_port', '587') . '"></label>'
            . '<label>User<input name="smtp_user" value="' . $v('smtp_user') . '"></label>'
            . '<label>Password<input type="password" name="smtp_password" value=""></label>'
            . '<label>Encryption<select name="smtp_encryption"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL (465)</option><option value="none">None</option></select></label></fieldset>'
            . '<p><button type="submit"' . ($blocking ? ' disabled' : '') . '>Install</button></p></form>';
        return $this->page('Install your brand portal', $html);
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . ' · Open Brand Exchange</title><style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:44rem;margin:2rem auto;padding:0 1rem;color:#1d1d1b;line-height:1.5}'
            . 'h1{font-size:1.6rem}h2{font-size:1.1rem;margin-top:2rem}label{display:block;margin:.6rem 0}input,select{display:block;width:100%;padding:.5rem;margin-top:.2rem;border:1px solid #bbb;border-radius:6px;font:inherit;box-sizing:border-box}'
            . 'label.radio input{display:inline;width:auto;margin-right:.4rem}fieldset{border:1px solid #ddd;border-radius:8px;margin:.8rem 0}'
            . 'button,.button{background:#2f5d62;color:#fff;border:0;border-radius:8px;padding:.7rem 1.2rem;font:inherit;font-weight:600;text-decoration:none;display:inline-block;cursor:pointer}button[disabled]{opacity:.5}'
            . '.checks{list-style:none;padding:0}.checks li{padding:.2rem 0}.checks li:before{display:inline-block;width:1.5rem}.ok:before{content:"✓";color:#2e7d32}.bad:before{content:"✗";color:#c62828}.warn:before{content:"!";color:#b26a00}'
            . '.errors{background:#fdecea;border-radius:8px;padding:1rem 1rem 1rem 2rem}.code{font-family:ui-monospace,monospace;font-size:1.2rem}pre{background:#f4f4f2;padding:.8rem;border-radius:6px;overflow:auto}p.ok{font-weight:600}small{color:#666}'
            . '</style></head><body><h1>' . htmlspecialchars($title) . '</h1>' . $body
            . '<p><small>Works with Open Brand Exchange · ' . Config::SOFTWARE . ' ' . Config::VERSION . '</small></p></body></html>';
    }
}
