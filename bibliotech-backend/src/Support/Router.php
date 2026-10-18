<?php
declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[$method][] = [$pattern, $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes[$method] ?? [] as [$pattern, $handler]) {
            $regex = preg_replace('#\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}#', '(?P<$1>[0-9]+)', $pattern);
            if (preg_match('#^' . $regex . '$#', $path, $matches) === 1) {
                $parameters = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($parameters);
                return;
            }
        }

        JsonResponse::error('Rota não encontrada.', 404, 'route_not_found');
    }
}
