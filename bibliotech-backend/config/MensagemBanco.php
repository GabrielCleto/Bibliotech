<?php
// MensagemBanco.php — função usada por várias telas pra mostrar pra
// pessoa só o texto que o BANCO escreveu quando recusou uma operação.
//
// Quando uma procedure ou trigger do banco recusa algo (SIGNAL
// SQLSTATE '45000'), o PHP recebe uma mensagem comprida e cheia de
// "lixo" técnico, tipo:
//   SQLSTATE[45000]: <<Unknown error>>: 1644 Usuário possui multa pendente.
// Essa função tira o lixo e devolve só: "Usuário possui multa pendente."

if (!function_exists("mensagemDoBanco")) {
    function mensagemDoBanco(PDOException $e): string
    {
        if (preg_match('/\d{3,5}\s+(.+)$/s', $e->getMessage(), $partes)) {
            return trim($partes[1]);
        }
        return "Não foi possível concluir a operação. Tente novamente.";
    }
}

/**
 * Quando o banco recusa por "integridade" (SQLSTATE 23000), pode ser
 * por 3 motivos bem diferentes. Essa função descobre qual foi, lendo a
 * mensagem do erro, e devolve um texto certo pra cada um:
 *  - valor repetido (e-mail, RA, CPF ou matrícula que já existe)
 *  - referência que não existe (ex.: turma inexistente)
 *  - regra de dados obrigatórios do tipo de conta
 */
if (!function_exists("mensagemIntegridade")) {
    function mensagemIntegridade(PDOException $e): string
    {
        $texto = $e->getMessage();

        if (stripos($texto, "Duplicate entry") !== false) {
            // Descobre qual campo repetiu: "... for key 'email'" ou 'usuario.email'
            $campo = "";
            if (preg_match("/for key '([^']+)'/i", $texto, $partes)) {
                $campo = strtolower(substr(strrchr("." . $partes[1], "."), 1));
            }
            return match ($campo) {
                "email" => "Esse e-mail já está cadastrado para outra pessoa.",
                "ra" => "Esse RA já está cadastrado para outro aluno.",
                "cpf" => "Esse CPF já está cadastrado para outra pessoa.",
                "matricula" => "Essa matrícula já está cadastrada para outro professor.",
                default => "Já existe um cadastro com esses dados (e-mail, RA, CPF ou matrícula).",
            };
        }

        if (stripos($texto, "foreign key") !== false) {
            return "Um dos itens escolhidos (como a turma) não existe mais. Atualize a página e tente de novo.";
        }

        if (stripos($texto, "chk_dados_por_tipo") !== false) {
            return "Faltam dados obrigatórios para esse tipo de conta.";
        }

        return "Os dados não foram aceitos pelo banco. Confira os campos e tente de novo.";
    }
}
