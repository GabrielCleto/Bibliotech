<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/Request.php';
require_once __DIR__ . '/../Support/JsonResponse.php';

final class BookController
{
    public function __construct(private PDO $db) {}

    public function index(): never
    {
        $search = trim($_GET['search'] ?? $_GET['q'] ?? '');
        $page = Request::integer('page');
        $perPage = Request::integer('per_page');

        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(L.titulo LIKE :s1 OR A.nome_autor LIKE :s2 OR C.nome_cat LIKE :s3 OR L.ISBN LIKE :s4)';
            $params['s1'] = '%' . $search . '%';
            $params['s2'] = '%' . $search . '%';
            $params['s3'] = '%' . $search . '%';
            $params['s4'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Se passar parâmetro de página, devolve paginado com metadados
        if ($page !== null) {
            $page = max(1, $page);
            $perPage = min(100, max(1, $perPage ?? 20));
            $offset = ($page - 1) * $perPage;

            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM livros L
                 INNER JOIN autores A ON A.id_autor = L.id_autor
                 INNER JOIN categorias C ON C.id_cat = L.id_cat
                 WHERE {$whereClause}"
            );
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $sql = "SELECT L.id_livro, L.titulo, L.ISBN, L.publicacao, L.editora,
                           A.nome_autor AS autor, C.nome_cat AS categoria,
                           L.quantidade_total, L.quantidade_disponivel
                    FROM livros L
                    INNER JOIN autores A ON A.id_autor = L.id_autor
                    INNER JOIN categorias C ON C.id_cat = L.id_cat
                    WHERE {$whereClause}
                    ORDER BY L.titulo
                    LIMIT :limit OFFSET :offset";
            $statement = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $statement->bindValue($k, $v);
            }
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

        $sql = "SELECT L.id_livro, L.titulo, L.ISBN, L.publicacao, L.editora,
                       A.nome_autor AS autor, C.nome_cat AS categoria,
                       L.quantidade_total, L.quantidade_disponivel
                FROM livros L
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                WHERE {$whereClause}
                ORDER BY L.titulo";
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        JsonResponse::success($statement->fetchAll());
    }

    public function show(array $params): never
    {
        $bookId = (int) ($params['bookId'] ?? 0);
        $sql = "SELECT L.id_livro, L.titulo, L.ISBN, L.publicacao, L.editora,
                       L.id_autor, A.nome_autor AS autor,
                       L.id_cat, C.nome_cat AS categoria,
                       L.quantidade_total, L.quantidade_disponivel
                FROM livros L
                INNER JOIN autores A ON A.id_autor = L.id_autor
                INNER JOIN categorias C ON C.id_cat = L.id_cat
                WHERE L.id_livro = :id";
        $statement = $this->db->prepare($sql);
        $statement->execute([':id' => $bookId]);
        $book = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$book) {
            JsonResponse::error('Livro não encontrado.', 404, 'book_not_found');
        }

        JsonResponse::success($book);
    }
}
