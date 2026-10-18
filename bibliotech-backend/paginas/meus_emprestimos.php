<?php
// meus_emprestimos.php — mostra o histórico de empréstimos da
// própria pessoa logada (pendentes, ativos, atrasados, concluídos),
// com botão de renovar (RN02).

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

$idUsuario = $_SESSION["id_usuario"];
$mensagem = "";

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["renovar"])) {
    $logica = new Emprestimo($conexao);
    $resultado = $logica->renovarEmprestimo((int) $_POST["renovar"], $idUsuario);
    $mensagem = $resultado["mensagem"];
}

$stmt = $conexao->prepare(
    "SELECT E.id_emprestimo, L.titulo, A.nome_autor, C.nome_cat, L.ISBN, L.editora, L.publicacao,
            E.data_emp, E.data_dev_prevista, E.data_dev,
            E.emp_ativo, E.reserva, E.multa_pendente, E.dev_pdia, E.valor_multa
     FROM emprestimo E
     INNER JOIN livros L ON L.id_livro = E.id_livro
     INNER JOIN autores A ON A.id_autor = L.id_autor
     INNER JOIN categorias C ON C.id_cat = L.id_cat
     WHERE E.id_usuario = :id
     ORDER BY E.data_emp DESC"
);
$stmt->execute([":id" => $idUsuario]);
$emprestimos = $stmt->fetchAll();

// Descobre o status de cada empréstimo, em palavras simples
function statusEmprestimo(array $item): string
{
    if ((int) $item["emp_ativo"] === 1 && (int) $item["reserva"] === 1) {
        return "Aguardando aprovação de renovação";
    }
    if ((int) $item["reserva"] === 1) {
        return "Aguardando aprovação";
    }
    if ((int) $item["emp_ativo"] === 1 && $item["data_dev"] === null) {
        return ($item["data_dev_prevista"] < date("Y-m-d")) ? "Atrasado" : "Ativo";
    }
    return "Concluído";
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus empréstimos - Biblioteca Virtual</title>

    <!-- CSS -->
    <link rel="stylesheet" href="./CSS/principal.css"> <!-- Para a Hotbar Global e Background -->
    <link rel="stylesheet" href="./CSS/meus_emprestimos.css"> <!-- CSS -->
</head>
<body>

    <!-- RENDER BACKGROUND -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="Background">
    </div>

    <!-- CABEÇALHO DA TELA -->
    <header class="emprestimos-header">
        <h2>Meus empréstimos</h2>
        <p>Acompanhe e gerencie os prazos dos seus livros</p>
        
        <?php if (!empty($mensagem)): ?>
            <div style="color: #00e5ff; font-weight: 600; margin-top: 10px; background: rgba(0, 229, 255, 0.1); display: inline-block; padding: 8px 16px; border-radius: 50px;">
                <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>
    </header>

    <!-- SE HOUVER EMPRÉSTIMOS, RENDERIZA O CARROSSEL -->
    <?php if (!empty($emprestimos)): ?>
        
        <!-- CONTAINER DO CARROSSEL DE CARDS -->
        <div class="emprestimos-container">
            <button class="carousel-btn carousel-prev">‹</button>
            
            <div class="livros-carousel">
                <?php foreach ($emprestimos as $item): ?>
                    <!-- O JS usa data-titulo e data-autor para buscar a capa -->
                    <div class="emprestimo-card" 
                         data-titulo="<?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>" 
                         data-autor="<?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?>">
                        
                        <div class="emprestimo-capa">
                            <div class="emprestimo-capa-fallback">
                                <?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
                            </div>
                        </div>
                        <div class="emprestimo-card-titulo">
                            <?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button class="carousel-btn carousel-next">›</button>
        </div>

        <!-- INDICADORES DO CARROSSEL -->
        <div class="carousel-indicators"></div>

        <!-- ÁREA DE DETALHES (O JS mostra apenas o do livro selecionado) -->
        <div class="detalhes-container" style="max-width: 900px; margin: 0 auto;">
            <?php foreach ($emprestimos as $item): ?>
                <?php $status = statusEmprestimo($item); ?>
                
                <div class="emprestimo-detalhes" style="display: none;">
                    
                    <div class="emprestimo-detalhes-capa">
                        <!-- O JS injeta a <img> da capa aqui dentro -->
                    </div>
                    
                    <div class="emprestimo-informacoes">
                        <div class="emprestimo-titulo-area">
                            <h3><?= htmlspecialchars($item["titulo"], ENT_QUOTES, "UTF-8") ?></h3>
                            <div class="emprestimo-autor"><?= htmlspecialchars($item["nome_autor"], ENT_QUOTES, "UTF-8") ?></div>
                            <div class="emprestimo-categoria"><?= htmlspecialchars($item["nome_cat"], ENT_QUOTES, "UTF-8") ?></div>
                        </div>

                        <div class="emprestimo-status">
                            <span class="status-label">Status:</span>
                            <span class="status-valor"><?= $status ?></span>
                        </div>

                        <div class="emprestimo-info-grid">
                            <div class="info-item"><span>ISBN</span><strong><?= htmlspecialchars($item["ISBN"], ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Editora</span><strong><?= htmlspecialchars($item["editora"], ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Ano</span><strong><?= htmlspecialchars((string) $item["publicacao"], ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Empréstimo</span><strong><?= htmlspecialchars((string) $item["data_emp"], ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Devolução Prevista</span><strong><?= htmlspecialchars((string) $item["data_dev_prevista"], ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Devolvido em</span><strong><?= htmlspecialchars((string) ($item["data_dev"] ?? "—"), ENT_QUOTES, "UTF-8") ?></strong></div>
                            <div class="info-item"><span>Dias de atraso</span><strong><?= (int) $item["dev_pdia"] > 0 ? $item["dev_pdia"] : "—" ?></strong></div>
                            <div class="info-item"><span>Multa Pendente</span><strong><?= $item["multa_pendente"] ? "R$ " . number_format((float) $item["valor_multa"], 2, ",", ".") : "Não" ?></strong></div>
                        </div>

                        <!-- Botão de Renovar caso esteja Ativo -->
                        <?php if ($status === "Ativo"): ?>
                            <form method="POST" action="" style="margin-top: 15px; text-align: right;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                                <input type="hidden" name="renovar" value="<?= $item["id_emprestimo"] ?>">
                                <button type="submit" style="background: rgba(0, 167, 181, 0.2); border: 1px solid #00a7b5; color: #00e5ff; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; text-transform: uppercase; font-size: 11px;" onmouseover="this.style.background='rgba(0, 167, 181, 0.4)'" onmouseout="this.style.background='rgba(0, 167, 181, 0.2)'">
                                    Solicitar Renovação
                                </button>
                            </form>
                        <?php elseif ($status === "Aguardando aprovação de renovação"): ?>
                            <div style="margin-top: 15px; text-align: right; color: #ffd166; font-size: 12px; font-weight: 600;">
                                ⏳ Solicitação de renovação em análise pela bibliotecária
                            </div>
                        <?php endif; ?>
                    </div>


                </div>
            <?php endforeach; ?>
        </div>

    <!-- SE NÃO HOUVER EMPRÉSTIMOS -->
    <?php else: ?>
        <div class="emprestimos-vazio">
            <div class="emprestimos-vazio-icone">📚</div>
            <h3>Nenhum empréstimo</h3>
            <p>Você ainda não tem nenhum empréstimo registrado conosco.</p>
        </div>
    <?php endif; ?>

    <!-- BOTÃO VOLTAR -->
    <div class="emprestimos-voltar">
        <a href="principal.php">Início</a>
    </div>

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

    <!-- IMPORTANDO OS SCRIPTS -->
    <script src="./javascript/capas.js"></script> <!-- Arquivo exigido pelo seu JS na linha: typeof BibliotechCapas -->
    <script src="./javascript/meus_emprestimos.js"></script> <!-- Seu script do carrossel -->
</body>
</html>
