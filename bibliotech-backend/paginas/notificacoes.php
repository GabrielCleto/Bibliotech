<?php
// notificacoes.php — tela simples pra ver as notificações da
// pessoa logada (qualquer tipo de usuário pode ter notificação).

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Datas.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
exigirContaAtiva($conexao);

$idUsuario = $_SESSION["id_usuario"];
session_write_close();

// Busca as notificações (guarda quais estavam "não lidas" antes de
// marcar, só pra destacar na tela quais eram novas)
$stmt = $conexao->prepare(
    "SELECT id_notificacao, titulo, mensagem, lida, criada_em
     FROM notificacao WHERE id_usuario = :usuario ORDER BY criada_em DESC"
);
$stmt->execute([":usuario" => $idUsuario]);
$notificacoes = $stmt->fetchAll();

// Já que a pessoa está vendo a tela agora, marca tudo como lido
// automaticamente (não precisa mais clicar uma por uma)
$stmt = $conexao->prepare(
    "UPDATE notificacao SET lida = 1, lida_em = NOW() WHERE id_usuario = :usuario AND lida = 0"
);
$stmt->execute([":usuario" => $idUsuario]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações - Biblioteca Virtual</title>
    
    <!-- Estilos -->
    <link rel="stylesheet" href="./CSS/principal.css">
    <link rel="stylesheet" href="./CSS/notificacoes.css">
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
                <a href="principal.php" class="btn-voltar">
                    <span class="emoji">⬅️</span> Voltar ao Início
                </a>
            </div>
            <div class="user-role-badge">
                <span class="pulse-dot"></span> Avisos
            </div>
        </nav>

        <!-- HERO SECTION (CABEÇALHO DA TELA) -->
        <header class="hero-banner" style="padding: 30px 40px;">
            <div class="hero-content">
                <h1>Caixa de <span class="text-highlight">Notificações</span> 🔔</h1>
                <p>Acompanhe seus avisos, alertas de multas e aprovações do sistema.</p>
            </div>
        </header>

        <!-- LISTA DE NOTIFICAÇÕES -->
        <section class="modules-section">
            <div class="notifications-list">
                
                <?php foreach ($notificacoes as $item): ?>
                    <!-- O Card recebe a classe 'unread' se lida == 0 para ganhar destaque -->
                    <div class="glass-card notification-card <?= $item['lida'] == 0 ? 'unread' : '' ?>">
                        <div class="noti-header">
                            <h3 class="noti-title">
                                <?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
                                <?php if ($item['lida'] == 0): ?>
                                    <span class="noti-badge-new">Nova</span>
                                <?php endif; ?>
                            </h3>
                            <span class="noti-date">
                                🕒 <?= htmlspecialchars(formatarDataHoraBr($item["criada_em"]), ENT_QUOTES, "UTF-8") ?>
                            </span>
                        </div>
                        <p class="noti-message">
                            <?= nl2br(htmlspecialchars($item["mensagem"], ENT_QUOTES, "UTF-8")) ?>
                        </p>
                    </div>
                <?php endforeach; ?>

                <!-- ESTADO VAZIO (Nenhuma notificação) -->
                <?php if (empty($notificacoes)): ?>
                    <div class="glass-card empty-state">
                        <div class="icon-wrapper" style="margin: 0 auto 20px;"><span class="emoji">📭</span></div>
                        <span class="card-title">Tudo limpo por aqui!</span>
                        <span class="card-desc">Você não tem nenhuma notificação no momento.</span>
                    </div>
                <?php endif; ?>

            </div>
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

        <a href="notificacoes.php" class="hotbar-item" style="color: #00e5ff; background: rgba(0, 229, 255, 0.1);">
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

</body>
</html>
