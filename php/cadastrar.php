<?php
// php/cadastrar.php
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$conteudoBruto = file_get_contents('php://input');
$dados = json_decode($conteudoBruto, true);

$nome = trim($dados['nome'] ?? '');
$email = trim($dados['email'] ?? '');
$senha = $dados['senha'] ?? '';

// --- Validações de Entrada ---
if ($nome === '' || $email === '' || $senha === '') {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Todos os campos são obrigatórios.']);
    exit;
}

if (strlen($nome) < 3) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'O nome deve ter pelo menos 3 caracteres.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Digite um e-mail válido.']);
    exit;
}

if (strlen($senha) < 6) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 6 caracteres.']);
    exit;
}

try {
    $pdo = getConexao();

    // 1. Verifica duplicidade de e-mail
    $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email');
    $stmtCheck->execute([':email' => $email]);

    if ($stmtCheck->fetch()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Este e-mail já está cadastrado.']);
        exit;
    }

    // 2. Criptografia segura da senha (Bcrypt por padrão)
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    // 3. Persistência
    $stmtInsert = $pdo->prepare('INSERT INTO usuarios (nome, email, senha) VALUES (:nome, :email, :senha)');
    $stmtInsert->execute([
        ':nome' => $nome,
        ':email' => $email,
        ':senha' => $senhaHash
    ]);

    echo json_encode(['sucesso' => true, 'mensagem' => 'Usuário ' . htmlspecialchars($nome) . ' cadastrado com sucesso!']);
} catch (Exception $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no banco de dados.']);
}