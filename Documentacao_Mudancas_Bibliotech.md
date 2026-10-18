# 📚 BIBLIOTECH — EVOLUÇÃO E ARQUITETURA
**Dossiê Completo de Mudanças, Regras de Negócio e Inovações do Back-end**  
*Equipe Back-end | Preparado para Apresentação e Importação no NotebookLM | Outubro de 2026*

---

## 1. Resumo Executivo para Apresentação
Este documento consolida todas as implementações, correções lógicas, reforços de segurança e inovações arquiteturais desenvolvidas no back-end do sistema **Bibliotech**. O projeto evoluiu de um conjunto básico de scripts PHP para uma aplicação corporativa robusta, orientada a objetos, compatível com os princípios do **12-Factor App**, **Clean Architecture** e padrões **PSR-4**.

O sistema atende simultaneamente a duas frentes:
1. **Interface Web Administrativa:** Páginas completas em PHP para gestão do acervo, usuários, multas, aprovações e relatórios.
2. **API RESTful de Alta Velocidade:** Endpoints padronizados em JSON prontos para integração com front-ends modernos (React, Vue, aplicativos móveis) e sistemas de terceiros.

---

## 2. Comparativo de Transformação: Antes vs. Depois

| Aspecto Avaliado | Estado Anterior | Estado Atual (Novo Back-end) |
|---|---|---|
| **Configuração de Ambiente** | Credenciais de banco e chaves secretas gravadas dentro do código PHP. | Desacoplamento total via `.env`, com fallbacks automáticos para XAMPP local e proteção via `.gitignore`. |
| **Carregamento de Classes** | `require_once` manuais e repetidos espalhados por dezenas de arquivos. | Autoloader PSR-4 nativo com alta performance, compatível com o padrão Composer. |
| **Catálogo de Livros** | Exigia login prévio até para ver a lista; sem paginação e com risco de erro de prepared statement. | Acesso público, novo endpoint por ID (`/api/books/{id}`), busca otimizada e paginação estruturada. |
| **Fluxo de Renovação** | Automático ou sem validação rígida de fila e pendência. | Necessita obrigatoriamente de **aprovação da bibliotecária**, bloqueia renovação se houver atraso ou fila de espera. |
| **Fila de Reserva** | Erro de constraint duplicada (`uq_reserva_fila_livro_posicao`) ao atender reserva anterior. | Cálculo atômico histórico que garante integridade absoluta sem travar o banco de dados. |
| **Relatórios Gerenciais** | Relatórios limitados e com ambiguidade entre ID do empréstimo e ID do usuário. | Módulos por turma, curso, situação acadêmica, histórico geral com filtros e IDs explicitados. |
| **Segurança no Login** | Enumeração de e-mails (mensagens diferentes para e-mail inexistente e senha incorreta). | Mensagem de credenciais uniforme, blindada contra enumeração e ataques de força bruta. |
| **Observabilidade** | Apenas logs genéricos do Apache. | Sistema de logging estruturado (`Logger`) registrando eventos e erros com JSON em `storage/logs/app.log`. |

---

## 3. Detalhamento das Novas Funcionalidades e Regras de Negócio

### 3.1. Fluxo de Renovação Controlada (Aprovação Obrigatória)
A renovação de empréstimos passou a seguir um fluxo rigoroso de governança acadêmica:
1. **Solicitação:** O aluno ou professor solicita a renovação pela tela de 'Meus Empréstimos' ou via API REST (`POST /api/my-loans/{id}/renew`).
2. **Validação Prévia:** O sistema valida previamente se o empréstimo está dentro do prazo (não atrasado), se nunca foi renovado anteriormente e se não há ninguém na fila de espera para aquele livro.
3. **Estado Transitório:** O status passa para `aguardando aprovação de renovação`. O prazo de devolução **não** é estendido de imediato.
4. **Decisão da Bibliotecária:** A bibliotecária analisa a solicitação na tela de 'Aprovações' ou via endpoint (`POST /api/loans/{id}/approve-renewal`). Ao aprovar, o prazo recebe mais 15 dias (aluno) ou 30 dias (professor) a contar da devolução prevista. Se recusar, o prazo original é mantido.

### 3.2. Módulo de Relatórios Analíticos e Diferenciação de IDs
Para dar suporte à tomada de decisão pedagógica e administrativa, foram implementados novos relatórios:
- **Relatório por Turma:** Quantifica alunos matriculados, total de empréstimos ativos, atrasados e taxa de conclusão por turma.
- **Relatório por Curso:** Consolida os dados acadêmicos por curso, permitindo visualizar quais cursos mais utilizam a biblioteca.
- **Relatório por Situação:** Filtra o engajamento dos alunos por status de matrícula (`Ativo`, `Trancado`, `Formado`, `Evadido`).
- **Histórico Completo Paginado:** Permite filtrar por intervalo de datas, status, curso, turma, livro ou usuário específico.
- **Correção Crítica de ID:** Na consulta anterior, a coluna `id` causava confusão se era o ID do aluno ou da locação. Agora, foram separadas explicitamente em `id_emprestimo` e `id_usuario` em todas as views e retornos da API.

### 3.3. Catálogo Público e Novo Endpoint Individual
- **Acesso Público:** A rota `GET /api/books` agora é pública, permitindo que visitantes e novos alunos consultem o acervo sem necessidade de login prévio.
- **Consulta Individual:** Criado o endpoint `GET /api/books/{bookId}`, que retorna todos os metadados de uma obra específica (título, autor, editora, ano, ISBN, categoria e exemplares disponíveis).
- **Busca e Paginação:** Adicionados parâmetros `?search=termo` e paginação `?page=1&per_page=20` com metadados estruturados de total de páginas e registros.

### 3.4. Resolução da Fila de Espera (Lista de Desejos)
Foi corrigido o bug que ocorria quando uma reserva anterior já atendida colidia com o índice UNIQUE (`uq_reserva_fila_livro_posicao`). O novo cálculo considera o histórico de posições, garantindo que novas reservas entrem na fila perfeitamente sem violar restrições do banco.

---

## 4. Arquitetura de Software, Segurança e .env

### 4.1. Por que o `.env` não sobe para o GitHub?
O arquivo `.env` armazena credenciais locais e chaves criptográficas AES-256 (`APP_KEY` e `APP_IV`) usadas para criptografar CPFs, telefones e endereços no banco de dados. Subir esse arquivo geraria vazamento de segredos e violação direta da LGPD. Em seu lugar, é versionado o `.env.example` (apenas o esqueleto).

### 4.2. Tolerância a Falhas (Fallback Automático)
Caso outra pessoa clone o projeto e esqueça de criar o arquivo `.env`, o sistema foi programado para assumir automaticamente as credenciais padrão do XAMPP (`localhost:3306`, `root`, senha vazia). Isso garante que o software nunca trave ao ser instalado por outros colegas ou avaliadores.

### 4.3. Autenticação Flexível na API (Web + Mobile/SPA)
A API do Bibliotech agora aceita duas formas de autenticação:
1. **Sessão Web:** Cookie de sessão padrão utilizado pelo navegador nas páginas HTML.
2. **Cabeçalho HTTP:** Aceita `Authorization: Bearer <token_ou_id>` ou `X-User-Id: <id>`, facilitando a conexão com front-ends desacoplados (React, Flutter, etc.).

### 4.4. Segurança Ativa e Encerramento em Tempo Real
Se uma bibliotecária inativar um usuário, a classe `CurrentUser::requireActiveAccount` detecta a alteração imediatamente na próxima requisição, encerrando a sessão web ou retornando `HTTP 403 Forbidden` na API.

---

## 5. Roteiro Sugerido para Apresentação de Slides (NotebookLM)
Utilize esta estrutura ao pedir para o NotebookLM gerar a apresentação, resumo de áudio ou tópicos de slides:

- **Slide 1: Título & Introdução:** Bibliotech — Transformação do Back-end em Padrão Corporativo. Apresentar o sistema como uma solução completa para bibliotecas modernas, com interface web administrativa e API RESTful.
- **Slide 2: O Desafio Inicial:** Identificação de gargalos anteriores: ausência de relatórios gerenciais, fragilidades no login, falha na fila de reservas e código com acoplamento manual de dependências.
- **Slide 3: Arquitetura & Boas Práticas:** Adoção de Clean Architecture, 12-Factor App (`.env`), Autoloader PSR-4 nativo, Composer e Logging Estruturado em JSON. Segurança LGPD com criptografia AES-256.
- **Slide 4: A Nova Regra de Empréstimos e Renovações:** Apresentar o fluxo de renovação controlada: por que a aprovação da bibliotecária é indispensável para evitar que livros fiquem retidos indevidamente e prejudiquem alunos na fila.
- **Slide 5: Inteligência de Dados & Relatórios:** Demonstração dos novos relatórios gerenciais por turma, curso e situação de matrícula. Separação clara de ID de empréstimo e ID de usuário para relatórios precisos.
- **Slide 6: API REST Pronta para o Futuro:** Catálogo público para visitantes, endpoints paginados, suporte a Bearer Token e prontidão para receber novos front-ends modernos.
- **Slide 7: Conclusão & Resultados de QA:** 100% dos testes de sintaxe e testes de integração aprovados. Software seguro, manutenível e escalável.
