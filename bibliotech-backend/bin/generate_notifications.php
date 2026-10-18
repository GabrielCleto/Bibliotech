<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

try {
    $created = (new NotificationService(database()))->generate();
    fwrite(STDOUT, "Notificações criadas: {$created}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Falha ao gerar notificações: {$exception->getMessage()}\n");
    exit(1);
}
