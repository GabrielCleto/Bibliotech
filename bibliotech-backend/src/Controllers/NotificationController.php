<?php
declare(strict_types=1);

final class NotificationController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $sql = 'SELECT id_notificacao, tipo, titulo, mensagem, lida, criada_em, lida_em
                FROM notificacao WHERE id_usuario = :user AND (:unread = 0 OR lida = 0)
                ORDER BY criada_em DESC LIMIT 100';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user' => CurrentUser::id(),
            'unread' => ($_GET['unread'] ?? '1') === '0' ? 0 : 1,
        ]);
        JsonResponse::success($statement->fetchAll());
    }

    public function read(array $params): never
    {
        $statement = $this->db->prepare('UPDATE notificacao SET lida = 1, lida_em = NOW()
            WHERE id_notificacao = :notification AND id_usuario = :user AND lida = 0');
        $statement->execute(['notification' => (int) $params['notificationId'], 'user' => CurrentUser::id()]);
        if ($statement->rowCount() === 0) {
            JsonResponse::error('Notificação não encontrada ou já lida.', 404, 'notification_not_found');
        }
        JsonResponse::success(['lida' => true]);
    }

    public function readAll(): never
    {
        $statement = $this->db->prepare('UPDATE notificacao SET lida = 1, lida_em = NOW()
            WHERE id_usuario = :user AND lida = 0');
        $statement->execute(['user' => CurrentUser::id()]);
        JsonResponse::success(['marcadas' => $statement->rowCount()]);
    }
}
