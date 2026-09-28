<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Config;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;

final class DiscoveryController extends Controller
{
    public function wellKnown(Request $req): Response
    {
        $this->app->limiter->hit('disc:' . $this->app->clientIp($req), 600, 60);
        $res = Response::json($this->document());
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        $s = $this->app->settings->all();
        $base = $this->app->baseUrl();
        $caps = ['changes', 'products', 'by_gtin', 'renditions', 'exports', 'webhooks', 'usage'];
        if ($s['portal']['access_requests_enabled']) {
            $caps[] = 'access_requests';
        }
        $caps[] = 'ingest';
        if ($this->app->renditions->serverGenerated()) {
            $caps[] = 'ingest.server_renditions';
        }
        $caps[] = 'portal';
        $brands = [];
        foreach ($this->app->db->all('SELECT id, name, rep FROM obx_brands WHERE visible = 1 ORDER BY name, id') as $b) {
            $rep = json_decode((string) $b['rep'], true);
            $brands[] = ['id' => $b['id'], 'name' => $b['name'], 'logo_url' => $rep['logo_url'] ?? null];
        }
        return [
            'obx_version' => Config::OBX_VERSION,
            'spec_version' => Config::SPEC_VERSION,
            'node' => ['software' => Config::SOFTWARE, 'version' => Config::VERSION],
            'publisher' => [
                'id' => $this->app->settings->nodeId(),
                'name' => $this->app->settings->displayName(),
                'logo_url' => $this->logoUrl(),
                'accent_color' => $s['branding']['accent_color'],
                'website' => $s['company']['website'],
                'contact_email' => $s['company']['contact_email'],
                'welcome_text' => $s['branding']['welcome_text'],
            ],
            'api_base' => $base . '/obx/v1',
            'portal_url' => $base . '/',
            'auth' => [
                'token_endpoint' => $base . '/obx/v1/oauth/token',
                'scopes_supported' => ['catalog:read', 'media:read', 'media:original', 'usage:write'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            ],
            'access_requests_url' => $s['portal']['access_requests_enabled'] ? $base . '/obx/v1/access-requests' : null,
            'ingest_url' => $base . '/obx/v1/ingest',
            'capabilities' => $caps,
            'brands' => $brands,
            'languages' => $this->app->settings->languages(),
            'limits' => [
                'changes_page_max' => 500,
                'products_page_max' => 100,
                'gtin_lookup_max' => 2000,
                'tombstone_retention_days' => 90,
            ],
        ];
    }

    public function logoUrl(): ?string
    {
        $b = $this->app->settings->all()['branding'];
        if (empty($b['logo_sha'])) {
            return null;
        }
        $ext = match ($b['logo_type'] ?? '') {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        return $this->app->baseUrl() . '/admin/api/public/logo/' . $b['logo_sha'] . '.' . $ext;
    }
}
