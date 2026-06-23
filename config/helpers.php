<?php
/**
 * 环境变量辅助函数
 *
 * 不依赖 putenv / getenv（宝塔面板常把这些函数加入 disable_functions）。
 * load_env() 负责解析 .env 并写入全局静态缓存 + $_ENV + $_SERVER。
 * env() 仅从上述缓存读取，保证所有代码路径（web / api / bin / install）一致。
 */

global $__MAILSYSTEM_ENV;
$__MAILSYSTEM_ENV = [];

if (!function_exists('load_env')) {
    function load_env(?string $file = null): void
    {
        global $__MAILSYSTEM_ENV;
        if ($file === null) {
            $file = dirname(__DIR__) . '/.env';
        }
        if (!file_exists($file)) {
            return;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $canPutenv = function_exists('putenv') && !in_array('putenv', $disabled);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (
                (strlen($v) >= 2 && str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (strlen($v) >= 2 && str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }
            $__MAILSYSTEM_ENV[$k] = $v;
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
            if ($canPutenv) {
                @putenv("$k=$v");
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        global $__MAILSYSTEM_ENV;
        if (is_array($__MAILSYSTEM_ENV) && array_key_exists($key, $__MAILSYSTEM_ENV) && $__MAILSYSTEM_ENV[$key] !== null && $__MAILSYSTEM_ENV[$key] !== '') {
            return $__MAILSYSTEM_ENV[$key];
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '' && $_ENV[$key] !== null) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '' && $_SERVER[$key] !== null) {
            return $_SERVER[$key];
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('getenv') && !in_array('getenv', $disabled)) {
            $value = @getenv($key);
            if ($value !== false && $value !== '' && $value !== null) {
                return $value;
            }
        }
        return $default;
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        static $config = null;
        if ($config === null) {
            $configFile = __DIR__ . '/app.php';
            $config = require $configFile;
        }
        $parts = explode('.', $key);
        $value = $config;
        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('data_path')) {
    function data_path(string $path = ''): string
    {
        return base_path('data' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}
