<?php
declare(strict_types=1);

final class ReportController
{
    public function __construct(private PDO $db) {}

    private function authorize(): void
    {
        CurrentUser::requireAdmin($this->db, CurrentUser::id());
    }

    public function popularBooks(): never
    {
        $this->authorize();
        $limit = min(100, max(1, Request::integer('limit', 20) ?? 20));
        $sql = 'SELECT L.id_livro, L.titulo, A.nome_autor AS autor,
                       COUNT(DISTINCT D.id_lista) AS total_adicoes,
                       COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos
                FROM livros L
                INNER JOIN autores A ON A.id_autor = L.id_autor
                LEFT JOIN lista_desejos D ON D.id_livro = L.id_livro
                LEFT JOIN emprestimo E ON E.id_livro = L.id_livro
                GROUP BY L.id_livro, L.titulo, A.nome_autor
                ORDER BY total_adicoes DESC, total_emprestimos DESC, L.titulo ASC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        JsonResponse::success($statement->fetchAll());
    }

    public function loans(): never
    {
        $this->authorize();
        $status = $_GET['status'] ?? 'all';
        $conditions = match ($status) {
            'active' => 'E.emp_ativo = 1 AND E.data_dev IS NULL',
            'overdue' => 'E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()',
            'completed' => 'E.emp_ativo = 0 AND E.data_dev IS NOT NULL',
            'reserved' => 'E.reserva = 1',
            'all' => '1 = 1',
            default => JsonResponse::error('Status inválido.', 400, 'invalid_status'),
        };
        $page = Request::integer('page');
        $perPage = Request::integer('per_page');

        if ($page !== null) {
            $page = max(1, $page);
            $perPage = min(100, max(1, $perPage ?? 20));
            $offset = ($page - 1) * $perPage;

            $countSql = "SELECT COUNT(*) FROM emprestimo E
                         INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                         INNER JOIN livros L ON L.id_livro = E.id_livro
                         WHERE {$conditions}";
            $total = (int) $this->db->query($countSql)->fetchColumn();

            $sql = "SELECT E.id_emprestimo, U.id_usuario, U.nome AS usuario, U.tipo_usuario,
                           L.id_livro, L.titulo, E.reserva, E.emp_ativo, E.multa_pendente,
                           E.data_emp, E.data_dev_prevista, E.data_dev,
                           CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()
                                THEN 'atrasado'
                                WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                                ELSE 'concluido' END AS status
                    FROM emprestimo E
                    INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                    INNER JOIN livros L ON L.id_livro = E.id_livro
                    WHERE {$conditions} ORDER BY E.data_emp DESC
                    LIMIT :limit OFFSET :offset";
            $statement = $this->db->prepare($sql);
            $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue('offset', $offset, PDO::PARAM_INT);
            $statement->execute();

            JsonResponse::success($statement->fetchAll(), 200, [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ]);
        }

        $sql = "SELECT E.id_emprestimo, U.id_usuario, U.nome AS usuario, U.tipo_usuario,
                       L.id_livro, L.titulo, E.reserva, E.emp_ativo, E.multa_pendente,
                       E.data_emp, E.data_dev_prevista, E.data_dev,
                       CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()
                            THEN 'atrasado'
                            WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                            ELSE 'concluido' END AS status
                FROM emprestimo E
                INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                INNER JOIN livros L ON L.id_livro = E.id_livro
                WHERE {$conditions} ORDER BY E.data_emp DESC";
        $statement = $this->db->query($sql);
        JsonResponse::success($statement->fetchAll());
    }

    public function activeUsers(): never
    {
        $this->authorize();
        $limit = min(100, max(1, Request::integer('limit', 20) ?? 20));
        $sql = 'SELECT U.id_usuario, U.nome, U.email, U.tipo_usuario,
                       COUNT(E.id_emprestimo) AS total_emprestimos,
                       MAX(E.data_emp) AS ultima_atividade
                FROM usuario U INNER JOIN emprestimo E ON E.id_usuario = U.id_usuario
                GROUP BY U.id_usuario, U.nome, U.email, U.tipo_usuario
                ORDER BY total_emprestimos DESC, ultima_atividade DESC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        JsonResponse::success($statement->fetchAll());
    }

    public function byClass(): never
    {
        $this->authorize();
        $classId = Request::integer('id_turma');
        $courseId = Request::integer('id_curso');

        $where = ['1 = 1'];
        $params = [];

        if ($classId !== null) {
            $where[] = 'T.id_turma = :id_turma';
            $params['id_turma'] = $classId;
        }
        if ($courseId !== null) {
            $where[] = 'T.id_curso = :id_curso';
            $params['id_curso'] = $courseId;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT T.id_turma, C.id_curso, C.nome_curso, T.turno, T.ano_calendario, T.periodo_letivo,
                       COUNT(DISTINCT AL.RA) AS total_alunos,
                       COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_ativos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_atrasados,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS emprestimos_concluidos
                FROM turma T
                INNER JOIN curso C ON C.id_curso = T.id_curso
                LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
                LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
                WHERE {$whereClause}
                GROUP BY T.id_turma, C.id_curso, C.nome_curso, T.turno, T.ano_calendario, T.periodo_letivo
                ORDER BY C.nome_curso ASC, T.ano_calendario DESC, T.turno ASC";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        JsonResponse::success($statement->fetchAll());
    }

    public function byCourse(): never
    {
        $this->authorize();
        $courseId = Request::integer('id_curso');

        $where = ['1 = 1'];
        $params = [];

        if ($courseId !== null) {
            $where[] = 'C.id_curso = :id_curso';
            $params['id_curso'] = $courseId;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT C.id_curso, C.nome_curso, C.Mtec,
                       COUNT(DISTINCT T.id_turma) AS total_turmas,
                       COUNT(DISTINCT AL.RA) AS total_alunos,
                       COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_ativos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_atrasados,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS emprestimos_concluidos
                FROM curso C
                LEFT JOIN turma T ON T.id_curso = C.id_curso
                LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
                LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
                WHERE {$whereClause}
                GROUP BY C.id_curso, C.nome_curso, C.Mtec
                ORDER BY C.nome_curso ASC";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        JsonResponse::success($statement->fetchAll());
    }

    public function bySituation(): never
    {
        $this->authorize();
        $situacao = $_GET['situacao'] ?? null;

        $where = ['1 = 1'];
        $params = [];

        if ($situacao !== null && trim($situacao) !== '') {
            $where[] = 'AL.situacao = :situacao';
            $params['situacao'] = trim($situacao);
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT AL.situacao,
                       COUNT(DISTINCT AL.RA) AS total_alunos,
                       COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_ativos,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS emprestimos_atrasados,
                       COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS emprestimos_concluidos
                FROM aluno AL
                LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
                WHERE {$whereClause}
                GROUP BY AL.situacao
                ORDER BY AL.situacao ASC";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        JsonResponse::success($statement->fetchAll());
    }

    public function loanHistory(): never
    {
        $this->authorize();

        $page = max(1, Request::integer('page', 1) ?? 1);
        $limit = min(200, max(1, Request::integer('limit', 50) ?? 50));
        $offset = ($page - 1) * $limit;

        $where = ['1 = 1'];
        $params = [];

        $status = $_GET['status'] ?? 'all';
        if ($status === 'active') {
            $where[] = 'E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE()';
        } elseif ($status === 'overdue') {
            $where[] = 'E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE()';
        } elseif ($status === 'completed') {
            $where[] = 'E.emp_ativo = 0 AND E.data_dev IS NOT NULL';
        } elseif ($status === 'reserved') {
            $where[] = 'E.reserva = 1';
        }

        $userId = Request::integer('id_usuario');
        if ($userId !== null) {
            $where[] = 'E.id_usuario = :id_usuario';
            $params['id_usuario'] = $userId;
        }

        $bookId = Request::integer('id_livro');
        if ($bookId !== null) {
            $where[] = 'E.id_livro = :id_livro';
            $params['id_livro'] = $bookId;
        }

        $courseId = Request::integer('id_curso');
        if ($courseId !== null) {
            $where[] = 'C.id_curso = :id_curso';
            $params['id_curso'] = $courseId;
        }

        $classId = Request::integer('id_turma');
        if ($classId !== null) {
            $where[] = 'T.id_turma = :id_turma';
            $params['id_turma'] = $classId;
        }

        $situacao = $_GET['situacao'] ?? null;
        if ($situacao !== null && trim($situacao) !== '') {
            $where[] = 'AL.situacao = :situacao';
            $params['situacao'] = trim($situacao);
        }

        $dataInicio = $_GET['data_inicio'] ?? null;
        if ($dataInicio !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
            $where[] = 'E.data_emp >= :data_inicio';
            $params['data_inicio'] = $dataInicio;
        }

        $dataFim = $_GET['data_fim'] ?? null;
        if ($dataFim !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            $where[] = 'E.data_emp <= :data_fim';
            $params['data_fim'] = $dataFim;
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*)
                     FROM emprestimo E
                     INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                     LEFT JOIN aluno AL ON AL.id_usuario = U.id_usuario
                     LEFT JOIN turma T ON T.id_turma = AL.id_turma
                     LEFT JOIN curso C ON C.id_curso = T.id_curso
                     INNER JOIN livros L ON L.id_livro = E.id_livro
                     WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT E.id_emprestimo,
                       U.id_usuario, U.nome AS usuario, U.email, U.tipo_usuario,
                       AL.RA AS aluno_ra, AL.situacao AS aluno_situacao,
                       C.id_curso, C.nome_curso,
                       T.id_turma, T.turno, T.ano_calendario, T.periodo_letivo,
                       L.id_livro, L.titulo AS livro_titulo, L.ISBN,
                       A.nome_autor AS autor,
                       CAT.nome_cat AS categoria,
                       E.data_emp, E.data_dev_prevista, E.data_dev,
                       E.emp_ativo, E.reserva, E.multa_pendente, E.dev_pdia, E.valor_multa,
                       CASE
                           WHEN E.reserva = 1 THEN 'aguardando_aprovacao'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'atrasado'
                           WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'ativo'
                           ELSE 'concluido'
                       END AS status
                FROM emprestimo E
                INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                LEFT JOIN aluno AL ON AL.id_usuario = U.id_usuario
                LEFT JOIN turma T ON T.id_turma = AL.id_turma
                LEFT JOIN curso C ON C.id_curso = T.id_curso
                INNER JOIN livros L ON L.id_livro = E.id_livro
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias CAT ON CAT.id_cat = L.id_cat
                WHERE {$whereClause}
                ORDER BY E.data_emp DESC, E.id_emprestimo DESC
                LIMIT :limit OFFSET :offset";

        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $statement->bindValue($key, $val);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        JsonResponse::success($statement->fetchAll(), 200, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / $limit),
        ]);
    }
}

