<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Services\Audit;
use Obx\Node\Services\Privacy;
use Obx\Node\Support\Json;

/**
 * Node extensions of the admin API for accountability and GDPR requests (not in
 * node-admin.yaml; see NOTES.md):
 *   GET /admin/api/audit-log                    admin actions, newest first
 *   GET /admin/api/retailers/{id}/export        everything stored about a retailer (JSON)
 *   GET /admin/api/users/{id}/export            everything stored about one account (JSON)
 *   GET /admin/api/me/export                    the same for the logged-in account itself
 */
final class PrivacyController extends Controller
{
    public function auditLog(Request $req): Response
    {
        $this->app->auth->user($req, 'admin');
        $page = (new Audit($this->app))->list([
            'actor_id' => $req->q('actor_id'),
            'action' => $req->q('action'),
            'since' => $req->q('since'),
        ], $this->limit($req, 100, 500), $req->q('cursor'));
        return Response::json($page);
    }

    public function retailerExport(Request $req): Response
    {
        $this->app->auth->user($req, 'admin');
        $id = (string) $req->params['id'];
        return $this->download((new Privacy($this->app))->retailerExport($id), 'retailer-' . $id);
    }

    public function userExport(Request $req): Response
    {
        $this->app->auth->user($req, 'admin');
        $id = (string) $req->params['id'];
        return $this->download((new Privacy($this->app))->userExport($id), 'account-' . $id);
    }

    public function meExport(Request $req): Response
    {
        $s = $this->app->auth->user($req);
        return $this->download((new Privacy($this->app))->userExport((string) $s['user']['id']), 'my-data');
    }

    /** @param array<string, mixed> $data */
    private function download(array $data, string $name): Response
    {
        $res = new Response(200, Json::encode($data, true));
        $res->header('Content-Type', 'application/json; charset=utf-8');
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        $res->header('Content-Disposition', 'attachment; filename="' . $safe . '-' . gmdate('Y-m-d') . '.json"');
        $res->header('Cache-Control', 'private, no-store');
        return $res;
    }
}
