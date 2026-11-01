<?php
declare(strict_types=1);

/**
 * Classe CurrentUser
 *
 * Descobre quem é o usuário logado. Antes, isso vinha de um
 * cabeçalho (X-User-Id) que qualquer um podia editar e se passar
 * por outra pessoa. Agora vem da sessão do PHP, que só o próprio
 * servidor consegue escrever (o login é quem grava isso).
 */
final class CurrentUser
{
    public static function id(): int
    {
        // Garante que a sessão foi lida (sem reabrir trava de escrita se já foi lida e fechada)
        if (session_status() === PHP_SESSION_NONE && !isset($_SESSION)) {
            session_start();
        }

        // Autenticação exclusivamente via sessão verificada do PHP (impede falsificação por cabeçalhos HTTP)
        $id = $_SESSION['id_usuario'] ?? null;
        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
            return (int) $id;
        }

        JsonResponse::error('Você precisa estar logado para acessar isso.', 401, 'authentication_required');
    }

    /**
     * Confere se a conta de quem está logado ainda está ATIVA. Se foi
     * inativada depois do login, encerra a sessão e responde 403.
     */
    public static function requireActiveAccount(PDO $db, int $userId): void
    {
        $statement = $db->prepare('SELECT ativo FROM usuario WHERE id_usuario = :id');
        $statement->execute(['id' => $userId]);
        $active = $statement->fetchColumn();

        if ($active === false || (int) $active === 0) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            JsonResponse::error('Conta inativa ou inexistente.', 403, 'account_inactive');
        }
    }

    public static function requireAdmin(PDO $db, int $userId): void
    {
        $statement = $db->prepare('SELECT tipo_usuario, ativo FROM usuario WHERE id_usuario = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        if (!$user || !$user['ativo'] || !in_array($user['tipo_usuario'], ['admin', 'bibliotecario'], true)) {
            JsonResponse::error('Acesso restrito ao administrador ou bibliotecário.', 403, 'forbidden');
        }
    }
}
