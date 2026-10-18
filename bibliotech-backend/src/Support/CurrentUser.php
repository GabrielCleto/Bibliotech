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
        // Garante que a sessão está ligada
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 1. Prioridade: Sessão ativa do PHP
        $id = $_SESSION['id_usuario'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            return (int) $id;
        }

        // 2. Alternativa para clientes REST externos / mobile: Authorization: Bearer <token_ou_id>
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            if (ctype_digit($token)) {
                return (int) $token;
            }
            if (str_contains($token, ':')) {
                [$possibleId] = explode(':', $token, 2);
                if (ctype_digit($possibleId)) {
                    return (int) $possibleId;
                }
            }
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
            $_SESSION = [];
            session_destroy();
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
