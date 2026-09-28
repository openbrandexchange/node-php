<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Brand admin: setup, sessions, invitations, access requests, keys, catalog, import sources.
 */
final class AdminTest extends NodeTestCase
{
    public function testSetupNeedsTheCodeAndHappensOnce(): void
    {
        $this->assertProblem($this->http('GET', '/.well-known/obx'), 503, 'setup_required');
        $this->assertProblem($this->http('GET', '/admin/api/settings', ['browser' => 'admin']), 409, 'setup_required');
        $this->assertTrue($this->json($this->http('GET', '/admin/api/setup'))['required']);
        $session = $this->json($this->http('GET', '/admin/api/session', ['browser' => 'admin']));
        $this->assertTrue($session['setup_required']);
        $this->assertFalse($session['authenticated']);
        $body = [
            'setup_code' => 'WRONG-CODE-0000',
            'company' => ['name' => 'Kestrel Outfitters', 'first_brand_name' => 'Kestrel', 'default_language' => 'nl', 'languages' => ['nl', 'en']],
            'admin' => ['name' => 'Ada', 'email' => 'admin@kestrel.example', 'password' => 'correct horse battery'],
        ];
        $this->assertProblem($this->http('POST', '/admin/api/setup', ['browser' => 'admin', 'json' => $body]), 403, 'forbidden');
        $weak = $body;
        $weak['setup_code'] = self::SETUP_CODE;
        $weak['admin']['password'] = 'password123';
        $this->assertProblem($this->http('POST', '/admin/api/setup', ['browser' => 'admin', 'json' => $weak]), 422, 'validation_failed');
        $body['setup_code'] = self::SETUP_CODE;
        $done = $this->json($this->http('POST', '/admin/api/setup', ['browser' => 'admin', 'json' => $body]), 201);
        $this->assertTrue($done['authenticated']);
        $this->assertSame('admin', $done['user']['role']);
        $this->assertSame('kestrel', $this->app->db->value('SELECT external_id FROM obx_brands'));
        $this->assertProblem($this->http('POST', '/admin/api/setup', ['browser' => 'admin', 'json' => $body]), 409, 'setup_completed');
        $this->assertSame(200, $this->http('GET', '/.well-known/obx')->status);
    }

    public function testLoginLogoutCsrfAndCookieFlags(): void
    {
        $this->freshNode();
        $this->http('GET', '/admin/api/session', ['browser' => 'b']);
        $this->assertProblem($this->http('POST', '/admin/api/session/login', ['browser' => 'b', 'json' => ['email' => 'admin@kestrel.example', 'password' => 'wrong password!']]), 401, 'invalid_credentials');
        $this->assertProblem($this->http('POST', '/admin/api/session/login', ['browser' => 'b', 'csrf' => false, 'json' => ['email' => 'admin@kestrel.example', 'password' => 'correct horse battery']]), 403, 'csrf_failed');
        $res = $this->http('POST', '/admin/api/session/login', ['browser' => 'b', 'json' => ['email' => 'admin@kestrel.example', 'password' => 'correct horse battery']]);
        $s = $this->json($res);
        $this->assertTrue($s['authenticated']);
        $cookie = implode("\n", $res->cookies);
        $this->assertStringContainsString('obx_session=', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertSame(200, $this->http('GET', '/admin/api/settings', ['browser' => 'b'])->status);
        $this->assertProblem($this->http('PATCH', '/admin/api/settings', ['browser' => 'b', 'csrf' => false, 'json' => []]), 403, 'csrf_failed');
        $this->assertSame(204, $this->http('POST', '/admin/api/session/logout', ['browser' => 'b'])->status);
        $this->assertProblem($this->http('GET', '/admin/api/settings', ['browser' => 'b']), 401, 'unauthorized');
    }

    public function testInviteWorksOnce(): void
    {
        $this->freshNode();
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'Shop Invite']]), 201);
        $inv = $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'buyer@shop.example', 'role' => 'retailer', 'retailer_id' => $r['id'], 'language' => 'nl']]), 201);
        $this->assertTrue($inv['email_sent']);
        $mail = end($this->app->mailer->sent);
        $this->assertSame('buyer@shop.example', $mail['to']);
        $this->assertStringContainsString($inv['invite_url'], $mail['text']);
        $this->assertStringContainsString('Kestrel Outfitters · Beeldbank', $mail['subject']);
        $token = substr($inv['invite_url'], strrpos($inv['invite_url'], '/') + 1);
        $info = $this->json($this->http('GET', '/admin/api/invites/' . $token));
        $this->assertSame('Shop Invite', $info['retailer_name']);
        $this->http('GET', '/admin/api/session', ['browser' => 'buyer']);
        $s = $this->json($this->http('POST', '/admin/api/invites/' . $token . '/accept', ['browser' => 'buyer', 'json' => ['password' => 'a very good passphrase', 'name' => 'Bo Buyer']]));
        $this->assertSame('retailer', $s['user']['role']);
        $this->assertSame('Shop Invite', $s['retailer']['name']);
        $this->assertProblem($this->http('GET', '/admin/api/invites/' . $token), 400, 'invite_invalid');
        $this->assertProblem($this->http('POST', '/admin/api/invites/' . $token . '/accept', ['browser' => 'buyer', 'json' => ['password' => 'a very good passphrase']]), 400, 'invite_invalid');
        $this->assertProblem($this->http('GET', '/admin/api/settings', ['browser' => 'buyer']), 403, 'forbidden');
        $this->assertProblem($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'buyer@shop.example', 'role' => 'retailer', 'retailer_id' => $r['id']]]), 409, 'conflict');
    }

    public function testAccessRequestApprovalHandsOutCredentialsOnce(): void
    {
        $this->freshNode();
        $req = $this->json($this->http('POST', '/obx/v1/access-requests', ['json' => [
            'retailer' => ['name' => 'Stad & Land Mode', 'country' => 'NL'],
            'contact' => ['name' => 'Sam', 'email' => 'sam@stadland.example'],
            'access' => ['portal', 'api'],
        ], 'headers' => ['Accept-Language' => 'de-DE,de;q=0.9']]), 202);
        $this->assertSame('pending', $req['status']);
        $this->assertStringStartsWith('obxr_', $req['request_token']);
        $to = array_column($this->app->mailer->sent, 'subject', 'to');
        $this->assertSame('New access request from Stad & Land Mode', $to['admin@kestrel.example']);
        $this->assertSame('We received your request for Kestrel Outfitters · Händlerportal', $to['sam@stadland.example']);
        $poll = $this->json($this->http('GET', '/obx/v1/access-requests/' . $req['id'], ['bearer' => $req['request_token']]));
        $this->assertSame('pending', $poll['status']);
        $this->assertProblem($this->http('GET', '/obx/v1/access-requests/' . $req['id'], ['bearer' => 'obxr_wrong']), 404, 'not_found');
        $list = $this->json($this->http('GET', '/admin/api/access-requests', ['browser' => 'admin', 'query' => ['status' => 'pending']]));
        $this->assertCount(1, $list['items']);
        $approved = $this->json($this->http('POST', '/admin/api/access-requests/' . $req['id'] . '/approve', ['browser' => 'admin', 'json' => []]));
        $this->assertArrayHasKey('invite', $approved);
        $this->assertArrayHasKey('api_client', $approved);
        $this->assertArrayNotHasKey('client_secret', $approved['api_client']);
        $first = $this->json($this->http('GET', '/obx/v1/access-requests/' . $req['id'], ['bearer' => $req['request_token']]));
        $this->assertSame('approved', $first['status']);
        $this->assertStringStartsWith('obxs_', $first['credentials']['client_secret']);
        $this->assertTrue($first['credentials']['claimed']);
        $this->assertTrue($first['portal']['invited']);
        $second = $this->json($this->http('GET', '/obx/v1/access-requests/' . $req['id'], ['bearer' => $req['request_token']]));
        $this->assertNull($second['credentials']['client_secret']);
        $this->assertTrue($second['credentials']['claimed']);
        $token = $this->accessToken($first['credentials']['client_id'], $first['credentials']['client_secret']);
        $grant = $this->json($this->http('GET', '/obx/v1/grant', ['bearer' => $token]));
        $this->assertSame('Stad & Land Mode', $grant['retailer']['name']);
        // The invitation e-mail is in the requester's browser language.
        $invite = null;
        foreach ($this->app->mailer->sent as $m) {
            if ($m['to'] === 'sam@stadland.example' && str_contains($m['text'], '/invite/')) {
                $invite = $m;
            }
        }
        $this->assertNotNull($invite);
        $this->assertStringContainsString('Händlerportal', $invite['subject']);
        $this->assertProblem($this->http('POST', '/admin/api/access-requests/' . $req['id'] . '/approve', ['browser' => 'admin', 'json' => []]), 409, 'conflict');

        $req2 = $this->json($this->http('POST', '/obx/v1/access-requests', ['json' => ['retailer' => ['name' => 'Nope BV'], 'contact' => ['name' => 'N', 'email' => 'n@nope.example']]]), 202);
        $this->json($this->http('POST', '/admin/api/access-requests/' . $req2['id'] . '/reject', ['browser' => 'admin', 'json' => ['reason' => 'We only work with shops in the Benelux.']]));
        $seen = $this->json($this->http('GET', '/obx/v1/access-requests/' . $req2['id'], ['bearer' => $req2['request_token']]));
        $this->assertSame('rejected', $seen['status']);
        $this->assertSame('We only work with shops in the Benelux.', $seen['reason']);
    }

    public function testRenewingAnApprovedKeyRetiresTheSecretWaitingForSoftware(): void
    {
        $this->freshNode();
        $req = $this->json($this->http('POST', '/obx/v1/access-requests', ['json' => [
            'retailer' => ['name' => 'Web Form Shop', 'country' => 'DE'],
            'contact' => ['name' => 'Wim', 'email' => 'wim@webform.example'],
            'access' => ['api'],
        ]]), 202);
        $approved = $this->json($this->http('POST', '/admin/api/access-requests/' . $req['id'] . '/approve', ['browser' => 'admin', 'json' => []]));
        // The brand shows the key itself ("Show the key" in the admin): a renewed secret.
        $renewed = $this->json($this->http('POST', '/admin/api/api-clients/' . $approved['api_client']['id'] . '/rotate', ['browser' => 'admin']));
        $this->assertStringStartsWith('obxs_', $renewed['client_secret']);
        $poll = $this->json($this->http('GET', '/obx/v1/access-requests/' . $req['id'], ['bearer' => $req['request_token']]));
        $this->assertNull($poll['credentials']['client_secret'], 'the waiting secret stopped working and is not handed out');
        $this->assertTrue($poll['credentials']['claimed']);
    }

    public function testNewKeysGetCatalogAndMediaScopesAndUsageOnlyWhenAsked(): void
    {
        $this->kestrelNode();
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'Scope Shop']]), 201);
        $default = $this->json($this->http('POST', '/admin/api/api-clients', ['browser' => 'admin', 'json' => ['retailer_id' => $r['id'], 'name' => 'PIM']]), 201);
        $this->assertSame(['catalog:read', 'media:read', 'media:original'], $default['client']['scopes']);
        $asked = $this->json($this->http('POST', '/admin/api/api-clients', ['browser' => 'admin', 'json' => ['retailer_id' => $r['id'], 'name' => 'Stats', 'scopes' => ['catalog:read', 'usage:write']]]), 201);
        $this->assertSame(['catalog:read', 'usage:write'], $asked['client']['scopes']);
        $noOriginals = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'No Originals', 'allow_original' => false]]), 201);
        $plain = $this->json($this->http('POST', '/admin/api/api-clients', ['browser' => 'admin', 'json' => ['retailer_id' => $noOriginals['id'], 'name' => 'PIM']]), 201);
        $this->assertSame(['catalog:read', 'media:read'], $plain['client']['scopes']);
    }

    public function testPortalOnlyApprovalSendsJustTheInvitation(): void
    {
        $this->freshNode();
        $req = $this->json($this->http('POST', '/obx/v1/access-requests', ['json' => [
            'retailer' => ['name' => 'Harbour Street Shoes'],
            'contact' => ['name' => 'Sam', 'email' => 'buyer@harbour.example'],
            'access' => ['portal'],
        ]]), 202);
        $toAdmin = array_values(array_filter($this->app->mailer->sent, fn ($m) => $m['to'] === 'admin@kestrel.example'));
        // The link opens the admin's list of access requests, a page the web interface has.
        $this->assertStringContainsString('http://localhost/admin/requests', end($toAdmin)['text']);
        $before = count($this->app->mailer->sent);
        $this->json($this->http('POST', '/admin/api/access-requests/' . $req['id'] . '/approve', ['browser' => 'admin', 'json' => []]));
        $after = array_slice($this->app->mailer->sent, $before);
        $this->assertCount(1, $after, 'one e-mail: the invitation with the link to choose a password');
        $this->assertSame('buyer@harbour.example', $after[0]['to']);
        $this->assertStringContainsString('/invite/', $after[0]['text']);
    }

    public function testBlockingARetailerRevokesSessionsAndApiAccess(): void
    {
        $this->kestrelNode();
        [$rid, $id, $secret] = $this->retailerClient(['name' => 'Blocked Shop']);
        $token = $this->accessToken($id, $secret);
        $inv = $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'b@blocked.example', 'role' => 'retailer', 'retailer_id' => $rid]]), 201);
        $t = substr($inv['invite_url'], strrpos($inv['invite_url'], '/') + 1);
        $this->http('GET', '/admin/api/session', ['browser' => 'r']);
        $this->json($this->http('POST', '/admin/api/invites/' . $t . '/accept', ['browser' => 'r', 'json' => ['password' => 'a very good passphrase']]));
        $this->assertSame(200, $this->http('GET', '/admin/api/portal/viewer', ['browser' => 'r'])->status);
        $this->json($this->http('PATCH', '/admin/api/retailers/' . $rid, ['browser' => 'admin', 'json' => ['status' => 'blocked']]));
        $this->assertProblem($this->http('GET', '/admin/api/portal/viewer', ['browser' => 'r']), 401, 'unauthorized');
        $this->assertProblem($this->http('GET', '/obx/v1/changes', ['bearer' => $token]), 401, 'access_revoked');
        $res = $this->http('POST', '/obx/v1/oauth/token', ['basic' => [$id, $secret], 'post' => ['grant_type' => 'client_credentials']]);
        $this->assertSame('invalid_client', $this->json($res, 401)['error']);
    }

    public function testKeysAreShownOnceRotateAndRevoke(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient(['name' => 'Key Shop']);
        $list = $this->json($this->http('GET', '/admin/api/api-clients', ['browser' => 'admin']));
        $this->assertStringNotContainsString($secret, Json::encode($list));
        $clientRow = null;
        foreach ($list['items'] as $c) {
            if ($c['client_id'] === $id) {
                $clientRow = $c;
            }
        }
        $rot = $this->json($this->http('POST', '/admin/api/api-clients/' . $clientRow['id'] . '/rotate', ['browser' => 'admin']));
        $res = $this->http('POST', '/obx/v1/oauth/token', ['basic' => [$id, $secret], 'post' => ['grant_type' => 'client_credentials']]);
        $this->assertSame(401, $res->status);
        $token = $this->accessToken($id, $rot['client_secret']);
        $this->assertSame(204, $this->http('DELETE', '/admin/api/api-clients/' . $clientRow['id'], ['browser' => 'admin'])->status);
        $this->assertProblem($this->http('GET', '/obx/v1/grant', ['bearer' => $token]), 401, 'access_revoked');

        $created = $this->json($this->http('POST', '/admin/api/ingest-tokens', ['browser' => 'admin', 'json' => ['name' => 'PIM', 'scopes' => ['ingest:read']]]), 201);
        $this->assertStringStartsWith('obxi_', $created['secret']);
        $this->assertStringStartsWith(substr($created['secret'], 0, 12), $created['token']['prefix']);
        $this->assertStringNotContainsString($created['secret'], Json::encode($this->json($this->http('GET', '/admin/api/ingest-tokens', ['browser' => 'admin']))));
        $rotated = $this->json($this->http('POST', '/admin/api/ingest-tokens/' . $created['token']['id'] . '/rotate', ['browser' => 'admin']));
        $this->assertSame(401, $this->http('GET', '/obx/v1/ingest', ['bearer' => $created['secret']])->status);
        $this->assertSame(200, $this->http('GET', '/obx/v1/ingest', ['bearer' => $rotated['secret']])->status);
    }

    public function testAdminCatalogWritesBehaveLikeIngest(): void
    {
        $this->freshNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $r = $this->json($this->http('PUT', '/admin/api/catalog/products/AC-1', ['browser' => 'admin', 'json' => ['style_number' => 'AC-1', 'name' => ['nl' => 'Trui']]]), 201);
        $this->assertSame('created', $r['result']);
        $this->assertSame('unchanged', $this->json($this->http('PUT', '/admin/api/catalog/products/AC-1', ['browser' => 'admin', 'json' => ['style_number' => 'AC-1', 'name' => ['nl' => 'Trui']]]))['result']);
        $this->assertSame('unpublish', $this->json($this->http('PATCH', '/admin/api/catalog/products/AC-1', ['browser' => 'admin', 'json' => ['status' => 'unpublished'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]))['op']);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame([['product', 'delete']], array_map(static fn ($c) => [$c['type'], $c['op']], $page['changes']));
        $list = $this->json($this->http('GET', '/admin/api/catalog/products', ['browser' => 'admin', 'query' => ['q' => 'trui']]));
        $this->assertSame(1, $list['total']);
        $this->assertSame('hidden', $list['items'][0]['visibility']);
        $detail = $this->json($this->http('GET', '/admin/api/catalog/products/AC-1', ['browser' => 'admin']));
        $this->assertSame('unpublished', $detail['record']['status']);
        $brands = $this->json($this->http('GET', '/admin/api/catalog/brands', ['browser' => 'admin']));
        $this->assertSame('kestrel', $brands['items'][0]['external_id']);
    }

    public function testImportSourcesRefusePrivateAddressesAndHideCredentials(): void
    {
        $this->freshNode();
        $this->app = $this->makeApp(['allow_private_urls' => false]);
        $res = $this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => ['name' => 'Local', 'kind' => 'https_url', 'config' => ['url' => 'https://127.0.0.1/products.csv']]]);
        $this->assertProblem($res, 422, 'unsafe_url');
        $res = $this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => ['name' => 'Metadata', 'kind' => 'https_url', 'config' => ['url' => 'http://169.254.169.254/latest']]]);
        $this->assertProblem($res, 422, 'unsafe_url');
        $src = $this->json($this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => [
            'name' => 'Bucket', 'kind' => 's3', 'schedule' => ['frequency' => 'daily', 'time' => '03:30', 'timezone' => 'Europe/Amsterdam'],
            'config' => ['region' => 'eu-west-1', 'bucket' => 'kestrel-exports', 'prefix' => 'obx/'],
            'credentials' => ['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 'very-secret-value'],
        ]]), 201);
        $this->assertTrue($src['credentials_set']['secret_access_key']);
        $this->assertStringNotContainsString('very-secret-value', Json::encode($src));
        $this->assertNotNull($src['next_run_at']);
        $got = $this->json($this->http('GET', '/admin/api/import-sources/' . $src['id'], ['browser' => 'admin']));
        $this->assertStringNotContainsString('very-secret-value', Json::encode($got));
        $this->assertStringNotContainsString('very-secret-value', (string) $this->app->db->value('SELECT credentials_enc FROM obx_import_sources'));
        $patched = $this->json($this->http('PATCH', '/admin/api/import-sources/' . $src['id'], ['browser' => 'admin', 'json' => ['credentials' => ['secret_access_key' => null]]]));
        $this->assertFalse($patched['credentials_set']['secret_access_key']);
        $this->assertTrue($patched['credentials_set']['access_key_id']);
    }

    public function testSettingsBrandingAndOriginalToggleWritesGrantResync(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $s = $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => [
            'branding' => ['accent_color' => '#123456', 'welcome_text' => ['nl' => 'Welkom', 'en' => 'Welcome'], 'portal_name' => ['en' => 'Media centre']],
            'portal' => ['allow_original_download' => false],
        ], 'headers' => ['Content-Type' => 'application/merge-patch+json']]));
        $this->assertSame('#123456', $s['branding']['accent_color']);
        $this->assertFalse($s['portal']['allow_original_download']);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame('grant', $page['changes'][0]['type']);
        $this->assertTrue($page['changes'][0]['resync']);
        $this->assertFalse($page['changes'][0]['data']['original_allowed']);
        $branding = $this->json($this->http('GET', '/admin/api/public/branding'));
        $this->assertSame(['en' => 'Welcome', 'nl' => 'Welkom'], $branding['welcome_text']);
        // Logo upload (PNG), public URL, no SVG.
        $png = (string) file_get_contents(self::fixtures('kestrel/images/KO-1001_NVY_1_front.png'));
        $withLogo = $this->json($this->http('PUT', '/admin/api/settings/logo', ['browser' => 'admin', 'body' => $png, 'headers' => ['Content-Type' => 'image/png']]));
        $logo = $withLogo['branding']['logo_url'];
        $this->assertNotNull($logo);
        $logoRes = $this->http('GET', substr($logo, strlen('http://localhost')));
        $this->assertSame($png, $logoRes->content());
        $this->assertNull($logoRes->getHeader('Access-Control-Allow-Origin'), 'CORS only on the discovery document');
        $this->assertProblem($this->http('PUT', '/admin/api/settings/logo', ['browser' => 'admin', 'body' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'headers' => ['Content-Type' => 'image/svg+xml']]), 415, 'unsupported_media_type');
        // Default rights change re-publishes assets with new effective rights.
        [, $cursor] = $this->drainSnapshot($client);
        $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['defaults' => ['rights' => ['channels' => ['webshop']]]]]));
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor, 'limit' => '500']]));
        $this->assertCount(27, array_filter($page['changes'], static fn ($c) => $c['type'] === 'asset' && $c['op'] === 'upsert'));
        $this->assertSame(['webshop'], $page['changes'][0]['data']['rights']['channels']);
    }

    public function testEmailsUseTheVendoredLocaleFilesAndTheBrandsOwnPortalName(): void
    {
        $this->freshNode();
        mkdir($this->dir . '/ui/locales', 0777, true);
        file_put_contents($this->dir . '/ui/locales/nl.json', json_encode([
            'glossary' => ['portal' => 'Beeldbank'],
            'email' => [
                'greeting' => 'Hallo {{name}},',
                'invite' => ['subject' => '{{brand}} nodigt je uit voor {{portal}}', 'body' => 'Welkom bij {{brand}}.', 'button' => 'Kies je wachtwoord', 'expiry' => 'Geldig tot {{expires}}.'],
            ],
        ]));
        $this->app = $this->makeApp(['locales_dir' => $this->dir . '/ui/locales']);
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'Winkel']]), 201);
        $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'nl@shop.example', 'name' => 'Noor', 'role' => 'retailer', 'retailer_id' => $r['id'], 'language' => 'nl']]), 201);
        $mail = end($this->app->mailer->sent);
        $this->assertSame('Kestrel Outfitters nodigt je uit voor Kestrel Outfitters · Beeldbank', $mail['subject']);
        $this->assertStringContainsString('Hallo Noor,', $mail['text']);
        $this->assertStringContainsString('Kies je wachtwoord', $mail['html']);
        $this->assertStringContainsString('Not expecting this?', $mail['text'], 'missing keys fall back to English');
        $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['branding' => ['portal_name' => ['nl' => 'Mediatheek']]]]));
        $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'nl2@shop.example', 'role' => 'retailer', 'retailer_id' => $r['id'], 'language' => 'nl']]), 201);
        $this->assertSame('Kestrel Outfitters nodigt je uit voor Kestrel Outfitters · Mediatheek', end($this->app->mailer->sent)['subject']);
        $branding = $this->json($this->http('GET', '/admin/api/public/branding'));
        $this->assertSame(['nl' => 'Mediatheek'], $branding['portal_name']);
    }

    public function testPasswordResetAndOwnAccount(): void
    {
        $this->freshNode();
        $this->http('GET', '/admin/api/session', ['browser' => 'anon']);
        $this->assertSame(202, $this->http('POST', '/admin/api/password/forgot', ['browser' => 'anon', 'json' => ['email' => 'nobody@nowhere.example']])->status);
        $this->assertSame(202, $this->http('POST', '/admin/api/password/forgot', ['browser' => 'anon', 'json' => ['email' => 'admin@kestrel.example']])->status);
        $mail = end($this->app->mailer->sent);
        $this->assertSame('admin@kestrel.example', $mail['to']);
        $this->assertSame(1, preg_match('#/reset-password/(\S+)#', $mail['text'], $m));
        $token = $m[1];
        $s = $this->json($this->http('POST', '/admin/api/password/reset', ['browser' => 'anon', 'json' => ['token' => $token, 'password' => 'another good passphrase']]));
        $this->assertTrue($s['authenticated']);
        $this->assertProblem($this->http('POST', '/admin/api/password/reset', ['browser' => 'anon', 'json' => ['token' => $token, 'password' => 'another good passphrase']]), 400, 'invite_invalid');
        $this->assertProblem($this->http('GET', '/admin/api/settings', ['browser' => 'admin']), 401, 'unauthorized');
        $this->assertProblem($this->http('PATCH', '/admin/api/me', ['browser' => 'anon', 'json' => ['new_password' => 'yet another passphrase', 'current_password' => 'wrong']]), 401, 'invalid_credentials');
        $me = $this->json($this->http('PATCH', '/admin/api/me', ['browser' => 'anon', 'json' => ['name' => 'Ada Lovelace', 'language' => 'de']]));
        $this->assertSame('Ada Lovelace', $me['user']['name']);
        $this->assertSame('de', $me['user']['language']);
        $users = $this->json($this->http('GET', '/admin/api/users', ['browser' => 'anon', 'query' => ['role' => 'admin']]));
        $this->assertProblem($this->http('PATCH', '/admin/api/users/' . $users['items'][0]['id'], ['browser' => 'anon', 'json' => ['status' => 'blocked']]), 409, 'conflict');
        $this->assertProblem($this->http('DELETE', '/admin/api/users/' . $users['items'][0]['id'], ['browser' => 'anon']), 409, 'conflict');
    }

    public function testEmailLinksIgnoreAForgedHostHeader(): void
    {
        $this->app = $this->makeApp(['public_url' => null]);
        $this->setupNode();
        $this->http('GET', '/admin/api/session', ['browser' => 'x', 'host' => 'evil.example']);
        $this->http('POST', '/admin/api/password/forgot', ['browser' => 'x', 'host' => 'evil.example', 'json' => ['email' => 'admin@kestrel.example']]);
        $mail = end($this->app->mailer->sent);
        $this->assertStringContainsString('http://localhost/reset-password/', $mail['text']);
        $this->assertStringNotContainsString('evil.example', $mail['text']);
    }
}
