<?php

// formatarDataBr() é usada na mensagem de aprovação (dd/mm/aaaa)
require_once __DIR__ . "/../config/Datas.php";

/**
 * Classe Emprestimo
 *
 * Cuida do fluxo de empréstimo com aprovação da bibliotecária:
 * 1) solicitarEmprestimo() — aluno/professor pede o livro e o pedido
 *    fica pendente esperando aprovação (não tira cópia do estoque)
 * 2) aprovarSolicitacao() — bibliotecária libera: quem cria o
 *    empréstimo é a procedure sp_realizar_emprestimo do banco, e só aí
 *    o prazo de devolução começa a contar e a cópia sai do estoque
 * 3) recusarSolicitacao() — bibliotecária nega, o pedido é apagado
 * 4) registrarDevolucao() — quem faz é a procedure sp_devolver_livro
 *    do banco (multa, estoque e aviso da fila de reserva)
 *
 * As regras de negócio ficam no banco (procedures e triggers). O PHP
 * só chama e mostra pra pessoa a mensagem que o banco devolver.
 *
 * Ela não abre conexão sozinha — recebe uma conexão PDO já pronta
 * (a mesma que a Conexao::getConexao() devolve).
 */
class Emprestimo
{
    private PDO $conexao;

    // Regras de negócio fixas do módulo
    private const PRAZO_DIAS_ALUNO = 15;
    private const PRAZO_DIAS_PROFESSOR = 30;

    public function __construct(PDO $conexaoPdo)
    {
        $this->conexao = $conexaoPdo;
    }

    /**
     * Passo 1: aluno ou professor solicita um livro. Fica como
     * "pedido pendente" (emp_ativo = 0, reserva = 1) até a
     * bibliotecária decidir.
     *
     * MUDANÇA (pedido do grupo de BD): o pedido pendente NÃO tira mais
     * a cópia do estoque. Quem tira a cópia do estoque agora é a
     * procedure sp_realizar_emprestimo, só na hora da aprovação (lá
     * dentro o banco já confere tudo: usuário ativo, multa, atraso,
     * limite, livro repetido e se ainda tem exemplar).
     *
     * Aqui só ficaram as conferências que a procedure NÃO consegue
     * fazer, porque ela só conhece empréstimo de verdade, não pedido
     * pendente: multa pendente, limite contando os pedidos pendentes,
     * livro sem estoque e pedido repetido do mesmo livro. Usuário
     * inativo e livro atrasado quem confere é a trigger do banco
     * (a mensagem dela aparece pra pessoa).
     */
    public function solicitarEmprestimo(int $idUsuario, int $idLivro): array
    {
        try {
            $this->conexao->beginTransaction();

            // Precisa do tipo (aluno/professor) só pra montar o aviso
            // pra bibliotecária mais abaixo
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario, tipo_usuario FROM usuario WHERE id_usuario = :id"
            );
            $stmt->execute([':id' => $idUsuario]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Usuário não encontrado.');
            }

            // Multa ainda não paga (inclusive de livro já devolvido)
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM emprestimo WHERE id_usuario = :id AND multa_pendente = 1"
            );
            $stmt->execute([':id' => $idUsuario]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->conexao->rollBack();
                return $this->resposta(
                    false,
                    'Usuário possui multa pendente. Regularize com a bibliotecária antes de solicitar outro empréstimo.'
                );
            }

            // Limite de livros (regra de negócio: 3 livros emprestados ou pendentes)
            $limite = 3;
            try {
                $stmtLim = $this->conexao->query("SELECT limite_emprestimos FROM configuracao ORDER BY id_configuracao LIMIT 1");
                $val = $stmtLim ? $stmtLim->fetchColumn() : null;
                if ($val !== false && $val !== null && (int) $val > 0) {
                    $limite = (int) $val;
                }
            } catch (PDOException) {
                // Se a coluna não existir no schema do banco existente, mantém o padrão de 3 livros
                $limite = 3;
            }


            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM emprestimo
                 WHERE id_usuario = :id AND (emp_ativo = 1 OR reserva = 1)"
            );
            $stmt->execute([':id' => $idUsuario]);
            if ((int) $stmt->fetchColumn() >= $limite) {
                $this->conexao->rollBack();
                return $this->resposta(
                    false,
                    "Limite de {$limite} livros (emprestados ou pendentes) atingido."
                );
            }

            // O livro existe e tem cópia disponível?
            $stmt = $this->conexao->prepare(
                "SELECT id_livro, titulo, quantidade_disponivel FROM livros WHERE id_livro = :id"
            );
            $stmt->execute([':id' => $idLivro]);
            $livro = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$livro) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Livro não encontrado.');
            }

            if ((int) $livro['quantidade_disponivel'] <= 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Nenhum exemplar disponível para empréstimo.');
            }

            // Já tem esse MESMO livro emprestado ou pendente?
            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM emprestimo
                 WHERE id_usuario = :usuario AND id_livro = :livro AND (emp_ativo = 1 OR reserva = 1)"
            );
            $stmt->execute([':usuario' => $idUsuario, ':livro' => $idLivro]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Você já tem esse livro emprestado ou pendente de aprovação.');
            }

            // Cria o pedido pendente. As datas ainda não valem nada de
            // verdade (a coluna é obrigatória no banco) — as de verdade
            // são criadas pela procedure na aprovação.
            // Aqui a trigger trg_emprestimo_regras_insert confere:
            // usuário inativo, tipo de conta e livro em atraso.
            $hoje = (new DateTime())->format('Y-m-d');
            $stmt = $this->conexao->prepare(
                "INSERT INTO emprestimo
                    (emp_ativo, reserva, dev_pdia, multa_pendente, data_emp, data_dev_prevista, data_dev, id_livro, id_usuario)
                 VALUES
                    (0, 1, 0, 0, :data, :data, NULL, :id_livro, :id_usuario)"
            );
            $stmt->execute([
                ':data'       => $hoje,
                ':id_livro'   => $idLivro,
                ':id_usuario' => $idUsuario,
            ]);
            $idEmprestimo = (int) $this->conexao->lastInsertId();

            // Avisa toda bibliotecária ativa, na hora
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario FROM usuario WHERE tipo_usuario = 'bibliotecario' AND ativo = 1"
            );
            $stmt->execute();
            $bibliotecarios = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $mensagemAviso = "{$usuario['tipo_usuario']} solicitou o livro \"{$livro['titulo']}\". Aguardando aprovação.";
            $inserirNotificacao = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'solicitacao_emprestimo', 'Nova solicitação de empréstimo', :mensagem)"
            );
            foreach ($bibliotecarios as $idBibliotecario) {
                $inserirNotificacao->execute([
                    ':id_usuario' => $idBibliotecario,
                    ':mensagem'   => $mensagemAviso,
                ]);
            }

            // Avisa quem solicitou, confirmando que o pedido foi
            // enviado (ainda esperando decisão da bibliotecária)
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'solicitacao_enviada', 'Solicitação enviada',
                         :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $idUsuario,
                ':mensagem' => "Sua solicitação do livro \"{$livro['titulo']}\" foi enviada e está aguardando aprovação da bibliotecária.",
            ]);

            $this->conexao->commit();

            return $this->resposta(true, 'Solicitação enviada. Aguardando aprovação da bibliotecária.', $idEmprestimo);
        } catch (PDOException $e) {
            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            // Mensagem escrita pelo próprio banco (trigger): usuário
            // inativo, livro em atraso etc. Mostra ela pra pessoa.
            if ($e->getCode() === "45000") {
                return $this->resposta(false, $this->mensagemDoBanco($e));
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao solicitar empréstimo. Tente novamente.');
        } catch (Exception $e) {
            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao solicitar empréstimo. Tente novamente.');
        }
    }

    /**
     * Passo 2 (caminho "sim"): bibliotecária aprova a solicitação.
     *
     * Quem cria o empréstimo de verdade é a procedure
     * sp_realizar_emprestimo do banco. Ela confere tudo de novo na
     * hora (usuário ativo, multa, atraso, limite, livro repetido,
     * exemplar disponível), tira a cópia do estoque e cria o
     * empréstimo com o prazo. Se ela recusar, a mensagem dela aparece
     * pra bibliotecária e o pedido continua pendente.
     *
     * Depois que a procedure der certo, o pedido pendente (que era só
     * um "recado") é apagado, porque o empréstimo ativo já existe.
     *
     * Obs.: não dá pra abrir transação aqui por fora, porque a
     * procedure abre e fecha a dela mesma.
     */
    public function aprovarSolicitacao(int $idEmprestimo): array
    {
        try {
            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.reserva, E.emp_ativo, E.id_usuario, E.id_livro,
                        U.tipo_usuario, L.titulo
                 FROM emprestimo E
                 INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$solicitacao || (int) $solicitacao['reserva'] !== 1 || (int) $solicitacao['emp_ativo'] !== 0) {
                return $this->resposta(false, 'Solicitação não encontrada ou já foi decidida antes.');
            }

            // Prazo: aluno 15 dias, professor 30 (a procedure pede o
            // número de dias)
            $prazoDias = ($solicitacao['tipo_usuario'] === 'professor')
                ? self::PRAZO_DIAS_PROFESSOR
                : self::PRAZO_DIAS_ALUNO;

            // O banco decide. Se não puder, ele devolve a mensagem.
            $stmt = $this->conexao->prepare("CALL sp_realizar_emprestimo(:usuario, :livro, :dias)");
            $stmt->execute([
                ':usuario' => $solicitacao['id_usuario'],
                ':livro'   => $solicitacao['id_livro'],
                ':dias'    => $prazoDias,
            ]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            if ($e->getCode() === "45000") {
                return $this->resposta(false, $this->mensagemDoBanco($e));
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao aprovar solicitação. Tente novamente.');
        }

        // A partir daqui o empréstimo JÁ existe no banco. Se algo abaixo
        // falhar, só registra no log (não dá pra "desfazer" a procedure).
        $mensagemFinal = 'Solicitação aprovada. Empréstimo liberado.';
        try {
            // Acha o empréstimo ativo que a procedure acabou de criar
            $stmt = $this->conexao->prepare(
                "SELECT id_emprestimo, data_dev_prevista FROM emprestimo
                 WHERE id_usuario = :usuario AND id_livro = :livro AND emp_ativo = 1
                 ORDER BY id_emprestimo DESC LIMIT 1"
            );
            $stmt->execute([
                ':usuario' => $solicitacao['id_usuario'],
                ':livro'   => $solicitacao['id_livro'],
            ]);
            $novo = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->conexao->beginTransaction();

            // Apaga o pedido pendente (só se ainda for pendente)
            $stmt = $this->conexao->prepare(
                "DELETE FROM emprestimo WHERE id_emprestimo = :id AND reserva = 1 AND emp_ativo = 0"
            );
            $stmt->execute([':id' => $idEmprestimo]);

            $dataPrevista = $novo ? formatarDataBr($novo['data_dev_prevista']) : '';
            $mensagem = "Seu pedido do livro \"{$solicitacao['titulo']}\" foi aprovado."
                . ($dataPrevista !== '' ? " Devolução prevista para {$dataPrevista}." : '');
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_aprovado', 'Empréstimo aprovado', :mensagem)"
            );
            $stmt->execute([':id_usuario' => $solicitacao['id_usuario'], ':mensagem' => $mensagem]);

            $this->conexao->commit();

            return $this->resposta(true, $mensagemFinal, $novo ? (int) $novo['id_emprestimo'] : null);
        } catch (Exception $e) {
            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            error_log("Empréstimo criado, mas falhou a limpeza do pedido {$idEmprestimo}: " . $e->getMessage());
            return $this->resposta(true, $mensagemFinal, null);
        }
    }

    /**
     * Passo 2 (caminho "não"): bibliotecária recusa a solicitação.
     * O pedido pendente é apagado (nunca chegou a virar empréstimo).
     * Como o pedido pendente não tira cópia do estoque, não tem nada
     * pra devolver pro estoque aqui.
     */
    public function recusarSolicitacao(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.reserva, E.emp_ativo, E.id_usuario, E.id_livro, L.titulo
                 FROM emprestimo E
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$solicitacao || (int) $solicitacao['reserva'] !== 1 || (int) $solicitacao['emp_ativo'] !== 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Solicitação não encontrada ou já foi decidida antes.');
            }

            $stmt = $this->conexao->prepare("DELETE FROM emprestimo WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);

            // Avisa quem pediu que foi recusado
            $mensagem = "Seu pedido do livro \"{$solicitacao['titulo']}\" foi recusado pela bibliotecária.";
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_recusado', 'Empréstimo recusado', :mensagem)"
            );
            $stmt->execute([':id_usuario' => $solicitacao['id_usuario'], ':mensagem' => $mensagem]);

            $this->conexao->commit();

            return $this->resposta(true, 'Solicitação recusada.');
        } catch (Exception $e) {
            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao recusar solicitação. Tente novamente.');
        }
    }

    /**
     * Registra a devolução de um livro emprestado.
     *
     * Quem faz o trabalho é a procedure sp_devolver_livro do banco:
     * ela marca a devolução, a trigger calcula dias de atraso e multa,
     * a cópia volta pro estoque e o primeiro da fila de reserva é
     * avisado. Se ela recusar (ex.: já foi devolvido), a mensagem dela
     * aparece pra bibliotecária.
     *
     * A única coisa que continua aqui é o aviso de multa pra pessoa
     * (a procedure não cria esse aviso).
     */
    public function registrarDevolucao(int $idEmprestimo): array
    {
        try {
            $stmt = $this->conexao->prepare("CALL sp_devolver_livro(:id)");
            $stmt->execute([':id' => $idEmprestimo]);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            if ($e->getCode() === "45000") {
                return $this->resposta(false, $this->mensagemDoBanco($e));
            }
            error_log($e->getMessage());
            return $this->resposta(false, 'Erro ao processar devolução. Tente novamente.');
        }

        // A devolução já está feita no banco. O aviso de multa é um
        // "extra": se falhar, só vai pro log e a devolução continua valendo.
        try {
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario, dev_pdia, valor_multa, multa_pendente
                 FROM emprestimo WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($emprestimo && (int) $emprestimo['multa_pendente'] === 1) {
                $valorFormatado = number_format((float) $emprestimo['valor_multa'], 2, ',', '.');
                $stmt = $this->conexao->prepare(
                    "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                     VALUES (:id_usuario, 'multa_gerada', 'Multa por atraso', :mensagem)"
                );
                $stmt->execute([
                    ':id_usuario' => $emprestimo['id_usuario'],
                    ':mensagem' => "Você devolveu um livro com {$emprestimo['dev_pdia']} dia(s) de atraso. Multa: R$ {$valorFormatado}. Regularize com a bibliotecária antes de solicitar outro empréstimo.",
                ]);
            }
        } catch (Exception $e) {
            error_log("Devolução {$idEmprestimo} feita, mas o aviso de multa falhou: " . $e->getMessage());
        }

        return $this->resposta(true, 'Devolução registrada com sucesso.');
    }

    /**
     * Bibliotecária marca uma multa como paga (quitada).
     */
    public function quitarMulta(int $idEmprestimo): array
    {
        try {
            $this->conexao->beginTransaction();

            $stmt = $this->conexao->prepare(
                "SELECT id_emprestimo, id_usuario, multa_pendente FROM emprestimo WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Empréstimo não encontrado.');
            }

            if ((int) $emprestimo['multa_pendente'] === 0) {
                $this->conexao->rollBack();
                return $this->resposta(false, 'Esse empréstimo não tem multa pendente.');
            }

            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo SET multa_pendente = 0, valor_multa = 0 WHERE id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);

            // Avisa a pessoa que a multa foi paga
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'multa_quitada', 'Multa quitada',
                         'Sua multa foi registrada como paga. Obrigado por regularizar!')"
            );
            $stmt->execute([':id_usuario' => $emprestimo['id_usuario']]);

            $this->conexao->commit();

            return $this->resposta(true, 'Multa quitada com sucesso.');
        } catch (Exception $e) {
            $this->conexao->rollBack();
            return $this->resposta(false, 'Erro ao quitar multa: ' . $e->getMessage());
        }
    }

    /**
     * RN02: Renovação de empréstimo.
     * Quando chamado por aluno ou professor, cria uma SOLICITAÇÃO de renovação
     * que precisa ser APROVADA pela bibliotecária.
     * Quando chamado por bibliotecária/admin, aprova/renova diretamente.
     */
    public function renovarEmprestimo(int $idEmprestimo, int $idUsuarioSolicitante): array
    {
        $stmt = $this->conexao->prepare("SELECT tipo_usuario FROM usuario WHERE id_usuario = :id");
        $stmt->execute([':id' => $idUsuarioSolicitante]);
        $tipo = $stmt->fetchColumn();

        if (in_array($tipo, ['bibliotecario', 'admin'], true)) {
            return $this->aprovarRenovacao($idEmprestimo);
        }

        return $this->solicitarRenovacao($idEmprestimo, $idUsuarioSolicitante);
    }

    /**
     * Aluno ou professor solicita renovação de empréstimo.
     * Marca o empréstimo ativo com reserva = 1 (aguardando aprovação da bibliotecária).
     */
    public function solicitarRenovacao(int $idEmprestimo, int $idUsuarioSolicitante): array
    {
        $transacaoPropria = false;
        try {
            if (!$this->conexao->inTransaction()) {
                $this->conexao->beginTransaction();
                $transacaoPropria = true;
            }

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.id_usuario, E.id_livro, E.emp_ativo, E.reserva,
                        E.data_emp, E.data_dev_prevista, E.data_dev,
                        U.nome AS nome_usuario, U.tipo_usuario, L.titulo
                 FROM emprestimo E
                 INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo || (int) $emprestimo['emp_ativo'] === 0) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Empréstimo não encontrado ou já devolvido.');
            }

            if ((int) $emprestimo['id_usuario'] !== $idUsuarioSolicitante) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Você só pode solicitar renovação dos seus próprios empréstimos.');
            }

            if ((int) $emprestimo['reserva'] === 1) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Já existe uma solicitação de renovação aguardando aprovação da bibliotecária para este livro.');
            }

            if ($emprestimo['data_dev_prevista'] < date('Y-m-d')) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Não é possível renovar um empréstimo atrasado. Devolva o livro primeiro.');
            }

            $prazoDias = ($emprestimo['tipo_usuario'] === 'professor')
                ? self::PRAZO_DIAS_PROFESSOR
                : self::PRAZO_DIAS_ALUNO;

            $stmt = $this->conexao->prepare(
                "SELECT DATEDIFF(:prevista, :emp) AS dias_totais"
            );
            $stmt->execute([':prevista' => $emprestimo['data_dev_prevista'], ':emp' => $emprestimo['data_emp']]);
            $diasTotais = (int) $stmt->fetchColumn();

            if ($diasTotais >= ($prazoDias * 2)) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Este empréstimo já foi renovado uma vez. Não é possível renovar de novo.');
            }

            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM reserva_fila WHERE id_livro = :id_livro AND atendida = 0"
            );
            $stmt->execute([':id_livro' => $emprestimo['id_livro']]);
            if ((int) $stmt->fetchColumn() > 0) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Este livro possui pessoas na fila de espera. Não é possível renovar.');
            }

            // Marca reserva = 1 indicando que há solicitação de renovação pendente
            $stmt = $this->conexao->prepare("UPDATE emprestimo SET reserva = 1 WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);

            // Notifica as bibliotecárias ativas
            $stmt = $this->conexao->prepare(
                "SELECT id_usuario FROM usuario WHERE tipo_usuario = 'bibliotecario' AND ativo = 1"
            );
            $stmt->execute();
            $bibliotecarios = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $mensagemAviso = "{$emprestimo['tipo_usuario']} {$emprestimo['nome_usuario']} solicitou renovação do livro \"{$emprestimo['titulo']}\". Aguardando sua aprovação.";
            $inserirNotificacao = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'solicitacao_renovacao', 'Solicitação de renovação de empréstimo', :mensagem)"
            );
            foreach ($bibliotecarios as $idBiblio) {
                $inserirNotificacao->execute([
                    ':id_usuario' => $idBiblio,
                    ':mensagem' => $mensagemAviso,
                ]);
            }

            // Notifica o solicitante
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'renovacao_solicitada', 'Renovação solicitada', :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $emprestimo['id_usuario'],
                ':mensagem' => "Sua solicitação de renovação do livro \"{$emprestimo['titulo']}\" foi enviada e está aguardando aprovação da bibliotecária.",
            ]);

            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->commit();
            }

            return $this->resposta(true, 'Solicitação de renovação enviada. Aguardando aprovação da bibliotecária.', $idEmprestimo);
        } catch (Exception $e) {
            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            return $this->resposta(false, 'Erro ao solicitar renovação: ' . $e->getMessage());
        }
    }

    /**
     * Bibliotecária aprova a renovação de empréstimo.
     */
    public function aprovarRenovacao(int $idEmprestimo): array
    {
        $transacaoPropria = false;
        try {
            if (!$this->conexao->inTransaction()) {
                $this->conexao->beginTransaction();
                $transacaoPropria = true;
            }

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.id_usuario, E.id_livro, E.emp_ativo, E.reserva,
                        E.data_emp, E.data_dev_prevista, E.data_dev,
                        U.tipo_usuario, L.titulo
                 FROM emprestimo E
                 INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo || (int) $emprestimo['emp_ativo'] === 0) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Empréstimo não encontrado ou já devolvido.');
            }

            if ($emprestimo['data_dev_prevista'] < date('Y-m-d')) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Não é possível renovar um empréstimo atrasado. Devolva o livro primeiro.');
            }

            $prazoDias = ($emprestimo['tipo_usuario'] === 'professor')
                ? self::PRAZO_DIAS_PROFESSOR
                : self::PRAZO_DIAS_ALUNO;

            $stmt = $this->conexao->prepare(
                "SELECT DATEDIFF(:prevista, :emp) AS dias_totais"
            );
            $stmt->execute([':prevista' => $emprestimo['data_dev_prevista'], ':emp' => $emprestimo['data_emp']]);
            $diasTotais = (int) $stmt->fetchColumn();

            if ($diasTotais >= ($prazoDias * 2)) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Este empréstimo já foi renovado uma vez. Não é possível renovar de novo.');
            }

            $stmt = $this->conexao->prepare(
                "SELECT COUNT(*) FROM reserva_fila WHERE id_livro = :id_livro AND atendida = 0"
            );
            $stmt->execute([':id_livro' => $emprestimo['id_livro']]);
            if ((int) $stmt->fetchColumn() > 0) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Este livro possui pessoas na fila de espera. Não é possível renovar.');
            }

            // Estende o prazo e remove o status pendente de reserva
            $stmt = $this->conexao->prepare(
                "UPDATE emprestimo
                 SET reserva = 0,
                     data_dev_prevista = DATE_ADD(data_dev_prevista, INTERVAL :dias DAY)
                 WHERE id_emprestimo = :id"
            );
            $stmt->execute([':dias' => $prazoDias, ':id' => $idEmprestimo]);

            $stmt = $this->conexao->prepare("SELECT data_dev_prevista FROM emprestimo WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);
            $novaData = $stmt->fetchColumn();

            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'emprestimo_renovado', 'Renovação de empréstimo aprovada', :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $emprestimo['id_usuario'],
                ':mensagem' => "Sua solicitação de renovação do livro \"{$emprestimo['titulo']}\" foi aprovada pela bibliotecária. Nova data de devolução prevista: "
                    . (new DateTime($novaData))->format('d/m/Y') . '.',
            ]);

            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->commit();
            }

            return $this->resposta(true, 'Renovação aprovada com sucesso.', $idEmprestimo);
        } catch (Exception $e) {
            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            return $this->resposta(false, 'Erro ao aprovar renovação: ' . $e->getMessage());
        }
    }

    /**
     * Bibliotecária recusa a renovação de empréstimo.
     */
    public function recusarRenovacao(int $idEmprestimo): array
    {
        $transacaoPropria = false;
        try {
            if (!$this->conexao->inTransaction()) {
                $this->conexao->beginTransaction();
                $transacaoPropria = true;
            }

            $stmt = $this->conexao->prepare(
                "SELECT E.id_emprestimo, E.id_usuario, E.id_livro, E.emp_ativo, E.reserva,
                        E.data_dev_prevista, L.titulo
                 FROM emprestimo E
                 INNER JOIN livros L ON L.id_livro = E.id_livro
                 WHERE E.id_emprestimo = :id"
            );
            $stmt->execute([':id' => $idEmprestimo]);
            $emprestimo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$emprestimo || (int) $emprestimo['emp_ativo'] === 0) {
                if ($transacaoPropria && $this->conexao->inTransaction()) {
                    $this->conexao->rollBack();
                }
                return $this->resposta(false, 'Empréstimo não encontrado ou já devolvido.');
            }

            // Remove o status pendente de reserva
            $stmt = $this->conexao->prepare("UPDATE emprestimo SET reserva = 0 WHERE id_emprestimo = :id");
            $stmt->execute([':id' => $idEmprestimo]);

            $dataPrevista = (new DateTime($emprestimo['data_dev_prevista']))->format('d/m/Y');
            $stmt = $this->conexao->prepare(
                "INSERT INTO notificacao (id_usuario, tipo, titulo, mensagem)
                 VALUES (:id_usuario, 'renovacao_recusada', 'Renovação recusada', :mensagem)"
            );
            $stmt->execute([
                ':id_usuario' => $emprestimo['id_usuario'],
                ':mensagem' => "Sua solicitação de renovação do livro \"{$emprestimo['titulo']}\" foi recusada pela bibliotecária. O prazo original de devolução ({$dataPrevista}) permanece inalterado.",
            ]);

            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->commit();
            }

            return $this->resposta(true, 'Solicitação de renovação recusada com sucesso.', $idEmprestimo);
        } catch (Exception $e) {
            if ($transacaoPropria && $this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }
            return $this->resposta(false, 'Erro ao recusar renovação: ' . $e->getMessage());
        }
    }


    /**
     * O PDO devolve o SIGNAL da trigger dentro de uma mensagem maior,
     * tipo "SQLSTATE[45000]: <<Unknown exception>>: 1644 Usuário
     * possui livro em atraso...". Essa função tira só o texto que a
     * trigger escreveu, pra mostrar pra pessoa sem o "lixo" técnico.
     */
    private function mensagemDoBanco(PDOException $e): string
    {
        $mensagem = $e->getMessage();
        if (preg_match('/\d{3,5}\s+(.+)$/s', $mensagem, $partes)) {
            return trim($partes[1]);
        }
        return 'Não foi possível concluir a operação. Tente novamente.';
    }

    private function resposta(bool $sucesso, string $mensagem, ?int $idEmprestimo = null): array
    {
        return [
            'sucesso'       => $sucesso,
            'mensagem'      => $mensagem,
            'id_emprestimo' => $idEmprestimo,
        ];
    }
}
