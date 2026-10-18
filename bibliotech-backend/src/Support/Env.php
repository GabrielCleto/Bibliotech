<?php
declare(strict_types=1);

namespace Bibliotech\Support;

/**
 * Carregador de variáveis de ambiente (.env)
 * Permite configurar banco de dados, chaves e ambiente sem alterar código.
 */
final class Env
{
    private static array $variables = [];
    private static bool $loaded = false;

    public static function load(?string $filePath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $path = $filePath ?? dirname(__DIR__, 2) . '/.env';

        if (!file_exists($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignora comentários
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Trata aspas
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Converte valores literais
            $lowered = strtolower($value);
            $parsedValue = match ($lowered) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                'empty', '(empty)' => '',
                default => $value,
            };

            self::$variables[$key] = $parsedValue;

            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $parsedValue;
            }
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $parsedValue;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
