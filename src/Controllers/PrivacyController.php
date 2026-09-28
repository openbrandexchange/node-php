<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Services\Privacy;
use Obx\Node\Support\Json;

/**
 * Node extensions of the admin API for accountability and GDPR requests (not in
 * node-admin.yaml; see NOTES.md):
 *   GET /admin/api/audit-log                    admin actions, newest first
 *   GET /admin/api/retailers/{id}/export        everything stored about a retailer (JSON)
 *   GET /admin/api/users/{id}/export            everything stored about one account (JSON)
 *   GET /admin/api/me/export                    the same for the logged-in account itself
 *   DELETE /admin/api/me                        a person deletes their own account
 * The paths and the export format (obx-personal-data-export/1) are the same in every OBX portal.
 */
final class PrivacyController extends Controller
{
    public function auditLog(Request $req): Response
    {
        $this->app->auth->user($req, 'admin');
        $page = $this->app->audit->list([
            'action' => $req->q('action'),
            'actor_id' => $req->q('actor_id'),
            'target_id' => $req->q('target_id'),
            'outcome' => $req->q('outcome'),
        ], $this->limit($req, 50, 200), $req->q('cursor'));
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

    public function deleteMe(Request $req): Response
    {
        $s = $this->app->auth->user($req);
        $u = $s['user'];
        if ($u['role'] === 'admin' && (int) $this->app->db->value("SELECT COUNT(*) FROM obx_users WHERE role = 'admin' AND status = 'active'") <= 1) {
            throw new \Obx\Node\Http\ApiError('conflict', 'You are the only administrator. Invite another administrator before you delete your account.');
        }
        $this->app->db->tx(function () use ($u): void {
            $this->app->auth->endSessions((string) $u['id']);
            (new Privacy($this->app))->forgetUser((string) $u['id']);
            $this->app->db->delete('obx_users', ['id' => $u['id']]);
        });
        $res = Response::empty(204);
        $this->app->auth->logout($req, $res);
        // The audit entry of this deletion keeps only the (random) account id.
        $req->attrs['_login_user'] = ['id' => $u['id'], 'role' => $u['role'], 'name' => null, 'email' => null];
        return $res;
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
