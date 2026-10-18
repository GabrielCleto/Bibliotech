<?php
// gerenciar_usuarios.php — painel da bibliotecária pra gerenciar
// contas (aluno, professor, bibliotecário).
//
// Regra de negócio (pedido do Lucas, 03/10): não existe mais
// autocadastro público. SÓ a bibliotecária cria conta nova, e só ela
// edita ou ativa/inativa uma conta já existente. NÃO EXISTE exclusão
// de conta nessa tela (nem em nenhuma outra) — o máximo que dá pra
// fazer é inativar. Isso é de propósito: excluir perderia o
// histórico de empréstimos da pessoa, e a própria sp_excluir_usuario
// do banco já bloqueia excluir quem tem histórico mesmo assim.
//
// Usa as procedures que o grupo de banco já deixou prontas pra isso
// (sp_criar_usuario, sp_atualizar_usuario, sp_alterar_status_usuario),
// que só funcionam se quem está chamando (p_id_bibliotecario) for
// uma bibliotecária ativa — o próprio banco garante isso de novo,
// além da checagem de sessão aqui no PHP.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";
require __DIR__ . "/../config/Csrf.php";
require __DIR__ . "/../config/Criptografia.php";
require __DIR__ . "/../config/Datas.php";
require __DIR__ . "/../config/MensagemBanco.php";
require __DIR__ . "/../config/Validacao.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

// Se a conta foi inativada depois do login, encerra a sessão
exigirContaAtiva(Conexao::getConexao());

$tipoLogado = $_SESSION["tipo_usuario"];
if ($tipoLogado !== "bibliotecario" && $tipoLogado !== "admin") {
    http_response_code(403);
    exit("Acesso restrito à bibliotecária.");
}

$idBibliotecario = (int) $_SESSION["id_usuario"];
$conexao = Conexao::getConexao();
$mensagem = "";

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();
session_write_close();

// ============================================================
// AÇÕES (POST)
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    $acao = campoTexto($_POST, "acao");

    try {
        if ($acao === "criar") {
            $tipo = campoTexto($_POST, "tipo");

            if (!in_array($tipo, ["aluno", "professor", "bibliotecario"], true)) {
                $mensagem = "Tipo de usuário inválido.";
            } else {
                // 1) nome, e-mail e senha   2) campos que dependem do tipo
                [$erro, $basico] = validarBasico($_POST, true, true);
                if ($erro === null) {
                    [$erro, $campos] = validarCamposDoTipo($conexao, $tipo, $_POST, true);
                }

                if ($erro !== null) {
                    $mensagem = $erro;
                } else {
                    $stmt = $conexao->prepare(
                        "CALL sp_criar_usuario(?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    // Os dados pessoais (CPF, nascimento, endereço, telefone)
                    // vão criptografados. Campo que não existe pro tipo
                    // fica NULL, que é o que o banco espera.
                    $stmt->execute([
                        $idBibliotecario,
                        $basico["nome"],
                        $basico["email"],
                        password_hash($basico["senha"], PASSWORD_DEFAULT),
                        $tipo,
                        $campos["ra"],
                        $campos["id_turma"],
                        $campos["disciplina"],
                        $campos["matricula"],
                        $campos["cpf"] !== null ? Criptografia::criptografar($campos["cpf"]) : null,
                        $campos["data_nascimento"] !== null ? Criptografia::criptografar($campos["data_nascimento"]) : null,
                        $campos["endereco"] !== null ? Criptografia::criptografar($campos["endereco"]) : null,
                        $campos["telefone"] !== null ? Criptografia::criptografar($campos["telefone"]) : null,
                    ]);
                    $stmt->closeCursor();
                    $mensagem = "Conta criada com sucesso.";
                }
            }
        } elseif ($acao === "atualizar") {
            $idUsuario = inteiroPositivo(campoTexto($_POST, "id_usuario"), "2147483647");

            // O tipo da conta vem do BANCO (não do formulário), porque o
            // formulário pode ser mexido por quem sabe usar o navegador
            $tipo = null;
            if ($idUsuario !== null) {
                $stmt = $conexao->prepare("SELECT tipo_usuario FROM usuario WHERE id_usuario = ?");
                $stmt->execute([$idUsuario]);
                $tipo = $stmt->fetchColumn() ?: null;
            }

            if ($tipo === null) {
                $mensagem = "Usuário não encontrado.";
            } else {
                // Na edição, campo em branco = "não muda"
                [$erro, $basico] = validarBasico($_POST, false, false);
                if ($erro === null) {
                    [$erro, $campos] = validarCamposDoTipo($conexao, $tipo, $_POST, false);
                }

                if ($erro !== null) {
                    $mensagem = $erro;
                } else {
                    $stmt = $conexao->prepare(
                        "CALL sp_atualizar_usuario(?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $idBibliotecario,
                        $idUsuario,
                        $basico["nome"],
                        $basico["email"],
                        $campos["ra"],
                        $campos["id_turma"],
                        $campos["disciplina"],
                        $campos["matricula"],
                        $campos["cpf"] !== null ? Criptografia::criptografar($campos["cpf"]) : null,
                        $campos["data_nascimento"] !== null ? Criptografia::criptografar($campos["data_nascimento"]) : null,
                        $campos["endereco"] !== null ? Criptografia::criptografar($campos["endereco"]) : null,
                        $campos["telefone"] !== null ? Criptografia::criptografar($campos["telefone"]) : null,
                    ]);
                    $stmt->closeCursor();
                    $mensagem = "Dados atualizados com sucesso.";
                }
            }
        } elseif ($acao === "alterar_status") {
            $idUsuario = inteiroPositivo(campoTexto($_POST, "id_usuario"), "2147483647");
            $novoStatus = campoTexto($_POST, "novo_status");

            // O novo status PRECISA vir como "0" (inativar) ou "1" (ativar).
            // Antes, qualquer coisa diferente de "1" (inclusive campo
            // faltando) virava "inativar" sem avisar — perigoso.
            if ($idUsuario === null) {
                $mensagem = "Usuário inválido.";
            } elseif ($novoStatus !== "0" && $novoStatus !== "1") {
                $mensagem = "Status inválido. Use 1 (ativar) ou 0 (inativar).";
            } else {
                // Regra: quem tem livro emprestado (ainda não devolvido)
                // NÃO pode ser inativado. Primeiro registra a devolução.
                // Só vale pra inativar; ativar uma conta é sempre liberado.
                $emprestimosAtivos = 0;
                if ($novoStatus === "0") {
                    $stmt = $conexao->prepare(
                        "SELECT COUNT(*) FROM emprestimo WHERE id_usuario = ? AND emp_ativo = 1"
                    );
                    $stmt->execute([$idUsuario]);
                    $emprestimosAtivos = (int) $stmt->fetchColumn();
                }

                if ($emprestimosAtivos > 0) {
                    $mensagem = "Não é possível inativar: a pessoa tem {$emprestimosAtivos} "
                        . ($emprestimosAtivos === 1 ? "empréstimo ativo" : "empréstimos ativos")
                        . ". Registre a devolução antes.";
                } else {
                    $stmt = $conexao->prepare("CALL sp_alterar_status_usuario(?,?,?)");
                    $stmt->execute([$idBibliotecario, $idUsuario, (int) $novoStatus]);
                    $stmt->closeCursor();
                    $mensagem = $novoStatus === "1" ? "Conta ativada." : "Conta inativada.";
                }
            }
        }
        // De propósito: não existe "acao=excluir" nessa tela.
    } catch (PDOException $e) {
        if ($e->getCode() === "23000") {
            // Pode ser valor repetido, referência inexistente ou dado
            // obrigatório faltando — cada um ganha a sua mensagem
            $mensagem = mensagemIntegridade($e);
        } elseif ($e->getCode() === "45000") {
            $mensagem = mensagemDoBanco($e);
        } else {
            error_log($e->getMessage());
            $mensagem = "Erro ao salvar. Tente novamente.";
        }
    }
}

// ============================================================
// DADOS PRA MONTAR A TELA
// ============================================================

// Turmas (pra lista suspensa do formulário de aluno)
$turmas = $conexao->query(
    "SELECT t.id_turma, c.nome_curso, t.turno, t.ano_calendario
     FROM turma t LEFT JOIN curso c ON c.id_curso = t.id_curso
     ORDER BY c.nome_curso, t.turno"
)->fetchAll();

// Alunos e professores vêm das procedures do banco. Se alguma delas
// der erro, a tela NÃO pode quebrar com aquela página de erro feia do
// PHP (que ainda mostra o caminho dos arquivos): a gente mostra um
// aviso e segue com o que deu pra carregar.
$alunos = [];
$professores = [];

// Alunos (pela procedure, sem filtro = todos)
try {
    $stmt = $conexao->prepare("CALL sp_listar_alunos(?, NULL, NULL, NULL, NULL)");
    $stmt->execute([$idBibliotecario]);
    $alunos = $stmt->fetchAll();
    $stmt->closeCursor();
} catch (PDOException $e) {
    if ($e->getCode() === "45000") {
        // O próprio banco recusou (ex.: quem está logada foi inativada)
        $mensagem = trim($mensagem . " " . mensagemDoBanco($e));
    } else {
        // Erro 1267 (mistura de collation): acontece quando o banco foi
        // importado com uma "regra de comparação de texto" diferente da
        // do próprio banco (o phpMyAdmin do XAMPP faz isso). A
        // correção de verdade é reimportar o .sql com
        // SET NAMES utf8mb4 COLLATE utf8mb4_general_ci; no topo. Enquanto
        // isso não é feito, a gente lê direto da view vw_alunos (que o
        // banco já tem pronta, com os mesmos dados), pra tela funcionar.
        error_log("sp_listar_alunos falhou, usando vw_alunos: " . $e->getMessage());
        try {
            // A procedure checava se quem está logada ainda é uma
            // bibliotecária ATIVA. Lendo direto da view essa checagem
            // se perderia, então a gente refaz aqui.
            $stmtAtiva = $conexao->prepare(
                "SELECT COUNT(*) FROM usuario
                 WHERE id_usuario = ? AND tipo_usuario = 'bibliotecario' AND ativo = 1"
            );
            $stmtAtiva->execute([$idBibliotecario]);
            if ((int) $stmtAtiva->fetchColumn() === 0) {
                throw new RuntimeException("Somente a bibliotecária pode consultar alunos.");
            }

            $alunos = $conexao->query(
                "SELECT id_usuario, RA, nome, email, telefone,
                        IF(ativo, 'Ativo', 'Inativo') AS situacao,
                        nome_curso, turno, id_turma, ano_calendario,
                        periodo_letivo, criado_em, atualizado_em
                 FROM vw_alunos ORDER BY nome"
            )->fetchAll();
        } catch (RuntimeException $e2) {
            $mensagem = trim($mensagem . " " . $e2->getMessage());
        } catch (PDOException $e2) {
            error_log($e2->getMessage());
            $mensagem = trim($mensagem . " Não foi possível carregar a lista de alunos agora.");
        }
    }
}

// Professores (pela procedure, sem filtro = todos)
try {
    $stmt = $conexao->prepare("CALL sp_listar_professores(?, NULL)");
    $stmt->execute([$idBibliotecario]);
    $professores = $stmt->fetchAll();
    $stmt->closeCursor();
} catch (PDOException $e) {
    if ($e->getCode() === "45000") {
        $mensagem = trim($mensagem . " " . mensagemDoBanco($e));
    } else {
        error_log($e->getMessage());
        $mensagem = trim($mensagem . " Não foi possível carregar a lista de professores agora.");
    }
}

// Bibliotecárias (não tem procedure/view própria pra isso — é uma
// lista pequena e só quem já é bibliotecária enxerga essa tela)
$bibliotecarios = $conexao->query(
    "SELECT id_usuario, nome, email, ativo, criado_em
     FROM usuario WHERE tipo_usuario = 'bibliotecario' ORDER BY nome"
)->fetchAll();

// Se veio ?editar=ID na URL, busca os dados crus (descriptografados)
// pra preencher o formulário de edição daquela pessoa
$registroEditar = null;
if (isset($_GET["editar"])) {
    $stmt = $conexao->prepare("SELECT * FROM usuario WHERE id_usuario = :id");
    $stmt->execute([":id" => (int) $_GET["editar"]]);
    $registroEditar = $stmt->fetch();
    if ($registroEditar) {
        $registroEditar["cpf"] = Criptografia::descriptografar($registroEditar["CPF"]);
        $registroEditar["data_nascimento"] = Criptografia::descriptografar($registroEditar["dataNascimento"]);
        $registroEditar["endereco"] = Criptografia::descriptografar($registroEditar["endereco"]);
        $registroEditar["telefone"] = Criptografia::descriptografar($registroEditar["telefone"]);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Biblioteca Virtual</title>

    <!-- Estilos -->
    <link rel="stylesheet" href="./CSS/principal.css">
    <link rel="stylesheet" href="./CSS/gerenciar_usuario.css">
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
                <span class="pulse-dot"></span> Administração
            </div>
        </nav>

        <!-- HERO SECTION -->
        <header class="hero-banner" style="padding: 30px 40px;">
            <div class="hero-content">
                <h1>Gerenciamento de <span class="text-highlight">Usuários</span> 👥</h1>
                <p>Controle de acessos, cadastro e inativação de contas da Biblioteca Virtual.</p>
            </div>
            
            <?php if (!empty($mensagem)): ?>
                <div class="alert-message <?= (strpos(strtolower($mensagem), 'erro') !== false || strpos(strtolower($mensagem), 'inválido') !== false || strpos(strtolower($mensagem), 'não é possível') !== false) ? 'alert-error' : 'alert-success' ?>">
                    <?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?>
                </div>
            <?php endif; ?>
        </header>

        <!-- SEÇÃO DE EDIÇÃO DE USUÁRIO (SÓ APARECE SE CLICAR EM EDITAR) -->
        <?php if ($registroEditar): ?>
            <section class="modules-section">
                <div class="glass-card form-card border-cyan">
                    <h3 class="form-title">
                        <span class="emoji">✏️</span> Editando: <?= htmlspecialchars($registroEditar["nome"], ENT_QUOTES, "UTF-8") ?>
                        <span class="badge badge-<?= strtolower($registroEditar["tipo_usuario"]) ?>"><?= ucfirst($registroEditar["tipo_usuario"]) ?></span>
                    </h3>
                    
                    <form method="POST" action="gerenciar_usuarios.php" class="register-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="acao" value="atualizar">
                        <input type="hidden" name="id_usuario" value="<?= (int) $registroEditar["id_usuario"] ?>">
                        <input type="hidden" name="tipo_usuario" value="<?= htmlspecialchars($registroEditar["tipo_usuario"], ENT_QUOTES, "UTF-8") ?>">

                        <div class="form-grid">
                            <div class="input-wrapper">
                                <label>Nome Completo</label>
                                <input type="text" name="nome" class="glass-input" value="<?= htmlspecialchars($registroEditar["nome"], ENT_QUOTES, "UTF-8") ?>" required>
                            </div>

                            <div class="input-wrapper">
                                <label>E-mail</label>
                                <input type="email" name="email" class="glass-input" value="<?= htmlspecialchars($registroEditar["email"], ENT_QUOTES, "UTF-8") ?>" required>
                            </div>
                            
                            <div class="input-wrapper">
                                <label>Telefone</label>
                                <input type="text" name="telefone" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["telefone"], ENT_QUOTES, "UTF-8") ?>">
                            </div>

                            <?php if ($registroEditar["tipo_usuario"] === "aluno"): ?>
                                <div class="input-wrapper">
                                    <label>RA (Registro Acadêmico)</label>
                                    <input type="text" name="ra" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["RA"], ENT_QUOTES, "UTF-8") ?>">
                                </div>
                                <div class="input-wrapper">
                                    <label>Turma</label>
                                    <select name="id_turma" class="glass-select">
                                        <?php foreach ($turmas as $turma): ?>
                                            <option value="<?= $turma["id_turma"] ?>" <?= (int) $turma["id_turma"] === (int) $registroEditar["id_turma"] ? "selected" : "" ?>>
                                                <?= htmlspecialchars($turma["nome_curso"] . " - " . $turma["turno"] . " - " . $turma["ano_calendario"], ENT_QUOTES, "UTF-8") ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="input-wrapper">
                                    <label>Data de Nascimento</label>
                                    <input type="date" name="data_nascimento" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["data_nascimento"], ENT_QUOTES, "UTF-8") ?>">
                                </div>
                                <div class="input-wrapper full-width">
                                    <label>Endereço</label>
                                    <input type="text" name="endereco" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["endereco"], ENT_QUOTES, "UTF-8") ?>">
                                </div>
                            <?php elseif ($registroEditar["tipo_usuario"] === "professor"): ?>
                                <div class="input-wrapper">
                                    <label>Matrícula</label>
                                    <input type="text" name="matricula" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["matricula"], ENT_QUOTES, "UTF-8") ?>">
                                </div>
                                <div class="input-wrapper">
                                    <label>Disciplina</label>
                                    <input type="text" name="disciplina" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["disciplina"], ENT_QUOTES, "UTF-8") ?>">
                                </div>
                            <?php endif; ?>

                            <?php if (in_array($registroEditar["tipo_usuario"], ["aluno", "bibliotecario"], true)): ?>
                                <div class="input-wrapper">
                                    <label>CPF</label>
                                    <input type="text" name="cpf" class="glass-input" value="<?= htmlspecialchars((string) $registroEditar["cpf"], ENT_QUOTES, "UTF-8") ?>" maxlength="14">
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions">
                            <a href="gerenciar_usuarios.php" class="btn-cancel">Cancelar Edição</a>
                            <button type="submit" class="btn-cyan">
                                <span class="emoji">💾</span> Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <!-- CRIAR NOVO USUÁRIO -->
        <section class="modules-section">
            <div class="glass-card form-card">
                <h3 class="form-title"><span class="emoji">➕</span> Criar Novo Usuário</h3>
                
                <form method="POST" action="gerenciar_usuarios.php" class="register-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                    <input type="hidden" name="acao" value="criar">

                    <div class="form-grid">
                        <div class="input-wrapper">
                            <label>Nome Completo *</label>
                            <input type="text" name="nome" class="glass-input" maxlength="100" required>
                        </div>

                        <div class="input-wrapper">
                            <label>E-mail *</label>
                            <input type="email" name="email" class="glass-input" maxlength="150" required>
                        </div>

                        <div class="input-wrapper">
                            <label>Senha Inicial *</label>
                            <input type="password" name="senha" class="glass-input" required>
                        </div>

                        <div class="input-wrapper">
                            <label>Tipo de Conta *</label>
                            <select name="tipo" id="tipo-novo" class="glass-select" onchange="mostrarCamposNovo()">
                                <option value="aluno">Aluno</option>
                                <option value="professor">Professor</option>
                                <option value="bibliotecario">Bibliotecário</option>
                            </select>
                        </div>
                    </div>

                    <!-- CAMPOS EXTRAS: ALUNO -->
                    <div id="novo-campos-aluno" class="dynamic-fieldset form-grid">
                        <div class="full-width"><h4 class="fieldset-title">Dados Acadêmicos (Aluno)</h4></div>
                        <div class="input-wrapper">
                            <label>RA</label>
                            <input type="text" name="ra" class="glass-input">
                        </div>
                        <div class="input-wrapper">
                            <label>Turma</label>
                            <select name="id_turma" class="glass-select">
                                <option value="" disabled selected>Selecione a turma</option>
                                <?php foreach ($turmas as $turma): ?>
                                    <option value="<?= $turma["id_turma"] ?>">
                                        <?= htmlspecialchars($turma["nome_curso"] . " - " . $turma["turno"] . " - " . $turma["ano_calendario"], ENT_QUOTES, "UTF-8") ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-wrapper">
                            <label>Data de Nascimento</label>
                            <input type="date" name="data_nascimento" class="glass-input">
                        </div>
                        <div class="input-wrapper">
                            <label>Endereço</label>
                            <input type="text" name="endereco" class="glass-input">
                        </div>
                    </div>

                    <!-- CAMPOS EXTRAS: PROFESSOR -->
                    <div id="novo-campos-professor" class="dynamic-fieldset form-grid" style="display:none">
                        <div class="full-width"><h4 class="fieldset-title">Dados Institucionais (Professor)</h4></div>
                        <div class="input-wrapper">
                            <label>Matrícula</label>
                            <input type="text" name="matricula" class="glass-input">
                        </div>
                        <div class="input-wrapper">
                            <label>Disciplina</label>
                            <input type="text" name="disciplina" class="glass-input">
                        </div>
                    </div>

                    <!-- DADOS COMPARTILHADOS -->
                    <div class="dynamic-fieldset form-grid">
                        <div id="novo-campos-cpf" class="input-wrapper">
                            <label>CPF</label>
                            <input type="text" name="cpf" class="glass-input" maxlength="14">
                        </div>
                        <div class="input-wrapper">
                            <label>Telefone</label>
                            <input type="text" name="telefone" class="glass-input">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-cyan">
                            <span class="emoji">✅</span> Criar Conta
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- LISTAGEM: ALUNOS -->
        <section class="modules-section table-section">
            <h2 class="section-title">Base de Alunos</h2>
            <div class="glass-card table-card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>RA</th><th>Nome</th><th>E-mail</th><th>Turma</th><th>Situação</th><th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $item): ?>
                                <tr>
                                    <td class="td-code"><?= htmlspecialchars((string) $item["RA"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td class="td-name"><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars($item["email"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars((string) ($item["nome_curso"] ?? "—") . " - " . ($item["turno"] ?? ""), ENT_QUOTES, "UTF-8") ?></td>
                                    <td>
                                        <span class="status-badge <?= $item["situacao"] === 'Ativo' ? 'status-active' : 'status-inactive' ?>">
                                            <?= htmlspecialchars($item["situacao"], ENT_QUOTES, "UTF-8") ?>
                                        </span>
                                    </td>
                                    <td class="td-actions">
                                        <a href="?editar=<?= (int) $item["id_usuario"] ?>" class="btn-icon btn-edit" title="Editar">✏️</a>
                                        <form method="POST" action="gerenciar_usuarios.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                                            <input type="hidden" name="acao" value="alterar_status">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $item["id_usuario"] ?>">
                                            <input type="hidden" name="novo_status" value="<?= $item["situacao"] === "Ativo" ? "0" : "1" ?>">
                                            <button type="submit" class="btn-icon <?= $item["situacao"] === "Ativo" ? "btn-disable" : "btn-enable" ?>" 
                                                    title="<?= $item["situacao"] === "Ativo" ? "Inativar Conta" : "Ativar Conta" ?>">
                                                <?= $item["situacao"] === "Ativo" ? "🔒" : "🔓" ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($alunos)): ?>
                                <tr><td colspan="6" class="td-empty">Nenhum aluno cadastrado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- LISTAGEM: PROFESSORES -->
        <section class="modules-section table-section">
            <h2 class="section-title">Corpo Docente</h2>
            <div class="glass-card table-card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Matrícula</th><th>Nome</th><th>E-mail</th><th>Disciplina</th><th>Situação</th><th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($professores as $item): ?>
                                <tr>
                                    <td class="td-code"><?= htmlspecialchars((string) $item["matricula"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td class="td-name"><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars($item["email"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars((string) $item["disciplina"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td>
                                        <span class="status-badge <?= $item["situacao"] === 'Ativo' ? 'status-active' : 'status-inactive' ?>">
                                            <?= htmlspecialchars($item["situacao"], ENT_QUOTES, "UTF-8") ?>
                                        </span>
                                    </td>
                                    <td class="td-actions">
                                        <a href="?editar=<?= (int) $item["id_usuario"] ?>" class="btn-icon btn-edit" title="Editar">✏️</a>
                                        <form method="POST" action="gerenciar_usuarios.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                                            <input type="hidden" name="acao" value="alterar_status">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $item["id_usuario"] ?>">
                                            <input type="hidden" name="novo_status" value="<?= $item["situacao"] === "Ativo" ? "0" : "1" ?>">
                                            <button type="submit" class="btn-icon <?= $item["situacao"] === "Ativo" ? "btn-disable" : "btn-enable" ?>" 
                                                    title="<?= $item["situacao"] === "Ativo" ? "Inativar Conta" : "Ativar Conta" ?>">
                                                <?= $item["situacao"] === "Ativo" ? "🔒" : "🔓" ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($professores)): ?>
                                <tr><td colspan="6" class="td-empty">Nenhum professor cadastrado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- LISTAGEM: BIBLIOTECÁRIOS -->
        <section class="modules-section table-section">
            <h2 class="section-title">Administração do Sistema</h2>
            <div class="glass-card table-card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nome</th><th>E-mail</th><th>Situação</th><th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bibliotecarios as $item): ?>
                                <tr>
                                    <td class="td-name"><?= htmlspecialchars($item["nome"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td><?= htmlspecialchars($item["email"], ENT_QUOTES, "UTF-8") ?></td>
                                    <td>
                                        <span class="status-badge <?= $item["ativo"] ? 'status-active' : 'status-inactive' ?>">
                                            <?= $item["ativo"] ? "Ativo" : "Inativo" ?>
                                        </span>
                                    </td>
                                    <td class="td-actions">
                                        <a href="?editar=<?= (int) $item["id_usuario"] ?>" class="btn-icon btn-edit" title="Editar">✏️</a>
                                        <?php if ((int) $item["id_usuario"] !== $idBibliotecario): ?>
                                            <form method="POST" action="gerenciar_usuarios.php" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">
                                                <input type="hidden" name="acao" value="alterar_status">
                                                <input type="hidden" name="id_usuario" value="<?= (int) $item["id_usuario"] ?>">
                                                <input type="hidden" name="novo_status" value="<?= $item["ativo"] ? "0" : "1" ?>">
                                                <button type="submit" class="btn-icon <?= $item["ativo"] ? "btn-disable" : "btn-enable" ?>" 
                                                        title="<?= $item["ativo"] ? "Inativar Conta" : "Ativar Conta" ?>">
                                                    <?= $item["ativo"] ? "🔒" : "🔓" ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="current-user-badge">Sua Conta</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
    <script src="./javascript/gerenciar_usuario.js"></script>
</body>
</html>
