<?php
// relatorio.php — tela de relatórios pra bibliotecário, em HTML
// simples (sem JSON, sem código pra ninguém ver).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
exigirContaAtiva($conexao);

$tipo = $_SESSION["tipo_usuario"];
if ($tipo !== "bibliotecario" && $tipo !== "admin") {
    http_response_code(403);
    exit("Acesso restrito a bibliotecários.");
}

session_write_close();

// Qual relatório mostrar (vem de um link, ex: ?relatorio=populares)
$relatorioEscolhido = $_GET["relatorio"] ?? "populares";

if ($relatorioEscolhido === "emprestimos") {
    $titulo = "Empréstimos";
    // Datas já formatadas em dia/mês/ano direto na consulta: essa
    // tela monta a tabela de forma genérica (olhando as colunas que
    // vierem), então é mais simples formatar aqui do que no PHP
    $sql = "SELECT E.id_emprestimo,
                   U.id_usuario,
                   U.nome AS usuario,
                   U.tipo_usuario,
                   L.titulo,
                   DATE_FORMAT(E.data_emp, '%d/%m/%Y') AS data_emp,
                   DATE_FORMAT(E.data_dev_prevista, '%d/%m/%Y') AS data_dev_prevista,
                   DATE_FORMAT(E.data_dev, '%d/%m/%Y') AS data_dev,
                   CASE
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            INNER JOIN livros L ON L.id_livro = E.id_livro
            ORDER BY E.data_emp DESC";
} elseif ($relatorioEscolhido === "usuarios") {
    $titulo = "Usuários mais ativos";
    $sql = "SELECT U.nome, U.tipo_usuario, COUNT(E.id_emprestimo) AS total_emprestimos,
                   DATE_FORMAT(MAX(E.data_emp), '%d/%m/%Y') AS ultima_atividade
            FROM usuario U
            INNER JOIN emprestimo E ON E.id_usuario = U.id_usuario
            GROUP BY U.id_usuario, U.nome, U.tipo_usuario
            ORDER BY total_emprestimos DESC
            LIMIT 20";
} elseif ($relatorioEscolhido === "turma") {
    $titulo = "Empréstimos por Turma";
    $sql = "SELECT C.nome_curso AS curso, T.turno, T.ano_calendario AS ano, T.periodo_letivo AS periodo,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM turma T
            INNER JOIN curso C ON C.id_curso = T.id_curso
            LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY T.id_turma, C.nome_curso, T.turno, T.ano_calendario, T.periodo_letivo
            ORDER BY C.nome_curso, T.ano_calendario DESC, T.turno";
} elseif ($relatorioEscolhido === "curso") {
    $titulo = "Empréstimos por Curso";
    $sql = "SELECT C.nome_curso AS curso,
                   IF(C.Mtec = 1, 'Sim', 'Não') AS mtec,
                   COUNT(DISTINCT T.id_turma) AS total_turmas,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM curso C
            LEFT JOIN turma T ON T.id_curso = C.id_curso
            LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY C.id_curso, C.nome_curso, C.Mtec
            ORDER BY C.nome_curso";
} elseif ($relatorioEscolhido === "situacao") {
    $titulo = "Empréstimos por Situação do Aluno";
    $sql = "SELECT COALESCE(AL.situacao, 'Não informado') AS situacao,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM aluno AL
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY AL.situacao
            ORDER BY AL.situacao";
} elseif ($relatorioEscolhido === "historico") {
    $titulo = "Histórico Detalhado de Empréstimos";
    $sql = "SELECT E.id_emprestimo,
                   U.id_usuario,
                   U.nome AS usuario,
                   U.tipo_usuario AS tipo,
                   COALESCE(AL.RA, '-') AS ra,
                   COALESCE(C.nome_curso, '-') AS curso,
                   COALESCE(T.turno, '-') AS turno,
                   COALESCE(AL.situacao, '-') AS situacao_aluno,
                   L.titulo AS livro,
                   DATE_FORMAT(E.data_emp, '%d/%m/%Y') AS data_emprestimo,
                   DATE_FORMAT(E.data_dev_prevista, '%d/%m/%Y') AS data_prevista,
                   IF(E.data_dev IS NOT NULL, DATE_FORMAT(E.data_dev, '%d/%m/%Y'), '-') AS devolucao,
                   CONCAT('R$ ', REPLACE(FORMAT(E.valor_multa, 2), '.', ',')) AS multa,
                   CASE
                       WHEN E.reserva = 1 THEN 'Aguardando aprovação'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            LEFT JOIN aluno AL ON AL.id_usuario = U.id_usuario
            LEFT JOIN turma T ON T.id_turma = AL.id_turma
            LEFT JOIN curso C ON C.id_curso = T.id_curso
            INNER JOIN livros L ON L.id_livro = E.id_livro
            ORDER BY E.data_emp DESC, E.id_emprestimo DESC";
} else {
    $relatorioEscolhido = "populares";
    $titulo = "Livros mais populares";
    $sql = "SELECT L.titulo, A.nome_autor AS autor, COUNT(E.id_emprestimo) AS total_emprestimos
            FROM livros L
            INNER JOIN autores A ON A.id_autor = L.id_autor
            LEFT JOIN emprestimo E ON E.id_livro = L.id_livro
            GROUP BY L.id_livro, L.titulo, A.nome_autor
            ORDER BY total_emprestimos DESC
            LIMIT 20";
}

$linhas = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Relatórios - Biblioteca Virtual</title>
</head>
<body>
    <h2>Relatórios</h2>

    <p>
        <a href="?relatorio=populares">Livros populares</a> |
        <a href="?relatorio=emprestimos">Empréstimos</a> |
        <a href="?relatorio=usuarios">Usuários ativos</a> |
        <a href="?relatorio=turma">Por turma</a> |
        <a href="?relatorio=curso">Por curso</a> |
        <a href="?relatorio=situacao">Por situação</a> |
        <a href="?relatorio=historico">Histórico detalhado</a>
    </p>

    <h3><?= htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") ?></h3>

    <table border="1" cellpadding="6">
        <?php if (!empty($linhas)): ?>
            <tr>
                <?php foreach (array_keys($linhas[0]) as $coluna): ?>
                    <th><?= htmlspecialchars((string) $coluna, ENT_QUOTES, "UTF-8") ?></th>
                <?php endforeach; ?>
            </tr>
            <?php foreach ($linhas as $linha): ?>
                <tr>
                    <?php foreach ($linha as $valor): ?>
                        <td><?= htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8") ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td style="padding: 15px; text-align: center;">Nenhum registro encontrado para este relatório.</td>
            </tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
