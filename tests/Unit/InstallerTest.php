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
}
