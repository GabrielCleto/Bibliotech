<?php
// solicitar_emprestimo.php — aluno/professor pede um livro
// emprestado (fica esperando aprovação da bibliotecária).

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
$idUsuario = $_SESSION["id_usuario"];
if ($tipo !== "aluno" && $tipo !== "professor") {
    http_response_code(403);
    exit("Só aluno ou professor pode solicitar empréstimo.");
}

$tokenValido = ($_SERVER["REQUEST_METHOD"] === "POST") && csrf_valido($_POST["csrf_token"] ?? null);
session_write_close();

if (!$tokenValido) {
    $resultado = ["sucesso" => false, "mensagem" => "Sessão expirada ou inválida. Tente novamente."];
} else {
    $idLivro = (int) ($_POST["id_livro"] ?? 0);
    $logica = new Emprestimo(Conexao::getConexao());
    $resultado = $logica->solicitarEmprestimo($idUsuario, $idLivro);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Solicitar empréstimo - Biblioteca Virtual</title>
</head>
<body>
    <h2>Solicitar empréstimo</h2>
    <p><?= htmlspecialchars($resultado["mensagem"], ENT_QUOTES, "UTF-8") ?></p>
    <p><a href="livros.php">Voltar para o acervo</a></p>
</body>
</html>
