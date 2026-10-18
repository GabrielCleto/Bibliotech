<?php
declare(strict_types=1);

final class NotificationService
{
    public function __construct(private PDO $db) {}

    public function generate(): int
    {
        $created = $this->notifyAvailableWishlistBooks();
        $created += $this->notifyDueLoans();
        return $created;
    }

    private function notifyAvailableWishlistBooks(): int
    {
        $sql = 'INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                SELECT D.id_usuario, :type, :title,
                       CONCAT("O livro ", L.titulo, " está disponível para empréstimo.")
                FROM lista_desejos D
                INNER JOIN livros L ON L.id_livro = D.id_livro
                WHERE L.quantidade_disponivel > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM notificacao N
                      WHERE N.id_usuario = D.id_usuario AND N.tipo = :type_check
                        AND N.mensagem = CONCAT("O livro ", L.titulo, " está disponível para empréstimo.")
                        AND N.criada_em >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                  )';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'type' => 'wishlist_available',
            'title' => 'Livro disponível',
            'type_check' => 'wishlist_available',
        ]);
        return $statement->rowCount();
    }

    private function notifyDueLoans(): int
    {
        $sql = 'INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                SELECT E.id_usuario, :type, :title,
                       CONCAT("O empréstimo do livro ", L.titulo, " vence em ",
                              DATE_FORMAT(E.data_dev_prevista, "%d/%m/%Y"), ".")
                FROM emprestimo E
                INNER JOIN livros L ON L.id_livro = E.id_livro
                WHERE E.emp_ativo = 1 AND E.data_dev IS NULL
                  AND E.data_dev_prevista BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                  AND NOT EXISTS (
                      SELECT 1 FROM notificacao N
                      WHERE N.id_usuario = E.id_usuario AND N.tipo = :type_check
                        AND N.mensagem = CONCAT("O empréstimo do livro ", L.titulo, " vence em ",
                              DATE_FORMAT(E.data_dev_prevista, "%d/%m/%Y"), ".")
                        AND N.criada_em >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                  )';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'type' => 'loan_due',
            'title' => 'Prazo de devolução próximo',
            'type_check' => 'loan_due',
        ]);
        return $statement->rowCount();
    }
}
