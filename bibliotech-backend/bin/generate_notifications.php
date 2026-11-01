<?php
declare(strict_types=1);

use Bibliotech\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script deve ser executado apenas via linha de comando (CLI).');
}

require_once __DIR__ . '/../src/Support/Autoloader.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

try {
    $created = (new NotificationService(database()))->generate();
    fwrite(STDOUT, "Notificações criadas: {$created}\n");
} catch (Throwable $exception) {
    Logger::error('Falha no job CLI de notificações: ' . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    fwrite(STDERR, "Falha ao gerar notificações: {$exception->getMessage()}\n");
    exit(1);
}
