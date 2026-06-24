<?php
/**
 * 日志
 */

namespace MailSystem\Core;

use MailSystem\Core\Config;

class Logger
{
    private string $path;
    private string $level;

    private const LEVELS = [
        'DEBUG' => 100,
        'INFO'  => 200,
        'WARN'  => 300,
        'ERROR' => 400,
    ];

    public function __construct(?string $path = null, string $level = 'info')
    {
        $this->path = $path ?? config('log.path', base_path('logs'));
        $this->level = $level;
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }
    }

    public function info(string $msg, array $ctx = []): void  { $this->write('INFO',  $msg, $ctx); }
    public function warn(string $msg, array $ctx = []): void  { $this->write('WARN',  $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->write('ERROR', $msg, $ctx); }
    public function debug(string $msg, array $ctx = []): void { $this->write('DEBUG', $msg, $ctx); }

    private function write(string $level, string $msg, array $ctx = []): void
    {
        // 实现日志级别过滤
        if (self::LEVELS[$level] < self::LEVELS[strtoupper($this->level)]) {
            return;
        }

        $date = date('Y-m-d');
        $file = $this->path . '/' . strtolower($level) . '-' . $date . '.log';
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }

    /**
     * 清理过期日志文件
     * @param int $days 保留天数
     */
    public function cleanOldLogs(int $days)
    {
        $logFiles = glob($this->path . '/*.log');
        $threshold = strtotime("-{$days} days");

        foreach ($logFiles as $file) {
            if (file_exists($file) && filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}
