# 📚 Bibliotech — Sistema de Gestão de Biblioteca

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Architecture](https://img.shields.io/badge/Architecture-Clean%20%2F%2012--Factor-success)](https://12factor.net/)
[![Standards](https://img.shields.io/badge/Standard-PSR--4-blue)](https://www.php-fig.org/psr/psr-4/)
[![License](https://img.shields.io/badge/License-MIT-lightgrey.svg)](LICENSE)

O **Bibliotech** é uma plataforma robusta para gestão de acervos, empréstimos, renovações, reservas e relatórios gerenciais para instituições de ensino. O sistema combina interfaces web administrativas completas em PHP com uma API RESTful de alta performance pronta para integração com aplicações front-end modernas (React, Vue, mobile) e sistemas externos.

---

## 🌟 Principais Recursos e Diferenciais

- **Catálogo & Acervo Inteligente:** Busca textual por título, autor e categoria com paginação otimizada via PDO.
- **Fluxo de Empréstimo com Reserva Atômica:** Controle de disponibilidade em estoque com integridade referencial via stored procedures e constraints únicas na fila de espera.
- **Renovação com Aprovação do Bibliotecário:** Solicitação de renovação controlada por regras de negócio (limite de 1 renovação, sem atrasos e sem fila de espera), exigindo validação expressa da equipe da biblioteca.
- **Relatórios Gerenciais:** Métricas em tempo real por turma, curso, situação acadêmica, livros populares e histórico completo.
- **Segurança & Privacidade (LGPD):** Criptografia reversível AES-256-CBC para dados sensíveis (CPF, telefone, endereço), proteção contra CSRF em formulários web e autenticação resiliente contra timing attacks e enumeração de usuários.
- **Observabilidade Estruturada:** Logs com níveis (`DEBUG`, `INFO`, `WARNING`, `ERROR`) e contexto serializado em JSON (`storage/logs/app.log`).

---

## 🏗️ Arquitetura do Software

O projeto segue os princípios de **Clean Architecture**, **PSR-4** e **12-Factor App**:

```text
Biblioteca/
├── .gitignore                     # Proteção de credenciais, logs e temporários
├── Banco_de_dados.sql             # Estrutura completa DDL, views e procedures
├── README.md                      # Documentação principal do repositório
└── bibliotech-backend/
    ├── .env.example               # Template de variáveis de ambiente
    ├── composer.json              # Metadados e padronização PSR-4
    ├── GUIA_INTEGRACAO.md         # Manual detalhado para desenvolvedores Front-end
    ├── bin/
    │   └── generate_notifications.php # CLI Job para automação de avisos
    ├── config/                    # Conexões, criptografia e utilitários de segurança
    ├── models/                    # Modelos de domínio (Usuario, Emprestimo)
    ├── paginas/                   # Interfaces administrativas prontas para uso
    ├── public/                    # Ponto único de entrada da API REST (index.php)
    └── src/
        ├── Controllers/           # Controladores REST (Livros, Empréstimos, Relatórios)
        ├── Services/              # Serviços de domínio e notificações
        └── Support/               # Autoloader PSR-4, Logger, Env parser e Router
```

---

## 🚀 Como Executar Localmente (XAMPP)

### Pré-requisitos
- [XAMPP](https://www.apachefriends.org/) com PHP 8.1+ e MySQL 8.0+ ativados.
- Git instalado.

### Passo a Passo

1. **Clone o repositório no diretório `htdocs`:**
   ```bash
   cd C:\xampp\htdocs
   git clone https://github.com/GabrielCleto/Bibliotech.git Biblioteca
   cd Biblioteca\bibliotech-backend
   ```

2. **Configure as Variáveis de Ambiente:**
   Copie o arquivo de exemplo e ajuste as credenciais se necessário:
   ```bash
   cp .env.example .env
   ```

3. **Importe o Banco de Dados:**
   - Acesse o **phpMyAdmin** (`http://localhost/phpmyadmin`).
   - Crie um banco chamado `biblioteca` com charset `utf8mb4_general_ci`.
   - Importe o script [Banco_de_dados.sql](file:///c:/xampp/htdocs/Biblioteca/Banco_de_dados.sql).

4. **Acesse o Sistema:**
   - **Interface Web:** `http://localhost/Biblioteca/bibliotech-backend/paginas/login.php`
   - **API REST:** `http://localhost/Biblioteca/bibliotech-backend/api/books`

---

## 📡 Visão Geral da API REST

A API responde no formato JSON padronizado com CORS habilitado:

| Método | Endpoint | Acesso | Descrição |
|---|---|---|---|
| `GET` | `/api/books` | Público | Lista o acervo com filtros (`?search=`) e paginação (`?page=&per_page=`) |
| `GET` | `/api/books/{id}` | Público | Detalhes completos de um livro específico |
| `POST` | `/api/books/{id}/request` | Autenticado | Solicita novo empréstimo |
| `GET` | `/api/my-loans` | Autenticado | Histórico de empréstimos do usuário conectado |
| `POST` | `/api/my-loans/{id}/renew` | Autenticado | Solicita renovação de um empréstimo ativo |
| `POST` | `/api/loans/{id}/approve-renewal` | Bibliotecário | Aprova a renovação solicitada |
| `POST` | `/api/loans/{id}/reject-renewal` | Bibliotecário | Recusa a renovação solicitada |
| `GET` | `/api/wishlist` | Autenticado | Lista de desejos e posição na fila de espera |
| `GET` | `/api/notifications` | Autenticado | Central de notificações do usuário |
| `GET` | `/api/reports/loans` | Bibliotecário | Relatório consolidado de empréstimos |
| `GET` | `/api/reports/by-class` | Bibliotecário | Relatório agrupado por turma |
| `GET` | `/api/reports/by-course` | Bibliotecário | Relatório agrupado por curso |
| `GET` | `/api/reports/loan-history` | Bibliotecário | Histórico detalhado com múltiplos filtros |

Para documentação exaustiva de payloads e códigos de erro, consulte o [GUIA_INTEGRACAO.md](file:///c:/xampp/htdocs/Biblioteca/bibliotech-backend/GUIA_INTEGRACAO.md).

---

## 🔒 Padrões de Segurança Aplicados

- **OWASP Compliance:** Proteção contra SQL Injection (100% de queries preparadas via PDO), Prevenção contra enumeração de e-mails em tentativas de autenticação e proteção contra CSRF em formulários web.
- **Controle de Sessão Ativa:** Verificação contínua do status de ativação da conta em cada requisição (`CurrentUser::requireActiveAccount`).
- **Segurança de Segredos:** Arquivos `.env` estritamente ignorados pelo versionamento através do [.gitignore](file:///c:/xampp/htdocs/Biblioteca/.gitignore).

---

## 👨‍💻 Convenção de Commits

O projeto adota o padrão **Conventional Commits**:
- `feat:` Nova funcionalidade
- `fix:` Correção de bug
- `refactor:` Refatoração de código sem alteração de funcionalidade
- `docs:` Alterações em documentação
- `test:` Inclusão ou ajuste de testes
- `chore:` Manutenção de arquivos de build, ambiente e dependências
