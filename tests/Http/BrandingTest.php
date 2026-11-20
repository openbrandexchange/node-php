<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Portal appearance (node-admin.yaml, Settings.branding): theme, login and home page settings,
 * favicon and branding images.
 */
final class BrandingTest extends NodeTestCase
{
    private function png(int $w, int $h, int $rgb = 0x1f4e5f): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, $rgb);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function uploadImage(string $bytes, string $name = 'image.png', int $status = 201): array
    {
        $file = $this->dir . '/img-' . bin2hex(random_bytes(4));
        file_put_contents($file, $bytes);
        $res = $this->http('POST', '/admin/api/settings/images', ['browser' => 'admin', 'files' => ['file' => [['name' => $name, 'tmp' => $file, 'size' => strlen($bytes), 'type' => 'image/png']]]]);
        return $status === 201 ? $this->json($res, 201) : ['res' => $res];
    }

    /**
     * The fixture with its image ids replaced by images uploaded to this node.
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = (string) file_get_contents(self::fixtures('examples/BrandingSettings.full.json'));
        preg_match_all('/"(img_[A-Za-z0-9_]+)"/', $json, $m);
        $map = [];
        foreach (array_unique($m[1]) as $i => $id) {
            $map['"' . $id . '"'] = '"' . $this->uploadImage($this->png(40 + $i, 30))['id'] . '"';
        }
        return Json::decode(strtr($json, $map));
    }

    /** @return array<string, mixed> */
    private function patch(array $branding, int $status = 200): array
    {
        $res = $this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['branding' => $branding]]);
        return $status === 200 ? $this->json($res) : $this->assertProblem($res, $status, 'validation_failed');
    }

    public function testFullFixtureRoundTripsAndIsResolvedForTheLoginPageAndThePortal(): void
    {
        $this->freshNode();
        $fixture = $this->fixture();
        $saved = $this->patch($fixture)['branding'];
        $got = $this->json($this->http('GET', '/admin/api/settings', ['browser' => 'admin']))['branding'];
        foreach (['theme', 'login', 'home', 'accent_color', 'portal_name'] as $k) {
            $this->assertSame(Json::canonical($fixture[$k]), Json::canonical($got[$k]), $k);
            $this->assertSame(Json::canonical($fixture[$k]), Json::canonical($saved[$k]), $k);
        }
        $this->assertNull($got['favicon_url']);

        // Sending the settings back unchanged (as an editor does) changes nothing.
        $again = $this->patch(['theme' => $got['theme'], 'login' => $got['login'], 'home' => $got['home']])['branding'];
        $this->assertSame(Json::canonical($got['home']), Json::canonical($again['home']));

        // Public branding: theme and login with the image resolved, no home page.
        $public = $this->json($this->http('GET', '/admin/api/public/branding'));
        $this->assertSame(Json::canonical($fixture['theme']), Json::canonical($public['theme']));
        $this->assertSame($fixture['login']['background_image'], $public['login']['background_image']);
        $this->assertSame('http://localhost/admin/api/public/images/' . $fixture['login']['background_image'], $public['login']['background_image_url']);
        $this->assertArrayNotHasKey('home', $public);
        $this->assertArrayHasKey('favicon_url', $public);

        // Portal viewer: home with every image id resolved; ids are kept.
        $viewer = $this->json($this->http('GET', '/admin/api/portal/viewer', ['browser' => 'admin']));
        $blocks = $viewer['branding']['home']['blocks'];
        $this->assertSame('hero', $blocks[0]['type']);
        $this->assertSame($fixture['home']['blocks'][0]['image'], $blocks[0]['image']);
        $this->assertSame('http://localhost/admin/api/public/images/' . $blocks[0]['image'], $blocks[0]['image_url']);
        foreach ($blocks[2]['items'] as $tile) {
            $this->assertSame('http://localhost/admin/api/public/images/' . $tile['image'], $tile['image_url']);
        }
        $this->assertArrayNotHasKey('image_url', $blocks[1], 'blocks without an image get no image_url');
        $this->assertSame(Json::canonical($fixture['theme']), Json::canonical($viewer['branding']['theme']));

        // Every uploaded image is now in use.
        $list = $this->json($this->http('GET', '/admin/api/settings/images', ['browser' => 'admin']))['items'];
        $this->assertCount(7, $list);
        $this->assertSame([true], array_values(array_unique(array_column($list, 'used'))));

        // Merge patch: one colour changes, the rest stays; null brings back the node default.
        $patched = $this->patch(['theme' => ['colors' => ['primary' => '#000000'], 'radius' => null]])['branding'];
        $this->assertSame('#000000', $patched['theme']['colors']['primary']);
        $this->assertSame('#a8705a', $patched['theme']['colors']['secondary']);
        $this->assertArrayNotHasKey('radius', $patched['theme']);
        $this->assertNull($this->patch(['home' => null])['branding']['home']);
        $this->assertSame('cormorant-garamond', $this->patch(['login' => null])['branding']['theme']['heading_font']);
    }

    public function testUnknownMembersAndBlockTypesAreKeptAsWritten(): void
    {
        $this->freshNode();
        $home = ['blocks' => [
            ['id' => 'future', 'type' => 'video', 'src' => 'https://video.example/1', 'options' => new \stdClass(), 'list' => []],
            ['id' => 'a', 'type' => 'announcement', 'text' => ['en' => 'Hi'], 'x-later' => ['nested' => new \stdClass()]],
        ], 'layout_hint' => 'wide'];
        $theme = ['color_scheme' => 'dark', 'shadows' => 'soft', 'colors' => ['primary' => '#123456', 'accent2' => 'not checked'], 'empty' => new \stdClass()];
        $this->patch(['home' => $home, 'theme' => $theme, 'login' => ['layout' => 'centered', 'mascot' => true]]);
        $res = $this->http('GET', '/admin/api/settings', ['browser' => 'admin']);
        $body = $res->content();
        $this->assertStringContainsString('"options":{}', $body);
        $this->assertStringContainsString('"list":[]', $body);
        $this->assertStringContainsString('"empty":{}', $body);
        $b = $this->json($res)['branding'];
        $this->assertSame('video', $b['home']['blocks'][0]['type']);
        $this->assertSame('https://video.example/1', $b['home']['blocks'][0]['src']);
        $this->assertSame('wide', $b['home']['layout_hint']);
        $this->assertSame('soft', $b['theme']['shadows']);
        $this->assertSame('not checked', $b['theme']['colors']['accent2']);
        $this->assertTrue($b['login']['mascot']);
        $viewer = $this->json($this->http('GET', '/admin/api/portal/viewer', ['browser' => 'admin']));
        $this->assertSame('https://video.example/1', $viewer['branding']['home']['blocks'][0]['src']);
    }

    public function testValidationErrorsHavePointers(): void
    {
        $this->freshNode();
        $cases = [
            [['theme' => ['colors' => ['primary' => 'red']]], '/branding/theme/colors/primary'],
            [['theme' => ['heading_font' => 'comic-sans']], '/branding/theme/heading_font'],
            [['theme' => ['color_scheme' => 'sepia']], '/branding/theme/color_scheme'],
            [['theme' => ['image_background' => '#fff']], '/branding/theme/image_background'],
            [['theme' => 'dark'], '/branding/theme'],
            [['login' => ['layout' => 'left']], '/branding/login/layout'],
            [['login' => ['background_image' => 'img_doesnotexistaaaaaaa']], '/branding/login/background_image'],
            [['login' => ['title' => ['en' => str_repeat('x', 2001)]]], '/branding/login/title/en'],
            [['login' => ['title' => 'Welcome']], '/branding/login/title'],
            [['login' => ['title' => ['EN' => 'Welcome']]], '/branding/login/title/EN'],
            [['home' => ['blocks' => [['type' => 'text']]]], '/branding/home/blocks/0/id'],
            [['home' => ['blocks' => [['id' => 'a']]]], '/branding/home/blocks/0/type'],
            [['home' => ['blocks' => [['id' => 'a', 'type' => 'text'], ['id' => 'a', 'type' => 'text']]]], '/branding/home/blocks/1/id'],
            [['home' => ['blocks' => [['id' => str_repeat('a', 41), 'type' => 'text']]]], '/branding/home/blocks/0/id'],
            [['home' => ['blocks' => array_map(static fn ($i) => ['id' => 'b' . $i, 'type' => 'text'], range(1, 31))]], '/branding/home/blocks'],
            [['home' => ['blocks' => [['id' => 'h', 'type' => 'hero', 'link' => 'javascript:alert(1)']]]], '/branding/home/blocks/0/link'],
            [['home' => ['blocks' => [['id' => 'h', 'type' => 'hero', 'link' => 'http://shop.example/']]]], '/branding/home/blocks/0/link'],
            [['home' => ['blocks' => [['id' => 'h', 'type' => 'hero', 'link' => '//evil.example/']]]], '/branding/home/blocks/0/link'],
            [['home' => ['blocks' => [['id' => 'h', 'type' => 'hero', 'align' => 'right']]]], '/branding/home/blocks/0/align'],
            [['home' => ['blocks' => [['id' => 't', 'type' => 'tiles', 'items' => []]]]], '/branding/home/blocks/0/items'],
            [['home' => ['blocks' => [['id' => 't', 'type' => 'tiles', 'columns' => 5]]]], '/branding/home/blocks/0/columns'],
            [['home' => ['blocks' => [['id' => 't', 'type' => 'tiles', 'items' => [['link' => 'ftp://x']]]]]], '/branding/home/blocks/0/items/0/link'],
            [['home' => ['blocks' => [['id' => 't', 'type' => 'text', 'body' => ['en' => str_repeat('x', 10001)]]]]], '/branding/home/blocks/0/body/en'],
            [['home' => ['blocks' => [['id' => 'p', 'type' => 'products', 'style_numbers' => range(1, 25)]]]], '/branding/home/blocks/0/style_numbers'],
            [['home' => ['blocks' => [['id' => 'p', 'type' => 'products', 'query' => '?category=jeans']]]], '/branding/home/blocks/0/query'],
            [['home' => ['blocks' => [['id' => 'x', 'type' => 'Big Video']]]], '/branding/home/blocks/0/type'],
            [['login' => ['title' => ['en' => '']]], '/branding/login/title/en'],
            [['login' => ['title' => new \stdClass()]], '/branding/login/title'],
            [['home' => ['blocks' => [['id' => 'p', 'type' => 'products', 'max' => 25]]]], '/branding/home/blocks/0/max'],
            [['home' => ['blocks' => [['id' => 'a', 'type' => 'announcement', 'tone' => 'alarm']]]], '/branding/home/blocks/0/tone'],
            [['home' => ['blocks' => [['id' => 'c', 'type' => 'contact', 'email' => 'not an address']]]], '/branding/home/blocks/0/email'],
            [['home' => ['blocks' => [['id' => 'i', 'type' => 'image_text', 'image_side' => 'top']]]], '/branding/home/blocks/0/image_side'],
            [['home' => ['blocks' => 'none']], '/branding/home/blocks'],
            [['home' => ['blocks' => [['id' => 'x', 'type' => 'future', 'payload' => str_repeat('x', 70000)]]]], '/branding'],
        ];
        foreach ($cases as [$branding, $pointer]) {
            $p = $this->patch($branding, 422);
            $this->assertContains($pointer, array_column($p['errors'] ?? [], 'pointer'), Json::encode($branding) . ' → ' . Json::encode($p));
        }
        // Nothing was stored by the refused requests.
        $b = $this->json($this->http('GET', '/admin/api/settings', ['browser' => 'admin']))['branding'];
        $this->assertNull($b['theme']);
        $this->assertNull($b['home']);
        // Good links pass.
        $ok = $this->patch(['home' => ['blocks' => [
            ['id' => 'h', 'type' => 'hero', 'link' => '/products/MN-1001'],
            ['id' => 'i', 'type' => 'image_text', 'link' => 'https://maison-noor.example/story?x=1'],
            ['id' => 'p', 'type' => 'products', 'style_numbers' => ['MN-1001'], 'query' => 'category=jeans'],
        ]]]);
        $this->assertCount(3, $ok['branding']['home']['blocks']);
    }

    public function testBrandingImagesUploadListServeAndDelete(): void
    {
        $this->freshNode();
        $bytes = $this->png(64, 48);
        $img = $this->uploadImage($bytes);
        $this->assertMatchesRegularExpression('/^img_[a-z2-7]{20}$/', $img['id']);
        $this->assertSame('http://localhost/admin/api/public/images/' . $img['id'], $img['url']);
        $this->assertSame([64, 48, strlen($bytes), 'image/png'], [$img['width'], $img['height'], $img['bytes'], $img['content_type']]);

        // Public, without a session, cached for a year.
        $res = $this->http('GET', '/admin/api/public/images/' . $img['id']);
        $this->assertSame(200, $res->status);
        $this->assertSame($bytes, $res->content());
        $this->assertSame('image/png', $res->getHeader('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $res->getHeader('Cache-Control'));
        $this->assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
        $this->assertSame(304, $this->http('GET', '/admin/api/public/images/' . $img['id'], ['headers' => ['If-None-Match' => (string) $res->getHeader('ETag')]])->status);
        $this->assertProblem($this->http('GET', '/admin/api/public/images/img_unknownaaaaaaaaaaaa'), 404, 'not_found');

        // Same bytes again: a new id (ids are never reused).
        $second = $this->uploadImage($bytes);
        $this->assertNotSame($img['id'], $second['id']);

        // Wrong type, too large in pixels, no file.
        $this->assertProblem($this->uploadImage('<svg xmlns="http://www.w3.org/2000/svg"/>', 'x.svg', 0)['res'], 415, 'unsupported_media_type');
        $this->assertProblem($this->uploadImage($this->png(8001, 1), 'wide.png', 0)['res'], 422, 'validation_failed');
        $this->assertProblem($this->http('POST', '/admin/api/settings/images', ['browser' => 'admin', 'post' => ['x' => '1']]), 422, 'validation_failed');

        // Admins only, CSRF as other writes.
        $this->assertProblem($this->http('GET', '/admin/api/settings/images'), 401, 'unauthorized');
        $this->assertProblem($this->http('DELETE', '/admin/api/settings/images/' . $img['id'], ['browser' => 'admin', 'csrf' => false]), 403, 'csrf_failed');

        // In use: listed as used, delete refused.
        $this->patch(['login' => ['background_image' => $img['id']]]);
        $list = $this->json($this->http('GET', '/admin/api/settings/images', ['browser' => 'admin']))['items'];
        $used = array_column($list, 'used', 'id');
        $this->assertSame([$img['id'] => true, $second['id'] => false], [$img['id'] => $used[$img['id']], $second['id'] => $used[$second['id']]]);
        $this->assertProblem($this->http('DELETE', '/admin/api/settings/images/' . $img['id'], ['browser' => 'admin']), 409, 'conflict');
        // An image in a block of a type this node does not know is also kept.
        $this->patch(['home' => ['blocks' => [['id' => 'v', 'type' => 'gallery', 'items' => [['image' => $second['id']]]]]]]);
        $this->assertProblem($this->http('DELETE', '/admin/api/settings/images/' . $second['id'], ['browser' => 'admin']), 409, 'conflict');

        $this->patch(['login' => null, 'home' => null]);
        $this->assertSame(204, $this->http('DELETE', '/admin/api/settings/images/' . $img['id'], ['browser' => 'admin'])->status);
        $this->assertProblem($this->http('DELETE', '/admin/api/settings/images/' . $img['id'], ['browser' => 'admin']), 404, 'not_found');
        $this->assertProblem($this->http('GET', '/admin/api/public/images/' . $img['id']), 404, 'not_found');
        $this->assertCount(1, $this->json($this->http('GET', '/admin/api/settings/images', ['browser' => 'admin']))['items']);
        // The bytes stay as long as another image row uses them (same sha), and are never collected while referenced.
        $this->app->blobs->collectGarbage(PHP_INT_MAX);
        $this->assertSame(200, $this->http('GET', '/admin/api/public/images/' . $second['id'])->status);
    }

    public function testImageLimit(): void
    {
        $this->freshNode();
        $first = $this->uploadImage($this->png(2, 2));
        $row = $this->app->db->one('SELECT * FROM obx_branding_images WHERE id = ?', [$first['id']]);
        for ($i = 1; $i < 200; $i++) {
            $row['id'] = 'img_fill' . str_pad((string) $i, 16, 'a', STR_PAD_LEFT);
            $this->app->db->insert('obx_branding_images', $row);
        }
        $this->assertProblem($this->uploadImage($this->png(3, 3), 'x.png', 0)['res'], 409, 'limit_exceeded');
    }

    public function testFavicon(): void
    {
        $this->freshNode();
        $this->assertProblem($this->http('PUT', '/admin/api/settings/favicon', ['browser' => 'admin', 'body' => $this->png(64, 32), 'headers' => ['Content-Type' => 'image/png']]), 422, 'validation_failed');
        $im = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $this->assertProblem($this->http('PUT', '/admin/api/settings/favicon', ['browser' => 'admin', 'body' => $jpeg, 'headers' => ['Content-Type' => 'image/jpeg']]), 415, 'unsupported_media_type');
        $this->assertProblem($this->http('PUT', '/admin/api/settings/favicon', ['browser' => 'admin', 'body' => str_repeat('x', 1024 * 1024 + 1), 'headers' => ['Content-Type' => 'image/png']]), 413, 'payload_too_large');

        $png = $this->png(64, 64);
        $s = $this->json($this->http('PUT', '/admin/api/settings/favicon', ['browser' => 'admin', 'body' => $png, 'headers' => ['Content-Type' => 'image/png']]));
        $url = $s['branding']['favicon_url'];
        $this->assertStringStartsWith('http://localhost/admin/api/public/favicon/', $url);
        $this->assertSame($url, $this->json($this->http('GET', '/admin/api/public/branding'))['favicon_url']);
        $res = $this->http('GET', substr($url, strlen('http://localhost')));
        $this->assertSame($png, $res->content());
        $this->assertSame('public, max-age=31536000, immutable', $res->getHeader('Cache-Control'));

        $this->assertNull($this->json($this->http('DELETE', '/admin/api/settings/favicon', ['browser' => 'admin']))['branding']['favicon_url']);
        $this->assertProblem($this->http('GET', substr($url, strlen('http://localhost'))), 404, 'not_found');
    }
}
