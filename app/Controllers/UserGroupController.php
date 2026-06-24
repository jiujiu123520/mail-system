<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\UserGroup;

class UserGroupController extends BaseController
{
    public function index(): void
    {
        Auth::requireAdmin();
        $list = UserGroup::all();
        $this->ok(['list' => $list]);
    }

    public function show(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $group = UserGroup::find($id);
        if (!$group) Response::notFound('用户组不存在');
        $this->ok($group);
    }

    public function create(Request $req): void
    {
        Auth::requireAdmin();
        $name = trim((string) $req->input('name'));
        $description = (string) $req->input('description', '');
        $permissions = (array) $req->input('permissions', []);

        if (empty($name)) {
            Response::error('用户组名称不能为空', 400, 400);
        }
        if (UserGroup::findByName($name)) {
            Response::error('用户组名称已存在', 400, 400);
        }

        $id = UserGroup::create([
            'name'        => $name,
            'description' => $description,
            'permissions' => json_encode($permissions),
        ]);

        $this->log('user_group.create', $name);
        $this->ok(['id' => $id]);
    }

    public function update(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $group = UserGroup::find($id);
        if (!$group) Response::notFound('用户组不存在');

        $data = [];
        if ($req->input('name') !== null) {
            $name = trim((string) $req->input('name'));
            if (empty($name)) Response::error('用户组名称不能为空', 400, 400);
            $existingGroup = UserGroup::findByName($name);
            if ($existingGroup && (int)$existingGroup['id'] !== $id) {
                Response::error('用户组名称已存在', 400, 400);
            }
            $data['name'] = $name;
        }
        if ($req->input('description') !== null) {
            $data['description'] = (string) $req->input('description');
        }
        if ($req->input('permissions') !== null) {
            $data['permissions'] = json_encode((array) $req->input('permissions'));
        }

        if (!empty($data)) {
            UserGroup::update($id, $data);
            $this->log('user_group.update', $group['name']);
        }
        $this->ok(null, '用户组已更新');
    }

    public function delete(Request $req, array $params): void
    {
        Auth::requireAdmin();
        $id = (int) $params['id'];
        $group = UserGroup::find($id);
        if (!$group) Response::notFound('用户组不存在');

        // TODO: Check if any users are associated with this group before deleting

        UserGroup::delete($id);
        $this->log('user_group.delete', $group['name']);
        $this->ok(null, '用户组已删除');
    }
}