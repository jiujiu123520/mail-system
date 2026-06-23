<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\IpBlacklist;
use MailSystem\Models\Device;

class SecurityController extends BaseController
{
    // ==================== IP封禁 ====================

    public function ipList(Request $req): void
    {
        Auth::requireAdmin();
        $limit = max(1, min(500, (int) $req->query('limit', 100)));
        $offset = max(0, (int) $req->query('offset', 0));
        $list = IpBlacklist::all($limit, $offset);
        $total = IpBlacklist::count();
        $this->ok(['list' => $list, 'total' => $total]);
    }

    public function ipBlock(Request $req): void
    {
        Auth::requireAdmin();
        $ip = trim((string) $req->input('ip'));
        $reason = (string) $req->input('reason', '');
        $minutes = (int) $req->input('minutes', 0); // 0 = 永久

        if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[\d\.]+\/\d+$/', $ip)) {
            Response::error('IP地址格式不正确', 400, 400);
        }

        if (IpBlacklist::isBlocked($ip)) {
            Response::error('该IP已在封禁列表中', 400, 400);
        }

        IpBlacklist::block($ip, $reason, Auth::id(), $minutes ?: null);
        $this->log('security.ip_block', $ip);
        $this->ok(null, 'IP已封禁');
    }

    public function ipUnblock(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        IpBlacklist::unblock($id);
        $this->log('security.ip_unblock', "id:$id");
        $this->ok(null, '已解封');
    }

    // ==================== 设备管理 ====================

    public function deviceList(Request $req): void
    {
        Auth::requireAdmin();
        $userId = (int) $req->query('user_id', 0);
        $limit = max(1, min(500, (int) $req->query('limit', 100)));
        $offset = max(0, (int) $req->query('offset', 0));

        if ($userId > 0) {
            $list = Device::allByUser($userId);
            $total = count($list);
        } else {
            $list = Device::all($limit, $offset);
            $total = Device::count();
        }
        $this->ok(['list' => $list, 'total' => $total]);
    }

    public function deviceBlock(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $device = Device::find($id);
        if (!$device) Response::notFound('设备不存在');
        Device::update($id, ['is_blocked' => 1]);
        $this->log('security.device_block', "id:$id user:" . ($device['user_id'] ?? ''));
        $this->ok(null, '设备已拉黑');
    }

    public function deviceUnblock(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        Device::update($id, ['is_blocked' => 0]);
        $this->log('security.device_unblock', "id:$id");
        $this->ok(null, '已取消拉黑');
    }

    public function deviceDelete(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        Device::delete($id);
        $this->log('security.device_delete', "id:$id");
        $this->ok(null, '已删除');
    }

    public function deviceTrust(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        Device::update($id, ['is_trusted' => 1]);
        $this->ok(null, '已设为信任设备');
    }

    public function myDevices(Request $req): void
    {
        Auth::requireLogin();
        $list = Device::allByUser(Auth::id());
        $this->ok(['list' => $list]);
    }
}
