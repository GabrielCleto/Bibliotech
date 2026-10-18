<?php
// aprovacoes.php — bibliotecária vê as solicitações de empréstimo
// pendentes e aprova ou recusa cada uma.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Csrf.php";
require __DIR__ . "/../config/Datas.php";
require __DIR__ . "/../models/Emprestimo.php";

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

$logica = new Emprestimo($conexao);
$mensagem = "";

// Confere o token e gera um novo (pro próximo formulário) ANTES de
// fechar a sessão — depois de fechada, só dá pra ler, não gravar
$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["aprovar"])) {
    $resultado = $logica->aprovarSolicitacao((int) $_POST["aprovar"]);
    $mensagem = $resultado["mensagem"];
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["recusar"])) {
    $resultado = $logica->recusarSolicitacao((int) $_POST["recusar"]);
    $mensagem = $resultado["mensagem"];
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["aprovar_renovacao"])) {
    $resultado = $logica->aprovarRenovacao((int) $_POST["aprovar_renovacao"]);
    $mensagem = $resultado["mensagem"];
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["recusar_renovacao"])) {
    $resultado = $logica->recusarRenovacao((int) $_POST["recusar_renovacao"]);
    $mensagem = $resultado["mensagem"];
}

$sql = "SELECT E.id_emprestimo, U.nome, U.tipo_usuario, L.titulo, E.data_emp
        FROM emprestimo E
        INNER JOIN usuario U ON U.id_usuario = E.id_usuario
        INNER JOIN livros L ON L.id_livro = E.id_livro
        WHERE E.reserva = 1 AND E.emp_ativo = 0
        ORDER BY E.data_emp";
$pendentes = $conexao->query($sql)->fetchAll();

$sqlRenovacoes = "SELECT E.id_emprestimo, U.nome, U.tipo_usuario, L.titulo, E.data_emp, E.data_dev_prevista
                  FROM emprestimo E
                  INNER JOIN usuario U ON U.id_usuario = E.id_usuario
                  INNER JOIN livros L ON L.id_livro = E.id_livro
                  WHERE E.emp_ativo = 1 AND E.reserva = 1 AND E.data_dev IS NULL
                  ORDER BY E.data_dev_prevista";
$renovacoesPendentes = $conexao->query($sqlRenovacoes)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Solicitações pendentes - Biblioteca Virtual</title>
</head>
<body>
    <h2>Solicitações de novos empréstimos pendentes</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <table border="1" cellpadding="6">
        <tr>
            <th>Quem pediu</th>
            <th>Tipo</th>
            <th>Livro</th>
            <th>Data do pedido</th>
            <th></th>
        </tr>
        <?php foreach ($pendentes as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["tipo_usuario"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars(formatarDataBr($item["data_emp"]), ENT_QUOTES, "UTF-8") ?></td>
                <td>
                    <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="aprovar" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Aprovar</button>
                    </form>
                    <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="recusar" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Recusar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($pendentes)): ?>
            <tr><td colspan="5">Nenhuma solicitação de novo empréstimo pendente.</td></tr>
        <?php endif; ?>
    </table>

    <h2 style="margin-top: 30px;">Solicitações de renovação de empréstimo pendentes</h2>

    <table border="1" cellpadding="6">
        <tr>
            <th>Quem pediu</th>
            <th>Tipo</th>
            <th>Livro</th>
            <th>Data do Empréstimo</th>
            <th>Devolução Atual</th>
            <th></th>
        </tr>
        <?php foreach ($renovacoesPendentes as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["tipo_usuario"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars(formatarDataBr($item["data_emp"]), ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars(formatarDataBr($item["data_dev_prevista"]), ENT_QUOTES, "UTF-8") ?></td>
                <td>
                    <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="aprovar_renovacao" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Aprovar Renovação</button>
                    </form>
                    <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="recusar_renovacao" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Recusar Renovação</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($renovacoesPendentes)): ?>
            <tr><td colspan="6">Nenhuma solicitação de renovação pendente.</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
