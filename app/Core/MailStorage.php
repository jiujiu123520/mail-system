<?php
/**
 * Maildir 风格邮件存储
 * 目录结构: data/mailboxes/<local>@<domain>/{cur,new,tmp}
 */

namespace MailSystem\Core;

class MailStorage
{
    private string $basePath;
    private string $mailbox;
    private string $format; // maildir / mbox

    public function __construct(string $mailbox, ?string $basePath = null, string $format = 'maildir')
    {
        $this->basePath = rtrim($basePath ?? config('mail.storage_path', base_path('data/mailboxes')), '/');
        $this->mailbox = $mailbox;
        $this->format = $format;
        $this->ensureDir();
    }

    public function path(): string
    {
        return $this->basePath . '/' . $this->mailbox;
    }

    private function ensureDir(): void
    {
        $p = $this->path();
        if (!is_dir($p)) {
            if (!mkdir($p, 0700, true)) {
                Logger::error(sprintf('Failed to create mailbox directory: %s', $p));
            }
        }
        foreach (['cur', 'new', 'tmp'] as $sub) {
            $d = $p . '/' . $sub;
            if (!is_dir($d)) {
                if (!mkdir($d, 0700, true)) {
                    Logger::error(sprintf('Failed to create Maildir subdirectory: %s', $d));
                }
            }
        }
    }

    /**
     * 投递邮件到 INBOX
     */
    public function deliver(string $raw): string
    {
        $this->ensureDir();
        if ($this->format === 'mbox') {
            return $this->appendMbox($raw);
        }
        return $this->appendMaildir($raw, 'INBOX');
    }

    /**
     * 追加到指定文件夹
     */
    public function append(string $raw, string $folder = 'INBOX'): string
    {
        $this->ensureDir();
        if ($this->format === 'mbox') {
            return $this->appendMbox($raw);
        }
        return $this->appendMaildir($raw, $folder);
    }

    private function appendMaildir(string $raw, string $folder): string
    {
        // 确保顶层 Maildir 结构和自定义文件夹结构存在
        $this->ensureDir();

        $uniqueId = sprintf('%d.%06d.%s.%s',
            time(),
            random_int(0, 999999),
            bin2hex(random_bytes(8)),
            getmypid()
        );
        $tmpFilename = $uniqueId; // Maildir tmp 文件名不带 .tmp 后缀，而是以 , 开头，或者只是 unique id

        // 写入到顶层 Maildir 的 tmp 目录
        $tempPath = $this->path() . '/tmp/' . $tmpFilename;
        
        if (file_put_contents($tempPath, $raw, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write mail temporary file');
        }

        // 确定最终目标目录
        $destinationDir = '';
        if ($folder === 'INBOX') {
            $destinationDir = $this->path() . '/new'; // 新邮件进入 INBOX/new
        } else {
            $folderBase = $this->path() . '/.' . $folder;
            // 确保自定义文件夹的 new/cur/tmp 存在
            if (!is_dir($folderBase . '/new')) {
                @mkdir($folderBase . '/cur', 0700, true);
                @mkdir($folderBase . '/new', 0700, true);
                @mkdir($folderBase . '/tmp', 0700, true);
            }
            $destinationDir = $folderBase . '/new'; // 自定义文件夹的 new
        }

        $finalPath = $destinationDir . '/' . $uniqueId; // 最终文件路径
        if (!rename($tempPath, $finalPath)) {
            @unlink($tempPath); // 失败时清理临时文件
            throw new \RuntimeException('Failed to move mail file from tmp to new');
        }
        return $uniqueId;
    }

    private function appendMbox(string $raw): string
    {
        $mboxFile = $this->path() . '/INBOX.mbox';
        $separator = "\nFrom " . $this->mailbox . ' ' . date('D M j H:i:s Y') . "\n";
        $content = $raw;
        if (!str_starts_with($content, 'From ')) {
            $content = $separator . $content;
        }
        // 确保以换行结尾
        if (!str_ends_with($content, "\n")) {
            $content .= "\n";
        }
        $content .= "\n";
        file_put_contents($mboxFile, $content, FILE_APPEND | LOCK_EX);
        return $mboxFile;
    }

    /**
     * 列出某文件夹下所有邮件 (new + cur)
     */
    public function listFolder(string $folder = 'INBOX'): array
    {
        $this->ensureDir();
        $emails = [];
        if ($this->format === 'mbox') {
            // 不支持 mbox 列表
            return [];
        }
        $bases = [];
        if ($folder === 'INBOX') {
            $bases = [$this->path() . '/cur', $this->path() . '/new'];
        } else {
            $base = $this->path() . '/.' . $folder;
            $bases = [$base . '/cur', $base . '/new'];
            if (!is_dir($base . '/cur')) @mkdir($base . '/cur', 0700, true);
            if (!is_dir($base . '/new')) @mkdir($base . '/new', 0700, true);
        }

        foreach ($bases as $base) {
            if (!is_dir($base)) continue;
            $files = glob($base . '/*') ?: [];
            foreach ($files as $f) {
                $emails[] = [
                    'path' => $f,
                    'filename' => basename($f),
                    'size' => filesize($f),
                    'mtime' => filemtime($f),
                    'flags' => self::parseFlags(basename($f)),
                ];
            }
        }
        usort($emails, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
        return $emails;
    }

    private static function parseFlags(string $filename): array
    {
        $flags = [];
        if (strpos($filename, ':2,') !== false) {
            $f = substr($filename, strpos($filename, ':2,') + 3);
            for ($i = 0; $i < strlen($f); $i++) {
                $c = $f[$i];
                if ($c === 'S') $flags[] = 'seen';
                elseif ($c === 'R') $flags[] = 'answered';
                elseif ($c === 'A') $flags[] = 'flagged';
                elseif ($c === 'T') $flags[] = 'deleted';
                elseif ($c === 'D') $flags[] = 'draft';
            }
        }
        return $flags;
    }

    /**
     * 读取邮件
     */
    public function read(string $filepath): ?string
    {
        if (!file_exists($filepath)) return null;
        $content = file_get_contents($filepath);
        return $content;
    }

    /**
     * 移动邮件到新文件夹
     */
    public function move(string $filepath, string $targetFolder): ?string
    {
        if (!file_exists($filepath)) return null;
        $filename = basename($filepath);
        $base = $this->path() . '/.' . $targetFolder;
        if (!is_dir($base)) {
            @mkdir($base . '/cur', 0700, true);
            @mkdir($base . '/new', 0700, true);
            @mkdir($base . '/tmp', 0700, true);
        }
        $isNew = strpos($filename, ':2,') === false;
        $target = $base . '/' . ($isNew ? 'new' : 'cur') . '/' . $filename;
        if (rename($filepath, $target)) {
            return $target;
        }
        return null;
    }

    /**
     * 删除邮件
     */
    public function delete(string $filepath): bool
    {
        if (!file_exists($filepath)) return false;
        return @unlink($filepath);
    }

    /**
     * 计算使用量 (字节)
     */
    public function usage(): int
    {
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $f) {
            if ($f->isFile()) $total += $f->getSize();
        }
        return $total;
    }
}
