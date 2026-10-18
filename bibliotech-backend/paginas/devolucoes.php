<?php
// devolucoes.php — bibliotecária vê os empréstimos ativos (já
// aprovados, ainda não devolvidos) e registra a devolução.

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

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["devolver"])) {
    $resultado = $logica->registrarDevolucao((int) $_POST["devolver"]);
    $mensagem = $resultado["mensagem"];
}

$sql = "SELECT E.id_emprestimo, U.nome, L.titulo, E.data_emp, E.data_dev_prevista,
               CASE WHEN E.data_dev_prevista < CURDATE() THEN 'Sim' ELSE 'Não' END AS atrasado
        FROM emprestimo E
        INNER JOIN usuario U ON U.id_usuario = E.id_usuario
        INNER JOIN livros L ON L.id_livro = E.id_livro
        WHERE E.emp_ativo = 1 AND E.data_dev IS NULL
        ORDER BY E.data_dev_prevista";
$ativos = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Devoluções - Biblioteca Virtual</title>
</head>
<body>
    <h2>Empréstimos ativos</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <table border="1" cellpadding="6">
        <tr>
            <th>Quem pegou</th>
            <th>Livro</th>
            <th>Data do empréstimo</th>
            <th>Devolução prevista</th>
            <th>Atrasado?</th>
            <th></th>
        </tr>
        <?php foreach ($ativos as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars(formatarDataBr($item["data_emp"]), ENT_QUOTES, "UTF-8") ?></td>
                <td><?= htmlspecialchars(formatarDataBr($item["data_dev_prevista"]), ENT_QUOTES, "UTF-8") ?></td>
                <td><?= $item["atrasado"] ?></td>
                <td>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="devolver" value="<?= $item["id_emprestimo"] ?>">
                        <button type="submit">Registrar devolução</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($ativos)): ?>
            <tr><td colspan="6">Nenhum empréstimo ativo.</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>
