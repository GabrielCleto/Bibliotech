<?php
// lista_desejos.php — tela simples pra ver e gerenciar a lista de
// desejos (sem JSON, direto em HTML).
//
// Integração com a fila de reserva (reserva_fila): quando a pessoa
// adiciona um livro que está SEM nenhuma cópia disponível, ela
// também entra na fila de espera daquele livro. Quando alguém
// devolve (ou tem o pedido recusado, liberando uma cópia), a
// primeira pessoa da fila recebe uma notificação avisando que já
// pode solicitar.

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

$idUsuario = $_SESSION["id_usuario"];
$mensagem = "";

$tokenValido = csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

// Remover um livro da lista (veio do formulário de remover)
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["acao"] ?? "") === "remover") {
    $idLivro = (int) ($_POST["id_livro"] ?? 0);
    $stmt = $conexao->prepare("DELETE FROM lista_desejos WHERE id_usuario = :usuario AND id_livro = :livro");
    $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

    // Sair da lista de desejos também tira da fila de espera desse livro
    // (só remove quem ainda está esperando — não desfaz um aviso já enviado)
    $stmt = $conexao->prepare(
        "DELETE FROM reserva_fila WHERE id_usuario = :usuario AND id_livro = :livro AND atendida = 0"
    );
    $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

    $mensagem = "Livro removido da lista de desejos.";
}

// Adicionar um livro (veio do formulário de adicionar, lá embaixo)
if ($_SERVER["REQUEST_METHOD"] === "POST" && $tokenValido && ($_POST["acao"] ?? "") === "adicionar") {
    $idLivro = (int) ($_POST["id_livro"] ?? 0);

    $stmt = $conexao->prepare("SELECT id_livro, titulo, quantidade_disponivel FROM livros WHERE id_livro = :id");
    $stmt->execute([":id" => $idLivro]);
    $livro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$livro) {
        $mensagem = "Livro não encontrado.";
    } else {
        $stmt = $conexao->prepare("SELECT id_lista FROM lista_desejos WHERE id_usuario = :usuario AND id_livro = :livro");
        $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

        if ($stmt->fetch()) {
            $mensagem = "Esse livro já está na sua lista de desejos.";
        } else {
            $stmt = $conexao->prepare("INSERT INTO lista_desejos (id_usuario, id_livro) VALUES (:usuario, :livro)");
            $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);
            $mensagem = "Livro adicionado à lista de desejos.";

            // Sem cópia disponível agora? Entra também na fila de espera
            if ((int) $livro["quantidade_disponivel"] <= 0) {
                $stmt = $conexao->prepare(
                    "SELECT id_reserva FROM reserva_fila
                     WHERE id_usuario = :usuario AND id_livro = :livro AND atendida = 0"
                );
                $stmt->execute([":usuario" => $idUsuario, ":livro" => $idLivro]);

                if (!$stmt->fetch()) {
                    // Próxima posição sequencial livre para o livro (respeitando a constraint única de id_livro + posicao_fila)
                    $stmt = $conexao->prepare(
                        "SELECT COALESCE(MAX(posicao_fila), 0) + 1 AS proxima
                         FROM reserva_fila WHERE id_livro = :livro"
                    );
                    $stmt->execute([":livro" => $idLivro]);
                    $proximaPosicao = (int) $stmt->fetchColumn();

                    $stmt = $conexao->prepare(
                        "INSERT INTO reserva_fila (id_usuario, id_livro, posicao_fila, atendida)
                         VALUES (:usuario, :livro, :posicao, 0)"
                    );
                    $stmt->execute([
                        ":usuario" => $idUsuario,
                        ":livro"   => $idLivro,
                        ":posicao" => $proximaPosicao,
                    ]);
                    $mensagem .= " Como não tem cópia disponível agora, você também entrou na fila de espera.";
                }
            }
        }
    }
}

// Busca a lista de desejos da pessoa, já trazendo a posição dela na
// fila de espera daquele livro, se houver (conta só quem também
// ainda está esperando, com posição igual ou menor que a dela)
$stmt = $conexao->prepare(
    "SELECT L.id_livro, L.titulo, A.nome_autor, L.quantidade_disponivel,
            (SELECT COUNT(*) FROM reserva_fila R2
             WHERE R2.id_livro = L.id_livro AND R2.atendida = 0
               AND R2.posicao_fila <= R1.posicao_fila) AS posicao_na_fila
     FROM lista_desejos D
     INNER JOIN livros L ON L.id_livro = D.id_livro
     INNER JOIN autores A ON A.id_autor = L.id_autor
     LEFT JOIN reserva_fila R1
            ON R1.id_livro = D.id_livro AND R1.id_usuario = D.id_usuario AND R1.atendida = 0
     WHERE D.id_usuario = :usuario
     ORDER BY D.criado_em DESC"
);
$stmt->execute([":usuario" => $idUsuario]);
$listaDesejos = $stmt->fetchAll();

// Busca todos os livros, pra montar a lista suspensa de adicionar
$todosLivros = $conexao->query("SELECT id_livro, titulo FROM livros ORDER BY titulo")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de desejos - Biblioteca Virtual</title>

    <!-- Estilos -->
    <link rel="stylesheet" href="./CSS/principal.css">
    <link rel="stylesheet" href="./CSS/lista_de_desejo.css">
</head>
<body>

    <!-- RENDER BACKGROUND -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="Background">
    </div>

    <main class="app-layout">
        
        <!-- BARRA DE NAVEGAÇÃO TOPO -->
        <nav class="topbar">
            <div class="LOGO">
                <a href="principal.php" class="btn-voltar">
                    <span class="emoji">⬅️</span> Voltar ao Início
                </a>
            </div>
            <div class="user-role-badge">
                <span class="pulse-dot"></span> Favoritos
            </div>
        </nav>

        <!-- HERO SECTION -->
        <header class="hero-banner" style="padding: 30px 40px;">
            <div class="hero-content">
                <h1>Minha <span class="text-highlight">Lista de Desejos</span> ❤️</h1>
                <p>Gerencie os livros que você quer ler e acompanhe sua posição na fila de espera.</p>
            </div>
            
            <?php if (!empty($mensagem)): ?>
                <div class="alert-message">
                    <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>
        </header>

        <!-- ADICIONAR NOVO LIVRO -->
        <section class="add-book-section">
            <div class="glass-card form-card">
                <h3 class="form-title">Adicionar livro à lista</h3>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="acao" value="adicionar">
                    
                    <div class="input-group">
                        <select name="id_livro" class="glass-select">
                            <?php foreach ($todosLivros as $livro): ?>
                                <option value="<?= $livro["id_livro"] ?>">
                                    <?= htmlspecialchars($livro["titulo"], ENT_QUOTES, "UTF-8") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-cyan">Adicionar</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- LISTA DE DESEJOS -->
        <section class="modules-section">
            <h2 class="section-title">Livros Salvos</h2>
            
            <div class="cards-grid">
                <?php foreach ($listaDesejos as $item): ?>
                    <div class="glass-card wishlist-card" 
                         data-titulo="<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>" 
                         data-autor="<?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?>">
                        
                        <!-- Capa do Livro -->
                        <div class="wishlist-capa">
                            <div class="wishlist-capa-fallback">
                                <?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
                            </div>
                        </div>

                        <!-- Detalhes do Livro -->
                        <div class="wishlist-info">
                            <h3 class="book-title"><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></h3>
                            <p class="book-author"><?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?></p>
                            
                            <div class="book-badges">
                                <span class="badge badge-avail">
                                    Disponíveis: <?= $item["quantidade_disponivel"] ?>
                                </span>
                                <?php if ($item["posicao_na_fila"] !== null): ?>
                                    <span class="badge badge-queue">
                                        <?= (int) $item["posicao_na_fila"] ?>º na fila
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-queue-empty">Sem fila</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Ação de Remover -->
                        <form method="POST" action="" class="remove-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="id_livro" value="<?= $item["id_livro"] ?>">
                            <button type="submit" class="btn-remove" title="Remover da lista">
                                <span class="emoji">🗑️</span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ESTADO VAZIO -->
            <?php if (empty($listaDesejos)): ?>
                <div class="glass-card empty-state">
                    <div class="icon-wrapper" style="margin: 0 auto 20px;"><span class="emoji">💔</span></div>
                    <span class="card-title">Nenhum livro salvo</span>
                    <span class="card-desc">Sua lista de desejos está vazia no momento.</span>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- ================= HOTBAR GLOBAL ================= -->
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
            </div>
            <span class="hotbar-text">Avisos</span>
        </a>

        <a href="logout.php" class="hotbar-item hotbar-logout">
            <span class="hotbar-icon">🚪</span>
            <span class="hotbar-text">Sair</span>
        </a>
    </nav>

    <!-- ================= FIM DA HOTBAR ================= -->

    <!-- SCRIPTS -->
    <script src="./javascript/capas.js"></script>
    <script src="./javascript/lista_de_desejo.js"></script>
</body>
</html>
