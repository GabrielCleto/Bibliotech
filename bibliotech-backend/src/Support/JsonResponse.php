<?php
declare(strict_types=1);

final class JsonResponse
{
    public static function send(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($status === 204) {
            exit;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data, int $status = 200, array $meta = []): never
    {
        self::send(['data' => $data] + ($meta === [] ? [] : ['meta' => $meta]), $status);
    }

    public static function error(string $message, int $status, string $code = 'api_error'): never
    {
        self::send(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
