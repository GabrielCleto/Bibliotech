<?php
// php/login.php
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$conteudoBruto = file_get_contents('php://input');
$dados = json_decode($conteudoBruto, true);

$email = trim($dados['email'] ?? '');
$senha = $dados['senha'] ?? '';

if ($email === '' || $senha === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Preencha e-mail e senha.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Digite um e-mail válido.']);
    exit;
}

try {
    $pdo = getConexao();

    // Busca usuário pelo e-mail
    $stmt = $pdo->prepare('SELECT id, nome, email, senha FROM usuarios WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    // Compara senha informada com o hash armazenado
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        echo json_encode([
            'sucesso' => true,
            'mensagem' => 'Login realizado com sucesso! Bem-vindo, ' . htmlspecialchars($usuario['nome']) . '.'
        ]);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail ou senha incorretos.']);
    }
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno de autenticação.']);
}