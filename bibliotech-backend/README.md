# Bibliotech API

API RESTful em PHP 8+ para a Biblioteca Virtual. Todos os endpoints retornam JSON e usam PDO com prepared statements.

## Configuração

Roda dentro do mesmo projeto (pasta no `htdocs` do XAMPP), servido pelo Apache do XAMPP (sem precisar de servidor separado). As rotas ficam disponíveis em:

```
http://localhost/<pasta-do-projeto>/api/...
```

Isso funciona graças ao arquivo `.htaccess` na raiz do projeto, que redireciona qualquer endereço começando com `api/` para `public/index.php`.

Execute `database/migrations/001_wishlist_notifications.sql` no banco `biblioteca` (já criado pelo script principal) antes de usar essas rotas.

## Autenticação

A API usa a **sessão do PHP** pra saber quem está logado — a mesma sessão criada pelo login (`paginas/login.php`). Não existe mais autenticação por cabeçalho (`X-User-Id`); isso foi removido por ser inseguro (permitia qualquer pessoa se passar por outro usuário).

Pra chamar qualquer rota abaixo, é preciso ter feito login antes (na mesma aba/navegador), pra sessão já existir.

## Rotas

- `GET /api/books` (lista o acervo — livro, autor, categoria, quantidade disponível)
- `POST /api/books/{bookId}/request` (solicita empréstimo de um livro para a pessoa logada)
- `GET /api/my-loans` (histórico de empréstimos da pessoa logada, com status calculado)
- `POST /api/my-loans` (solicita empréstimo passando `{"id_livro": X}` no corpo JSON)
- `POST /api/my-loans/{loanId}/renew` (solicita renovação de empréstimo ativo; requer aprovação da bibliotecária)
- `POST /api/loans/{loanId}/approve-renewal` (bibliotecária/admin aprova a renovação)
- `POST /api/loans/{loanId}/reject-renewal` (bibliotecária/admin recusa a renovação)

- `GET /api/wishlist?page=1&per_page=20&sort=newest|title`
- `POST /api/wishlist/{bookId}`
- `DELETE /api/wishlist/{bookId}`
- `GET /api/notifications?unread=1`
- `PUT /api/notifications/{notificationId}/read`
- `PUT /api/notifications/read-all`
- `GET /api/reports/popular-books?limit=20`
- `GET /api/reports/loans?status=all|active|overdue|completed|reserved`
- `GET /api/reports/active-users?limit=20`
- `GET /api/reports/by-class?id_turma=&id_curso=` (relatório consolidado por turma)
- `GET /api/reports/by-course?id_curso=` (relatório consolidado por curso)
- `GET /api/reports/by-situation?situacao=` (relatório consolidado por situação do aluno)
- `GET /api/reports/loan-history?status=&id_usuario=&id_livro=&id_curso=&id_turma=&situacao=&data_inicio=&data_fim=&page=1&limit=50` (histórico completo de empréstimos com dados de turma, curso e situação)

Os relatórios exigem usuário com `tipo_usuario` `admin` ou `bibliotecario`.

## Notificações internas

A criação de notificações deve ser chamada por um job/cron da aplicação. Exemplos de regras são livros da wishlist que voltaram a ter disponibilidade e empréstimos próximos do vencimento.

Execute `php bin/generate_notifications.php` periodicamente (por exemplo, via Agendador de Tarefas do Windows, já que o servidor é local) para aplicar essas regras sem duplicar avisos recentes.
