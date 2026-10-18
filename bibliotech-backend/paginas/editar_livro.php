<?php
// editar_livro.php — tela pra bibliotecário editar um livro já
// cadastrado (corrigir dado, ajustar quantidade em estoque).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Csrf.php";

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

$mensagem = "";
$idLivro = isset($_GET["id"]) ? (int) $_GET["id"] : null;

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

// Salvar a edição
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
    $idLivro = (int) ($_POST["id_livro"] ?? 0);
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idLivro = (int) $_POST["id_livro"];
    $titulo = trim($_POST["titulo"] ?? "");
    $isbn = trim($_POST["isbn"] ?? "");
    $editora = trim($_POST["editora"] ?? "");
    $publicacao = trim($_POST["publicacao"] ?? "");
    $novoTotal = (int) ($_POST["quantidade_total"] ?? 0);

    // Descobre quantas cópias desse livro estão emprestadas agora
    // (é o total atual menos o disponível atual, ANTES da edição)
    $stmt = $conexao->prepare("SELECT quantidade_total, quantidade_disponivel FROM livros WHERE id_livro = :id");
    $stmt->execute([":id" => $idLivro]);
    $livroAtual = $stmt->fetch();

    if (!$livroAtual) {
        $mensagem = "Livro não encontrado.";
    } else {
        $emprestadosAgora = $livroAtual["quantidade_total"] - $livroAtual["quantidade_disponivel"];

        if ($titulo === "" || $isbn === "" || $editora === "" || $publicacao === "") {
            $mensagem = "Título, ISBN, editora e ano de publicação são obrigatórios.";
        } elseif ($novoTotal < $emprestadosAgora) {
            $mensagem = "O total não pode ser menor que {$emprestadosAgora}, que é quanto já está emprestado agora.";
        }
    }

    // Se deu erro de validação, mostra de novo o que a pessoa tinha
    // digitado (em vez de voltar pro que já estava salvo no banco)
    if ($mensagem !== "") {
        $livroDigitado = [
            "id_livro" => $idLivro,
            "titulo" => $titulo,
            "ISBN" => $isbn,
            "editora" => $editora,
            "publicacao" => $publicacao,
            "quantidade_total" => $novoTotal,
            "quantidade_disponivel" => $novoTotal - $emprestadosAgora,
        ];
    }

    if ($mensagem === "") {
        try {
            // O disponível novo é: o total novo, menos quem já está
            // emprestado (essas cópias continuam fora até devolverem)
            $novoDisponivel = $novoTotal - $emprestadosAgora;

            $stmt = $conexao->prepare(
                "UPDATE livros
                 SET titulo = :titulo, ISBN = :isbn, editora = :editora, publicacao = :publicacao,
                     quantidade_total = :total, quantidade_disponivel = :disponivel
                 WHERE id_livro = :id"
            );
            $stmt->execute([
                ":titulo" => $titulo,
                ":isbn" => $isbn,
                ":editora" => $editora,
                ":publicacao" => $publicacao,
                ":total" => $novoTotal,
                ":disponivel" => $novoDisponivel,
                ":id" => $idLivro,
            ]);

            $mensagem = "Livro atualizado com sucesso!";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            if ($e->getCode() === "23000" && str_contains($e->getMessage(), "ISBN")) {
                $mensagem = "Já existe outro livro cadastrado com esse ISBN.";
            } else {
                $mensagem = "Erro ao atualizar o livro. Tente novamente.";
            }
        }
    }
}

// Se tem um id (seja do link "Editar" ou de um POST que acabou de
// salvar), busca os dados atuais desse livro pra mostrar no formulário.
// Se deu erro de validação no POST, usa o que a pessoa digitou
// (guardado em $livroDigitado) em vez de buscar nome de novo.
$livro = null;
if (isset($livroDigitado)) {
    $livro = $livroDigitado;
} elseif ($idLivro) {
    $stmt = $conexao->prepare(
        "SELECT id_livro, titulo, ISBN, editora, publicacao, quantidade_total, quantidade_disponivel
         FROM livros WHERE id_livro = :id"
    );
    $stmt->execute([":id" => $idLivro]);
    $livro = $stmt->fetch();
}

// Sem id nenhum (ex: alguém acessou o arquivo direto, sem vir do
// link "Editar" da livros.php): manda pra lista, é lá que mora
// agora
if (!$livro) {
    header("Location: livros.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Editar Livro - Biblioteca Virtual</title>
</head>
<body>
    <h2>Editar acervo</h2>

    <?php if (!empty($mensagem)): ?>
        <div><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
    <?php endif; ?>

    <?php $emprestadosAgora = $livro["quantidade_total"] - $livro["quantidade_disponivel"]; ?>

    <p><strong><?= $emprestadosAgora ?></strong> cópia(s) desse livro estão emprestadas agora
       — o total não pode ficar menor que isso.</p>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
        <input type="hidden" name="id_livro" value="<?= $livro["id_livro"] ?>">

        <label>Título:</label>
        <input type="text" name="titulo" value="<?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>ISBN:</label>
        <input type="text" name="isbn" value="<?= htmlspecialchars($livro["ISBN"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Editora:</label>
        <input type="text" name="editora" value="<?= htmlspecialchars($livro["editora"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Ano de Publicação:</label>
        <input type="number" name="publicacao" value="<?= htmlspecialchars((string) $livro["publicacao"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Quantidade total:</label>
        <input type="number" name="quantidade_total" min="<?= $emprestadosAgora ?>" value="<?= $livro["quantidade_total"] ?>" required>

        <button type="submit">Salvar alterações</button>
    </form>

    <p><a href="livros.php">Voltar para o acervo</a></p>
</body>
</html>
