<?php
declare(strict_types=1);

// Essa função existia separada, com sua própria lógica de conexão.
// Agora ela só "repassa" pra classe Conexao que já usamos no login
// e no empréstimo — assim existe um único lugar que sabe como
// conectar no banco, em vez de duas versões diferentes fazendo a
// mesma coisa.

require_once __DIR__ . '/Conexao.php';

function database(): PDO
{
    return Conexao::getConexao();
}
