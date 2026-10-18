<?php
declare(strict_types=1);

namespace Bibliotech\Support;

/**
 * Logger estruturado do sistema.
 * Registra eventos em storage/logs/app.log com timestamp, nível e contexto JSON.
 */
final class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';

    private static ?string $logPath = null;

    public static function setPath(string $path): void
    {
        self::$logPath = $path;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = self::$logPath ?? $dir . '/app.log';
        $timestamp = date('Y-m-d H:i:s');
        $userId = $_SESSION['id_usuario'] ?? 'guest';

        $contextStr = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = "[{$timestamp}] [{$level}] [User: {$userId}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }
}
