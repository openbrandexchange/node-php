<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;
use Obx\Node\Support\Lang;

/**
 * Retailer access requests: public form / protocol endpoint, brand approval, one-time hand-over
 * of API credentials to the requester.
 */
final class AccessRequests
{
    public function __construct(private App $app)
    {
    }

    /** @return array<string, mixed> validated AccessRequestInput */
    private function validate(mixed $in): array
    {
        if (!is_array($in) || array_is_list($in)) {
            throw ApiError::validation('', 'Send an object with retailer and contact.');
        }
        $r = $in['retailer'] ?? null;
        $c = $in['contact'] ?? null;
        $errors = [];
        if (!is_array($r) || !is_string($r['name'] ?? null) || trim($r['name']) === '' || mb_strlen($r['name'], 'UTF-8') > 200) {
            $errors[] = ['pointer' => '/retailer/name', 'detail' => 'Enter your company or shop name.'];
        }
        if (!is_array($c) || !is_string($c['name'] ?? null) || trim($c['name']) === '' || mb_strlen($c['name'], 'UTF-8') > 200) {
            $errors[] = ['pointer' => '/contact/name', 'detail' => 'Enter your name.'];
        }
        if (!is_array($c) || !is_string($c['email'] ?? null) || !filter_var(trim((string) $c['email']), FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['pointer' => '/contact/email', 'detail' => 'Enter a valid e-mail address.'];
        }
        $website = is_array($r) ? ($r['website'] ?? null) : null;
        if ($website !== null && (!is_string($website) || !preg_match('#^https?://\S+$#i', $website) || strlen($website) > 2048)) {
            $errors[] = ['pointer' => '/retailer/website', 'detail' => 'A web address starting with https://.'];
        }
        $country = is_array($r) ? ($r['country'] ?? null) : null;
        if ($country !== null && (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country))) {
            $errors[] = ['pointer' => '/retailer/country', 'detail' => 'Two capital letters, e.g. NL.'];
        }
        $access = $in['access'] ?? ['portal'];
        if (!is_array($access) || $access === [] || array_diff($access, ['portal', 'api']) !== []) {
            $errors[] = ['pointer' => '/access', 'detail' => 'Choose portal and/or api.'];
            $access = ['portal'];
        }
        $brands = $in['requested_brand_ids'] ?? null;
        if ($brands !== null && (!is_array($brands) || !array_is_list($brands))) {
            $errors[] = ['pointer' => '/requested_brand_ids', 'detail' => 'A list of brand ids, or null.'];
            $brands = null;
        }
        $message = $in['message'] ?? null;
        if ($message !== null && (!is_string($message) || mb_strlen($message, 'UTF-8') > 2000)) {
            $errors[] = ['pointer' => '/message', 'detail' => 'At most 2000 characters.'];
        }
        $lang = is_array($c) ? ($c['language'] ?? null) : null;
        if ($lang !== null && (!is_string($lang) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $lang))) {
            $errors[] = ['pointer' => '/contact/language', 'detail' => 'A language code such as nl or en.'];
        }
        if ($errors !== []) {
            throw new ApiError('validation_failed', 'Please check the highlighted fields.', $errors);
        }
        $s = static fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max, 'UTF-8') : null;
        return [
            'retailer' => [
                'name' => trim((string) $r['name']),
                'reference' => $s($r['reference'] ?? null, 100),
                'website' => $website,
                'vat_number' => $s($r['vat_number'] ?? null, 32),
                'country' => $country,
            ],
            'contact' => [
                'name' => trim((string) $c['name']),
                'email' => strtolower(trim((string) $c['email'])),
                'phone' => $s($c['phone'] ?? null, 40),
                'language' => $lang,
            ],
            'access' => array_values(array_intersect(['portal', 'api'], $access)),
            'requested_brand_ids' => $brands === null ? null : array_values(array_unique(array_map('strval', $brands))),
            'message' => $s($message, 2000),
        ];
    }

    /** @return array<string, mixed> AccessRequest (with request_token, shown once) */
    public function create(mixed $input, string $browserLanguage): array
    {
        $in = $this->validate($input);
        $token = Crypto::token('obxr_', 24);
        $id = Ids::new('acr');
        $lang = Lang::ui($in['contact']['language'] ?? $browserLanguage);
        $row = [
            'id' => $id,
            'token_hash' => Crypto::hashToken($token),
            'status' => 'pending',
            'input' => Json::encode($in),
            'language' => $lang,
            'retailer_id' => null,
            'api_client_id' => null,
            'secret_enc' => null,
            'claimed' => 0,
            'portal_invited' => 0,
            'reason' => null,
            'created_at' => Clock::iso(),
            'decided_at' => null,
        ];
        $this->app->db->insert('obx_access_requests', $row);
        $this->notifyBrand($row, $in);
        $mail = $this->app->emails->render('accessRequestReceived', $lang, [], null, [], [], (string) $in['contact']['name']);
        $this->app->mailer->send((string) $in['contact']['email'], $mail['subject'], $mail['text'], $mail['html']);
        $out = $this->protocolJson($row);
        $out['request_token'] = $token;
        return $out;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $in */
    private function notifyBrand(array $row, array $in): void
    {
        $this->app->webhooks->admin('access_request.created', ['access_request_id' => $row['id'], 'retailer_name' => $in['retailer']['name']]);
        $url = $this->app->publicUrl() . '/admin/retailers/requests/' . $row['id'];
        $admins = $this->app->db->all("SELECT * FROM obx_users WHERE role = 'admin' AND status = 'active'");
        foreach ($admins as $admin) {
            $mail = $this->app->emails->render('accessRequestNew', (string) $admin['language'], [
                'retailer' => $in['retailer']['name'],
                'contact' => $in['contact']['name'],
            ], $url, [], [], (string) $admin['name']);
            $this->app->mailer->send((string) $admin['email'], $mail['subject'], $mail['text'], $mail['html']);
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function protocolJson(array $row, ?string $secret = null): array
    {
        $in = Json::decode((string) $row['input'], []);
        $base = $this->app->baseUrl();
        $out = [
            'id' => $row['id'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'decided_at' => $row['decided_at'],
            'reason' => $row['status'] === 'rejected' ? $row['reason'] : null,
            'status_url' => $base . '/obx/v1/access-requests/' . $row['id'],
            'portal' => null,
            'credentials' => null,
        ];
        if ($row['status'] === 'approved') {
            if ((int) $row['portal_invited'] === 1 || in_array('portal', (array) ($in['access'] ?? []), true)) {
                $out['portal'] = ['invited' => (int) $row['portal_invited'] === 1, 'login_url' => $base . '/login'];
            }
            if ($row['api_client_id'] !== null) {
                $client = $this->app->db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$row['api_client_id']]);
                if ($client !== null) {
                    $out['credentials'] = [
                        'client_id' => $client['client_id'],
                        'client_secret' => $secret,
                        'token_endpoint' => $base . '/obx/v1/oauth/token',
                        'scopes' => Json::decode((string) $client['scopes'], []),
                        'claimed' => $secret !== null || (int) $row['claimed'] === 1,
                    ];
                }
            }
        }
        return $out;
    }

    /** Status poll by the requester; hands out the client secret exactly once. @return array<string, mixed> */
    public function poll(string $id, string $token): array
    {
        return $this->app->db->tx(function () use ($id, $token) {
            $row = $this->app->db->one('SELECT * FROM obx_access_requests WHERE id = ?', [$id]);
            if ($row === null || !hash_equals((string) $row['token_hash'], Crypto::hashToken($token))) {
                throw new ApiError('not_found', 'No such access request (or a wrong request token).');
            }
            $secret = null;
            if ($row['status'] === 'approved' && (int) $row['claimed'] === 0 && $row['secret_enc'] !== null) {
                $secret = $this->app->crypto->decrypt((string) $row['secret_enc']);
                $this->app->db->update('obx_access_requests', ['claimed' => 1, 'secret_enc' => null], ['id' => $id]);
                $row['claimed'] = 1;
            }
            return $this->protocolJson($row, $secret);
        });
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function adminJson(array $row): array
    {
        $in = Json::decode((string) $row['input'], []);
        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'decided_at' => $row['decided_at'],
            'reason' => $row['reason'],
            'retailer' => $in['retailer'] ?? ['name' => ''],
            'contact' => $in['contact'] ?? ['name' => '', 'email' => ''],
            'access' => $in['access'] ?? ['portal'],
            'requested_brand_ids' => $in['requested_brand_ids'] ?? null,
            'message' => $in['message'] ?? null,
            'retailer_id' => $row['retailer_id'],
            'source_ip_country' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $row = $this->app->db->one('SELECT * FROM obx_access_requests WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiError('not_found', 'No such access request.');
        }
        return $row;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function approve(string $id, array $body): array
    {
        $retailers = new Retailers($this->app);
        $result = $this->app->db->tx(function () use ($id, $body, $retailers) {
            $row = $this->get($id);
            if ($row['status'] !== 'pending') {
                throw new ApiError('conflict', 'This request was already ' . $row['status'] . '.');
            }
            $in = Json::decode((string) $row['input'], []);
            $brandIds = array_key_exists('brand_ids', $body) ? $body['brand_ids'] : ($in['requested_brand_ids'] ?? null);
            if (is_array($brandIds) && $brandIds === []) {
                $brandIds = null;
            }
            if (isset($body['retailer_id'])) {
                $retailer = $retailers->get((string) $body['retailer_id']);
                if (array_key_exists('brand_ids', $body)) {
                    $retailer = $retailers->update((string) $retailer['id'], ['brand_ids' => $brandIds]);
                }
            } else {
                $retailer = $retailers->create([
                    'name' => $in['retailer']['name'],
                    'reference' => $in['retailer']['reference'] ?? null,
                    'website' => $in['retailer']['website'] ?? null,
                    'vat_number' => $in['retailer']['vat_number'] ?? null,
                    'country' => $in['retailer']['country'] ?? null,
                    'notes' => $in['message'] ?? null,
                    'brand_ids' => is_array($brandIds) ? array_values(array_filter($brandIds, fn ($b) => $this->app->db->value('SELECT id FROM obx_brands WHERE id = ?', [$b]) !== null)) ?: null : null,
                ]);
            }
            $wantsPortal = in_array('portal', (array) $in['access'], true);
            $wantsApi = in_array('api', (array) $in['access'], true);
            $invitePortal = array_key_exists('invite_portal_user', $body) ? (bool) $body['invite_portal_user'] : $wantsPortal;
            $createApi = array_key_exists('create_api_client', $body) ? (bool) $body['create_api_client'] : $wantsApi;
            $invite = null;
            $client = null;
            $set = ['status' => 'approved', 'decided_at' => Clock::iso(), 'retailer_id' => $retailer['id']];
            if ($invitePortal) {
                $existing = $this->app->db->one('SELECT * FROM obx_users WHERE email = ?', [$in['contact']['email']]);
                if ($existing === null) {
                    $invite = $this->app->users->invite((string) $in['contact']['email'], (string) $in['contact']['name'], 'retailer', (string) $retailer['id'], (string) $row['language']);
                    $set['portal_invited'] = 1;
                }
            }
            if ($createApi) {
                $c = $retailers->createClient((string) $retailer['id'], 'API key (access request)', null);
                $client = Retailers::clientJson($c['row']);
                $set['api_client_id'] = $c['row']['id'];
                $set['secret_enc'] = $this->app->crypto->encrypt($c['secret']);
            }
            $this->app->db->update('obx_access_requests', $set, ['id' => $id]);
            $fresh = $this->get($id);
            $out = ['access_request' => $this->adminJson($fresh), 'retailer' => $retailers->json($retailers->get((string) $retailer['id']))];
            if ($invite !== null) {
                $out['invite'] = $invite;
            }
            if ($client !== null) {
                $out['api_client'] = $client;
            }
            return [$out, $in, $row, $createApi];
        });
        [$out, $in, $row, $createApi] = $result;
        $mail = $this->app->emails->render('accessApproved', (string) $row['language'], ['retailer' => $in['retailer']['name']], $this->app->publicUrl() . '/login', $createApi ? ['apiNote'] : [], [], (string) $in['contact']['name']);
        $this->app->mailer->send((string) $in['contact']['email'], $mail['subject'], $mail['text'], $mail['html']);
        return $out;
    }

    /** @return array<string, mixed> */
    public function reject(string $id, ?string $reason): array
    {
        $row = $this->app->db->tx(function () use ($id, $reason) {
            $row = $this->get($id);
            if ($row['status'] !== 'pending') {
                throw new ApiError('conflict', 'This request was already ' . $row['status'] . '.');
            }
            $this->app->db->update('obx_access_requests', ['status' => 'rejected', 'reason' => $reason, 'decided_at' => Clock::iso()], ['id' => $id]);
            return $this->get($id);
        });
        $in = Json::decode((string) $row['input'], []);
        $mail = $this->app->emails->render('accessRejected', (string) $row['language'], ['retailer' => $in['retailer']['name'], 'reason' => (string) $reason], null, $reason !== null && $reason !== '' ? ['reason'] : [], ['contact'], (string) $in['contact']['name']);
        $this->app->mailer->send((string) $in['contact']['email'], $mail['subject'], $mail['text'], $mail['html']);
        return $this->adminJson($row);
    }
}
