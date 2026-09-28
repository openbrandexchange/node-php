<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\Db\Db;
use Obx\Node\Support\Json;

/**
 * Node settings (brand profile, branding, portal switches, defaults) and node state
 * (setup done, node id, setup code), stored as JSON rows in obx_settings.
 */
final class Settings
{
    public const DEFAULT_RIGHTS = [
        'license' => 'retailer-use',
        'license_text' => null,
        'channels' => ['webshop', 'marketplace', 'social'],
        'territories' => null,
        'embargo_until' => null,
        'expires_at' => null,
        'credit' => null,
        'model_release' => null,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;
    /** @var array<string, mixed>|null */
    private ?array $stateCache = null;

    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'company' => ['name' => 'Open Brand Exchange', 'website' => null, 'contact_email' => null, 'country' => null],
            'branding' => [
                'display_name' => null,
                'logo_sha' => null,
                'logo_type' => null,
                'accent_color' => null,
                'welcome_text' => null,
                'portal_word' => null,
            ],
            'portal' => [
                'access_requests_enabled' => true,
                'allow_original_download' => true,
                'allow_article_data_export' => true,
                'retailer_api_self_service' => false,
                'languages' => ['en'],
                'default_language' => 'en',
            ],
            'defaults' => [
                'rights' => self::DEFAULT_RIGHTS,
                'ingest_status' => 'published',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $raw = $this->db->value("SELECT v FROM obx_settings WHERE k = 'settings'");
            $stored = Json::decode(is_string($raw) ? $raw : null, []);
            $this->cache = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
            // Lists must replace, not merge, so re-apply them from storage.
            foreach ([['portal', 'languages'], ['defaults', 'rights']] as [$a, $b]) {
                if (isset($stored[$a][$b])) {
                    $this->cache[$a][$b] = $stored[$a][$b];
                }
            }
        }
        return $this->cache;
    }

    /** @param array<string, mixed> $settings */
    public function save(array $settings): void
    {
        $json = Json::encode($settings);
        if ($this->db->value("SELECT k FROM obx_settings WHERE k = 'settings'") === null) {
            $this->db->insert('obx_settings', ['k' => 'settings', 'v' => $json]);
        } else {
            $this->db->run("UPDATE obx_settings SET v = ? WHERE k = 'settings'", [$json]);
        }
        $this->cache = null;
    }

    public function get(string $section, string $key): mixed
    {
        return $this->all()[$section][$key] ?? null;
    }

    /** @return array<string, mixed> full default rights */
    public function defaultRights(): array
    {
        return array_replace(self::DEFAULT_RIGHTS, (array) ($this->all()['defaults']['rights'] ?? []));
    }

    public function defaultStatus(): string
    {
        return ($this->all()['defaults']['ingest_status'] ?? 'published') === 'unpublished' ? 'unpublished' : 'published';
    }

    /** @return list<string> content languages, default first */
    public function languages(): array
    {
        $p = $this->all()['portal'];
        $default = (string) ($p['default_language'] ?? 'en');
        $langs = array_values(array_unique(array_merge([$default], (array) ($p['languages'] ?? []))));
        return $langs;
    }

    public function defaultLanguage(): string
    {
        return $this->languages()[0];
    }

    public function displayName(): string
    {
        $s = $this->all();
        return (string) ($s['branding']['display_name'] ?: $s['company']['name']);
    }

    // ---------------------------------------------------------------- node state

    /** @return array<string, mixed> */
    public function state(): array
    {
        if ($this->stateCache === null) {
            $raw = $this->db->value("SELECT v FROM obx_settings WHERE k = 'state'");
            $v = Json::decode(is_string($raw) ? $raw : null, []);
            $this->stateCache = is_array($v) ? $v : [];
        }
        return $this->stateCache;
    }

    /** @param array<string, mixed> $changes */
    public function updateState(array $changes): void
    {
        $state = array_replace($this->state(), $changes);
        $json = Json::encode($state);
        if ($this->db->value("SELECT k FROM obx_settings WHERE k = 'state'") === null) {
            $this->db->insert('obx_settings', ['k' => 'state', 'v' => $json]);
        } else {
            $this->db->run("UPDATE obx_settings SET v = ? WHERE k = 'state'", [$json]);
        }
        $this->stateCache = $state;
    }

    public function setupDone(): bool
    {
        return !empty($this->state()['setup_done']);
    }

    public function nodeId(): string
    {
        $id = $this->state()['node_id'] ?? null;
        if (!is_string($id) || $id === '') {
            $id = \Obx\Node\Support\Ids::new('pub');
            $this->updateState(['node_id' => $id]);
        }
        return $id;
    }

    public function clearCache(): void
    {
        $this->cache = null;
        $this->stateCache = null;
    }
}
