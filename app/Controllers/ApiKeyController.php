<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\ApiKey;

class ApiKeyController extends BaseController
{
    public function index(Request $req): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        $all = ($u['role'] === 'admin') && ((string)$req->query('all') === '1');
        $list = ApiKey::allByUser((int) $u['id'], $all);
        $this->ok(['list' => $list]);
    }

    public function create(Request $req): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        $name = trim((string) $req->input('name'));
        $perms = (string) $req->input('permissions', 'read,send');
        $expires = $req->input('expires_at', null);
        $customSecretKey = (string) $req->input('secret_key', ''); // New: custom secret key
        $whitelistIps = (array) $req->input('whitelist_ips', []); // New: whitelist IPs

        if ($name === '') Response::error('名称不能为空', 400, 400);

        $accessKey = 'ak_' . bin2hex(random_bytes(16));
        $secretKey = $customSecretKey ?: bin2hex(random_bytes(32)); // Use custom or generate random

        $id = ApiKey::create([
            'user_id'     => (int) $u['id'],
            'name'        => $name,
            'access_key'  => $accessKey,
            'secret_key'  => password_hash($secretKey, PASSWORD_DEFAULT),
            'permissions' => $perms,
            'status'      => 1,
            'expires_at'  => $expires ?: null,
            'whitelist_ips' => json_encode($whitelistIps), // Store as JSON
        ]);
        $this->log('apikey.create', $name);
        // 明文仅此一次返回
        $this->ok([
            'id'         => $id,
            'name'       => $name,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
        ], 'API Key 已生成（请妥善保存 secret_key）');
    }

    public function delete(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $k = ApiKey::find($id);
        if (!$k) Response::notFound('Key 不存在');
        $u = Auth::user();
        if ($k['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权操作');
        }
        ApiKey::delete($id);
        $this->log('apikey.delete', $k['name']);
        $this->ok(null, '已删除');
    }

    public function update(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $k = ApiKey::find($id);
        if (!$k) Response::notFound('Key 不存在');
        $u = Auth::user();
        if ($k['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权操作');
        }
        $data = [];
        foreach (['name', 'permissions', 'status', 'expires_at'] as $f) {
            if ($req->input($f) !== null) $data[$f] = $req->input($f);
        }
        // Handle whitelist_ips separately as it's an array
        if ($req->input('whitelist_ips') !== null) {
            $data['whitelist_ips'] = json_encode((array) $req->input('whitelist_ips'));
        }
        if (!empty($data)) ApiKey::update($id, $data);
        $this->ok(null, '已更新');
    }
}
