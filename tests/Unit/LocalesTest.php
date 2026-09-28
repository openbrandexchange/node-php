<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Mail\Emails;
use Obx\Node\Support\Lang;
use PHPUnit\Framework\TestCase;

/**
 * Every one of the 25 languages has every text the node itself sends (e-mails, the portal
 * word), with the same placeholders as the English original.
 */
final class LocalesTest extends TestCase
{
    public const LANGUAGES = ['bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'nb', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'];

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/public/ui/locales';
    }

    /** @return array<string, mixed> */
    private static function flat(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::flat($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function languages(): array
    {
        $out = [];
        foreach (self::LANGUAGES as $l) {
            $out[$l] = [$l];
        }
        return $out;
    }

    public function testTheNodeOffersExactlyTheTwentyFiveLanguages(): void
    {
        $this->assertEqualsCanonicalizing(self::LANGUAGES, Lang::UI_LANGUAGES);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function testEveryEmailTextExistsWithItsPlaceholders(string $lang): void
    {
        $file = self::dir() . '/' . $lang . '.json';
        $this->assertFileExists($file, 'The vendored web interface has no ' . $lang . ' texts; run scripts/update-ui.sh.');
        $texts = self::flat((array) json_decode((string) file_get_contents($file), true));
        $keys = array_merge(array_keys(Emails::DEFAULTS), ['glossary.portal']);
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $texts, $lang . ' lacks ' . $key);
            $this->assertIsString($texts[$key]);
            $this->assertNotSame('', trim($texts[$key]), $lang . ' has an empty ' . $key);
            if (isset(Emails::DEFAULTS[$key])) {
                preg_match_all('/\{\{(\w+)\}\}/', Emails::DEFAULTS[$key], $want);
                preg_match_all('/\{\{(\w+)\}\}/', $texts[$key], $got);
                $this->assertEqualsCanonicalizing(array_unique($want[1]), array_unique($got[1]), $lang . ' ' . $key . ' must use the same placeholders');
            }
        }
    }
}
