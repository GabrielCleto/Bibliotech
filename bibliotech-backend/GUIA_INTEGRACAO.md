# Guia do Back-end — Bibliotech (Biblioteca Virtual)

Este documento descreve **tudo** que o back-end já entrega, pra quem for
construir o front-end (pessoa ou IA) entender exatamente o que já existe,
como usar, e o que ainda precisa ser decidido. Não presuma nada que não
esteja escrito aqui — se alguma informação estiver faltando, pergunte
antes de inventar um comportamento.

## 1. Visão geral

- Linguagem: **PHP** (orientado a objetos), banco **MySQL** (nome: `biblioteca`)
- Servido pelo **Apache** (XAMPP), dentro da pasta do projeto (o nome da pasta tanto faz: o `public/index.php` ignora tudo que vem antes de `/api/`). O arquivo `.htaccess` na raiz do projeto precisa estar junto (ele manda `/api/...` pro `public/index.php`)
- Duas formas de acessar o sistema:
  1. **Páginas HTML prontas** (pasta `paginas/`) — funcionam sozinhas, sem
     JavaScript, servem de referência/fallback
  2. **API em JSON** (rotas `/api/...`) — pensada pra quem for montar telas
     customizadas em JavaScript (ex: um carrossel de livros)
- **As duas formas usam a mesma autenticação por sessão** (ver seção 2).
  Não existe autenticação por token/JWT — é sessão de navegador (cookie).

## 2. Autenticação

- **Web (Páginas HTML):** Login feito em `paginas/login.php` (e-mail + senha com proteção CSRF). Sessão nativa PHP armazenada em cookie.
- **API REST (`/api/...`):** Suporta duas modalidades flexíveis:
  1. **Sessão via Cookie:** Se a chamada vier de um navegador na mesma origem, a sessão do PHP é detectada automaticamente.
  2. **Cabeçalho de Autenticação (Mobile / SPA / Clientes Externos):** Aceita `Authorization: Bearer <id_ou_token>` ou `X-User-Id: <id>`, dispensando cookies e facilitando integrações desacopladas.
- Tipos de usuário: `aluno`, `professor`, `bibliotecario`, `admin`.
- Controle de acesso unificado: `CurrentUser::requireActiveAccount` garante que contas inativas sejam bloqueadas em tempo real tanto na Web quanto na API.

## 3. Proteção CSRF

Toda ação das páginas HTML que modifica dados (aprovar, recusar, cadastrar, editar, remover, solicitar) exige um campo `csrf_token` no `POST`, validado com a sessão. A API REST utiliza autenticação por cabeçalho/sessão com JSON Payload e dispensa tokens CSRF.

## 4. Páginas HTML prontas (pasta `paginas/`)

Todas exigem estar logado (redirecionam pra `login.php` se não estiver). Servem de referência de comportamento e fallback administrativo.

| Arquivo | Quem pode acessar | O que faz |
|---|---|---|
| `login.php` | Todos (não logado) | Formulário de login com token CSRF e feedback uniforme |
| `gerenciar_usuarios.php` | Só `bibliotecario`/`admin` | Gerenciamento de contas (criação, edição e ativação/inativação) |
| `logout.php` | Logado | Encerra a sessão |
| `principal.php` | Logado | Dashboard e menu de acesso rápido por perfil |
| `livros.php` | Logado | Acervo geral com paginação e busca |
| `meus_emprestimos.php` | Logado (aluno/professor) | Histórico de empréstimos, multas e botão de renovação |
| `cadastro_livro.php` | Só `bibliotecario`/`admin` | Cadastro transacional de livros com novos autores/gêneros |
| `editar_livro.php?id=X` | Só `bibliotecario`/`admin` | Edição com checagem de existência prévia |
| `solicitar_emprestimo.php` | Só `aluno`/`professor` | Criação de pedido de empréstimo pendente |
| `aprovacoes.php` | Só `bibliotecario`/`admin` | Aprovação/recusa de novos empréstimos e renovações |
| `devolucoes.php` | Só `bibliotecario`/`admin` | Registro de devoluções com cálculo de multas |
| `multas.php` | Só `bibliotecario`/`admin` | Quitação e gestão de multas |
| `lista_desejos.php` | Logado (qualquer tipo) | Lista de desejos e fila de reserva única atômica |
| `notificacoes.php` | Logado (qualquer tipo) | Central de notificações |
| `relatorio.php` | Só `bibliotecario`/`admin` | Relatórios gerenciais e histórico detalhado |

## 5. API em JSON (rotas `/api/...`)

A API entrega respostas padronizadas em JSON com CORS habilitado para integração moderna.

| Método | Rota | Autenticação | O que faz |
|---|---|---|---|
| GET | `/api/books` | Pública | Lista acervo. Suporta `?search=termo` e paginação com `?page=1&per_page=20` |
| GET | `/api/books/{bookId}` | Pública | Detalhes completos de um livro específico |
| POST | `/api/books/{bookId}/request` | Requer login | Solicita empréstimo de um livro para o usuário logado |
| GET | `/api/my-loans` | Requer login | Histórico de empréstimos do usuário autenticado com status calculado |
| POST | `/api/my-loans` | Requer login | Solicita empréstimo via payload `{"id_livro": X}` |
| POST | `/api/my-loans/{loanId}/renew` | Requer login | Solicita renovação de empréstimo ativo |
| POST | `/api/loans/{loanId}/approve-renewal` | Bibliotecário | Aprova solicitação de renovação |
| POST | `/api/loans/{loanId}/reject-renewal` | Bibliotecário | Recusa solicitação de renovação |
| GET | `/api/wishlist?page=1&per_page=20&sort=newest\|title` | Requer login | Lista de desejos do usuário |
| POST | `/api/wishlist/{bookId}` | Requer login | Adiciona livro à lista de desejos (com fila se zerado) |
| DELETE | `/api/wishlist/{bookId}` | Requer login | Remove livro da lista de desejos |
| GET | `/api/notifications?unread=1` | Requer login | Lista notificações |
| PUT | `/api/notifications/{id}/read` | Requer login | Marca notificação individual como lida |
| PUT | `/api/notifications/read-all` | Requer login | Marca todas as notificações como lidas |
| GET | `/api/reports/popular-books?limit=20` | Bibliotecário | Livros mais emprestados |
| GET | `/api/reports/loans?status=all\|active\|overdue\|completed\|reserved` | Bibliotecário | Lista consolidada de empréstimos |
| GET | `/api/reports/active-users?limit=20` | Bibliotecário | Usuários mais ativos |
| GET | `/api/reports/by-class?id_turma=&id_curso=` | Bibliotecário | Consolidado por turma |
| GET | `/api/reports/by-course?id_curso=` | Bibliotecário | Consolidado por curso |
| GET | `/api/reports/by-situation?situacao=` | Bibliotecário | Consolidado por situação de aluno |
| GET | `/api/reports/loan-history` | Bibliotecário | Histórico completo e filtrado |

Todas as respostas de sucesso vêm no formato:
```json
{ "data": ... }
```
E erros:
```json
{ "error": { "code": "codigo_do_erro", "message": "Mensagem descritiva em português" } }
```
E erros assim:
```json
{ "error": { "code": "algo_snake_case", "message": "Mensagem em português" } }
```

## 6. Regras de negócio que o front-end precisa saber

- **Prazo de empréstimo**: 15 dias corridos para aluno, 30 para professor
  — contados a partir da **aprovação**, não da solicitação
- **Renovação**: a renovação **necessita de aprovação da bibliotecária**. O aluno ou professor solicita a renovação (o empréstimo ganha o status de aguardando aprovação de renovação). A bibliotecária aprova ou recusa na tela `aprovacoes.php` ou via API (`POST /api/loans/{id}/approve-renewal`). Só é permitido renovar um empréstimo ativo **uma única vez**, ganhando mais um prazo inteiro (15 ou 30 dias) a partir da data atual de devolução prevista. Não é possível renovar se: já estiver atrasado, já tiver sido renovado antes, ou tiver alguém esperando aquele livro na fila de reserva

- **Limite**: no máximo 3 livros por pessoa, somando os já emprestados
  com os ainda pendentes de aprovação
- **Não dá pra pedir o mesmo livro duas vezes** enquanto já tiver um
  ativo ou pendente dele
- **Conta ativa** (atualizado 03/10): a tabela `aluno` separada foi
  removida do banco — agora é tudo uma tabela `usuario` só, e o campo
  `situacao` próprio do aluno não existe mais. O que decide se a
  pessoa pode solicitar empréstimo é simplesmente `usuario.ativo`
  (verdadeiro/falso), que só a bibliotecária muda. Não existe mais uma
  "situação de matrícula" separada do status de login
- **Atraso e multa**: se devolver depois do prazo, o sistema calcula os
  dias de atraso **e o valor em reais** (dias × valor da multa diária,
  configurável na tabela `configuracao`) automaticamente. Enquanto
  tiver multa pendente (ou livro atrasado sem devolver), a pessoa **não
  consegue solicitar** nenhum livro novo, até a bibliotecária quitar a
  multa ou o livro ser devolvido
- **Fila de espera**: ao adicionar na lista de desejos um livro sem
  cópia disponível, a pessoa entra automaticamente na fila daquele
  livro (quem cria a reserva é a procedure `sp_criar_reserva` do banco).
  Quando uma cópia libera por **devolução** (procedure
  `sp_devolver_livro`), a primeira pessoa da fila recebe uma
  notificação — mas a cópia **não fica reservada** pra ela, é só um
  aviso; quem solicitar primeiro leva
- **Só aluno e professor** pegam livro emprestado. Bibliotecário só
  gerencia (cadastra, aprova, recebe devolução) — nunca aparece como
  "dono" de um empréstimo
- **Dados pessoais criptografados**: CPF, telefone, endereço e data de
  nascimento (de aluno/professor/bibliotecário) ficam criptografados no
  banco. Só a tela `gerenciar_usuarios.php` (bibliotecária) descriptografa
  pra mostrar no formulário de edição — nenhuma outra tela/rota devolve
  esses campos
- **Estoque e empréstimo ficam por conta do banco** (atualizado 05/10,
  pedido do grupo de BD): o pedido de empréstimo fica *pendente* e
  **não tira cópia do estoque**. Quando a bibliotecária **aprova**, o
  PHP chama a procedure `sp_realizar_emprestimo`, que confere tudo de
  novo (usuário ativo, multa, atraso, limite, mesmo livro já
  emprestado, exemplar disponível), tira a cópia do estoque e cria o
  empréstimo (15 dias aluno / 30 professor). A devolução chama
  `sp_devolver_livro` (estoque, multa e aviso da fila). Se o banco
  recusar, a **mensagem do banco aparece na tela** (ex.: "Usuário possui
  multa pendente.", "Nenhum exemplar disponível para empréstimo.",
  "Usuário está inativo e não pode realizar empréstimos.")
- **Consequências disso pro front-end** (atualizado 05/10):
  - dois pedidos pendentes do último exemplar são possíveis; o 2º a
    ser aprovado recebe "Nenhum exemplar disponível para empréstimo."
    e continua pendente (a bibliotecária pode recusar)
  - ao aprovar, o pedido pendente é apagado e o banco cria **um
    empréstimo novo com outro `id_emprestimo`**. O id do pedido não é o
    id do empréstimo
  - recusar um pedido não mexe no estoque (nunca tirou)
  - o limite de livros vem da tabela `configuracao` (não é mais um
    número fixo no PHP)
- **Aluno não tem mais "matrícula"** (atualização 03/10) — só RA. Quem
  tem matrícula é só o professor. Se o front-end tinha um campo de
  matrícula no formulário de aluno, precisa tirar
- **Turma é obrigatória pra aluno** (atualização 03/10) e agora é uma
  referência de verdade (`id_turma`) pras tabelas novas `turma` e
  `curso` — não é mais um texto livre. O formulário de criar/editar
  aluno precisa de uma lista suspensa com as turmas existentes
  (curso + turno + ano), não um campo de texto
- **Formato de data**: as páginas HTML (`paginas/*.php`) agora mostram
  toda data em formato brasileiro (`dd/mm/aaaa`, e `dd/mm/aaaa hh:mm`
  pra notificação). A **API JSON continua devolvendo ISO**
  (`aaaa-mm-dd`), igual sempre foi — é o formato padrão pra API, e o
  front-end formata como quiser na tela dele

## 7. O que ainda não existe / decisões em aberto

- Tipo `admin` existe no banco, mas nenhuma funcionalidade usa ele de
  forma diferente de `bibliotecario`
- Não existe tela de "esqueci minha senha" (colunas
  `token_redefinicao`/`token_expiracao` existem no banco, mas sem uso)
- Sugestão (não implementada): usar `/api/books` pra montar uma
  exibição visual do acervo (tipo carrossel/roleta), já que ele devolve
  o acervo completo em JSON

## 8. Ambiente de teste (dados de exemplo já no banco)

Todos os usuários de teste têm senha `123456`. Alguns e-mails de
exemplo: `felipe@gmail.com` (aluno), `roberto.nogueira@escola.edu.br`
(professor), `patricia.lima@escola.edu.br` (bibliotecário).

## 9. Atualização de banco de dados (03/10) — resumo pra quem só quer o essencial

- Tabelas `aluno`, `professor` e `bibliotecario` **sumiram**: agora é
  tudo uma tabela `usuario` só, com os campos de cada tipo direto nela
  (RA/turma pra aluno; matrícula/disciplina pra professor). Isso é
  invisível pro front-end (nenhuma rota/página mudou de endereço por
  causa disso), mas o modelo de dados mudou se algum dia o front
  precisar montar uma consulta direto no banco
- Autocadastro público **não existe mais** (seção 2 e tabela da seção 4)
- Aluno não tem mais matrícula, só RA; e turma passou a ser obrigatória
  e vem de uma lista (curso + turno + ano), não texto livre
- Datas nas páginas HTML em formato brasileiro; API continua em ISO
- Corrigido um bug de fuso horário: o PHP agora usa explicitamente o
  horário de Brasília (antes usava UTC por padrão, o que podia fazer a
  data de hoje ficar um dia errada entre 21h e meia-noite, afetando
  cálculo de multa/atraso/renovação)


## Importar o banco no XAMPP (atualizado 05/10)

O phpMyAdmin do XAMPP importa com a regra de comparação de texto
`utf8mb4_unicode_ci`, mas o banco do script usa `utf8mb4_general_ci`.
Isso dava o erro `1267 Illegal mix of collations ... for operation
'like'` na tela `gerenciar_usuarios.php` (procedure `sp_listar_alunos`).
Pra evitar: apagar o banco `biblioteca` e reimportar o `.sql` com esta
linha **no topo do arquivo**, antes de qualquer outra coisa:

    SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

Mudar a conexão do PHP **não resolve** (testado). Mesmo assim, o PHP
agora tem uma proteção: se a procedure falhar por esse motivo, a tela
lê os alunos direto da view `vw_alunos` e continua funcionando.

## Cadastro de usuário: campos e regras (atualizado 05/10)

Formulário de **criar** (`gerenciar_usuarios.php`, `acao=criar`). Nomes
dos campos (cada nome aparece **uma vez só** no formulário — o `cpf` é um
campo único, usado por aluno e bibliotecário; se aparecer duas vezes, o
navegador manda os dois e só o último chega):

| Tipo | Campos obrigatórios |
|---|---|
| todos | `nome` (até 100), `email` (até 150), `senha` (6 a 72 caracteres), `tipo`, `telefone` |
| aluno | `ra`, `id_turma`, `cpf`, `data_nascimento`, `endereco` |
| professor | `disciplina` (até 50), `matricula` |
| bibliotecario | `cpf` |

Regras que o PHP confere antes de salvar (e a mensagem que volta):
- `ra` e `matricula`: só números, maior que zero (não aceita vazio, letra, negativo, zero ou decimal)
- `id_turma`: precisa existir na tabela `turma`
- `cpf`: 11 números (aceita com pontos e traço; é guardado só com números, então o mesmo CPF em formatos diferentes é reconhecido como repetido). **Não** confere os dígitos verificadores
- `telefone`: de 8 a 11 números (com ou sem DDD; guardado só com números)
- `data_nascimento`: `aaaa-mm-dd`, data real e que já passou
- `endereco`: até 200 caracteres
- Repetidos: a mensagem diz qual foi (e-mail, RA, CPF ou matrícula)
- Na **edição** (`acao=atualizar`) campo em branco = "não muda". O tipo da conta vem do banco, não do formulário
- **Não dá pra inativar quem tem empréstimo ativo** (ainda não devolvido, mesmo atrasado): a tela responde "Não é possível inativar: a pessoa tem N empréstimo(s) ativo(s). Registre a devolução antes.". Pedido pendente de aprovação e multa pendente **não** impedem. Ativar sempre pode
- `acao=alterar_status` exige `id_usuario` e `novo_status` igual a `0` ou `1`. Faltando ou outro valor: recusa (antes, inativava sem avisar)

## Conta inativada com a pessoa logada (atualizado 05/10)

Se a bibliotecária inativar alguém que já está logado, na **próxima
página** que a pessoa abrir a sessão é encerrada e ela volta pro
`login.php` (resposta `302`). Na API (`/api/...`) a primeira chamada
devolve `403` com `{"error":{"code":"account_inactive", ...}}` e as
seguintes `401`. O front-end deve tratar esses dois casos mandando a
pessoa pro login.
