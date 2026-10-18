<?php
// Validacao.php — funções que conferem os dados do cadastro de usuário
// ANTES de mandar pro banco, pra dar mensagem clara pra bibliotecária
// em vez de um erro genérico do banco.
//
// Cada função "validar..." devolve um array com 2 itens:
//   [ mensagem_de_erro_ou_null , dados_ja_limpos ]
// Se a mensagem vier preenchida, tem algo errado e NÃO deve salvar.

/**
 * Pega um campo do formulário sempre como TEXTO, sem quebrar.
 * (Se alguém mandar o campo como lista, tipo cpf[]=1, o trim() normal
 * do PHP dá erro fatal; aqui a gente só trata como campo vazio.)
 */
function campoTexto(array $dados, string $nome): string
{
    $valor = $dados[$nome] ?? "";
    return is_string($valor) ? trim($valor) : "";
}

/** Deixa só os números de um texto ("123.456.789-01" vira "12345678901"). */
function soDigitos(string $texto): string
{
    return preg_replace("/\D+/", "", $texto) ?? "";
}

/**
 * Confere se o texto é um número inteiro maior que zero e cabe no limite.
 * Devolve o número, ou null se não servir (vazio, letra, negativo, zero,
 * com vírgula/ponto, grande demais).
 */
function inteiroPositivo(string $texto, string $maximo): ?string
{
    if ($texto === "" || !ctype_digit($texto)) {
        return null;
    }
    $texto = ltrim($texto, "0");
    if ($texto === "") {
        return null; // era só zeros
    }
    // Compara como texto de tamanho igual pra não estourar o limite do PHP
    if (strlen($texto) > strlen($maximo)
        || (strlen($texto) === strlen($maximo) && strcmp($texto, $maximo) > 0)) {
        return null;
    }
    return $texto;
}

/** CPF: aceita com ou sem pontos/traço, mas precisa ter 11 números. */
function validarCpf(string $texto): ?string
{
    $cpf = soDigitos($texto);
    return strlen($cpf) === 11 ? $cpf : null;
}

/**
 * Telefone: de 8 a 11 números. Aceita com ou sem DDD, porque os dados
 * iniciais do banco têm telefone sem DDD (ex.: 92876-5745) e a
 * bibliotecária precisa conseguir editar essas pessoas sem erro.
 */
function validarTelefone(string $texto): ?string
{
    $tel = soDigitos($texto);
    return (strlen($tel) >= 8 && strlen($tel) <= 11) ? $tel : null;
}

/** Data no formato aaaa-mm-dd (o campo "date" do HTML manda assim), que exista e não seja futura. */
function validarDataNascimento(string $texto): ?string
{
    $data = DateTime::createFromFormat("Y-m-d", $texto);
    if (!$data || $data->format("Y-m-d") !== $texto) {
        return null; // formato errado ou data que não existe (ex.: 2020-02-31)
    }
    $hoje = new DateTime("today");
    if ($data > $hoje || (int) $data->format("Y") < 1900) {
        return null;
    }
    return $texto;
}

/** Confere se a turma existe de verdade na tabela turma. */
function turmaExiste(PDO $conexao, string $idTurma): bool
{
    $stmt = $conexao->prepare("SELECT COUNT(*) FROM turma WHERE id_turma = ?");
    $stmt->execute([$idTurma]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Valida os campos que mudam de acordo com o TIPO da conta.
 * $obrigatorio = true na criação (tudo precisa vir preenchido, igual
 * o banco exige) e false na edição (campo em branco = "não muda").
 */
function validarCamposDoTipo(PDO $conexao, string $tipo, array $post, bool $obrigatorio): array
{
    $d = ["ra" => null, "id_turma" => null, "disciplina" => null, "matricula" => null,
          "cpf" => null, "data_nascimento" => null, "endereco" => null, "telefone" => null];

    // Confere um campo: se estiver em branco, só reclama quando for obrigatório
    $vazioOk = function (string $valor) use ($obrigatorio): bool {
        return $valor === "" && !$obrigatorio;
    };

    if ($tipo === "aluno") {
        $ra = campoTexto($post, "ra");
        if (!$vazioOk($ra)) {
            $d["ra"] = inteiroPositivo($ra, "2147483647");
            if ($d["ra"] === null) {
                return ["RA inválido: use só números, maior que zero.", $d];
            }
        }

        $turma = campoTexto($post, "id_turma");
        if (!$vazioOk($turma)) {
            if (inteiroPositivo($turma, "2147483647") === null || !turmaExiste($conexao, $turma)) {
                return ["Turma não encontrada. Escolha uma turma da lista.", $d];
            }
            $d["id_turma"] = $turma;
        }

        $nasc = campoTexto($post, "data_nascimento");
        if (!$vazioOk($nasc)) {
            $d["data_nascimento"] = validarDataNascimento($nasc);
            if ($d["data_nascimento"] === null) {
                return ["Data de nascimento inválida (use uma data que já passou).", $d];
            }
        }

        $end = campoTexto($post, "endereco");
        if (!$vazioOk($end)) {
            // Limite em bytes: o endereço vai criptografado e o campo do
            // banco tem tamanho fixo (320), então ~200 bytes é o seguro
            if ($end === "" || strlen($end) > 200) {
                return ["Endereço é obrigatório e pode ter no máximo 200 caracteres.", $d];
            }
            $d["endereco"] = $end;
        }
    }

    if ($tipo === "professor") {
        $disc = campoTexto($post, "disciplina");
        if (!$vazioOk($disc)) {
            if ($disc === "" || mb_strlen($disc) > 50) {
                return ["Disciplina é obrigatória e pode ter no máximo 50 caracteres.", $d];
            }
            $d["disciplina"] = $disc;
        }

        $mat = campoTexto($post, "matricula");
        if (!$vazioOk($mat)) {
            $d["matricula"] = inteiroPositivo($mat, "9223372036854775807");
            if ($d["matricula"] === null) {
                return ["Matrícula inválida: use só números, maior que zero.", $d];
            }
        }
    }

    if ($tipo === "aluno" || $tipo === "bibliotecario") {
        $cpf = campoTexto($post, "cpf");
        if (!$vazioOk($cpf)) {
            $d["cpf"] = validarCpf($cpf);
            if ($d["cpf"] === null) {
                return ["CPF inválido: precisa ter 11 números.", $d];
            }
        }
    }

    // Telefone vale pros três tipos
    $tel = campoTexto($post, "telefone");
    if (!$vazioOk($tel)) {
        $d["telefone"] = validarTelefone($tel);
        if ($d["telefone"] === null) {
            return ["Telefone inválido: use de 8 a 11 números (com ou sem DDD).", $d];
        }
    }

    return [null, $d];
}

/** Valida nome, e-mail e (opcionalmente) senha. */
function validarBasico(array $post, bool $obrigatorio, bool $comSenha): array
{
    $d = ["nome" => null, "email" => null, "senha" => null];

    $nome = campoTexto($post, "nome");
    if ($nome !== "" || $obrigatorio) {
        if ($nome === "" ) {
            return ["Nome, e-mail e senha são obrigatórios.", $d];
        }
        if (mb_strlen($nome) > 100) {
            return ["O nome pode ter no máximo 100 caracteres.", $d];
        }
        $d["nome"] = $nome;
    }

    $email = strtolower(campoTexto($post, "email"));
    if ($email !== "" || $obrigatorio) {
        if ($email === "") {
            return ["Nome, e-mail e senha são obrigatórios.", $d];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            return ["Formato de e-mail inválido.", $d];
        }
        $d["email"] = $email;
    }

    if ($comSenha) {
        $senha = is_string($post["senha"] ?? null) ? $post["senha"] : "";
        if ($senha === "") {
            return ["Nome, e-mail e senha são obrigatórios.", $d];
        }
        if (strlen($senha) < 6) {
            return ["A senha precisa ter pelo menos 6 caracteres.", $d];
        }
        // O jeito que a senha é guardada (bcrypt) só olha os primeiros
        // 72 caracteres; passar disso faria senhas diferentes valerem igual
        if (strlen($senha) > 72) {
            return ["A senha pode ter no máximo 72 caracteres.", $d];
        }
        $d["senha"] = $senha;
    }

    return [null, $d];
}
