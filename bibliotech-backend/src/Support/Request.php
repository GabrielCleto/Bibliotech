<?php
declare(strict_types=1);

final class Request
{
    public static function json(): array
    {
        $content = file_get_contents('php://input') ?: '';
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            JsonResponse::error('O corpo deve ser um JSON válido.', 400, 'invalid_json');
        }

        return $data;
    }

    public static function integer(string $key, ?int $default = null): ?int
    {
        if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
            return $default;
        }
        $validated = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        return $validated !== false ? $validated : $default;
    }

    public static function queryString(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) ? trim($value) : $default;
    }

    public static function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $dt = DateTime::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
