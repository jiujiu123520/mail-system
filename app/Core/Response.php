<?php
/**
 * HTTP 响应封装
 *
 * 设计目标：保证所有 API 响应始终是合法 JSON，绝不应出现 PHP 默认的 HTML 错误
 */

namespace MailSystem\Core;

class Response
{
    public static function json($data, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        // 如果之前有任何非 JSON 输出（例如 PHP Warning echo 到了缓冲区），
        // 这里丢弃缓冲再输出 JSON，保证响应体干净
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, string $message = 'OK'): void
    {
        self::json(['code' => 0, 'message' => $message, 'data' => $data]);
    }

    public static function error(string $message = 'Error', int $code = 1, int $http = 200, $data = null): void
    {
        self::json(['code' => $code, 'message' => $message, 'data' => $data], $http);
    }

    public static function unauthorized(string $message = '请先登录'): void
    {
        self::error($message, 401, 401);
    }

    public static function forbidden(string $message = '无权限访问'): void
    {
        self::error($message, 403, 403);
    }

    public static function notFound(string $message = '资源不存在'): void
    {
        self::error($message, 404, 404);
    }

    /**
     * 致命错误兜底 - 被全局异常/错误处理器调用
     * 无论 APP_DEBUG 为 true/false，都返回 JSON 格式
     * debug=true 时附带堆栈信息
     */
    public static function fatal(\Throwable $e, bool $debug = false): void
    {
        $payload = [
            'code'    => 500,
            'message' => $debug ? $e->getMessage() : '服务暂不可用，请稍后重试',
            'data'    => null,
        ];
        if ($debug) {
            $payload['error'] = [
                'type'  => get_class($e),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }
        self::json($payload, 500);
    }

    public static function html(string $content, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $content;
        exit;
    }

    public static function redirect(string $url, int $code = 302): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Location: ' . $url);
        } else {
            echo '<script>location.href="' . htmlspecialchars($url) . '"</script>';
        }
        exit;
    }

    public static function download(string $content, string $filename, string $contentType = 'application/octet-stream'): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
        }
        echo $content;
        exit;
    }
}
