# 🗄️ Banco de Dados Oficial — Bibliotech (v1.1)

Este diretório armazena o script oficial do banco de dados MySQL (`biblioteca`), mantido sem alterações no DDL interno (100% fiel à entrega da equipe de Banco de Dados).

---

## 📋 Versão Ativa do Banco de Dados

* **Arquivo:** `Banco_de_dados_v1.1.sql`
* **Versão:** **v1.1 (Atual / Oficial)**
* **Descrição:** Primeira atualização pós-1.0. Unifica os perfis na tabela `usuario` (com dados sensíveis criptografados em AES-256-CBC), adiciona a tabela `configuracao`, as views `vw_alunos` e `vw_professores`, triggers de integridade (`trg_emprestimo_*`, `trg_usuario_turma_*`), evento diário `ev_atualizar_atrasos` e stored procedures completas de circulação (`sp_realizar_emprestimo`, `sp_devolver_livro`, `sp_criar_reserva`, `sp_criar_usuario`, `sp_atualizar_usuario`, `sp_alterar_status_usuario`, `sp_excluir_usuario`, `sp_listar_alunos`, `sp_listar_professores`).

---

## 🚀 Como Importar

1. Inicie o serviço **MySQL 8.0+** no XAMPP.
2. Acesse o **phpMyAdmin** (`http://localhost/phpmyadmin`) ou cliente CLI do MySQL.
3. Importe o arquivo **`Banco_de_dados_v1.1.sql`** (ele já executa `DROP DATABASE IF EXISTS biblioteca` e `CREATE DATABASE biblioteca CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci` automaticamente).
   - **Nota de compatibilidade:** mantenha a collation da conexão em `utf8mb4_general_ci` durante a importação para garantir conformidade com as procedures de consulta.

---

## 🔐 Integridade do Arquivo (SHA-256)

- `Banco_de_dados_v1.1.sql`: `1BB9EAE9004C451B01123AFA569D061DD562E919CD7CDE0F2A63FD59BFA66892`
