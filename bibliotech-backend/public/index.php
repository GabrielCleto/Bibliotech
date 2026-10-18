<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/Autoloader.php';
require_once __DIR__ . '/../config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['id_usuario'])) {
        CurrentUser::requireActiveAccount(database(), (int) $_SESSION['id_usuario']);
    }
    $router = new Router();
    $wishlist = new WishlistController(database());
    $notifications = new NotificationController(database());
    $reports = new ReportController(database());
    $books = new BookController(database());
    $myLoans = new MyLoansController(database());

    $router->add('GET', '/api/books', [$books, 'index']);
    $router->add('GET', '/api/books/{bookId}', [$books, 'show']);
    $router->add('POST', '/api/books/{bookId}/request', [$myLoans, 'request']);
    $router->add('GET', '/api/my-loans', [$myLoans, 'index']);
    $router->add('POST', '/api/my-loans', [$myLoans, 'request']);
    $router->add('POST', '/api/my-loans/{loanId}/renew', [$myLoans, 'renew']);
    $router->add('POST', '/api/loans/{loanId}/renew', [$myLoans, 'renew']);
    $router->add('POST', '/api/loans/{loanId}/approve-renewal', [$myLoans, 'approveRenewal']);
    $router->add('POST', '/api/loans/{loanId}/reject-renewal', [$myLoans, 'rejectRenewal']);

    $router->add('GET', '/api/wishlist', [$wishlist, 'index']);
    $router->add('POST', '/api/wishlist/{bookId}', [$wishlist, 'store']);
    $router->add('DELETE', '/api/wishlist/{bookId}', [$wishlist, 'destroy']);
    $router->add('GET', '/api/notifications', [$notifications, 'index']);
    $router->add('PUT', '/api/notifications/{notificationId}/read', [$notifications, 'read']);
    $router->add('PUT', '/api/notifications/read-all', [$notifications, 'readAll']);
    $router->add('GET', '/api/reports/popular-books', [$reports, 'popularBooks']);
    $router->add('GET', '/api/reports/loans', [$reports, 'loans']);
    $router->add('GET', '/api/reports/active-users', [$reports, 'activeUsers']);
    $router->add('GET', '/api/reports/by-class', [$reports, 'byClass']);
    $router->add('GET', '/api/reports/by-course', [$reports, 'byCourse']);
    $router->add('GET', '/api/reports/by-situation', [$reports, 'bySituation']);
    $router->add('GET', '/api/reports/loan-history', [$reports, 'loanHistory']);

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // O endereço completo que chega aqui inclui o nome da pasta do
    // projeto no Apache, que muda de máquina pra máquina (já foi
    // "bibliotech-api", agora é "bibliotech-backend", e no XAMPP de
    // alguém pode ficar até com a pasta repetida:
    // "/bibliotech-backend/bibliotech-backend/api/books").
    // As rotas cadastradas acima começam todas em "/api/...", então
    // a gente joga fora tudo que vem ANTES do primeiro "/api/",
    // qualquer que seja o nome da pasta.
    $posicaoApi = strpos($path, '/api/');
    if ($posicaoApi === false && substr($path, -4) === '/api') {
        $posicaoApi = strlen($path) - 4;
    }
    if ($posicaoApi !== false && $posicaoApi > 0) {
        $path = substr($path, $posicaoApi);
    }

    $router->dispatch($_SERVER['REQUEST_METHOD'], rtrim($path, '/') ?: '/');
} catch (Throwable $exception) {
    Logger::error($exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);
    JsonResponse::error('Erro interno do servidor.', 500, 'internal_error');
}
