<?php
// ContaAtiva.php — confere, a cada página, se quem está logada ainda
// tem a conta ATIVA.
//
// Por quê: o login só checa se a conta está ativa na hora de entrar. Se
// a bibliotecária inativar alguém (ou outra bibliotecária) DEPOIS que
// a pessoa já entrou, a sessão dela continuava valendo e ela seguia
// usando o sistema até clicar em "Sair". Com essa conferência, na
// próxima página que ela abrir a sessão é encerrada e ela volta pro login.
//
// IMPORTANTE: chamar ANTES do session_write_close(), porque aqui a
// gente pode precisar apagar a sessão.

function exigirContaAtiva(PDO $conexao): void
{
    $id = (int) ($_SESSION["id_usuario"] ?? 0);

    $stmt = $conexao->prepare("SELECT ativo FROM usuario WHERE id_usuario = ?");
    $stmt->execute([$id]);
    $ativo = $stmt->fetchColumn();

    // false = a conta nem existe mais; 0 = conta inativada
    if ($ativo === false || (int) $ativo === 0) {
        $_SESSION = [];
        session_destroy();
        header("Location: login.php");
        exit;
    }
}
