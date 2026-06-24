<?php

namespace MailSystem\Controllers;

use MailSystem\Core\Auth;
use MailSystem\Core\Database;
use MailSystem\Core\Request;
use MailSystem\Core\Response;
use MailSystem\Models\Port;

class SystemController extends BaseController
{
    /**
     * 获取面板统计信息
     */
    public function stats(Request $req): void
    {
        Auth::requireLogin();
        $db = Database::getInstance();
        $stats = [
            'users'    => (int) $db->fetchValue('SELECT COUNT(*) FROM ms_users'),
            'domains'  => (int) $db->fetchValue('SELECT COUNT(*) FROM ms_domains'),
            'mailboxes'=> (int) $db->fetchValue('SELECT COUNT(*) FROM ms_mailboxes'),
            'emails'   => (int) $db->fetchValue('SELECT COUNT(*) FROM ms_emails'),
            'inbox'    => (int) $db->fetchValue("SELECT COUNT(*) FROM ms_emails WHERE folder = 'INBOX'"),
            'sent'     => (int) $db->fetchValue("SELECT COUNT(*) FROM ms_emails WHERE folder = 'SENT'"),
            'unread'   => (int) $db->fetchValue("SELECT COUNT(*) FROM ms_emails WHERE is_read = 0 AND folder = 'INBOX'"),
            'api_keys' => (int) $db->fetchValue('SELECT COUNT(*) FROM ms_api_keys'),
        ];
        $this->ok($stats);
    }

    /**
     * 获取系统服务状态
     */
    public function services(Request $req): void
    {
        Auth::requireLogin();
        $ports = Port::allEnabled();
        $list = [];
        foreach ($ports as $p) {
            $errno = 0; $errstr = '';
            $sock = @stream_socket_client("tcp://127.0.0.1:{$p['port']}", $errno, $errstr, 1);
            $running = $sock !== false;
            if (!$running) {
                \MailSystem\Core\Logger::warn(sprintf('Service port test failed for %s:%d: %s (%d)', $p['service'], $p['port'], $errstr, $errno));
            }
            if ($sock) fclose($sock);
            $list[] = [
                'id'      => $p['id'],
                'service' => $p['service'],
                'port'    => $p['port'],
                'ssl'     => (bool) $p['ssl'],
                'tls'     => (bool) $p['tls'],
                'running' => $running,
            ];
        }
        $this->ok(['list' => $list]);
    }

    /**
     * 获取 PHP 与系统信息
     */
    public function info(Request $req): void
    {
        Auth::requireAdmin();
        $info = [
            'php_version'   => PHP_VERSION,
            'os'            => PHP_OS,
            'sapi'          => php_sapi_name(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'extensions'    => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl'   => extension_loaded('openssl'),
                'sockets'   => extension_loaded('sockets'),
                'pcntl'     => extension_loaded('pcntl'),
                'mbstring'  => extension_loaded('mbstring'),
                'iconv'     => extension_loaded('iconv'),
                'curl'      => extension_loaded('curl'),
                'zip'       => extension_loaded('zip'),
            ],
            'memory_limit'  => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'timezone'      => date_default_timezone_get(),
            'disk_free'    => disk_free_space(base_path()) ?: 0,
        ];
        $this->ok($info);
    }

    /**
     * 获取服务器实时状态 (CPU, 内存, 磁盘, 负载, 运行时间)
     */
    public function getServerStatus(Request $req): void
    {
        Auth::requireAdmin();

        $status = [
            'cpu_usage'    => 'N/A',
            'mem_total'    => 'N/A',
            'mem_used'     => 'N/A',
            'mem_free'     => 'N/A',
            'mem_percent'  => 'N/A',
            'disk_total'   => 'N/A',
            'disk_used'    => 'N/A',
            'disk_free'    => 'N/A',
            'disk_percent' => 'N/A',
            'load_avg'     => 'N/A',
            'uptime'       => 'N/A',
        ];

        // 获取 CPU 使用率
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') { // 仅限非 Windows 系统
            $cpu_usage_raw = shell_exec("top -bn1 | grep \"Cpu(s)\" | sed \"s/.*, *\\([0-9.]*\\)%* id.*/\\1/\" | awk '{print 100 - $1}'");
            if ($cpu_usage_raw !== null) {
                $status['cpu_usage'] = trim($cpu_usage_raw);
            } else {
                \MailSystem\Core\Logger::warn('Failed to get CPU usage via top command.');
            }

            // 获取内存信息
            $mem_raw = shell_exec('free -m | grep Mem');
            if ($mem_raw !== null) {
                preg_match('/Mem:\\s+(\\d+)\\s+(\\d+)\s+(\\d+)/', $mem_raw, $matches);
                if (count($matches) >= 4) {
                    $status['mem_total'] = (int) $matches[1];
                    $status['mem_used']  = (int) $matches[2];
                    $status['mem_free']  = (int) $matches[3];
                    if ($status['mem_total'] > 0) {
                        $status['mem_percent'] = round(($status['mem_used'] / $status['mem_total']) * 100, 2);
                    }
                } else {
                    \MailSystem\Core\Logger::warn('Failed to parse memory info from free command.', ['raw' => $mem_raw]);
                }
            } else {
                \MailSystem\Core\Logger::warn('Failed to get memory info via free command.');
            }

            // 获取磁盘信息
            $disk_raw = shell_exec("df -h / | awk 'NR==2 {print $2,$3,$4,$5}'");
            if ($disk_raw !== null) {
                preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $disk_raw, $matches);
                if (count($matches) >= 5) {
                    $status['disk_total']   = $matches[1];
                    $status['disk_used']    = $matches[2];
                    $status['disk_free']    = $matches[3];
                    $status['disk_percent'] = str_replace('%', '', $matches[4]);
                } else {
                    \MailSystem\Core\Logger::warn('Failed to parse disk info from df command.', ['raw' => $disk_raw]);
                }
            } else {
                \MailSystem\Core\Logger::warn('Failed to get disk info via df command.');
            }

            // 获取负载
            $load_avg_raw = shell_exec("cat /proc/loadavg | awk '{print $1, $2, $3}'");
            if ($load_avg_raw !== null) {
                $loads = explode(' ', $load_avg_raw);
                $status['load_avg'] = implode(', ', array_slice($loads, 0, 3));
            } else {
                \MailSystem\Core\Logger::warn('Failed to get load average from /proc/loadavg.');
            }

            // 获取运行时间
            $uptime_raw = shell_exec('uptime -p');
            if ($uptime_raw !== null) {
                $status['uptime'] = trim($uptime_raw);
            } else {
                \MailSystem\Core\Logger::warn('Failed to get uptime via uptime command.');
            }
        } else {
            // Windows 环境下的占位符或简易信息
            $status['os'] = 'Windows';
            $status['message'] = 'Server status monitoring is designed for Linux systems.';
        }


        $this->ok($status);
    }

    /**
     * 获取操作日志
     */
    public function logs(Request $req): void
    {
        Auth::requireAdmin();
        $limit = max(1, min(200, (int) $req->query('limit', 50)));
        $offset = max(0, (int) $req->query('offset', 0));
        $filters = [
            'action'  => $req->query('action', ''),
            'user_id' => (int) $req->query('user_id', 0),
        ];

        $list = \MailSystem\Models\Log::list($limit, $offset, $filters);
        $total = \MailSystem\Models\Log::count($filters);

        $this->ok(['list' => $list, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }
}
