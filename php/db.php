<?php
// php/db.php

function getConexao(): PDO {
    // Cria ou abre o arquivo de banco de dados SQLite na mesma pasta php
    $caminhoBanco = __DIR__ . '/banco.sqlite';
    
    $pdo = new PDO('sqlite:' . $caminhoBanco);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Cria a tabela de usuários caso ainda não exista
    $sql = "CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        senha TEXT NOT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);

    return $pdo;
}