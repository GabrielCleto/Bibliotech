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
// O ?? usa "populares" como padrão se não vier nada válido na URL
$relatorioEscolhido = is_string($_GET["relatorio"] ?? null) ? $_GET["relatorio"] : "populares";

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
                       WHEN E.emp_ativo = 1 AND E.reserva = 1 THEN 'Aguardando aprovação de renovação'
                       WHEN E.reserva = 1 THEN 'Aguardando aprovação'
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
    // Liga turma -> curso e turma -> usuario (tipo aluno) -> emprestimo
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
            LEFT JOIN usuario AL ON AL.id_turma = T.id_turma AND AL.tipo_usuario = 'aluno'
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
            LEFT JOIN usuario AL ON AL.id_turma = T.id_turma AND AL.tipo_usuario = 'aluno'
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            GROUP BY C.id_curso, C.nome_curso, C.Mtec
            ORDER BY C.nome_curso";

// ---- Relatório 5: empréstimos pela situação do aluno ----
} elseif ($relatorioEscolhido === "situacao") {
    $titulo = "Empréstimos por Situação do Aluno";
    // Agrupa pela situação da conta do aluno na tabela unificada usuario (Ativo / Inativo)
    $sql = "SELECT IF(AL.ativo = 1, 'Ativo', 'Inativo') AS situacao,
                   COUNT(DISTINCT AL.RA) AS total_alunos,
                   COUNT(DISTINCT E.id_emprestimo) AS total_emprestimos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista >= CURDATE() THEN 1 ELSE 0 END), 0) AS ativos,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 1 ELSE 0 END), 0) AS atrasados,
                   COALESCE(SUM(CASE WHEN E.emp_ativo = 0 AND E.data_dev IS NOT NULL THEN 1 ELSE 0 END), 0) AS concluidos
            FROM usuario AL
            LEFT JOIN emprestimo E ON E.id_usuario = AL.id_usuario
            WHERE AL.tipo_usuario = 'aluno'
            GROUP BY AL.ativo
            ORDER BY situacao";

// ---- Relatório 6: histórico completo (o mais detalhado) ----
} elseif ($relatorioEscolhido === "historico") {
    $titulo = "Histórico Detalhado de Empréstimos";
    // Junta várias tabelas: emprestimo, usuario (unificado), turma, curso e livros
    // Usa LEFT JOIN em turma/curso porque nem todo usuário é aluno
    // (quem não é aluno fica com '-' no lugar, por causa do COALESCE)
    // A multa é formatada em reais: FORMAT põe 2 casas decimais e
    // o REPLACE troca o ponto pela vírgula
    // No status distingue renovação pendente de reserva inicial pendente
    $sql = "SELECT E.id_emprestimo,
                   U.id_usuario,
                   U.nome AS usuario,
                   U.tipo_usuario AS tipo,
                   COALESCE(CAST(U.RA AS CHAR), '-') AS ra,
                   COALESCE(C.nome_curso, '-') AS curso,
                   COALESCE(T.turno, '-') AS turno,
                   CASE WHEN U.tipo_usuario = 'aluno' THEN IF(U.ativo = 1, 'Ativo', 'Inativo') ELSE '-' END AS situacao_aluno,
                   L.titulo AS livro,
                   DATE_FORMAT(E.data_emp, '%d/%m/%Y') AS data_emprestimo,
                   DATE_FORMAT(E.data_dev_prevista, '%d/%m/%Y') AS data_prevista,
                   IF(E.data_dev IS NOT NULL, DATE_FORMAT(E.data_dev, '%d/%m/%Y'), '-') AS devolucao,
                   CONCAT('R$ ', REPLACE(FORMAT(E.valor_multa, 2), '.', ',')) AS multa,
                   CASE
                       WHEN E.emp_ativo = 1 AND E.reserva = 1 THEN 'Aguardando aprovação de renovação'
                       WHEN E.reserva = 1 THEN 'Aguardando aprovação'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL AND E.data_dev_prevista < CURDATE() THEN 'Atrasado'
                       WHEN E.emp_ativo = 1 AND E.data_dev IS NULL THEN 'Ativo'
                       ELSE 'Concluído'
                   END AS status
            FROM emprestimo E
            INNER JOIN usuario U ON U.id_usuario = E.id_usuario
            LEFT JOIN turma T ON T.id_turma = U.id_turma
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
<!-- Cabeçalho da página e importação do CSS -->
<head>
    <?= sem_cache_js() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Estilos globais e estilo do relatório -->
    <link rel="stylesheet" href="./CSS/principal.css">
    <link rel="stylesheet" href="./CSS/relatorio.css">
    <title>Relatórios - Bibliotech</title>
</head>
<body>

    <!-- RENDER BACKGROUND -->
    <div class="RENDER BACKGROUND">
        <img src="./source/Arthur/home/Group 631.png" alt="Background">
    </div>

<!-- Container principal dos relatórios -->
<main class="relatorios">

    <!-- Botão de voltar ao menu principal -->
    <a class="voltar" href="principal.php">
        &larr; Voltar ao menu principal
    </a>

    <!-- Título e descrição da página -->
    <h1>Relatórios</h1>

    <p class="descricao">
        Consulte os empréstimos, as turmas, os cursos e a situação
        dos registros da biblioteca.
    </p>

    <!-- BLOCO 6 - MENU: links que mudam o relatório pela URL (?relatorio=...) -->
    <!-- Botões de filtro dos relatórios -->
    <nav class="menu-relatorios" aria-label="Tipos de relatório">
        <a class="btn-relatorio" href="?relatorio=todos" id="btn-todos-relatorios">Todos os relatórios</a>
        <a class="btn-relatorio" href="?relatorio=populares">Livros populares</a>
        <a class="btn-relatorio" href="?relatorio=emprestimos">Empréstimos</a>
        <a class="btn-relatorio" href="?relatorio=usuarios">Usuários ativos</a>
        <a class="btn-relatorio" href="?relatorio=turma">Por turma</a>
        <a class="btn-relatorio" href="?relatorio=curso">Por curso</a>
        <a class="btn-relatorio" href="?relatorio=situacao">Por situação</a>
        <a class="btn-relatorio" href="?relatorio=historico">Histórico detalhado</a>
    </nav>

    <!-- Botões de ação (Imprimir e Salvar) -->
    <div class="acoes-relatorio">
        <button type="button" class="btn-acao-relatorio" id="btn-imprimir">
            🖨️ Imprimir relatório
        </button>
        <button type="button" class="btn-acao-relatorio btn-salvar-relatorio" id="btn-salvar">
            💾 Salvar relatório
        </button>
    </div>

    <!-- Área de conteúdo da tabela do relatório -->
    <div id="conteudo-relatorio">
        <!-- Cabeçalho do relatório com título e barra de pesquisa ao lado -->
        <div class="cabecalho-tabela-relatorio">
            <!-- Mostra o título do relatório escolhido -->
            <!-- htmlspecialchars transforma caracteres especiais (< > " ') em texto normal,
                 pra ninguém conseguir colocar código malicioso na tela -->
            <h2><?= htmlspecialchars($titulo, ENT_QUOTES, "UTF-8") ?></h2>

            <!-- Barra de pesquisa do relatório -->
            <div class="search-input-wrapper">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input
                    type="search"
                    class="search-input input-busca-relatorio"
                    placeholder="Pesquisar neste relatório..."
                    aria-label="Pesquisar no relatório"
                >
            </div>
        </div>

        <!-- BLOCO 7 - TABELA: montada de forma automática -->
        <!-- Se o array tem alguma linha, monta a tabela. Se não, cai no else lá embaixo -->
        <?php if (!empty($linhas)): ?>
            <!-- Container com rolagem horizontal para a tabela -->
            <div class="tabela-container">
                <table class="tabela-relatorios">
                    <!-- Cabeçalho da tabela -->
                    <thead>
                        <tr>
                            <!-- Cabeçalho: pega o nome das colunas da primeira linha (array_keys)
                                 por isso a tabela serve pra qualquer relatório -->
                            <?php foreach (array_keys($linhas[0]) as $coluna): ?>
                                <th scope="col"><?= htmlspecialchars((string) $coluna, ENT_QUOTES, "UTF-8") ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <!-- Corpo da tabela -->
                    <tbody>
                        <!-- Corpo: o primeiro foreach cria uma linha (tr) pra cada registro -->
                        <?php foreach ($linhas as $linha): ?>
                            <tr>
                                <!-- O segundo foreach cria uma célula (td) pra cada valor da linha -->
                                <?php foreach ($linha as $valor): ?>
                                    <td><?= htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8") ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Se a consulta não trouxe nada, mostra essa mensagem -->
            <p class="mensagem-vazia">
                Nenhum registro encontrado para este relatório.
            </p>
        <?php endif; ?>
    </div>

</main>

<!-- Hotbar global de navegação -->
<nav class="global-hotbar">
    <!-- Botão Início (Painel) -->
    <a href="principal.php" class="hotbar-item">
        <span class="hotbar-icon">🏠</span>
        <span class="hotbar-text">Início</span>
    </a>

    <!-- Botão Acervo -->
    <a href="livros.php" class="hotbar-item">
        <span class="hotbar-icon">📚</span>
        <span class="hotbar-text">Acervo</span>
    </a>

    <!-- Botão Notificações -->
    <a href="notificacoes.php" class="hotbar-item">
        <div class="hotbar-icon-wrapper">
            <span class="hotbar-icon">🔔</span>
        </div>
        <span class="hotbar-text">Avisos</span>
    </a>

    <!-- Botão Sair -->
    <a href="logout.php" class="hotbar-item hotbar-logout">
        <span class="hotbar-icon">🚪</span>
        <span class="hotbar-text">Sair</span>
    </a>
</nav>

<!-- Script de interação dos relatórios -->
<script src="./javascript/relatorio.js"></script>

</body>
</html>
