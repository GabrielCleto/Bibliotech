<?php
// relatorio.php — tela de relatórios pra bibliotecário, em HTML
// simples (sem JSON, sem código pra ninguém ver).

/* ============================================================
   BLOCO 1 - INÍCIO: sessão e arquivos que a página precisa
   Aqui a página prepara tudo antes de fazer qualquer coisa
   ============================================================ */

// Liga a sessão, pra gente conseguir ler os dados de quem fez login ($_SESSION)
session_start();

// Puxa os arquivos de fora (require = obrigatório, se faltar dá erro)
// __DIR__ é a pasta onde esse arquivo está, e o ../ volta uma pasta
require __DIR__ . "/../config/SemCache.php";
// Função nossa que faz o navegador não guardar a página em cache
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";

/* ============================================================
   BLOCO 2 - SEGURANÇA: login, conta ativa e permissão
   Só entra quem está logado e é bibliotecário ou admin
   ============================================================ */

// Se não existe id_usuario na sessão, a pessoa não fez login
// Então manda pro login.php e o exit para o código aqui
if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

// Pega a conexão com o banco de dados (classe Conexao)
$conexao = Conexao::getConexao();
// Função nossa que confere se a conta do usuário está ativa
exigirContaAtiva($conexao);

// Guarda o tipo do usuário (aluno, bibliotecário, admin...)
$tipo = $_SESSION["tipo_usuario"];
// Se não for bibliotecário nem admin, bloqueia
// 403 é o código de "acesso proibido"
if ($tipo !== "bibliotecario" && $tipo !== "admin") {
    http_response_code(403);
    exit("Acesso restrito a bibliotecários.");
}

// Fecha a sessão pra escrita, porque daqui pra frente só vamos ler
// Assim outras páginas do sistema não ficam esperando essa terminar
session_write_close();

/* ============================================================
   BLOCO 3 - ESCOLHA DO RELATÓRIO
   Pega pela URL qual relatório o bibliotecário clicou
   ============================================================ */

// Qual relatório mostrar (vem de um link, ex: ?relatorio=populares)
// O ?? usa "populares" como padrão se não vier nada na URL
$relatorioEscolhido = $_GET["relatorio"] ?? "populares";

/* ============================================================
   BLOCO 4 - MONTAGEM DO SQL (um if/elseif pra cada relatório)
   Em cada um a gente define o $titulo (que aparece na tela)
   e o $sql (a consulta que busca os dados no banco)

   Palavras do SQL que mais aparecem aqui:
   - INNER JOIN: junta tabelas, só traz quem tem ligação nas duas
   - LEFT JOIN: junta tabelas, mas traz tudo da tabela da esquerda
     mesmo se não tiver ligação (fica vazio/NULL)
   - COUNT: conta quantos registros
   - GROUP BY: agrupa os registros iguais pra poder contar/somar
   - CASE WHEN: é um if dentro do SQL
   - COALESCE: se o valor for NULL, troca por outro valor
   - CURDATE(): data de hoje
   - DATE_FORMAT: muda a data pro formato dia/mês/ano
   - ORDER BY: ordena / LIMIT: limita quantos resultados
   ============================================================ */

// ---- Relatório 1: lista de todos os empréstimos ----
if ($relatorioEscolhido === "emprestimos") {
    $titulo = "Empréstimos";
    // Datas já formatadas em dia/mês/ano direto na consulta: essa
    // tela monta a tabela de forma genérica (olhando as colunas que
    // vierem), então é mais simples formatar aqui do que no PHP

    // Junta emprestimo + usuario + livros pra mostrar quem pegou qual livro
    // O CASE cria a coluna "status":
    //   Atrasado = ativo, não devolveu e a data prevista já passou
    //   Ativo = ativo e ainda não devolveu
    //   Concluído = qualquer outro caso (já devolveu)
    // Ordena do empréstimo mais novo pro mais antigo
    $sql = "SELECT E.id_emprestimo,
                   U.id_usuario,
                   U.nome AS usuario,
                   U.tipo_usuario,
                   L.titulo,
                   DATE_FORMAT(E.data_emp, '%d/%m/%Y') AS data_emp,
                   DATE_FORMAT(E.data_dev_prevista, '%d/%m/%Y') AS data_dev_prevista,
                   DATE_FORMAT(E.data_dev, '%d/%m/%Y') AS data_dev,
                   CASE
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            INNER JOIN livros L ON L.id_livro = E.id_livro
            ORDER BY E.data_emp DESC";

// ---- Relatório 2: usuários que mais pegaram livros ----
} elseif ($relatorioEscolhido === "usuarios") {
    $titulo = "Usuários mais ativos";
    // COUNT conta os empréstimos de cada usuário
    // MAX(data_emp) pega a data do empréstimo mais recente (última atividade)
    // Ordena de quem tem mais pra quem tem menos e mostra só os 20 primeiros
    $sql = "SELECT U.nome, U.tipo_usuario, COUNT(E.id_emprestimo) AS total_emprestimos,
                   DATE_FORMAT(MAX(E.data_emp), '%d/%m/%Y') AS ultima_atividade
            FROM usuario U
            INNER JOIN emprestimo E ON E.id_usuario = U.id_usuario
            GROUP BY U.id_usuario, U.nome, U.tipo_usuario
            ORDER BY total_emprestimos DESC
            LIMIT 20";

// ---- Relatório 3: empréstimos agrupados por turma ----
} elseif ($relatorioEscolhido === "turma") {
    $titulo = "Empréstimos por Turma";
    // Liga turma -> curso e turma -> aluno -> emprestimo
    // Usa LEFT JOIN pra turma aparecer mesmo sem aluno ou sem empréstimo
    // COUNT(DISTINCT ...) conta sem repetir (cada aluno/empréstimo uma vez só)
    // Os SUM(CASE...) contam quantos estão ativos, atrasados e concluídos
    // COALESCE troca o NULL por 0 quando não tem nada
    $sql = "SELECT C.nome_curso AS curso, T.turno, T.ano_calendario AS ano, T.periodo_letivo AS periodo,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM turma T
            INNER JOIN curso C ON C.id_curso = T.id_curso
            LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY T.id_turma, C.nome_curso, T.turno, T.ano_calendario, T.periodo_letivo
            ORDER BY C.nome_curso, T.ano_calendario DESC, T.turno";

// ---- Relatório 4: empréstimos agrupados por curso ----
} elseif ($relatorioEscolhido === "curso") {
    $titulo = "Empréstimos por Curso";
    // Parecido com o da turma, mas agrupando por curso
    // IF(C.Mtec = 1, 'Sim', 'Não') mostra se o curso é Mtec ou não
    // Também conta quantas turmas o curso tem
    $sql = "SELECT C.nome_curso AS curso,
                   IF(C.Mtec = 1, 'Sim', 'Não') AS mtec,
                   COUNT(DISTINCT T.id_turma) AS total_turmas,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM curso C
            LEFT JOIN turma T ON T.id_curso = C.id_curso
            LEFT JOIN aluno AL ON AL.id_turma = T.id_turma
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY C.id_curso, C.nome_curso, C.Mtec
            ORDER BY C.nome_curso";

// ---- Relatório 5: empréstimos pela situação do aluno ----
} elseif ($relatorioEscolhido === "situacao") {
    $titulo = "Empréstimos por Situação do Aluno";
    // Agrupa pela situação do aluno (ex: cursando, formado...)
    // Se a situação for NULL, o COALESCE mostra 'Não informado'
    $sql = "SELECT COALESCE(AL.situacao, 'Não informado') AS situacao,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM aluno AL
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY AL.situacao
            ORDER BY AL.situacao";

// ---- Relatório 6: histórico completo (o mais detalhado) ----
} elseif ($relatorioEscolhido === "historico") {
    $titulo = "Histórico Detalhado de Empréstimos";
    // Junta várias tabelas: emprestimo, usuario, aluno, turma, curso e livros
    // Usa LEFT JOIN no aluno/turma/curso porque nem todo usuário é aluno
    // (quem não é aluno fica com '-' no lugar, por causa do COALESCE)
    // A multa é formatada em reais: FORMAT põe 2 casas decimais e
    // o REPLACE troca o ponto pela vírgula
    // No status entra mais uma opção: 'Aguardando aprovação' (reserva)
    $sql = "SELECT E.id_emprestimo,
                   U.id_usuario,
                   U.nome AS usuario,
                   U.tipo_usuario AS tipo,
                   COALESCE(AL.RA, '-') AS ra,
                   COALESCE(C.nome_curso, '-') AS curso,
                   COALESCE(T.turno, '-') AS turno,
                   COALESCE(AL.situacao, '-') AS situacao_aluno,
                   L.titulo AS livro,
                   DATE_FORMAT(E.data_emp, '%d/%m/%Y') AS data_emprestimo,
                   DATE_FORMAT(E.data_dev_prevista, '%d/%m/%Y') AS data_prevista,
                   IF(E.data_dev IS NOT NULL, DATE_FORMAT(E.data_dev, '%d/%m/%Y'), '-') AS devolucao,
                   CONCAT('R$ ', REPLACE(FORMAT(E.valor_multa, 2), '.', ',')) AS multa,
                   CASE
                       WHEN E.reserva = 1 THEN 'Aguardando aprovação'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            LEFT JOIN aluno AL ON AL.id_usuario = U.id_usuario
            LEFT JOIN turma T ON T.id_turma = AL.id_turma
            LEFT JOIN curso C ON C.id_curso = T.id_curso
            INNER JOIN livros L ON L.id_livro = E.id_livro
            ORDER BY E.data_emp DESC, E.id_emprestimo DESC";

// ---- Relatório 7 (padrão): livros mais populares ----
} else {
    // Cai aqui se for "populares" ou qualquer valor que não existe
    // Se alguém digitar algo errado na URL, volta pro "populares"
    $relatorioEscolhido = "populares";
    $titulo = "Livros mais populares";
    // Conta quantas vezes cada livro foi emprestado
    // LEFT JOIN pra livro sem empréstimo aparecer com 0
    // Top 20, do mais emprestado pro menos
    $sql = "SELECT L.titulo, A.nome_autor AS autor, COUNT(E.id_emprestimo) AS total_emprestimos
            FROM livros L
            INNER JOIN autores A ON A.id_autor = L.id_autor
            LEFT JOIN emprestimo E ON E.id_livro = L.id_livro
            GROUP BY L.id_livro, L.titulo, A.nome_autor
            ORDER BY total_emprestimos DESC
            LIMIT 20";
}

/* ============================================================
   BLOCO 5 - EXECUTA A CONSULTA
   ============================================================ */

// query() roda o SQL que foi escolhido no bloco 4
// fetchAll() pega todas as linhas e guarda num array ($linhas)
$linhas = $conexao->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <title>Relatórios - Biblioteca Virtual</title>
</head>
<body>
    <h2>Relatórios</h2>

    <!-- BLOCO 6 - MENU: links que mudam o relatório pela URL (?relatorio=...) -->
    <p>
        <a href="?relatorio=populares">Livros populares</a> |
        <a href="?relatorio=emprestimos">Empréstimos</a> |
        <a href="?relatorio=usuarios">Usuários ativos</a> |
        <a href="?relatorio=turma">Por turma</a> |
        <a href="?relatorio=curso">Por curso</a> |
        <a href="?relatorio=situacao">Por situação</a> |
        <a href="?relatorio=historico">Histórico detalhado</a>
    </p>

    <!-- Mostra o título do relatório escolhido -->
    <!-- htmlspecialchars transforma caracteres especiais (< > " ') em texto normal,
         pra ninguém conseguir colocar código malicioso na tela -->
    <h3><?= htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") ?></h3>

    <!-- BLOCO 7 - TABELA: montada de forma automática -->
    <table border="1" cellpadding="6">
        <!-- Se o array tem alguma linha, monta a tabela. Se não, cai no else lá embaixo -->
        <?php if (!empty($linhas)): ?>
            <tr>
                <!-- Cabeçalho: pega o nome das colunas da primeira linha (array_keys)
                     por isso a tabela serve pra qualquer relatório -->
                <?php foreach (array_keys($linhas[0]) as $coluna): ?>
                    <th><?= htmlspecialchars((string) $coluna, ENT_QUOTES, "UTF-8") ?></th>
                <?php endforeach; ?>
            </tr>
            <!-- Corpo: o primeiro foreach cria uma linha (tr) pra cada registro -->
            <?php foreach ($linhas as $linha): ?>
                <tr>
                    <!-- O segundo foreach cria uma célula (td) pra cada valor da linha -->
                    <?php foreach ($linha as $valor): ?>
                        <td><?= htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8") ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Se a consulta não trouxe nada, mostra essa mensagem -->
            <tr>
                <td style="padding: 15px; text-align: center;">Nenhum registro encontrado para este relatório.</td>
            </tr>
        <?php endif; ?>
    </table>

    <p><a href="principal.php">Voltar</a></p>
</body>
</html>