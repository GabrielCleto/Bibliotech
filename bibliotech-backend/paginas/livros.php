<?php
// livros.php — lista simples do acervo (título, autor, categoria,
// quantas cópias tem disponível). Qualquer pessoa logada pode ver.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Csrf.php";

$estaLogado = isset($_SESSION["id_usuario"]);
$conexao = Conexao::getConexao();

if ($estaLogado) {
    exigirContaAtiva($conexao);
    $idUsuario = $_SESSION["id_usuario"];
    $tipo = $_SESSION["tipo_usuario"];
    $ehBibliotecario = ($tipo === "bibliotecario" || $tipo === "admin");
    $podeSolicitar = ($tipo === "aluno" || $tipo === "professor");

    // Busca o total de notificações não lidas para o badge da hotbar
    $stmtNoti = $conexao->prepare("SELECT COUNT(*) FROM notificacao WHERE id_usuario = :id AND lida = 0");
    $stmtNoti->execute([":id" => $idUsuario]);
    $totalNaoLidas = (int) $stmtNoti->fetchColumn();
} else {
    $idUsuario = null;
    $tipo = null;
    $ehBibliotecario = false;
    $podeSolicitar = false;
    $totalNaoLidas = 0;
}

// Gera o token CSRF (se ainda não tiver um) ANTES de fechar a
// sessão, porque depois de fechada não dá mais pra gravar nela
$tokenCsrf = csrf_token();
session_write_close();

$sql = "SELECT L.id_livro, L.titulo, A.nome_autor, C.nome_cat, L.quantidade_disponivel, L.quantidade_total
        FROM livros L
        INNER JOIN autores A ON A.id_autor = L.id_autor
        INNER JOIN categorias C ON C.id_cat = L.id_cat
        ORDER BY L.titulo";
$livros = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<!-- Cabeçalho da página -->
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acervo Biblioteca Virtual</title>
    <!-- Folha de estilo do acervo -->
    <link rel="stylesheet" href="./css/livros.css">
</head>

<body>

    <!-- Imagem de fundo -->
    <div class="RENDER BACKGROUND" aria-hidden="true">
        <img src="./source/Arthur/home/Group 631.png" alt="">
    </div>

    <!-- Conteúdo principal -->
    <main class="app-layout">

        <!-- Cabeçalho do acervo -->
        <header class="hero-banner acervo-header">
            <div class="acervo-logo">
                <a href="principal.php" aria-label="Página inicial">
                    <img src="./source/image/logo.png" alt="BIBLIOTECH">
                </a>
            </div>
            <h1>Acervo de <span class="text-highlight">Livros</span></h1>
            <p class="acervo-subtitulo">Explore os exemplares disponíveis em nossa biblioteca digital.</p>
        </header>

        <!-- Barra de ações superior -->
        <div class="acervo-top-actions">
            <!-- Botão voltar -->
            <a href="principal.php" class="btn-action btn-voltar">
                <span class="icon">↩️</span> Voltar ao Painel
            </a>

            <!-- Botão cadastrar livro (bibliotecário) -->
            <?php if ($ehBibliotecario): ?>
                <a href="cadastro_livro.php" class="btn-action btn-cadastrar">
                    <span class="icon">➕</span> Cadastrar Novo Livro
                </a>
            <?php endif; ?>
        </div>

        <!-- Barra de pesquisa de livros -->
        <div class="acervo-search-container">
            <div class="search-input-wrapper">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input
                    type="search"
                    id="inputBuscaLivros"
                    class="search-input"
                    placeholder="Pesquisar por título ou autor..."
                    aria-label="Pesquisar livros no acervo"
                >
            </div>
        </div>

        <!-- Grade de livros -->
        <section class="livros-area">
            <div class="livros-grid">

                <!-- Listagem dos livros -->
                <?php foreach ($livros as $livro): ?>

                <!-- Card do livro -->
                <article
                    class="livro-card glass-card"
                    data-titulo="<?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>"
                    data-autor="<?= htmlspecialchars($livro["nome_autor"], ENT_QUOTES, "UTF-8") ?>"
                >

                    <!-- Capa do livro -->
                    <div class="livro-capa">
                        <div class="livro-capa-fallback">
                            <?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>
                        </div>
                    </div>

                    <!-- Informações do livro -->
                    <div class="livro-conteudo">
                        <h2 class="livro-titulo"><?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?></h2>
                        <p class="livro-autor"><?= htmlspecialchars($livro["nome_autor"], ENT_QUOTES, "UTF-8") ?></p>
                        <span class="livro-categoria"><?= htmlspecialchars($livro["nome_cat"], ENT_QUOTES, "UTF-8") ?></span>

                        <!-- Disponibilidade -->
                        <div class="livro-disponibilidade">
                            <?php if ($livro["quantidade_disponivel"] > 0): ?>
                                <span class="status-disponivel"><span class="dot-green"></span> Disponível</span>
                                <span class="quantidade-livro">(<?= $livro["quantidade_disponivel"] ?> un.)</span>
                            <?php else: ?>
                                <span class="status-indisponivel"><span class="dot-red"></span> Indisponível</span>
                            <?php endif; ?>
                        </div>

                        <!-- Ações do livro -->
                        <div class="livro-acoes">

                            <?php if ($ehBibliotecario): ?>

                                <a href="editar_livro.php?id=<?= $livro["id_livro"] ?>" class="btn-card btn-editar">✏️ Editar Livro</a>

                            <?php elseif ($podeSolicitar): ?>

                                <?php if ($livro["quantidade_disponivel"] > 0): ?>

                                    <form method="POST" action="solicitar_emprestimo.php">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                                        <input type="hidden" name="id_livro" value="<?= $livro["id_livro"] ?>">
                                        <button type="submit" class="btn-card btn-solicitar">📚 Solicitar Empréstimo</button>
                                    </form>

                                <?php else: ?>

                                    <span class="btn-card btn-indisponivel">⚠️ Indisponível</span>

                                <?php endif; ?>

                            <?php elseif (!$estaLogado): ?>

                                <?php if ($livro["quantidade_disponivel"] > 0): ?>
                                    <a href="login.php" class="btn-card btn-solicitar">🔑 Entrar para Solicitar</a>
                                <?php else: ?>
                                    <span class="btn-card btn-indisponivel">⚠️ Indisponível</span>
                                <?php endif; ?>

                            <?php endif; ?>

                        </div>
                    </div>
                </article>

                <?php endforeach; ?>

            </div>
        </section>

        <!-- Scripts dos livros -->
        <script src="./javascript/capas.js"></script>
        <script src="./javascript/livros.js"></script>

    </main>

    <!-- Hotbar global de navegação -->
    <nav class="global-hotbar">
        <a href="principal.php" class="hotbar-item">
            <span class="hotbar-icon">🏠</span>
            <span class="hotbar-text">Início</span>
        </a>
        <a href="livros.php" class="hotbar-item">
            <span class="hotbar-icon">📚</span>
            <span class="hotbar-text">Acervo</span>
        </a>
        <a href="notificacoes.php" class="hotbar-item">
            <div class="hotbar-icon-wrapper">
                <span class="hotbar-icon">🔔</span>
                <?php if (isset($totalNaoLidas) && $totalNaoLidas > 0): ?>
                    <span class="hotbar-badge"><?= $totalNaoLidas ?></span>
                <?php endif; ?>
            </div>
            <span class="hotbar-text">Avisos</span>
        </a>
        <?php if ($estaLogado): ?>
            <a href="logout.php" class="hotbar-item hotbar-logout">
                <span class="hotbar-icon">🚪</span>
                <span class="hotbar-text">Sair</span>
            </a>
        <?php else: ?>
            <a href="login.php" class="hotbar-item">
                <span class="hotbar-icon">🔑</span>
                <span class="hotbar-text">Entrar</span>
            </a>
        <?php endif; ?>
    </nav>

</body>
</html>