<?php
// cadastro.php — tela de "criar conta". A pessoa escolhe o tipo
// (aluno/professor/bibliotecário) e preenche os dados daquele tipo.
// Usa a tabela unificada "usuario" do banco de dados atualizado.

session_start();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/Csrf.php";
require __DIR__ . "/../config/SemCache.php";
require __DIR__ . "/../config/Criptografia.php";
require __DIR__ . "/../config/MensagemBanco.php";
require __DIR__ . "/../config/Validacao.php";
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
        $dados[$campo] = campoTexto($_POST, $campo);
    }
    if ($dados["tipo"] === "") {
        $dados["tipo"] = "aluno";
    }

    $nome = $dados["nome"];
    $email = strtolower($dados["email"]);
    $senha = is_string($_POST["senha"] ?? null) ? $_POST["senha"] : "";
    $confirmarSenha = is_string($_POST["confirmar_senha"] ?? null) ? $_POST["confirmar_senha"] : "";
    $tipo = $dados["tipo"];

    $raLimpo = null;
    $idTurmaLimpo = null;
    $disciplinaLimpa = null;
    $matriculaLimpa = null;
    $cpfLimpo = null;
    $nascLimpo = null;
    $enderecoLimpo = null;
    $telefoneLimpo = null;

    // 1) Campos gerais (de usuario)
    if ($nome === "" || mb_strlen($nome) > 100) {
        $mensagem = "O campo Nome é obrigatório (máximo 100 caracteres).";
    } elseif ($email === "") {
        $mensagem = "O campo E-mail é obrigatório.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $mensagem = "Formato de e-mail inválido.";
    } elseif ($senha === "" || $confirmarSenha === "") {
        $mensagem = "Preencha a senha e a confirmação de senha.";
    } elseif (strlen($senha) < 6 || strlen($senha) > 72) {
        $mensagem = "A senha precisa ter entre 6 e 72 caracteres.";
    } elseif ($senha !== $confirmarSenha) {
        $mensagem = "As senhas digitadas não são iguais.";
    } elseif ($tipo === "bibliotecario") {
        $mensagem = "Contas de bibliotecário não podem ser criadas por autocadastro. Solicite à administração.";
    } elseif (!in_array($tipo, ["aluno", "professor"], true)) {
        $mensagem = "Tipo de usuário inválido.";
    } else {
        // 2) Campos específicos de cada tipo (respeitando chk_dados_por_tipo e trg_usuario_turma_insert)
        if ($tipo === "aluno") {
            $raLimpo = inteiroPositivo($dados["ra"], "2147483647");
            $cpfLimpo = validarCpf($dados["cpf_aluno"]);
            $nascLimpo = validarDataNascimento($dados["data_nascimento"]);
            $telefoneLimpo = validarTelefone($dados["telefone_aluno"]);

            if ($raLimpo === null) {
                $mensagem = "Informe um RA válido (só números, maior que zero).";
            } elseif ($dados["id_turma"] === "" || inteiroPositivo($dados["id_turma"], "2147483647") === null || !turmaExiste($conexao, $dados["id_turma"])) {
                $mensagem = "O aluno precisa estar em uma turma válida.";
            } elseif ($cpfLimpo === null) {
                $mensagem = "CPF inválido: precisa ter 11 números.";
            } elseif ($nascLimpo === null) {
                $mensagem = "Data de nascimento inválida (use uma data que já passou).";
            } elseif ($dados["endereco"] === "" || strlen($dados["endereco"]) > 200) {
                $mensagem = "O campo Endereço é obrigatório (máximo 200 caracteres).";
            } elseif ($telefoneLimpo === null) {
                $mensagem = "Telefone inválido: use de 8 a 11 números (com ou sem DDD).";
            } else {
                $idTurmaLimpo = (int) $dados["id_turma"];
                $enderecoLimpo = $dados["endereco"];
            }
        } elseif ($tipo === "professor") {
            $telefoneLimpo = validarTelefone($dados["telefone_prof"]);
            $matriculaLimpa = inteiroPositivo($dados["matricula_prof"], "9223372036854775807");

            if ($telefoneLimpo === null) {
                $mensagem = "Telefone inválido: use de 8 a 11 números (com ou sem DDD).";
            } elseif ($dados["disciplina"] === "" || mb_strlen($dados["disciplina"]) > 50) {
                $mensagem = "O campo Disciplina é obrigatório (máximo 50 caracteres).";
            } elseif ($matriculaLimpa === null) {
                $mensagem = "Informe uma matrícula válida (só números, maior que zero).";
            } else {
                $disciplinaLimpa = $dados["disciplina"];
            }
        }
    }

    // 3) Se passou por tudo, tenta gravar na tabela unificada usuario
    if ($mensagem === "") {
        try {
            // Confere duplicidade de e-mail antes (mensagem mais amigável)
            $stmt = $conexao->prepare("SELECT id_usuario FROM usuario WHERE email = :email");
            $stmt->execute([":email" => $email]);
            if ($stmt->fetch()) {
                $mensagem = "Já existe uma conta cadastrada com esse e-mail.";
            }

            if ($mensagem === "") {
                $conexao->beginTransaction();

                $stmt = $conexao->prepare(
                    "INSERT INTO usuario (
                        nome, email, senha_hash, tipo_usuario,
                        RA, id_turma, disciplina, matricula,
                        CPF, dataNascimento, endereco, telefone
                    ) VALUES (
                        :nome, :email, :senha_hash, :tipo,
                        :ra, :id_turma, :disciplina, :matricula,
                        :cpf, :nascimento, :endereco, :telefone
                    )"
                );
                $stmt->execute([
                    ":nome"       => $nome,
                    ":email"      => $email,
                    ":senha_hash" => password_hash($senha, PASSWORD_DEFAULT),
                    ":tipo"       => $tipo,
                    ":ra"         => $raLimpo,
                    ":id_turma"   => $idTurmaLimpo,
                    ":disciplina" => $disciplinaLimpa,
                    ":matricula"  => $matriculaLimpa,
                    ":cpf"        => $cpfLimpo !== null ? Criptografia::criptografar($cpfLimpo) : null,
                    ":nascimento" => $nascLimpo !== null ? Criptografia::criptografar($nascLimpo) : null,
                    ":endereco"   => $enderecoLimpo !== null ? Criptografia::criptografar($enderecoLimpo) : null,
                    ":telefone"   => $telefoneLimpo !== null ? Criptografia::criptografar($telefoneLimpo) : null,
                ]);
                $idUsuario = (int) $conexao->lastInsertId();

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
                $mensagem = mensagemIntegridade($e);
            } elseif ($e->getCode() === "45000") {
                $mensagem = mensagemDoBanco($e);
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
