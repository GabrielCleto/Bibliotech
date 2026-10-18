<?php
// principal.php — página que aparece depois do login.
// Ainda "crua" (sem estilo), só pra mostrar que a sessão e os
// dados certos aparecem pra cada tipo de usuário.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";

// Se ninguém fez login, manda de volta pro login
if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
exigirContaAtiva($conexao);

$idUsuario = $_SESSION["id_usuario"];
$nome = $_SESSION["nome"];
$tipo = $_SESSION["tipo_usuario"];

// Já pegamos tudo que precisávamos da sessão — libera a trava agora,
// em vez de segurar até o fim da página (isso é o que evita a
// página "travar" quando duas abas/telas usam a sessão ao mesmo tempo)
session_write_close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Usuário</title>
    <link rel="stylesheet" href="./CSS/principal.css">
</head>
<body>

    <!-- RENDER BACKGROUND (FUNDO ORIGINAL MANTIDO) -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="Background">
    </div>

    <!-- ESTRUTURA PRINCIPAL DO DASHBOARD -->
    <main class="app-layout">
        
        <!-- BARRA DE NAVEGAÇÃO TOPO -->
        <nav class="topbar">
            <div class="LOGO">
                <a href="#">
                    <img src="./source/image/logo.png" alt="Logo">
                </a>
            </div>
            <div class="user-role-badge">
                <span class="pulse-dot"></span>
                <?= htmlspecialchars($tipo, ENT_QUOTES, "UTF-8") ?>
            </div>
        </nav>

        <!-- HERO SECTION (TELA DE BOAS-VINDAS) -->
        <header class="hero-banner">
            <div class="hero-content">
                <h1>Olá, <span class="text-highlight"><?= htmlspecialchars($nome, ENT_QUOTES, "UTF-8") ?></span> 👋</h1>
                <p>Bem-vindo(a) ao seu ecossistema. Explore o acervo, gerencie seus empréstimos e acompanhe suas pendências em um só lugar.</p>
            </div>
        </header>

        <!-- SESSÃO DE MÓDULOS / CARDS (PHP MANTIDO INTACTO) -->
        <section class="modules-section">
            <h2 class="section-title">Painel de Controle</h2>
            
            <div class="cards-grid">
                <?php if ($tipo === "aluno"): ?>
                    
                    <a href="livros.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📚</span></div>
                        <span class="card-title">Acervo de Livros</span>
                        <span class="card-desc">Explore nossa biblioteca</span>
                    </a>
                    <a href="meus_emprestimos.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📖</span></div>
                        <span class="card-title">Meus Empréstimos</span>
                        <span class="card-desc">Livros com você</span>
                    </a>
                    <a href="lista_desejos.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">❤️</span></div>
                        <span class="card-title">Lista de Desejos</span>
                        <span class="card-desc">Seus livros favoritos</span>
                    </a>

                <?php elseif ($tipo === "professor"): ?>
                    
                    <a href="livros.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📚</span></div>
                        <span class="card-title">Acervo de Livros</span>
                        <span class="card-desc">Explore nossa biblioteca</span>
                    </a>
                    <a href="meus_emprestimos.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📖</span></div>
                        <span class="card-title">Meus Empréstimos</span>
                        <span class="card-desc">Livros com você</span>
                    </a>
                    <a href="lista_desejos.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">❤️</span></div>
                        <span class="card-title">Lista de Desejos</span>
                        <span class="card-desc">Seus livros favoritos</span>
                    </a>

                <?php elseif ($tipo === "bibliotecario"): ?>
                    
                    <?php
                    $totalPendentes = $conexao->query(
                        "SELECT COUNT(*) AS total FROM emprestimo WHERE reserva = 1 AND emp_ativo = 0"
                    )->fetch()["total"];
                    ?>
                    
                    <a href="livros.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📚</span></div>
                        <span class="card-title">Ver Acervo</span>
                        <span class="card-desc">Gestão de livros</span>
                    </a>
                    <a href="gerenciar_usuarios.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">👥</span></div>
                        <span class="card-title">Gerenciar Usuários</span>
                        <span class="card-desc">Alunos e Professores</span>
                    </a>
                    <a href="aprovacoes.php" class="glass-card highlight-card">
                        <div class="icon-wrapper"><span class="emoji">📋</span></div>
                        <span class="card-title">Solicitações</span>
                        <span class="card-desc badge-info"><?= $totalPendentes ?> Pendentes</span>
                    </a>
                    <a href="devolucoes.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">🔄</span></div>
                        <span class="card-title">Registrar Devolução</span>
                        <span class="card-desc">Entrada de exemplares</span>
                    </a>
                    <a href="multas.php" class="glass-card highlight-card-warning">
                        <div class="icon-wrapper"><span class="emoji">⚠️</span></div>
                        <span class="card-title">Multas Pendentes</span>
                        <span class="card-desc">Verificar atrasos</span>
                    </a>
                    <a href="relatorio.php" class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">📊</span></div>
                        <span class="card-title">Relatórios</span>
                        <span class="card-desc">Estatísticas do sistema</span>
                    </a>

                <?php else: ?>
                    <!-- ADMIN -->
                    <div class="glass-card">
                        <div class="icon-wrapper"><span class="emoji">⚙️</span></div>
                        <span class="card-title">Área Administrativa</span>
                        <span class="card-desc">Recursos em breve</span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- RODAPÉ DE AÇÕES -->
        <footer class="action-footer">
            <?php
            $totalNaoLidas = $conexao->prepare("SELECT COUNT(*) AS total FROM notificacao WHERE id_usuario = :id AND lida = 0");
            $totalNaoLidas->execute([":id" => $idUsuario]);
            $totalNaoLidas = $totalNaoLidas->fetch()["total"];
            ?>
            <a href="notificacoes.php" class="btn-action btn-notify">
                <span class="emoji">🔔</span>
                Notificações 
                <?php if($totalNaoLidas > 0): ?>
                    <span class="notification-badge"><?= $totalNaoLidas ?></span>
                <?php endif; ?>
            </a>
            
            <a href="logout.php" class="btn-action btn-logout">
                <span class="emoji">🚪</span>
                Sair
            </a>
        </footer>

    </main>

    <!-- ================= HOTBAR GLOBAL ================= -->
    <nav class="global-hotbar">
        <!-- Botão Início (Painel) -->
        <a href="principal.php" class="hotbar-item">
            <span class="hotbar-icon">🏠</span>
            <span class="hotbar-text">Início</span>
        </a>

        <!-- Botão Acervo -->
        <a href="livros.php" class="hotbar-item">
            <span class="hotbar-icon">📚</span>
            <span class="hotbar-text">Acervo</span>
        </a>

        <!-- Botão Notificações (Usa a mesma lógica PHP que você já tem) -->
        <a href="notificacoes.php" class="hotbar-item">
            <div class="hotbar-icon-wrapper">
                <span class="hotbar-icon">🔔</span>
                <?php if (isset($totalNaoLidas) && $totalNaoLidas > 0): ?>
                    <span class="hotbar-badge"><?= $totalNaoLidas ?></span>
                <?php endif; ?>
            </div>
            <span class="hotbar-text">Avisos</span>
        </a>

        <!-- Botão Sair -->
        <a href="logout.php" class="hotbar-item hotbar-logout">
            <span class="hotbar-icon">🚪</span>
            <span class="hotbar-text">Sair</span>
        </a>
    </nav>
    <!-- ================= FIM DA HOTBAR ================= -->

</body>
</html>