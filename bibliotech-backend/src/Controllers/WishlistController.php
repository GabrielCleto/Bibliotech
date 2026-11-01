<?php
declare(strict_types=1);

final class WishlistController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $userId = CurrentUser::id();
        $page = max(1, Request::integer('page', 1) ?? 1);
        $perPage = min(100, max(1, Request::integer('per_page', 20) ?? 20));
        $offset = ($page - 1) * $perPage;
        $sort = Request::queryString('sort', 'newest') === 'title' ? 'L.titulo ASC' : 'D.criado_em DESC';

        $count = $this->db->prepare('SELECT COUNT(*) FROM lista_desejos WHERE id_usuario = :user');
        $count->execute(['user' => $userId]);
        $total = (int) $count->fetchColumn();

        $sql = "SELECT D.id_lista, D.criado_em, L.id_livro, L.titulo, L.ISBN,
                       A.nome_autor AS autor, C.nome_cat AS categoria,
                       L.quantidade_disponivel,
                       CASE
                           WHEN R1.id_reserva IS NULL THEN NULL
                           ELSE (
                               SELECT COUNT(*) FROM reserva_fila R2
                               WHERE R2.id_livro = L.id_livro AND R2.atendida = 0
                                 AND R2.posicao_fila <= R1.posicao_fila
                           )
                       END AS posicao_na_fila
                FROM lista_desejos D
                INNER JOIN livros L ON L.id_livro = D.id_livro
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                LEFT JOIN reserva_fila R1
                       ON R1.id_livro = D.id_livro AND R1.id_usuario = D.id_usuario AND R1.atendida = 0
                WHERE D.id_usuario = :user
                ORDER BY {$sort} LIMIT :limit OFFSET :offset";
        $statement = $this->db->prepare($sql);
        $statement->bindValue('user', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        JsonResponse::success($statement->fetchAll(), 200, [
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function store(array $params): never
    {
        $userId = CurrentUser::id();
        $bookId = (int) ($params['bookId'] ?? 0);
        if ($bookId <= 0) {
            JsonResponse::error('ID do livro inválido.', 400, 'invalid_book_id');
        }

        $book = $this->db->prepare('SELECT id_livro, quantidade_disponivel FROM livros WHERE id_livro = :book');
        $book->execute(['book' => $bookId]);
        $bookData = $book->fetch(PDO::FETCH_ASSOC);
        if (!$bookData) {
            JsonResponse::error('Livro não encontrado.', 404, 'book_not_found');
        }

        $userStmt = $this->db->prepare('SELECT tipo_usuario, ativo FROM usuario WHERE id_usuario = :id');
        $userStmt->execute(['id' => $userId]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userData || (int) $userData['ativo'] === 0) {
            JsonResponse::error('Conta inativa ou inexistente.', 403, 'account_inactive');
        }

        $exists = $this->db->prepare('SELECT id_lista FROM lista_desejos WHERE id_usuario = :user AND id_livro = :book');
        $exists->execute(['user' => $userId, 'book' => $bookId]);
        if ($exists->fetch()) {
            JsonResponse::error('O livro já está na lista de desejos.', 409, 'already_in_wishlist');
        }

        try {
            $insert = $this->db->prepare('INSERT INTO lista_desejos (id_usuario, id_livro) VALUES (:user, :book)');
            $insert->execute(['user' => $userId, 'book' => $bookId]);
            $wishlistId = (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                JsonResponse::error('O livro já está na lista de desejos.', 409, 'already_in_wishlist');
            }
            \Bibliotech\Support\Logger::error('Erro ao salvar na lista de desejos: ' . $e->getMessage());
            JsonResponse::error('Erro ao adicionar livro à lista de desejos.', 500, 'database_error');
        }

        $entrouFila = false;
        $posicaoFila = null;

        // Se o livro está esgotado e o usuário é aluno ou professor, entra na fila de espera
        // usando a procedure sp_criar_reserva do banco (que trava o livro com FOR UPDATE sem race condition).
        if ((int) $bookData['quantidade_disponivel'] <= 0 && in_array($userData['tipo_usuario'], ['aluno', 'professor'], true)) {
            try {
                $stmtReserva = $this->db->prepare('CALL sp_criar_reserva(:user, :book)');
                $stmtReserva->execute(['user' => $userId, 'book' => $bookId]);
                $stmtReserva->closeCursor();
                $entrouFila = true;
            } catch (PDOException $e) {
                // Código 45000 = usuário já estava na fila ativa ou um exemplar acabou de ficar disponível
                if ($e->getCode() !== '45000') {
                    \Bibliotech\Support\Logger::error('Falha ao registrar reserva_fila via sp_criar_reserva: ' . $e->getMessage());
                }
            }

            // Consulta a posição real atual na fila de espera (contando apenas reservas ainda não atendidas)
            $stmtPos = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM reserva_fila R2
                 INNER JOIN reserva_fila R1
                         ON R1.id_livro = R2.id_livro AND R1.id_usuario = :user AND R1.atendida = 0
                 WHERE R2.id_livro = :book AND R2.atendida = 0 AND R2.posicao_fila <= R1.posicao_fila'
            );
            $stmtPos->execute(['user' => $userId, 'book' => $bookId]);
            $posAtual = (int) $stmtPos->fetchColumn();
            if ($posAtual > 0) {
                $posicaoFila = $posAtual;
                $entrouFila = true;
            }
        }

        JsonResponse::success([
            'id_lista' => $wishlistId,
            'entrou_fila_reserva' => $entrouFila,
            'posicao_fila' => $posicaoFila,
        ], 201);
    }

    public function destroy(array $params): never
    {
        $userId = CurrentUser::id();
        $bookId = (int) $params['bookId'];

        try {
            $this->db->beginTransaction();

            $statement = $this->db->prepare('DELETE FROM lista_desejos WHERE id_usuario = :user AND id_livro = :book');
            $statement->execute(['user' => $userId, 'book' => $bookId]);
            if ($statement->rowCount() === 0) {
                $this->db->rollBack();
                JsonResponse::error('Livro não encontrado na lista de desejos.', 404, 'wishlist_item_not_found');
            }

            // Remove também da fila de espera se estiver aguardando
            $stmtFila = $this->db->prepare(
                'DELETE FROM reserva_fila WHERE id_usuario = :user AND id_livro = :book AND atendida = 0'
            );
            $stmtFila->execute(['user' => $userId, 'book' => $bookId]);

            $this->db->commit();
            JsonResponse::success(null, 204);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Erro ao remover da lista de desejos: ' . $e->getMessage());
            JsonResponse::error('Erro ao remover livro da lista de desejos.', 500, 'database_error');
        }
    }
}
