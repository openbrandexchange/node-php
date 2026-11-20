<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Catalog\Validator;
use Obx\Node\Http\ApiError;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Portal appearance (node-admin.yaml, Settings.branding): theme, login page and retailer home
 * page, plus the branding images they refer to (obx_branding_images).
 *
 * theme, login and home are kept as decoded JSON objects (stdClass), stored in their own
 * obx_settings row "appearance", so members this node does not know (and unknown block types)
 * come back exactly as written, including empty objects.
 */
final class Branding
{
    public const FONTS = ['system', 'inter', 'dm-sans', 'manrope', 'work-sans', 'jost', 'space-grotesk', 'fraunces',
        'playfair-display', 'libre-baskerville', 'cormorant-garamond'];
    public const COLORS = ['primary', 'secondary', 'background', 'surface', 'text'];
    public const MEMBERS = ['theme', 'login', 'home'];
    public const MAX_BRANDING_BYTES = 65536;
    public const MAX_BLOCKS = 30;
    public const MAX_TEXT = 2000;
    public const MAX_BODY = 10000;
    public const MAX_IMAGES = 200;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_IMAGE_SIDE = 8000;
    public const MAX_FAVICON_BYTES = 1024 * 1024;
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    public const FAVICON_TYPES = ['image/png', 'image/webp'];

    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** @var list<array{pointer: string, detail: string, obx_error: string}> */
    private array $errors = [];

    public function __construct(private App $app)
    {
    }

    // ================================================================ storage

    /** @return array{theme: mixed, login: mixed, home: mixed} decoded with objects as stdClass */
    public function appearance(): array
    {
        $raw = $this->app->db->value("SELECT v FROM obx_settings WHERE k = 'appearance'");
        $v = is_string($raw) && $raw !== '' ? json_decode($raw, false) : null;
        $out = ['theme' => null, 'login' => null, 'home' => null];
        if ($v instanceof \stdClass) {
            foreach (self::MEMBERS as $m) {
                $out[$m] = $v->{$m} ?? null;
            }
        }
        return $out;
    }

    /** @param array{theme: mixed, login: mixed, home: mixed} $appearance */
    public function save(array $appearance): void
    {
        $json = Json::encode((object) $appearance);
        if ($this->app->db->value("SELECT k FROM obx_settings WHERE k = 'appearance'") === null) {
            $this->app->db->insert('obx_settings', ['k' => 'appearance', 'v' => $json]);
        } else {
            $this->app->db->run("UPDATE obx_settings SET v = ? WHERE k = 'appearance'", [$json]);
        }
    }

    /**
     * Applies the theme/login/home members of a branding merge patch (decoded with objects) to
     * the stored appearance and validates the result. Throws 422 with pointers.
     *
     * @param array<string, mixed> $otherBranding the rest of branding, for the 64 KiB limit
     * @return array{theme: mixed, login: mixed, home: mixed}
     */
    public function patch(\stdClass $brandingPatch, array $otherBranding): array
    {
        $a = $this->appearance();
        foreach (self::MEMBERS as $m) {
            if (property_exists($brandingPatch, $m)) {
                $a[$m] = self::mergePatch($a[$m], $brandingPatch->{$m});
            }
        }
        $this->validate($a);
        $size = strlen(Json::encode(array_merge($otherBranding, $a)));
        if ($size > self::MAX_BRANDING_BYTES) {
            throw ApiError::validation('/branding', 'The branding settings are ' . $size . ' bytes; at most ' . self::MAX_BRANDING_BYTES . ' bytes are allowed.');
        }
        return $a;
    }

    /** RFC 7396 on decoded JSON with objects as stdClass; an empty result object stays an object. */
    public static function mergePatch(mixed $target, mixed $patch): mixed
    {
        if (!$patch instanceof \stdClass) {
            return $patch;
        }
        $out = $target instanceof \stdClass ? clone $target : new \stdClass();
        foreach (get_object_vars($patch) as $k => $v) {
            if ($v === null) {
                unset($out->{$k});
            } else {
                $out->{$k} = self::mergePatch($out->{$k} ?? null, $v);
            }
        }
        return $out;
    }

    // ================================================================ validation

    /** @param array{theme: mixed, login: mixed, home: mixed} $a */
    public function validate(array $a): void
    {
        $this->errors = [];
        $ids = $this->imageIds();
        if ($a['theme'] !== null) {
            $this->theme($a['theme'], '/branding/theme');
        }
        if ($a['login'] !== null) {
            $this->login($a['login'], '/branding/login', $ids);
        }
        if ($a['home'] !== null) {
            $this->home($a['home'], '/branding/home', $ids);
        }
        if ($this->errors !== []) {
            $detail = count($this->errors) === 1 ? $this->errors[0]['detail'] : 'The request has ' . count($this->errors) . ' invalid fields.';
            throw new ApiError('validation_failed', $detail, $this->errors);
        }
    }

    private function err(string $pointer, string $detail): void
    {
        $this->errors[] = ['pointer' => $pointer, 'detail' => $detail, 'obx_error' => 'validation_failed'];
    }

    private function object(mixed $v, string $p): ?\stdClass
    {
        if (!$v instanceof \stdClass) {
            $this->err($p, 'Must be an object or null.');
            return null;
        }
        return $v;
    }

    /** @param list<string|int> $allowed */
    private function enum(\stdClass $o, string $k, array $allowed, string $p): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        if (!in_array($o->{$k}, $allowed, true)) {
            $this->err($p . '/' . $k, 'One of: ' . implode(', ', array_map('strval', $allowed)) . '.');
        }
    }

    private function color(\stdClass $o, string $k, string $p): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        if (!is_string($o->{$k}) || !preg_match(self::HEX, $o->{$k})) {
            $this->err($p . '/' . $k, 'A colour like #1f4e5f, or null.');
        }
    }

    private function text(\stdClass $o, string $k, string $p, int $max = self::MAX_TEXT): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        $v = $o->{$k};
        if (!$v instanceof \stdClass) {
            $this->err($p . '/' . $k, 'Text per language, e.g. {"en": "…"}.');
            return;
        }
        if (get_object_vars($v) === []) {
            $this->err($p . '/' . $k, 'At least one language, or null for no text.');
        }
        foreach (get_object_vars($v) as $lang => $t) {
            if (!preg_match(Validator::LANGUAGE_TAG, (string) $lang)) {
                $this->err($p . '/' . $k . '/' . $lang, 'Language keys are BCP 47 tags such as "en" or "nl-BE".');
            } elseif (!is_string($t) || $t === '') {
                $this->err($p . '/' . $k . '/' . $lang, 'A non-empty string (leave the language out for no text).');
            } elseif (mb_strlen($t, 'UTF-8') > $max) {
                $this->err($p . '/' . $k . '/' . $lang, 'At most ' . $max . ' characters.');
            }
        }
    }

    private function plain(\stdClass $o, string $k, string $p, int $max = 200): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        if (!is_string($o->{$k}) || mb_strlen($o->{$k}, 'UTF-8') > $max) {
            $this->err($p . '/' . $k, 'A string of at most ' . $max . ' characters.');
        }
    }

    /** @param array<string, true> $ids */
    private function imageRef(\stdClass $o, string $k, string $p, array $ids): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        if (!is_string($o->{$k}) || !isset($ids[$o->{$k}])) {
            $this->err($p . '/' . $k, 'Not an uploaded branding image (POST /admin/api/settings/images).');
        }
    }

    private function link(\stdClass $o, string $k, string $p): void
    {
        if (!property_exists($o, $k) || $o->{$k} === null) {
            return;
        }
        if (!is_string($o->{$k}) || !self::validLink($o->{$k})) {
            $this->err($p . '/' . $k, 'A portal path starting with / (e.g. /catalog?category=jeans) or an https:// address.');
        }
    }

    public static function validLink(string $l): bool
    {
        if ($l === '' || strlen($l) > 2000 || preg_match('/[\x00-\x20\x7F\\\\]/', $l)) {
            return false;
        }
        if ($l[0] === '/') {
            return !str_starts_with($l, '//');
        }
        return (bool) preg_match('#^https://[^/?\#]+#i', $l) && filter_var($l, FILTER_VALIDATE_URL) !== false;
    }

    private function theme(mixed $v, string $p): void
    {
        $t = $this->object($v, $p);
        if ($t === null) {
            return;
        }
        $this->enum($t, 'color_scheme', ['light', 'dark', 'auto'], $p);
        if (property_exists($t, 'colors') && $t->colors !== null) {
            $c = $this->object($t->colors, $p . '/colors');
            if ($c !== null) {
                foreach (self::COLORS as $k) {
                    $this->color($c, $k, $p . '/colors');
                }
            }
        }
        $this->enum($t, 'heading_font', self::FONTS, $p);
        $this->enum($t, 'body_font', self::FONTS, $p);
        $this->enum($t, 'radius', ['none', 'small', 'medium', 'large'], $p);
        $this->enum($t, 'grid_density', ['compact', 'comfortable', 'editorial'], $p);
        $this->enum($t, 'card_style', ['plain', 'bordered', 'elevated'], $p);
        $this->enum($t, 'image_fit', ['contain', 'cover'], $p);
        $this->color($t, 'image_background', $p);
    }

    /** @param array<string, true> $ids */
    private function login(mixed $v, string $p, array $ids): void
    {
        $l = $this->object($v, $p);
        if ($l === null) {
            return;
        }
        $this->enum($l, 'layout', ['centered', 'split'], $p);
        $this->imageRef($l, 'background_image', $p, $ids);
        $this->text($l, 'title', $p);
        $this->text($l, 'text', $p);
    }

    /** @param array<string, true> $ids */
    private function home(mixed $v, string $p, array $ids): void
    {
        $h = $this->object($v, $p);
        if ($h === null || !property_exists($h, 'blocks') || $h->blocks === null) {
            return;
        }
        if (!is_array($h->blocks)) {
            $this->err($p . '/blocks', 'A list of blocks.');
            return;
        }
        if (count($h->blocks) > self::MAX_BLOCKS) {
            $this->err($p . '/blocks', 'At most ' . self::MAX_BLOCKS . ' blocks.');
            return;
        }
        $seen = [];
        foreach ($h->blocks as $i => $b) {
            $bp = $p . '/blocks/' . $i;
            if (!$b instanceof \stdClass) {
                $this->err($bp, 'A block is an object with an id and a type.');
                continue;
            }
            $id = $b->id ?? null;
            if (!is_string($id) || $id === '' || mb_strlen($id, 'UTF-8') > 40) {
                $this->err($bp . '/id', 'A string of 1 to 40 characters.');
            } elseif (isset($seen[$id])) {
                $this->err($bp . '/id', 'Block ids must be unique.');
            } else {
                $seen[$id] = true;
            }
            $type = $b->type ?? null;
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type)) {
                $this->err($bp . '/type', 'The block type, such as hero or tiles (lower case letters, digits and _).');
                continue;
            }
            $this->block($b, $type, $bp, $ids);
        }
    }

    /** @param array<string, true> $ids */
    private function block(\stdClass $b, string $type, string $p, array $ids): void
    {
        switch ($type) {
            case 'hero':
                $this->imageRef($b, 'image', $p, $ids);
                foreach (['title', 'text', 'button_label'] as $k) {
                    $this->text($b, $k, $p);
                }
                $this->link($b, 'link', $p);
                $this->enum($b, 'align', ['left', 'center'], $p);
                $this->enum($b, 'height', ['medium', 'large'], $p);
                $this->enum($b, 'overlay', ['none', 'dark', 'light'], $p);
                break;
            case 'tiles':
                $this->text($b, 'title', $p);
                $this->enum($b, 'columns', [2, 3, 4], $p);
                if (property_exists($b, 'items') && $b->items !== null) {
                    if (!is_array($b->items) || count($b->items) < 1 || count($b->items) > 12) {
                        $this->err($p . '/items', 'A list of 1 to 12 tiles.');
                        break;
                    }
                    foreach ($b->items as $j => $item) {
                        $ip = $p . '/items/' . $j;
                        if (!$item instanceof \stdClass) {
                            $this->err($ip, 'A tile is an object with image, title and link.');
                            continue;
                        }
                        $this->imageRef($item, 'image', $ip, $ids);
                        $this->text($item, 'title', $ip);
                        $this->link($item, 'link', $ip);
                    }
                }
                break;
            case 'text':
                $this->text($b, 'title', $p);
                $this->text($b, 'body', $p, self::MAX_BODY);
                break;
            case 'image_text':
                $this->imageRef($b, 'image', $p, $ids);
                foreach (['title', 'text', 'button_label'] as $k) {
                    $this->text($b, $k, $p);
                }
                $this->link($b, 'link', $p);
                $this->enum($b, 'image_side', ['left', 'right'], $p);
                break;
            case 'products':
                $this->text($b, 'title', $p);
                $styles = $b->style_numbers ?? null;
                $query = $b->query ?? null;
                if ($styles !== null) {
                    if (!is_array($styles) || count($styles) > 24) {
                        $this->err($p . '/style_numbers', 'A list of at most 24 style numbers.');
                    } else {
                        foreach ($styles as $j => $s) {
                            if (!is_string($s) || trim($s) === '' || mb_strlen($s, 'UTF-8') > 100) {
                                $this->err($p . '/style_numbers/' . $j, 'A style number.');
                            }
                        }
                    }
                }
                // When both are set, style_numbers wins (the UI decides); both are kept.
                if ($query !== null && (!is_string($query) || strlen($query) > 2000 || preg_match('/[?#\s\x00-\x1F\x7F]/', $query))) {
                    $this->err($p . '/query', 'A catalogue filter query without "?", such as category=jeans&qualifier=organic.');
                }
                if (property_exists($b, 'max') && $b->max !== null && (!is_int($b->max) || $b->max < 1 || $b->max > 24)) {
                    $this->err($p . '/max', 'A whole number from 1 to 24.');
                }
                break;
            case 'announcement':
                $this->text($b, 'text', $p);
                $this->enum($b, 'tone', ['neutral', 'info', 'success'], $p);
                break;
            case 'contact':
                $this->text($b, 'title', $p);
                $this->plain($b, 'name', $p);
                $this->plain($b, 'phone', $p, 50);
                if (property_exists($b, 'email') && $b->email !== null
                    && (!is_string($b->email) || strlen($b->email) > 254 || filter_var($b->email, FILTER_VALIDATE_EMAIL) === false)) {
                    $this->err($p . '/email', 'An e-mail address.');
                }
                break;
            default:
                // Unknown block types are stored and returned unchanged (forward compatible).
        }
    }

    // ================================================================ images

    /** @return array<string, true> */
    private function imageIds(): array
    {
        $ids = [];
        foreach ($this->app->db->column('SELECT id FROM obx_branding_images') as $id) {
            $ids[(string) $id] = true;
        }
        return $ids;
    }

    /**
     * Image ids the stored appearance refers to: login.background_image, and the image of every
     * block and of every item in a block (whatever its type, so a block type this node does not
     * know still keeps its image from being deleted).
     *
     * @return array<string, true>
     */
    public function referencedImages(): array
    {
        $a = $this->appearance();
        $refs = [];
        $add = static function (mixed $id) use (&$refs): void {
            if (is_string($id) && $id !== '') {
                $refs[$id] = true;
            }
        };
        if ($a['login'] instanceof \stdClass) {
            $add($a['login']->background_image ?? null);
        }
        foreach (self::blocks($a['home']) as $b) {
            $add($b->image ?? null);
            foreach ((is_array($b->items ?? null) ? $b->items : []) as $item) {
                if ($item instanceof \stdClass) {
                    $add($item->image ?? null);
                }
            }
        }
        return $refs;
    }

    /** @return list<\stdClass> */
    private static function blocks(mixed $home): array
    {
        if (!$home instanceof \stdClass || !is_array($home->blocks ?? null)) {
            return [];
        }
        return array_values(array_filter($home->blocks, static fn ($b) => $b instanceof \stdClass));
    }

    public function imageUrl(string $id): string
    {
        return $this->app->baseUrl() . '/admin/api/public/images/' . $id;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function imageJson(array $row, ?bool $used = null): array
    {
        $out = [
            'id' => (string) $row['id'],
            'url' => $this->imageUrl((string) $row['id']),
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'bytes' => (int) $row['bytes'],
            'content_type' => (string) $row['content_type'],
            'created_at' => (string) $row['created_at'],
        ];
        if ($used !== null) {
            $out['used'] = $used;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function images(): array
    {
        $refs = $this->referencedImages();
        return array_map(
            fn (array $r) => $this->imageJson($r, isset($refs[$r['id']])),
            $this->app->db->all('SELECT * FROM obx_branding_images ORDER BY created_at DESC, id DESC'),
        );
    }

    /** @return array<string, mixed>|null */
    public function image(string $id): ?array
    {
        return $this->app->db->one('SELECT * FROM obx_branding_images WHERE id = ?', [$id]);
    }

    /**
     * Stores an uploaded image file (PNG, JPEG or WebP, at most 10 MB and 8,000 px per side).
     *
     * @return array<string, mixed>
     */
    public function addImage(string $path): array
    {
        $bytes = (int) @filesize($path);
        if ($bytes > self::MAX_IMAGE_BYTES) {
            throw new ApiError('payload_too_large', 'A branding image is at most 10 MB.');
        }
        $type = ImageInfo::sniffFile($path);
        if ($type === null || !in_array($type, self::IMAGE_TYPES, true)) {
            throw new ApiError('unsupported_media_type', 'Upload a PNG, JPEG or WebP image.');
        }
        $dims = ImageInfo::dimensions($path);
        if ($dims === null) {
            throw ApiError::validation('/file', 'The image cannot be read.');
        }
        if ($dims[0] > self::MAX_IMAGE_SIDE || $dims[1] > self::MAX_IMAGE_SIDE) {
            throw ApiError::validation('/file', 'At most ' . self::MAX_IMAGE_SIDE . ' pixels per side.');
        }
        return $this->app->db->tx(function () use ($path, $dims): array {
            if ((int) $this->app->db->value('SELECT COUNT(*) FROM obx_branding_images') >= self::MAX_IMAGES) {
                throw new ApiError('limit_exceeded', 'At most ' . self::MAX_IMAGES . ' branding images; delete unused ones first.', [], 409);
            }
            $blob = $this->app->blobs->storeCopy($path, self::IMAGE_TYPES);
            $row = [
                'id' => Ids::new('img'),
                'sha256' => $blob['sha256'],
                'bytes' => $blob['bytes'],
                'content_type' => $blob['content_type'],
                'width' => $blob['width'] ?? $dims[0],
                'height' => $blob['height'] ?? $dims[1],
                'created_at' => Clock::iso(),
            ];
            $this->app->db->insert('obx_branding_images', $row);
            return $this->imageJson($row);
        });
    }

    public function deleteImage(string $id): void
    {
        $this->app->db->tx(function () use ($id): void {
            if ($this->image($id) === null) {
                throw new ApiError('not_found', 'No such branding image.');
            }
            if (isset($this->referencedImages()[$id])) {
                throw new ApiError('conflict', 'This image is still used in the branding (login page or home page). Remove it there first.');
            }
            $this->app->db->delete('obx_branding_images', ['id' => $id]);
        });
    }

    // ================================================================ resolved views

    /** login for public branding: background_image_url next to background_image. */
    public function resolvedLogin(): mixed
    {
        $login = $this->appearance()['login'];
        if (!$login instanceof \stdClass) {
            return $login;
        }
        $login = clone $login;
        $id = $login->background_image ?? null;
        $login->background_image_url = is_string($id) ? $this->urlIfExists($id) : null;
        return $login;
    }

    /** home for the portal viewer: every block (and tile) with an image gets image_url. */
    public function resolvedHome(): mixed
    {
        $home = $this->appearance()['home'];
        if (!$home instanceof \stdClass) {
            return $home;
        }
        $home = json_decode(Json::encode($home), false); // deep copy
        foreach (self::blocks($home) as $b) {
            if (property_exists($b, 'image')) {
                $b->image_url = is_string($b->image) ? $this->urlIfExists($b->image) : null;
            }
            foreach ((is_array($b->items ?? null) ? $b->items : []) as $item) {
                if ($item instanceof \stdClass && property_exists($item, 'image')) {
                    $item->image_url = is_string($item->image) ? $this->urlIfExists($item->image) : null;
                }
            }
        }
        return $home;
    }

    private function urlIfExists(string $id): ?string
    {
        return $this->image($id) !== null ? $this->imageUrl($id) : null;
    }

    // ================================================================ favicon

    public function faviconUrl(): ?string
    {
        $b = $this->app->settings->all()['branding'];
        if (empty($b['favicon_sha'])) {
            return null;
        }
        return $this->app->baseUrl() . '/admin/api/public/favicon/' . $b['favicon_sha'] . '.' . ImageInfo::extension((string) ($b['favicon_type'] ?? 'image/png'));
    }
}
