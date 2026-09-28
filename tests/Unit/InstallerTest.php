<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Config;
use Obx\Node\Install\Installer;
use PHPUnit\Framework\TestCase;

/**
 * The web installer speaks all 25 languages: every text exists with the English placeholders,
 * and a chosen language is used for the server check.
 */
final class InstallerTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, array{string}> */
    public static function languages(): array
    {
        $out = [];
        foreach (LocalesTest::LANGUAGES as $l) {
            $out[$l] = [$l];
        }
        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function testEveryInstallerTextIsTranslated(string $lang): void
    {
        $en = json_decode((string) file_get_contents(self::root() . '/resources/installer/en.json'), true);
        $file = self::root() . '/resources/installer/' . $lang . '.json';
        $this->assertFileExists($file);
        $t = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($t, $lang . '.json is not valid JSON');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($t))), $lang . ' misses texts');
        $this->assertSame([], array_values(array_diff(array_keys($t), array_keys($en))), $lang . ' has unknown texts');
        foreach ($en as $key => $text) {
            $this->assertNotSame('', trim((string) $t[$key]), $lang . ' ' . $key . ' is empty');
            preg_match_all('/\{(\w+)\}/', $text, $want);
            preg_match_all('/\{(\w+)\}/', (string) $t[$key], $got);
            $this->assertEqualsCanonicalizing($want[1], $got[1], $lang . ' ' . $key . ' placeholders');
            if ($lang !== 'en' && !in_array($key, ['f_host', 'f_port', 'f_password', 's4', 'chk_zip', 'chk_curl', 'f_smtp_host'], true)) {
                $this->assertNotSame($text, $t[$key], $lang . ' ' . $key . ' is still English');
            }
        }
    }

    public function testTheChosenLanguageIsUsed(): void
    {
        $i = new Installer(self::root(), Config::load(self::root(), ['installed' => false, 'data_dir' => sys_get_temp_dir() . '/obx-installer-test']));
        $i->useLanguage('de-AT');
        $this->assertSame('de', $i->language());
        $this->assertStringStartsWith('PHP 8.2 oder neuer', $i->checks()[0]['label']);
        $i->useLanguage('xx');
        $this->assertSame('en', $i->language());
        $this->assertSame('The folder data/ is writable', $i->checks()[4]['label']);
    }
    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/obx-installer-' . bin2hex(random_bytes(4));
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/config', 0777, true);
        return $dir;
    }

    private static function installer(string $dir, bool $otherUser): Installer
    {
        putenv('OBX_CONFIG_FILE=' . $dir . '/config/config.php');
        try {
            $config = Config::load(self::root(), ['installed' => false, 'data_dir' => $dir . '/data', 'db_dsn' => 'sqlite:' . $dir . '/data/obx.sqlite']);
        } finally {
            putenv('OBX_CONFIG_FILE');
        }
        return new Installer(self::root(), $config, $otherUser);
    }

    private static function page(Installer $i): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['lang' => 'en'];
        ob_start();
        $i->serve();
        return (string) ob_get_clean();
    }

    public function testTheFtpAccountCanOpenTheSetupCodeWhenPhpRunsAsTheWebServer(): void
    {
        // Apache with mod_php: the file belongs to the web server, the owner reads it over FTP.
        $dir = self::tempDir();
        self::page(self::installer($dir, true));
        $this->assertSame(0644, fileperms($dir . '/data/SETUP-CODE.txt') & 0777);
        // PHP runs as the FTP account itself (PHP-FPM, suPHP): nobody else needs to read it.
        $dir = self::tempDir();
        self::page(self::installer($dir, false));
        $this->assertSame(0600, fileperms($dir . '/data/SETUP-CODE.txt') & 0777);
    }

    public function testNoCodeIsPromisedWhileTheDataFolderIsNotWritable(): void
    {
        // A folder that cannot be created, even by root: its parent is a file.
        putenv('OBX_CONFIG_FILE=/etc/hostname/config/config.php');
        try {
            $config = Config::load(self::root(), ['installed' => false, 'data_dir' => '/etc/hostname/data']);
        } finally {
            putenv('OBX_CONFIG_FILE');
        }
        $html = self::page(new Installer(self::root(), $config, false));
        $this->assertStringContainsString('As soon as the data folder is writable', $html);
        $this->assertStringNotContainsString('We wrote a code', $html);
        $this->assertStringContainsString('set this folder&#039;s permissions to 775', $html);
    }

    public function testAfterInstallingTheWizardOpensWithTheCodeAndTheRightCronAdvice(): void
    {
        foreach ([false, true] as $otherUser) {
            $dir = self::tempDir();
            $i = self::installer($dir, $otherUser);
            self::page($i);
            $code = (string) file_get_contents($dir . '/data/SETUP-CODE.txt');
            preg_match('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $code, $m);
            [$errors, $html] = $i->install(['setup_code' => $m[0], 'public_url' => 'https://brands.kestrel.example', 'db' => 'sqlite'], $m[0]);
            $this->assertSame([], $errors);
            $this->assertStringContainsString('href="https://brands.kestrel.example/setup#code=' . $m[0] . '"', (string) $html);
            $this->assertStringContainsString('https://brands.kestrel.example/cron?key=', (string) $html);
            $this->assertSame($otherUser ? 0644 : 0640, fileperms($dir . '/config/config.php') & 0777, 'the FTP account can open its own settings');
            if ($otherUser) {
                $this->assertStringNotContainsString('bin/obx cron', (string) $html, 'a cron job as the FTP account could not read config.php');
                $this->assertStringContainsString('use the web address', (string) $html);
            } else {
                $this->assertStringContainsString('bin/obx cron', (string) $html);
            }
        }
    }

    public function testTheCommandLineExplainsAConfigFileItMayNotRead(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !is_executable('/bin/su')) {
            $this->markTestSkipped('Needs root and su to run the command line as another user.');
        }
        $dir = self::tempDir();
        file_put_contents($dir . '/config/config.php', "<?php return ['public_url' => 'https://brands.kestrel.example'];\n");
        chmod($dir . '/config/config.php', 0600);
        chmod($dir, 0755);
        chmod($dir . '/config', 0755);
        $cmd = 'OBX_CONFIG_FILE=' . escapeshellarg($dir . '/config/config.php') . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/obx') . ' cron 2>&1';
        exec('su -s /bin/sh nobody -c ' . escapeshellarg($cmd), $lines, $status);
        $out = implode("\n", $lines);
        $this->assertSame(1, $status, $out);
        $this->assertStringContainsString('may not read', $out);
        $this->assertStringContainsString('cron web address', $out);
        $this->assertStringNotContainsString('Warning', $out, 'no raw PHP warning');
    }
}
