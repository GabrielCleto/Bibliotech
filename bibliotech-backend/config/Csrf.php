<?php
// Csrf.php — proteção contra CSRF (alguém de fora usando a sessão
// da pessoa sem ela saber, através de outro site).
//
// A ideia: cada formulário nosso carrega um código secreto (token)
// gerado só pra essa sessão. Quando o formulário é enviado, a gente
// confere se o código bate com o que está guardado na sessão. Um
// site de fora nunca vai saber esse código, então não consegue
// forjar o envio.

/**
 * Devolve o token da sessão atual, criando um novo se ainda não
 * existir. Deve ser chamado ao montar qualquer formulário que
 * muda dado (POST).
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION["csrf_token"])) {
        // random_bytes gera algo realmente aleatório, difícil de adivinhar
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

/**
 * Confere se o token que veio do formulário bate com o da sessão.
 */
function csrf_valido(?string $tokenRecebido): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION["csrf_token"]) || !is_string($tokenRecebido)) {
        return false;
    }

    // hash_equals compara sem dar "dica" de quanto acertou (mais seguro
    // que usar só ==)
    return hash_equals($_SESSION["csrf_token"], $tokenRecebido);
}
