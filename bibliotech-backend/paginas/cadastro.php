<?php
// cadastro.php — tela de "criar conta". A pessoa escolhe o tipo
// (aluno/professor/bibliotecário) e preenche os dados daquele tipo.
// Usa as tabelas que já existem (usuario + aluno/professor/
// bibliotecario) — não criamos nenhuma tabela ou coluna nova.

session_start();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/Csrf.php";
require __DIR__ . "/../config/SemCache.php";
require __DIR__ . "/../config/Criptografia.php";
sem_cache();

$conexao = Conexao::getConexao();
$mensagem = "";

// Guarda o que a pessoa digitou, pra reaparecer no formulário se der erro
$dados = [
    "nome" => "", "email" => "", "tipo" => "aluno",
    "ra" => "", "id_turma" => "", "cpf_aluno" => "", "data_nascimento" => "",
    "endereco" => "", "telefone_aluno" => "", "matricula_aluno" => "",
    "telefone_prof" => "", "disciplina" => "", "matricula_prof" => "",
    "cpf_biblio" => "", "telefone_biblio" => "",
];

$tokenValido = $_SERVER["REQUEST_METHOD"] !== "POST" || csrf_valido($_POST["csrf_token"] ?? null);
$tokenCsrf = csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$tokenValido) {
    $mensagem = "Sessão expirada ou inválida. Tente novamente.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($dados as $campo => $valorPadrao) {
        $dados[$campo] = trim($_POST[$campo] ?? "");
    }
    if ($dados["tipo"] === "") {
        $dados["tipo"] = "aluno";
    }

    $nome = $dados["nome"];
    $email = strtolower($dados["email"]);
    $senha = $_POST["senha"] ?? "";
    $confirmarSenha = $_POST["confirmar_senha"] ?? "";
    $tipo = $dados["tipo"];

    // 1) Campos gerais (de usuario)
    if ($nome === "") {
        $mensagem = "O campo Nome é obrigatório.";
    } elseif ($email === "") {
        $mensagem = "O campo E-mail é obrigatório.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensagem = "Formato de e-mail inválido.";
    } elseif ($senha === "" || $confirmarSenha === "") {
        $mensagem = "Preencha a senha e a confirmação de senha.";
    } elseif (strlen($senha) < 6) {
        $mensagem = "A senha precisa ter pelo menos 6 caracteres.";
    } elseif ($senha !== $confirmarSenha) {
        $mensagem = "As senhas digitadas não são iguais.";
    } elseif ($tipo === "bibliotecario") {
        $mensagem = "Contas de bibliotecário não podem ser criadas por autocadastro. Solicite à administração.";
    } elseif (!in_array($tipo, ["aluno", "professor"], true)) {
        $mensagem = "Tipo de usuário inválido.";
    } else {
        // 2) Campos específicos de cada tipo
        if ($tipo === "aluno") {
            if ($dados["ra"] === "" || !ctype_digit($dados["ra"])) {
                $mensagem = "Informe um RA válido (só números).";
            } elseif ($dados["cpf_aluno"] === "") {
                $mensagem = "O campo CPF é obrigatório.";
            } elseif ($dados["data_nascimento"] === "") {
                $mensagem = "O campo Data de nascimento é obrigatório.";
            } elseif ($dados["endereco"] === "") {
                $mensagem = "O campo Endereço é obrigatório.";
            } elseif ($dados["telefone_aluno"] === "") {
                $mensagem = "O campo Telefone é obrigatório.";
            } elseif ($dados["matricula_aluno"] === "" || !ctype_digit($dados["matricula_aluno"])) {
                $mensagem = "Informe uma matrícula válida (só números).";
            }
        } elseif ($tipo === "professor") {
            if ($dados["telefone_prof"] === "") {
                $mensagem = "O campo Telefone é obrigatório.";
            } elseif ($dados["disciplina"] === "") {
                $mensagem = "O campo Disciplina é obrigatório.";
            } elseif ($dados["matricula_prof"] === "" || !ctype_digit($dados["matricula_prof"])) {
                $mensagem = "Informe uma matrícula válida (só números).";
            }
        } else { // bibliotecario
            if ($dados["cpf_biblio"] === "") {
                $mensagem = "O campo CPF é obrigatório.";
            } elseif ($dados["telefone_biblio"] === "") {
                $mensagem = "O campo Telefone é obrigatório.";
            }
        }
    }

    // 3) Se passou por tudo, tenta gravar
    if ($mensagem === "") {
        try {
            // Confere duplicidade de e-mail antes (mensagem mais
            // amigável do que deixar o banco recusar sozinho)
            $stmt = $conexao->prepare("SELECT id_usuario FROM usuario WHERE email = :email");
            $stmt->execute([":email" => $email]);
            if ($stmt->fetch()) {
                $mensagem = "Já existe uma conta cadastrada com esse e-mail.";
            }

            if ($mensagem === "") {
                $conexao->beginTransaction();

                $stmt = $conexao->prepare(
                    "INSERT INTO usuario (nome, email, senha_hash, tipo_usuario)
                     VALUES (:nome, :email, :senha_hash, :tipo)"
                );
                $stmt->execute([
                    ":nome" => $nome,
                    ":email" => $email,
                    ":senha_hash" => password_hash($senha, PASSWORD_DEFAULT),
                    ":tipo" => $tipo,
                ]);
                $idUsuario = (int) $conexao->lastInsertId();

                if ($tipo === "aluno") {
                    $stmt = $conexao->prepare(
                        "INSERT INTO aluno (RA, id_usuario, id_turma, CPF_aluno, dataNascimento, endereco, telefone_aluno, matricula)
                         VALUES (:ra, :id_usuario, :id_turma, :cpf, :nascimento, :endereco, :telefone, :matricula)"
                    );
                    $stmt->execute([
                        ":ra" => $dados["ra"],
                        ":id_usuario" => $idUsuario,
                        ":id_turma" => $dados["id_turma"] !== "" ? $dados["id_turma"] : null,
                        ":cpf" => Criptografia::criptografar($dados["cpf_aluno"]),
                        ":nascimento" => Criptografia::criptografar($dados["data_nascimento"]),
                        ":endereco" => Criptografia::criptografar($dados["endereco"]),
                        ":telefone" => Criptografia::criptografar($dados["telefone_aluno"]),
                        ":matricula" => $dados["matricula_aluno"],
                    ]);
                } elseif ($tipo === "professor") {
                    $stmt = $conexao->prepare(
                        "INSERT INTO professor (id_usuario, telefone_prof, disciplina, matricula)
                         VALUES (:id_usuario, :telefone, :disciplina, :matricula)"
                    );
                    $stmt->execute([
                        ":id_usuario" => $idUsuario,
                        ":telefone" => Criptografia::criptografar($dados["telefone_prof"]),
                        ":disciplina" => $dados["disciplina"],
                        ":matricula" => $dados["matricula_prof"],
                    ]);
                } else { // bibliotecario
                    $stmt = $conexao->prepare(
                        "INSERT INTO bibliotecario (id_usuario, CPF_biblio, telefone_biblio)
                         VALUES (:id_usuario, :cpf, :telefone)"
                    );
                    $stmt->execute([
                        ":id_usuario" => $idUsuario,
                        ":cpf" => Criptografia::criptografar($dados["cpf_biblio"]),
                        ":telefone" => Criptografia::criptografar($dados["telefone_biblio"]),
                    ]);
                }

                $conexao->commit();

                // Conta criada: já loga a pessoa direto, sem
                // precisar digitar tudo de novo na tela de login
                session_regenerate_id(true);
                $_SESSION["id_usuario"] = $idUsuario;
                $_SESSION["nome"] = $nome;
                $_SESSION["tipo_usuario"] = $tipo;
                header("Location: principal.php");
                exit;
            }
        } catch (PDOException $e) {
            if (isset($conexao) && $conexao->inTransaction()) {
                $conexao->rollBack();
            }
            error_log($e->getMessage());
            if ($e->getCode() === "23000") {
                $mensagem = "RA, CPF ou matrícula já cadastrado(a) para outra pessoa.";
            } else {
                $mensagem = "Erro ao criar a conta. Tente novamente.";
            }
        }
    }
}

// Fecha a sessão antes de renderizar a página (se não logou e foi para principal.php)
session_write_close();

// Turmas existentes, pra montar a lista suspensa
$turmas = $conexao->query(
    "SELECT t.id_turma, c.nome_curso, t.turno, t.ano_calendario
     FROM turma t LEFT JOIN curso c ON c.id_curso = t.id_curso
     ORDER BY c.nome_curso, t.turno"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<?= sem_cache_js() ?>
<meta charset="UTF-8">
<title>Cadrastro Bibliotech</title>
<link rel="stylesheet" href="./css/cadastro.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <!-- RENDER BACKGROUND -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="">
    </div>

    <!-- O FORMULÁRIO AGORA ENGLOBA TUDO PARA ROLAR PERFEITAMENTE NA TELA -->
    <form method="POST" action="">
        
        <!-- Logo -->
        <div class="LOGO">
            <img src="./source/image/logo.png" alt="">
        </div>

        <!-- Texto Cadastro -->
        <div class="textologin">
            <h1>Criar conta</h1>
            <p>Preencha os dados abaixo para criar sua conta</p>    
        </div>

        <!-- MENSAGEM DO PHP -->
        <?php if (!empty($mensagem)): ?>
            <div id="resultado"><?= htmlspecialchars($mensagem, ENT_QUOTES, "UTF-8") ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, "UTF-8") ?>">

        <label>Nome:</label>
        <input type="text" name="nome" value="<?= htmlspecialchars($dados["nome"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>E-mail:</label>
        <input type="email" name="email" value="<?= htmlspecialchars($dados["email"], ENT_QUOTES, "UTF-8") ?>" required>

        <label>Senha:</label>
        <input type="password" name="senha" required>

        <label>Confirmar senha:</label>
        <input type="password" name="confirmar_senha" required>

        <label>Eu sou:</label>
        <select name="tipo" id="tipo" onchange="mostrarCampos()">
            <option value="aluno" <?= $dados["tipo"] === "aluno" ? "selected" : "" ?>>Aluno</option>
            <option value="professor" <?= $dados["tipo"] === "professor" ? "selected" : "" ?>>Professor</option>
            <option value="bibliotecario" <?= $dados["tipo"] === "bibliotecario" ? "selected" : "" ?>>Bibliotecário</option>
        </select>

        <fieldset id="campos-aluno">
            <legend>Dados do aluno</legend>

            <label>RA:</label>
            <input type="text" name="ra" value="<?= htmlspecialchars($dados["ra"], ENT_QUOTES, "UTF-8") ?>">

            <label>Turma:</label>
            <select name="id_turma">
                <option value="">— não informar —</option>
                <?php foreach ($turmas as $turma): ?>
                    <option value="<?= $turma["id_turma"] ?>" <?= (string) $turma["id_turma"] === $dados["id_turma"] ? "selected" : "" ?>>
                        <?= htmlspecialchars($turma["nome_curso"] . " - " . $turma["turno"] . " - " . $turma["ano_calendario"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>CPF:</label>
            <input type="text" name="cpf_aluno" value="<?= htmlspecialchars($dados["cpf_aluno"], ENT_QUOTES, "UTF-8") ?>">

            <label>Data de nascimento:</label>
            <input type="date" name="data_nascimento" value="<?= htmlspecialchars($dados["data_nascimento"], ENT_QUOTES, "UTF-8") ?>">

            <label>Endereço:</label>
            <input type="text" name="endereco" value="<?= htmlspecialchars($dados["endereco"], ENT_QUOTES, "UTF-8") ?>">

            <label>Telefone:</label>
            <input type="text" name="telefone_aluno" value="<?= htmlspecialchars($dados["telefone_aluno"], ENT_QUOTES, "UTF-8") ?>">

            <label>Matrícula:</label>
            <input type="text" name="matricula_aluno" value="<?= htmlspecialchars($dados["matricula_aluno"], ENT_QUOTES, "UTF-8") ?>">
        </fieldset>

        <fieldset id="campos-professor" style="display:none">
            <legend>Dados do professor</legend>

            <label>Telefone:</label>
            <input type="text" name="telefone_prof" value="<?= htmlspecialchars($dados["telefone_prof"], ENT_QUOTES, "UTF-8") ?>">

            <label>Disciplina:</label>
            <input type="text" name="disciplina" value="<?= htmlspecialchars($dados["disciplina"], ENT_QUOTES, "UTF-8") ?>">

            <label>Matrícula:</label>
            <input type="text" name="matricula_prof" value="<?= htmlspecialchars($dados["matricula_prof"], ENT_QUOTES, "UTF-8") ?>">
        </fieldset>

        <fieldset id="campos-bibliotecario" style="display:none">
            <legend>Dados do bibliotecário</legend>

            <label>CPF:</label>
            <input type="text" name="cpf_biblio" value="<?= htmlspecialchars($dados["cpf_biblio"], ENT_QUOTES, "UTF-8") ?>">

            <label>Telefone:</label>
            <input type="text" name="telefone_biblio" value="<?= htmlspecialchars($dados["telefone_biblio"], ENT_QUOTES, "UTF-8") ?>">
        </fieldset>

        <button type="submit">Criar conta</button>

        <!-- Botão de Voltar ao Login -->
        <div class="link-cadastro">
            <a href="login.php">Já tenho conta</a>
        </div>
    </form>

    <script>
        // Mostra só os campos do tipo escolhido — mas repara: essa
        // troca é só visual/conveniência. Quem realmente garante
        // que os campos certos foram preenchidos é o PHP no
        // servidor (a pessoa poderia desligar o JavaScript e
        // mesmo assim a validação continua funcionando)
        function mostrarCampos() {
            const tipo = document.getElementById("tipo").value;
            document.getElementById("campos-aluno").style.display = (tipo === "aluno") ? "block" : "none";
            document.getElementById("campos-professor").style.display = (tipo === "professor") ? "block" : "none";
            document.getElementById("campos-bibliotecario").style.display = (tipo === "bibliotecario") ? "block" : "none";
        }
        mostrarCampos();
    </script>
</body>
</html>
