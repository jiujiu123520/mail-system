<?php
/**
 * CLI 日志清理脚本
 */

require_once __DIR__ . '/../app/Core/Bootstrap.php';

use MailSystem\Core\Config;
use MailSystem\Core\Logger;

// 获取日志保留天数，默认为 30 天
$retentionDays = Config::get('log.retention_days', 30);

$logger = new Logger(Config::get('log.path'), Config::get('log.level'));
$logger->cleanOldLogs($retentionDays);

echo "日志清理完成，已删除 {$retentionDays} 天前的日志文件.\n";
exit(0);