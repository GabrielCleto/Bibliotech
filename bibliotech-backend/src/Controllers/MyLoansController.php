<?php
declare(strict_types=1);

final class MyLoansController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $userId = CurrentUser::id();

        $sql = "SELECT E.id_emprestimo, L.id_livro, L.titulo, A.nome_autor AS autor,
                       C.nome_cat AS categoria, L.ISBN, L.editora, L.publicacao,
                       E.data_emp, E.data_dev_prevista, E.data_dev, E.emp_ativo,
                       E.reserva, E.multa_pendente, E.dev_pdia, E.valor_multa,
                       CASE
                           WHEN E.emp_ativo = 1 AND E.reserva = 1 THEN 'aguardando_aprovacao_renovacao'
                           WHEN E.reserva = 1 THEN 'aguardando_aprovacao'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'atrasado'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                           ELSE 'concluido'
                       END AS status

                FROM emprestimo E
                INNER JOIN livros L ON L.id_livro = E.id_livro
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                WHERE E.id_usuario = :user
                ORDER BY E.data_emp DESC";
        $statement = $this->db->prepare($sql);
        $statement->bindValue('user', $userId, PDO::PARAM_INT);
        $statement->execute();

        JsonResponse::success($statement->fetchAll());
    }

    public function renew(array $params): never
    {
        $userId = CurrentUser::id();
        $loanId = (int) ($params['loanId'] ?? 0);

        if ($loanId <= 0) {
            JsonResponse::error('ID do empréstimo inválido.', 400, 'invalid_loan_id');
        }

        require_once __DIR__ . '/../../models/Emprestimo.php';
        $logica = new Emprestimo($this->db);
        $resultado = $logica->renovarEmprestimo($loanId, $userId);

        if (!$resultado['sucesso']) {
            JsonResponse::error($resultado['mensagem'], 400, 'renew_failed');
        }

        JsonResponse::success([
            'id_emprestimo' => $resultado['id_emprestimo'] ?? $loanId,
            'mensagem' => $resultado['mensagem'],
        ]);
    }

    public function request(array $params = []): never
    {
        $userId = CurrentUser::id();

        $stmt = $this->db->prepare('SELECT tipo_usuario, ativo FROM usuario WHERE id_usuario = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int) $user['ativo'] === 0) {
            JsonResponse::error('Usuário inativo ou não encontrado.', 403, 'user_inactive');
        }

        if (!in_array($user['tipo_usuario'], ['aluno', 'professor'], true)) {
            JsonResponse::error('Apenas alunos e professores podem solicitar empréstimos.', 403, 'forbidden');
        }

        $body = Request::json();
        $bookId = (int) ($params['bookId'] ?? $body['id_livro'] ?? Request::integer('id_livro') ?? 0);

        if ($bookId <= 0) {
            JsonResponse::error('ID do livro não informado ou inválido.', 400, 'invalid_book_id');
        }

        require_once __DIR__ . '/../../models/Emprestimo.php';
        $logica = new Emprestimo($this->db);
        $resultado = $logica->solicitarEmprestimo($userId, $bookId);

        if (!$resultado['sucesso']) {
            JsonResponse::error($resultado['mensagem'], 400, 'request_failed');
        }

        JsonResponse::success([
            'id_emprestimo' => $resultado['id_emprestimo'] ?? null,
            'mensagem' => $resultado['mensagem'],
        ], 201);
    }

    public function approveRenewal(array $params): never
    {
        CurrentUser::requireAdmin($this->db, CurrentUser::id());
        $loanId = (int) ($params['loanId'] ?? 0);

        if ($loanId <= 0) {
            JsonResponse::error('ID do empréstimo inválido.', 400, 'invalid_loan_id');
        }

        require_once __DIR__ . '/../../models/Emprestimo.php';
        $logica = new Emprestimo($this->db);
        $resultado = $logica->aprovarRenovacao($loanId);

        if (!$resultado['sucesso']) {
            JsonResponse::error($resultado['mensagem'], 400, 'approve_renewal_failed');
        }

        JsonResponse::success([
            'id_emprestimo' => $loanId,
            'mensagem' => $resultado['mensagem'],
        ]);
    }

    public function rejectRenewal(array $params): never
    {
        CurrentUser::requireAdmin($this->db, CurrentUser::id());
        $loanId = (int) ($params['loanId'] ?? 0);

        if ($loanId <= 0) {
            JsonResponse::error('ID do empréstimo inválido.', 400, 'invalid_loan_id');
        }

        require_once __DIR__ . '/../../models/Emprestimo.php';
        $logica = new Emprestimo($this->db);
        $resultado = $logica->recusarRenovacao($loanId);

        if (!$resultado['sucesso']) {
            JsonResponse::error($resultado['mensagem'], 400, 'reject_renewal_failed');
        }

        JsonResponse::success([
            'id_emprestimo' => $loanId,
            'mensagem' => $resultado['mensagem'],
        ]);
    }
}



