<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\MailStorage;
use MailSystem\Core\MimeParser;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\Email;
use MailSystem\Models\Mailbox;

class EmailController extends BaseController
{
    public function list(Request $req, array $params): void
    {
        Auth::requireLogin();
        $mailboxId = (int) $params['id'];
        $m = Mailbox::find($mailboxId);
        if (!$m) Response::notFound('邮箱不存在');
        $folder = $req->query('folder', 'INBOX');
        $limit = max(1, min(200, (int) $req->query('limit', 50)));
        $offset = max(0, (int) $req->query('offset', 0));
        $kw = (string) $req->query('keyword', '');
        $conversation = (bool) $req->query('conversation', false);

        if ($conversation) {
            $list = Email::listConversationsByMailbox($mailboxId, $folder, $limit, $offset, $kw);
            $total = Email::countConversationsByMailbox($mailboxId, $folder, $kw);
        } else {
            $list = Email::listByMailbox($mailboxId, $folder, $limit, $offset, $kw);
            $total = Email::countByMailbox($mailboxId, $folder, $kw);
        }
        $this->ok(['list' => $list, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function show(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $e = Email::find($id);
        if (!$e) Response::notFound('邮件不存在');
        $mailbox = Mailbox::find($e['mailbox_id']);
        // 权限：邮箱所有者或管理员
        $u = Auth::user();
        if ($mailbox['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权访问');
        }

        $conversationView = (bool) $req->query('conversation', false);

        if ($conversationView && !empty($e['conversation_id'])) {
            $conversationEmails = Email::getConversation($e['conversation_id'], $e['mailbox_id']);
            // Mark all emails in the conversation as read
            foreach ($conversationEmails as $convEmail) {
                if (!$convEmail['is_read']) {
                    Email::update($convEmail['id'], ['is_read' => 1]);
                }
            }
            // Re-fetch to get updated read status
            $conversationEmails = Email::getConversation($e['conversation_id'], $e['mailbox_id']);

            // Decode headers for all emails in the conversation
            foreach ($conversationEmails as &$convEmail) {
                if (!empty($convEmail['headers'])) {
                    $convEmail['headers'] = json_decode($convEmail['headers'], true);
                }
            }
            $this->ok(['conversation' => $conversationEmails]);
            return;
        }

        // 标记已读
        if (!$e['is_read']) {
            Email::update($id, ['is_read' => 1]);
            $e['is_read'] = 1;
        }
        // 解析 headers
        if (!empty($e['headers'])) {
            $e['headers'] = json_decode($e['headers'], true);
        }
        $this->ok($e);
    }

    public function send(Request $req): void
    {
        Auth::requireLogin();

        // --- 邮件发送速率限制 ---
        $u = Auth::user(); // Get user once
        $userId = $u['id'];

        // --- 邮件发送速率限制 ---
        $rateLimit = config('mail.rate_limit_per_second', 10);
        $sessionKey = 'email_send_rate_limit_' . $userId;

        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = ['last_send_time' => 0, 'send_count_in_second' => 0];
        }

        $currentTime = microtime(true);
        $lastSendTime = $_SESSION[$sessionKey]['last_send_time'];
        $sendCount = $_SESSION[$sessionKey]['send_count_in_second'];

        if (($currentTime - $lastSendTime) < 1) { // 在同一秒内
            if ($sendCount >= $rateLimit) {
                Response::error('邮件发送频率过高，请稍后再试', 429, 429);
            }
            $_SESSION[$sessionKey]['send_count_in_second']++;
        } else { // 新的一秒
            $_SESSION[$sessionKey]['last_send_time'] = $currentTime;
            $_SESSION[$sessionKey]['send_count_in_second'] = 1;
        }
        // --- 邮件发送速率限制结束 ---

        // --- 邮件发送权限检查 ---
        if (isset($u['can_send_email']) && $u['can_send_email'] === 0) {
            Response::error('您没有发送邮件的权限 (个人设置)', 403, 403);
        }

        $userPermissions = \MailSystem\Models\User::getPermissions($userId);
        if (isset($userPermissions['send_email']) && $userPermissions['send_email'] === false) {
            Response::error('您没有发送邮件的权限 (用户组设置)', 403, 403);
        }
        // --- 邮件发送权限检查结束 ---

        $fromMailboxId = (int) $req->input('from_mailbox_id');
        $to = (array) $req->input('to', []);
        $cc = (array) $req->input('cc', []);
        $bcc = (array) $req->input('bcc', []);
        $subject = (string) $req->input('subject', '');
        $bodyText = (string) $req->input('body_text', '');
        $bodyHtml = (string) $req->input('body_html', '');
        $inReplyTo = (string) $req->input('in_reply_to', ''); // For conversation threading
        $references = (string) $req->input('references', ''); // For conversation threading

        if (empty($to)) Response::error('收件人不能为空', 400, 400);
        $m = Mailbox::find($fromMailboxId);
        if (!$m) Response::error('发件邮箱不存在', 400, 400);

        // 权限
        $u = Auth::user();
        if ($m['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权使用该邮箱');
        }

        $from = ['name' => $m['display_name'] ?: $m['local_part'], 'address' => $m['full_address']];
        $headers = ['hostname' => config('mail.hostname', 'localhost')];
        if (!empty($inReplyTo)) {
            $headers['In-Reply-To'] = $inReplyTo;
        }
        if (!empty($references)) {
            $headers['References'] = $references;
        }

        $raw = MimeParser::build([
            'from'     => $from,
            'to'       => $to,
            'cc'       => $cc,
            'bcc'      => $bcc,
            'subject'  => $subject,
            'body_text'=> $bodyText,
            'body_html'=> $bodyHtml,
            'headers'  => $headers,
        ]);

        // Extract Message-ID from raw email
        $messageId = '';
        if (preg_match('/^Message-ID:\s*<?([^>\s]+)/mi', $raw, $mm)) $messageId = $mm[1];

        // Determine conversation_id
        $conversationId = $messageId; // Default to current message ID
        if (!empty($inReplyTo)) {
            // Use In-Reply-To as conversation ID if present
            if (preg_match('/<?([^>\s]+)>/', $inReplyTo, $m_irt)) {
                $conversationId = $m_irt[1];
            } else {
                $conversationId = $inReplyTo;
            }
        } elseif (!empty($references)) {
            // If In-Reply-To is not present, try References
            $refs = explode(' ', $references);
            if (!empty($refs)) {
                if (preg_match('/<?([^>\s]+)>/', end($refs), $m_ref)) {
                    $conversationId = $m_ref[1];
                } else {
                    $conversationId = end($refs);
                }
            }
        }

        // 投递到 SENT
        $maildirFilename = '';
        try {
            $storage = new MailStorage($m['full_address']);
            $maildirFilename = $storage->append($raw, 'SENT');
        } catch (\Throwable $e) {
            Response::error('写入发件箱失败: ' . $e->getMessage(), 500, 500);
        }

        $emailId = Email::create([
            'mailbox_id'   => $m['id'],
            'message_id'   => $messageId,
            'conversation_id' => $conversationId, // Add conversation_id
            'from_address' => $m['full_address'],
            'from_name'    => $from['name'],
            'to_addresses' => implode(', ', array_map(fn($a) => is_array($a) ? $a['address'] : $a, $to)),
            'cc_addresses' => implode(', ', array_map(fn($a) => is_array($a) ? $a['address'] : $a, $cc)),
            'bcc_addresses'=> implode(', ', array_map(fn($a) => is_array($a) ? $a['address'] : $a, $bcc)),
            'subject'      => $subject,
            'body_text'    => $bodyText,
            'body_html'    => $bodyHtml,
            'size_bytes'   => strlen($raw),
            'folder'       => 'SENT',
            'direction'    => 'out',
            'status'       => 'sent',
            'maildir_filename' => $maildirFilename,
        ]);

        // 投递给收件人
        $fail = [];
        foreach (array_merge($to, $cc, $bcc) as $addr) {
            $target = is_array($addr) ? $addr['address'] : $addr;
            $t = Mailbox::findByAddress($target);
            if (!$t) {
                $fail[] = ['address' => $target, 'reason' => '收件人邮箱不存在'];
                continue;
            }
            try {
                $st = new MailStorage($t['full_address']);
                $st->deliver($raw);
                Email::create([
                    'mailbox_id'   => $t['id'],
                    'message_id'   => $messageId,
                    'conversation_id' => $conversationId, // Add conversation_id
                    'from_address' => $m['full_address'],
                    'from_name'    => $from['name'],
                    'to_addresses' => $target,
                    'subject'      => $subject,
                    'body_text'    => $bodyText,
                    'body_html'    => $bodyHtml,
                    'size_bytes'   => strlen($raw),
                    'folder'       => 'INBOX',
                    'direction'    => 'in',
                    'status'       => 'received',
                ]);
            } catch (\Throwable $e) {
                $fail[] = ['address' => $target, 'reason' => $e->getMessage()];
            }
        }

        $this->log('email.send', $m['full_address'], $subject);
        $this->ok(['id' => $emailId, 'failed' => $fail], $fail ? '已发送（部分收件人投递失败）' : '发送成功');
    }

    public function delete(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $e = Email::find($id);
        if (!$e) Response::notFound('邮件不存在');
        $m = Mailbox::find($e['mailbox_id']);
        $u = Auth::user();
        if ($m['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权操作');
        }
        // 标记为已删除 (TRASH) 或彻底删除
        $permanent = $req->query('permanent') == '1';
        if ($permanent) {
            Email::delete($id);
            // 删除文件
            if ($m && !empty($e['maildir_filename'])) {
                $storage = new MailStorage($m['full_address']);
                $folderPath = $storage->path() . '/' . ($e['folder'] === 'INBOX' ? 'new' : '.' . $e['folder'] . '/new');
                $filePath = $folderPath . '/' . $e['maildir_filename'];
                if (file_exists($filePath)) {
                    if (!$storage->delete($filePath)) {
                        \MailSystem\Core\Logger::error(sprintf('Failed to delete maildir file %s for email ID %d', $filePath, $id));
                    }
                } else {
                    // 尝试在 cur 目录查找并删除
                    $folderPath = $storage->path() . '/' . ($e['folder'] === 'INBOX' ? 'cur' : '.' . $e['folder'] . '/cur');
                    $filePath = $folderPath . '/' . $e['maildir_filename'] . ':2,S'; // Maildir 邮件在 cur 目录会有 flags
                    if (file_exists($filePath)) {
                        if (!$storage->delete($filePath)) {
                            \MailSystem\Core\Logger::error(sprintf('Failed to delete maildir file %s (cur) for email ID %d', $filePath, $id));
                        }
                    } else {
                        \MailSystem\Core\Logger::warn(sprintf('Maildir file %s not found for email ID %d', $e['maildir_filename'], $id));
                    }
                }
            }
        } else {
            Email::update($id, ['folder' => 'TRASH']);
        }
        $this->log('email.delete', $e['subject'] ?? '');
        $this->ok(null, '已删除');
    }

    public function move(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $folder = strtoupper((string) $req->input('folder', 'INBOX'));
        $e = Email::find($id);
        if (!$e) Response::notFound('邮件不存在');
        $m = Mailbox::find($e['mailbox_id']);
        $u = Auth::user();
        if ($m['user_id'] != $u['id'] && $u['role'] !== 'admin') {
            Response::forbidden('无权操作');
        }
        Email::update($id, ['folder' => $folder]);
        $this->ok(null, '已移动');
    }

    public function star(Request $req, array $params): void
    {
        Auth::requireLogin();
        $id = (int) $params['id'];
        $star = (int) $req->input('starred', 1);
        $e = Email::find($id);
        if (!$e) Response::notFound('邮件不存在');
        Email::update($id, ['is_starred' => $star ? 1 : 0]);
        $this->ok(null, '已操作');
    }
}
