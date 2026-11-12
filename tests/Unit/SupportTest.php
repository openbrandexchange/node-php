<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Catalog\Validator;
use Obx\Node\Http\ApiError;
use Obx\Node\Images\Renderer;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Lang;
use Obx\Node\Support\Str;
use Obx\Node\Zip\ZipWriter;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testLocalizedTextFallback(): void
    {
        $t = ['nl' => 'Trui', 'en' => 'Sweater', 'fr' => 'Pull'];
        $this->assertSame('Trui', Lang::pick($t, 'nl'));
        $this->assertSame('Trui', Lang::pick($t, 'nl-BE'));
        $this->assertSame('Sweater', Lang::pick($t, 'de'), 'falls back to English');
        $this->assertSame('Pullover', Lang::pick(['de' => 'Pullover'], 'sv'), 'then the first available');
        $this->assertNull(Lang::pick(null, 'en'));
    }

    public function testLanguageNegotiation(): void
    {
        $this->assertSame('de', Lang::negotiate('de-DE,de;q=0.9,en;q=0.8'));
        $this->assertSame('nl', Lang::negotiate('fy;q=1, nl;q=0.8'));
        $this->assertSame('nb', Lang::negotiate('no'));
        $this->assertSame('en', Lang::negotiate('zh-CN'));
        $this->assertSame('en', Lang::negotiate(null));
        $this->assertCount(25, Lang::UI_LANGUAGES);
        $this->assertCount(25, Lang::PORTAL_WORD);
        $this->assertSame('Beeldbank', Lang::PORTAL_WORD['nl']);
        $this->assertSame('Händlerportal', Lang::PORTAL_WORD['de']);
        $this->assertSame('Espace revendeurs', Lang::PORTAL_WORD['fr']);
    }

    public function testDatesAndSlugs(): void
    {
        $this->assertSame('2030-01-01T00:00:00Z', Clock::normalize('2030-01-01'));
        $this->assertSame('2030-01-01T08:00:00Z', Clock::normalize('2030-01-01T10:00:00+02:00'));
        $this->assertSame('2030-01-01T08:00:00Z', Clock::normalize('2030-01-01T10:00:00 02:00'));
        $this->assertSame('2030-01-01T00:00:00.500Z', Clock::normalize('2030-01-01T00:00:00.5Z'));
        $this->assertNull(Clock::normalize('2030-02-30'));
        $this->assertSame('kestrel-trail', Str::slug('Kestrel Trail'));
        $this->assertSame('etoile-du-nord', Str::slug('Étoile du Nord!'));
        $this->assertSame('KO-1001_NVY_1_web.jpg', Str::renditionFilename('KO-1001_NVY_1.png', 'web'));
        $this->assertSame('attachment; filename="caf_.jpg"; filename*=UTF-8\'\'caf%C3%A9.jpg', Str::contentDisposition('attachment', 'café.jpg'));
        $safe = Str::safeFilename('../../evil');
        $this->assertStringNotContainsString('/', $safe);
        $this->assertStringStartsNotWith('.', $safe);
    }

    public function testMergePatch(): void
    {
        $this->assertSame(['a' => 'z', 'c' => ['d' => 'e']], Validator::mergePatch(['a' => 'b', 'c' => ['d' => 'e', 'f' => 'g']], ['a' => 'z', 'c' => ['f' => null]]));
        $this->assertSame(['x'], Validator::mergePatch(['a' => 1], ['x']));
    }

    public function testValidatorNormalisesAndReportsPointers(): void
    {
        [$model, $status] = (new Validator())->product([
            'style_number' => ' KO-1 ',
            'name' => ['nl' => 'Trui', 'en' => 'Sweater'],
            'care' => [['code' => 'wash_30']],
            'materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100.0]]]],
            'status' => 'unpublished',
        ]);
        $this->assertSame('KO-1', $model['style_number']);
        $this->assertSame(['en' => 'Sweater', 'nl' => 'Trui'], $model['name']);
        $this->assertSame(['code' => 'wash_30', 'category' => 'washing', 'symbol' => '1_D_30'], array_slice($model['care'][0], 0, 3));
        $this->assertSame([null, 'Cotton'], [$model['materials'][0]['part']['name'], $model['materials'][0]['fibers'][0]['material']['name']['en']]);
        $this->assertSame(100, $model['materials'][0]['fibers'][0]['percentage']);
        $this->assertSame('unpublished', $status);
        try {
            (new Validator())->variant(['product_external_id' => 'KO-1', 'gtin' => '2000100101019', 'color' => ['code' => 'A']]);
            $this->fail('invalid check digit');
        } catch (ApiError $e) {
            $this->assertSame('invalid_gtin', $e->obxError);
            $this->assertSame('/gtin', $e->errors[0]['pointer']);
        }
    }

    public function testRenditionSizes(): void
    {
        $this->assertSame([1200, 1500], Renderer::fit(1200, 1500, 2000));
        $this->assertSame([1600, 2000], Renderer::fit(2400, 3000, 2000));
        $this->assertSame([480, 600], Renderer::fit(1200, 1500, 600));
        $this->assertSame([600, 1], Renderer::fit(3000, 2, 600));
    }

    public function testZipWriterProducesAReadableArchive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $src = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($src, str_repeat('abc', 1000));
        $fh = fopen($path, 'wb');
        $zip = new ZipWriter($fh);
        $zip->addFile($zip->uniqueName('KO-1/a.jpg'), $src);
        $zip->addFile($zip->uniqueName('KO-1/a.jpg'), $src);
        $zip->addString($zip->uniqueName('../../data.csv'), "a,b\n");
        $zip->addString($zip->uniqueName('ünïcode/ß.txt'), 'x');
        $zip->finish();
        fclose($fh);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($path) === true);
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        $this->assertSame(['KO-1/a.jpg', 'KO-1/a (2).jpg', 'data.csv', 'ünïcode/ß.txt'], $names);
        $this->assertSame(str_repeat('abc', 1000), $z->getFromName('KO-1/a (2).jpg'));
        $this->assertSame("a,b\n", $z->getFromName('data.csv'));
        $z->close();
        unlink($path);
        unlink($src);
    }
}
