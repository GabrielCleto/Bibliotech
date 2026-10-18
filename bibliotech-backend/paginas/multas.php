<?php
// multas.php — bibliotecária vê as multas pendentes (livros
// devolvidos com atraso) e marca como paga.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Csrf.php";
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

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["quitar"])) {
    $resultado = $logica->quitarMulta((int) $_POST["quitar"]);
    $mensagem = $resultado["mensagem"];
}

$sql = "SELECT E.id_emprestimo, U.nome, L.titulo, E.dev_pdia, E.valor_multa
        FROM emprestimo E
        INNER JOIN usuario U ON U.id_usuario = E.id_usuario
        INNER JOIN livros L ON L.id_livro = E.id_livro
        WHERE E.multa_pendente = 1
        ORDER BY E.dev_pdia DESC";
$multas = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Multas - Biblioteca Virtual</title>
</head>
<body>
    <h2>Multas pendentes</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <table border="1" cellpadding="6">
        <tr>
            <th>Quem deve</th>
            <th>Livro</th>
            <th>Dias de atraso</th>
            <th>Valor</th>
            <th></th>
        </tr>
        <?php foreach ($multas as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= $item["dev_pdia"] ?></td>
                <td>R$ <?= number_format((float) $item["valor_multa"], 2, ",", ".") ?></td>
                <td>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="quitar" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Marcar como paga</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($multas)): ?>
            <tr><td colspan="5">Nenhuma multa pendente.</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
