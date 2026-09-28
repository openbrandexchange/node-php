<?php

declare(strict_types=1);

namespace Obx\Node\Install;

use Obx\Node\Config;
use Obx\Node\Db\Db;
use Obx\Node\Db\Schema;
use Obx\Node\Support\Lang;

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
    /** @var array<string, string> */
    private array $text = [];
    private string $lang = 'en';

    /**
     * @param ?bool $otherUser whether PHP runs as another user than the owner of the uploaded
     *                         files; null = detect (see runsAsOtherUser)
     */
    public function __construct(private string $root, private Config $config, private ?bool $otherUser = null)
    {
        $this->useLanguage('en');
    }

    /**
     * True on hosts where PHP runs as the web server's own user (Apache with mod_php) instead
     * of as the FTP account that uploaded the files. There, files the portal writes belong to
     * the web server: the FTP account can only read them when they are world-readable, and a
     * cron job (which runs as the FTP account) cannot use the command line.
     */
    public function runsAsOtherUser(): bool
    {
        if ($this->otherUser !== null) {
            return $this->otherUser;
        }
        if (!function_exists('posix_geteuid')) {
            return false;
        }
        $owner = @fileowner($this->root . '/src');
        return $owner !== false && $owner !== posix_geteuid();
    }

    private function dataWritable(): bool
    {
        $data = $this->config->dataDir();
        return is_dir($data) ? is_writable($data) : is_dir(dirname($data)) && is_writable(dirname($data));
    }

    /** Chooses the installer's language (one of the 25; English for anything else). */
    public function useLanguage(?string $lang): void
    {
        $this->lang = Lang::ui($lang);
        $dir = $this->root . '/resources/installer';
        $en = json_decode((string) @file_get_contents($dir . '/en.json'), true);
        $local = $this->lang !== 'en' ? json_decode((string) @file_get_contents($dir . '/' . $this->lang . '.json'), true) : [];
        $this->text = array_replace(is_array($en) ? $en : [], is_array($local) ? $local : []);
    }

    public function language(): string
    {
        return $this->lang;
    }

    /** @param array<string, string> $vars */
    public function t(string $key, array $vars = []): string
    {
        $s = $this->text[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $s = str_replace('{' . $k . '}', $v, $s);
        }
        return $s;
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
        $ext = fn (string $name): string => $this->t('hint_ext', ['ext' => $name]);
        return [
            // @phpstan-ignore greaterOrEqual.alwaysTrue (checked at run time on the host)
            ['label' => $this->t('chk_php', ['version' => PHP_VERSION]), 'ok' => PHP_VERSION_ID >= 80200, 'required' => true, 'hint' => $this->t('hint_php')],
            ['label' => $this->t('chk_pdo'), 'ok' => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), 'required' => true, 'hint' => $ext('pdo_sqlite')],
            ['label' => $this->t('chk_ext', ['ext' => 'openssl']), 'ok' => extension_loaded('openssl'), 'required' => true, 'hint' => $ext('openssl')],
            ['label' => $this->t('chk_ext', ['ext' => 'mbstring']), 'ok' => extension_loaded('mbstring'), 'required' => true, 'hint' => $ext('mbstring')],
            ['label' => $this->t('chk_folder', ['folder' => 'data/']), 'ok' => $this->dataWritable(), 'required' => true, 'hint' => $this->t('hint_data')],
            ['label' => $this->t('chk_folder', ['folder' => 'config/']), 'ok' => is_writable(dirname($this->config->configFile())), 'required' => true, 'hint' => $this->t('hint_config')],
            ['label' => $this->t('chk_gd'), 'ok' => \Obx\Node\Images\Renderer::available(), 'required' => false, 'hint' => $this->t('hint_gd')],
            ['label' => $this->t('chk_zip'), 'ok' => class_exists(\ZipArchive::class), 'required' => false, 'hint' => $this->t('hint_zip')],
            ['label' => $this->t('chk_curl'), 'ok' => function_exists('curl_init'), 'required' => false, 'hint' => $this->t('hint_curl')],
            ['label' => $this->t('chk_upload', ['mb' => (string) round($upload / 1048576)]), 'ok' => $upload >= 100 * 1048576, 'required' => false, 'hint' => $this->t('hint_upload')],
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
        // Only the owner may read it, unless the owner is the web server and not the FTP account
        // that has to open it: then it must be readable for others (the folder is never served).
        @chmod($file, $this->runsAsOtherUser() ? 0644 : 0600);
        error_log('[obx NOTICE] Setup code: ' . $code . ' (also in data/SETUP-CODE.txt)');
        return $code;
    }

    public function serve(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        header('Referrer-Policy: same-origin');
        $asked = isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : null;
        $this->useLanguage($asked ?? Lang::negotiate(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($this->config->installed()) {
            echo $this->page($this->t('title_already'), '<p>' . $e($this->t('already_text')) . ' <a href="./">' . $e($this->t('already_open')) . '</a></p>'
                . '<p>' . $this->t('already_reset', ['config' => '<code>config/config.php</code>', 'data' => '<code>data</code>']) . '</p>');
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
     * @return array{0: list<string>, 1: ?string} errors (HTML-safe), page
     */
    public function install(array $in, string $code): array
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $errors = [];
        foreach ($this->checks() as $c) {
            if ($c['required'] && !$c['ok']) {
                $errors[] = $e($c['label'] . ': ' . $c['hint']);
            }
        }
        $given = strtoupper(trim((string) ($in['setup_code'] ?? '')));
        if (!hash_equals($code, $given)) {
            $errors[] = $this->t('err_code', ['file' => '<code>data/SETUP-CODE.txt</code>']);
        }
        $url = rtrim(trim((string) ($in['public_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s/]+#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = $e($this->t('err_url'));
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
                $errors[] = $e($this->t('err_mysql'));
            }
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
            $user = (string) ($in['db_user'] ?? '');
            $pass = (string) ($in['db_password'] ?? '');
        }
        $from = trim((string) ($in['mail_from'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $e($this->t('err_from'));
        }
        $smtpHost = trim((string) ($in['smtp_host'] ?? ''));
        if ($errors !== []) {
            return [$errors, null];
        }
        try {
            $db = new Db($dsn, $user, $pass);
            Schema::migrate($db);
        } catch (\Throwable $ex) {
            return [[$e($this->t('err_db', ['error' => \Obx\Node\Support\Log::scrub($ex->getMessage())]))], null];
        }
        $encryption = (string) ($in['smtp_encryption'] ?? 'tls');
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
            'smtp_encryption' => in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
        ];
        $php = "<?php\n// Written by the Open Brand Exchange installer on " . gmdate('Y-m-d H:i') . " UTC.\n// Keep this file private: it holds the database password and the secret key.\n\nreturn " . var_export($values, true) . ";\n";
        $target = $this->config->configFile();
        if (@file_put_contents($target . '.tmp', $php) === false || !@rename($target . '.tmp', $target)) {
            return [[$this->t('err_config', ['file' => '<code>config/config.php</code>'])], null];
        }
        // Private to the web server's user and group, except where that user is not the FTP
        // account: then the owner could not open (or back up, or edit) their own settings.
        @chmod($target, $this->runsAsOtherUser() ? 0644 : 0640);
        $cronUrl = $url . '/cron?key=' . (new \Obx\Node\Security\Crypto($values['secret_key']))->derive('cron', 32);
        $html = '<p class="ok">' . $e($this->t('done_ok')) . '</p>'
            . '<h2>' . $e($this->t('done_next')) . '</h2>'
            . '<p>' . $this->t('done_code', ['code' => '<strong class="code">' . $e($code) . '</strong>']) . '</p>'
            // The code travels in the fragment: the wizard fills it in, and it never reaches a log.
            . '<p><a class="button" href="' . $e($url) . '/setup#code=' . $e($code) . '">' . $e($this->t('done_button')) . '</a></p>'
            . '<h2>' . $e($this->t('done_bg')) . '</h2>'
            . '<p>' . $e($this->t('done_bg_text')) . '</p>'
            . ($this->runsAsOtherUser()
                // A cron job runs as the FTP account, which may not read the files the web
                // server wrote (config.php): only the web address works here.
                ? '<pre>' . $e($cronUrl) . '</pre><p>' . $e($this->t('done_bg_url_only')) . '</p>'
                : '<pre>php ' . $e($this->root) . '/bin/obx cron</pre>'
                    . '<p>' . $e($this->t('done_bg_or')) . '</p><pre>' . $e($cronUrl) . '</pre>')
            . '<p>' . $e($this->t('done_bg_none')) . '</p>';
        return [[], $this->page($this->t('title_installed'), $html)];
    }

    /** @param array<string, mixed> $in @param list<string> $errors HTML-safe */
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
        $enc = (string) ($in['smtp_encryption'] ?? 'tls');
        $opt = static fn (string $value, string $label) => '<option value="' . $value . '"' . ($enc === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
        $field = static fn (string $label, string $input) => '<label>' . $e($label) . $input . '</label>';
        $html = ($err !== '' ? '<ul class="errors" role="alert">' . $err . '</ul>' : '')
            . '<h2>' . $e($this->t('s1')) . '</h2><ul class="checks">' . $checks . '</ul>'
            . '<form method="post" action="?lang=' . $e($this->lang) . '" autocomplete="off">'
            . '<h2>' . $e($this->t('s2')) . '</h2><p>' . $this->t($this->dataWritable() ? 's2_text' : 's2_wait', ['file' => '<code>data/SETUP-CODE.txt</code>']) . '</p>'
            . $field($this->t('code_label'), '<input name="setup_code" required value="' . $v('setup_code') . '" placeholder="ABCD-EFGH-JKLM" autocapitalize="characters">')
            . '<h2>' . $e($this->t('s3')) . '</h2>' . $field($this->t('url_label'), '<input name="public_url" type="url" required value="' . $v('public_url', $guess) . '">')
            . '<h2>' . $e($this->t('s4')) . '</h2>'
            . '<label class="radio"><input type="radio" name="db" value="sqlite"' . ($mysql ? '' : ' checked') . '> ' . $e($this->t('db_sqlite')) . '</label>'
            . '<label class="radio"><input type="radio" name="db" value="mysql"' . ($mysql ? ' checked' : '') . '> ' . $e($this->t('db_mysql')) . '</label>'
            . '<fieldset><legend>' . $e($this->t('db_legend')) . '</legend>'
            . $field($this->t('f_host'), '<input name="db_host" value="' . $v('db_host', 'localhost') . '">')
            . $field($this->t('f_port'), '<input name="db_port" inputmode="numeric" value="' . $v('db_port', '3306') . '">')
            . $field($this->t('f_dbname'), '<input name="db_name" value="' . $v('db_name') . '">')
            . $field($this->t('f_user'), '<input name="db_user" value="' . $v('db_user') . '">')
            . $field($this->t('f_password'), '<input type="password" name="db_password" value="">') . '</fieldset>'
            . '<h2>' . $e($this->t('s5')) . '</h2><p>' . $e($this->t('s5_text')) . '</p>'
            . $field($this->t('from_label'), '<input name="mail_from" type="email" value="' . $v('mail_from') . '" placeholder="portal@yourbrand.com">')
            . '<fieldset><legend>' . $e($this->t('smtp_legend')) . '</legend>'
            . $field($this->t('f_smtp_host'), '<input name="smtp_host" value="' . $v('smtp_host') . '">')
            . $field($this->t('f_port'), '<input name="smtp_port" inputmode="numeric" value="' . $v('smtp_port', '587') . '">')
            . $field($this->t('f_user'), '<input name="smtp_user" value="' . $v('smtp_user') . '">')
            . $field($this->t('f_password'), '<input type="password" name="smtp_password" value="">')
            . $field($this->t('f_encryption'), '<select name="smtp_encryption">' . $opt('tls', 'STARTTLS (587)') . $opt('ssl', 'SSL/TLS (465)') . $opt('none', $this->t('enc_none')) . '</select>') . '</fieldset>'
            . '<p><button type="submit"' . ($blocking ? ' disabled' : '') . '>' . $e($this->t('button_install')) . '</button></p></form>';
        return $this->page($this->t('title_install'), $html);
    }

    /** "Works with Open Brand Exchange" in the page's language (the web interface's own text). */
    private function worksWith(): string
    {
        $file = rtrim((string) $this->config->get('ui_dir'), '/') . '/locales/' . $this->lang . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $text = is_array($data) ? ($data['common']['worksWith'] ?? null) : null;
        return is_string($text) && $text !== '' ? $text : 'Works with Open Brand Exchange';
    }

    private function languageSwitch(): string
    {
        $options = '';
        foreach (Lang::UI_LANGUAGES as $l) {
            $options .= '<option value="' . $l . '"' . ($l === $this->lang ? ' selected' : '') . '>' . htmlspecialchars(self::NATIVE[$l], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return '<form method="get" class="lang"><label>' . htmlspecialchars($this->t('lang_label'), ENT_QUOTES, 'UTF-8')
            . ' <select name="lang">' . $options . '</select></label> <button type="submit">OK</button></form>';
    }

    private const NATIVE = [
        'bg' => 'Български', 'cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'el' => 'Ελληνικά', 'en' => 'English',
        'es' => 'Español', 'et' => 'Eesti', 'fi' => 'Suomi', 'fr' => 'Français', 'ga' => 'Gaeilge', 'hr' => 'Hrvatski',
        'hu' => 'Magyar', 'it' => 'Italiano', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'mt' => 'Malti', 'nb' => 'Norsk bokmål',
        'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português', 'ro' => 'Română', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina',
        'sv' => 'Svenska',
    ];

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="' . $this->lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . '</title><style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:44rem;margin:2rem auto;padding:0 1rem;color:#1d1d1b;line-height:1.5}'
            . 'h1{font-size:1.6rem}h2{font-size:1.1rem;margin-top:2rem}label{display:block;margin:.6rem 0}input,select{display:block;width:100%;padding:.5rem;margin-top:.2rem;border:1px solid #767676;border-radius:6px;font:inherit;box-sizing:border-box}'
            . 'label.radio input{display:inline;width:auto;margin-right:.4rem}fieldset{border:1px solid #ddd;border-radius:8px;margin:.8rem 0}'
            . 'button,.button{background:#2f5d62;color:#fff;border:0;border-radius:8px;padding:.7rem 1.2rem;font:inherit;font-weight:600;text-decoration:none;display:inline-block;cursor:pointer}button[disabled]{opacity:.5}'
            . ':focus-visible{outline:3px solid #1a73e8;outline-offset:2px}'
            . '.lang{display:flex;gap:.5rem;align-items:end;justify-content:flex-end}.lang label{margin:0}.lang select{display:inline-block;width:auto}.lang button{padding:.45rem .8rem}'
            . '.checks{list-style:none;padding:0}.checks li{padding:.2rem 0}.checks li:before{display:inline-block;width:1.5rem}.ok:before{content:"✓";color:#2e7d32}.bad:before{content:"✗";color:#c62828}.warn:before{content:"!";color:#8a5300}'
            . '.errors{background:#fdecea;border-radius:8px;padding:1rem 1rem 1rem 2rem}.code{font-family:ui-monospace,monospace;font-size:1.2rem}pre{background:#f4f4f2;padding:.8rem;border-radius:6px;overflow:auto}p.ok{font-weight:600}small{color:#595959}'
            . '</style></head><body>' . $this->languageSwitch() . '<main><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</main>'
            . '<footer><p><small>' . htmlspecialchars($this->worksWith()) . ' · ' . Config::SOFTWARE . ' ' . Config::VERSION . '</small></p></footer></body></html>';
    }
}
