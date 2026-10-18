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
        $value = $_GET[$key] ?? $default;
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
    }
}
