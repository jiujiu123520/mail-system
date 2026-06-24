<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\MembershipCard;
use MailSystem\Models\User;

class MembershipCardController extends BaseController
{
    public function index(Request $req): void
    {
        Auth::requireAdmin();
        $limit = max(1, min(500, (int) $req->query('limit', 100)));
        $offset = max(0, (int) $req->query('offset', 0));
        $filters = [];

        if ($req->query('status') !== null) {
            $filters['status'] = (string) $req->query('status');
        }
        if ($req->query('user_id') !== null) {
            $filters['user_id'] = (int) $req->query('user_id');
        }
        if ($req->query('card_key') !== null) {
            $filters['card_key'] = (string) $req->query('card_key');
        }

        $list = MembershipCard::list($limit, $offset, $filters);
        $total = MembershipCard::count($filters);

        $this->ok(['list' => $list, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function show(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $card = MembershipCard::find($id);
        if (!$card) Response::notFound('卡密不存在');

        if (!empty($card['user_id'])) {
            $user = User::find($card['user_id']);
            if ($user) {
                unset($user['password']); // Never expose password hash
                $card['bound_user'] = $user;
            }
        }
        $this->ok($card);
    }

    public function generate(Request $req): void
    {
        Auth::requireAdmin();
        $count = max(1, min(100, (int) $req->input('count', 1)));
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cardKey = MembershipCard::generateUniqueCardKey();
            $id = MembershipCard::create([
                'card_key' => $cardKey,
                'status'   => 'unused',
            ]);
            $cards[] = ['id' => $id, 'card_key' => $cardKey];
        }
        $this->log('membership_card.generate', '生成了 ' . $count . ' 张卡密');
        $this->ok(['list' => $cards], '卡密生成成功');
    }

    public function update(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $card = MembershipCard::find($id);
        if (!$card) Response::notFound('卡密不存在');

        $data = [];
        if ($req->input('user_id') !== null) {
            $data['user_id'] = (int) $req->input('user_id');
            if ($data['user_id'] === 0) $data['user_id'] = null; // Allow unbinding
        }
        if ($req->input('status') !== null) {
            $status = (string) $req->input('status');
            if (!in_array($status, ['unused', 'used'])) {
                Response::error('无效的状态', 400, 400);
            }
            $data['status'] = $status;
            if ($status === 'used' && empty($card['used_at'])) {
                $data['used_at'] = date('Y-m-d H:i:s');
            }
        }

        if (!empty($data)) {
            MembershipCard::update($id, $data);
            $this->log('membership_card.update', $card['card_key']);
        }
        $this->ok(null, '卡密信息已更新');
    }

    public function delete(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $card = MembershipCard::find($id);
        if (!$card) Response::notFound('卡密不存在');

        MembershipCard::delete($id);
        $this->log('membership_card.delete', $card['card_key']);
        $this->ok(null, '卡密已删除');
    }
}