<div align="center">

# 📚 Bibliotech — Sistema de Autenticação

  <p>
    <b>Módulo de autenticação assíncrono (SPA Component) para gerenciamento de acesso.</b>
  </p>

  <p>
    <a href="#-tecnologias">Tecnologias</a> •
    <a href="#-funcionalidades">Funcionalidades</a> •
    <a href="#-estrutura-do-projeto">Estrutura</a> •
    <a href="#-como-executar">Como Executar</a> •
    <a href="#-roadmap">Roadmap</a>
  </p>

  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black" alt="JS" />
  <img src="https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white" alt="HTML5" />
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License" />

</div>

<br />

---

## 📖 Sobre o Projeto

O **Bibliotech** é um componente de autenticação leve e responsivo projetado para sistemas de bibliotecas virtuais. Ele utiliza uma abordagem **Single-Page Component (SPC)**, permitindo transições fluídas entre as telas de **Login** e **Cadastro** sem a necessidade de recarregar a página (*zero page reloads*).

---

## ✨ Funcionalidades

- 🔄 **Alternância Dinâmica de UI:** Chaveamento de formulário direto via manipulação de DOM
- 🛡️ **Validação em Camadas (Client & Server):**
  - Checagem do formato de e-mail por Regex (Front-end) e `filter_var` (Back-end).
  - Validação de tamanhos mínimos de string e confirmação de senha
- ⚡ **Comunicação Assíncrona:** Requisições via `Fetch API` utilizando payloads JSON em verbos HTTP `POST`.
- 📦 **Respostas Padronizadas:** Retornos em JSON estruturados (`sucesso`: bool, `mensagem`: string)

---

## 🛠️ Tecnologias

- **Front-end:** HTML5 semântico, JavaScript moderno (ES6+, Async/Await, Fetch API)[
- **Back-end:** PHP 8.x
- **Protocolo de Dados:** JSON (`application/json`)

---

## 📁 Estrutura do Projeto

```text
.
├── 📄 index.html          # Interface de usuário principal (Formulário dinâmico)
├── 📂 js/
│   └── 📄 script.js       # Regras de negócio do cliente, validações e Fetch API
└── 📂 php/
    ├── 📄 cadastrar.php   # Endpoint de processamento do cadastro
    └── 📄 login.php       # Endpoint de validação de credenciais
```[cite: 1, 2, 3, 4]

---
 ```
## 🚀 Como Executar Localmente

### Pré-requisitos
- PHP 7.4 ou superior instalado.

### Passo a Passo

1. **Clone o repositório:**
  bash
   git clone [https://github.com/seu-usuario/bibliotech.git](https://github.com/seu-usuario/bibliotech.git)
   cd bibliotech
Inicie o servidor local do PHP:

Bash
php -S localhost:8000
Acesse no navegador:

Plaintext
http://localhost:8000
⚠️ Atenção: Não abra o arquivo index.html diretamente via protocolo file:///, pois as chamadas assíncronas aos endpoints PHP serão bloqueadas

📌 Roadmap de Evolução
[ ] Implementar persistência de dados em banco de dados SQLite/MySQL via PDO.

[ ] Criptografar senhas com password_hash() e password_verify().

[ ] Adicionar controle de sessão de usuário (session_start()).

[ ] Extrair estilos inline do index.html para um arquivo CSS externo

Visão Geral da Arquitetura Atual
Front-end (index.html): Possui formulário único com alternância dinâmica de visibilidade de elementos

Cliente JS (js/script.js): Concentra o controle de DOM, validação e chamadas fetch

Back-end (php/cadastrar.php e php/login.php): Revalida entradas via PHP e retorna dados em JSON
