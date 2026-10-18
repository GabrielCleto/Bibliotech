<?php
// roleta_livros.php — mostra o acervo como uma "roleta" horizontal
// que gira sozinha. Qualquer pessoa logada pode ver (aluno,
// professor ou bibliotecária) — é só visualização, não empresta
// nada por aqui.
//
// Por que isso é leve: quem faz a animação girar é o CSS (uma
// propriedade chamada @keyframes), não o JavaScript. O navegador
// entrega esse trabalho pra placa de vídeo, então não trava a
// página nem fica calculando posição a cada instante. O JavaScript
// aqui só é usado UMA vez, pra pedir os livros pro nosso /api/books
// e montar os cartõezinhos na tela.

session_start();
require __DIR__ . "/../config/SemCache.php";
sem_cache();
require __DIR__ . "/../config/Conexao.php";
require __DIR__ . "/../config/ContaAtiva.php";

if (!isset($_SESSION["id_usuario"])) {
    header("Location: login.php");
    exit;
}

$conexao = Conexao::getConexao();
exigirContaAtiva($conexao);
session_write_close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<?= sem_cache_js() ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Roleta do acervo — Biblioteca Virtual</title>
<style>
    :root {
        --madeira: #2b1d14;
        --madeira-clara: #4a3323;
        --papel: #f2e8d5;
        --papel-escuro: #d8c7a1;
        --verde: #3f5d43;
        --verde-claro: #6b8f6e;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        min-height: 100vh;
        background: radial-gradient(circle at top, var(--madeira-clara), var(--madeira) 70%);
        font-family: "Georgia", "Times New Roman", serif;
        color: var(--papel);
        padding: 40px 0;
    }

    h1 {
        text-align: center;
        font-weight: normal;
        letter-spacing: 0.04em;
        margin: 0 0 6px;
        font-size: 28px;
    }

    p.subtitulo {
        text-align: center;
        color: var(--papel-escuro);
        margin: 0 0 32px;
        font-size: 14px;
    }

    /* A "janela" que corta a roleta — sem isso, os livros
       apareceriam soltos pela tela inteira */
    .janela {
        overflow: hidden;
        -webkit-mask-image: linear-gradient(90deg, transparent, black 8%, black 92%, transparent);
        mask-image: linear-gradient(90deg, transparent, black 8%, black 92%, transparent);
    }

    /* A trilha é uma fileira comprida com os livros duplicados
       (a mesma lista 2x, uma do lado da outra). Ela desliza da
       esquerda pra direita e, quando chega na metade exata, volta
       pro início sem ninguém perceber o "corte" — é o truque
       clássico de carrossel infinito, só com CSS */
    .trilha {
        display: flex;
        gap: 18px;
        width: max-content;
        padding: 10px 0 26px;
        animation: girar 60s linear infinite;
    }

    /* Se a pessoa passar o mouse em cima, a roleta pausa — assim
       dá pra ler o título com calma */
    .janela:hover .trilha {
        animation-play-state: paused;
    }

    @keyframes girar {
        from { transform: translateX(0); }
        to   { transform: translateX(-50%); }
    }

    .livro {
        flex: 0 0 auto;
        width: 150px;
        background: var(--papel);
        color: var(--madeira);
        border-radius: 4px;
        padding: 14px 12px;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
        border-left: 6px solid var(--verde);
        transition: transform 0.2s ease;
    }

    .livro:hover {
        transform: translateY(-6px);
    }

    .livro .categoria {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--verde);
        margin-bottom: 8px;
        display: block;
    }

    .livro h3 {
        font-size: 15px;
        margin: 0 0 6px;
        line-height: 1.3;
    }

    .livro .autor {
        font-size: 12px;
        color: #5a4a38;
        margin: 0 0 10px;
    }

    .livro .disponibilidade {
        font-size: 11px;
        font-weight: bold;
    }

    .livro .disponivel { color: var(--verde); }
    .livro .indisponivel { color: #a03b3b; }

    .carregando, .erro {
        text-align: center;
        color: var(--papel-escuro);
        padding: 40px;
    }

    .voltar {
        display: block;
        text-align: center;
        margin-top: 30px;
        color: var(--papel-escuro);
    }

    /* Gente que prefere menos movimento na tela (configuração do
       próprio sistema operacional) — a roleta para de girar
       sozinha, mas continua dando pra rolar com o mouse/dedo */
    @media (prefers-reduced-motion: reduce) {
        .trilha {
            animation: none;
            overflow-x: auto;
        }
    }
</style>
</head>
<body>
    <h1>Dando uma volta pelo acervo</h1>
    <p class="subtitulo">Passe o mouse em cima de um livro pra pausar e ler com calma</p>

    <div class="janela">
        <div class="trilha" id="trilha">
            <p class="carregando">Carregando os livros...</p>
        </div>
    </div>

    <a class="voltar" href="principal.php">Voltar</a>

    <script>
        // Monta o cartão de um livro (mesma função usada duas vezes,
        // pra criar a lista duplicada da roleta)
        function cartaoLivro(livro) {
            const disponivel = livro.quantidade_disponivel > 0;
            const div = document.createElement("div");
            div.className = "livro";
            div.innerHTML = `
                <span class="categoria">${escaparTexto(livro.categoria)}</span>
                <h3>${escaparTexto(livro.titulo)}</h3>
                <p class="autor">${escaparTexto(livro.autor)}</p>
                <span class="disponibilidade ${disponivel ? "disponivel" : "indisponivel"}">
                    ${disponivel ? `${livro.quantidade_disponivel} disponível(is)` : "Indisponível"}
                </span>
            `;
            return div;
        }

        // Nunca insere texto de fora direto no HTML sem tratar —
        // isso evita que um título de livro malicioso quebre a
        // página (mesma ideia do htmlspecialchars do PHP)
        function escaparTexto(texto) {
            const div = document.createElement("div");
            div.textContent = texto ?? "";
            return div.innerHTML;
        }

        async function carregarRoleta() {
            const trilha = document.getElementById("trilha");
            try {
                const resposta = await fetch("../api/books");
                const corpo = await resposta.json();
                const livros = corpo.data ?? [];

                if (livros.length === 0) {
                    trilha.innerHTML = '<p class="erro">Nenhum livro no acervo ainda.</p>';
                    return;
                }

                trilha.innerHTML = "";

                // Coloca a lista DUAS vezes seguidas — é isso que
                // permite a animação "voltar ao início" sem dar
                // aquele pulo feio quando o CSS chega na metade
                for (let volta = 0; volta < 2; volta++) {
                    livros.forEach(livro => trilha.appendChild(cartaoLivro(livro)));
                }
            } catch (erro) {
                trilha.innerHTML = '<p class="erro">Não foi possível carregar o acervo agora.</p>';
            }
        }

        carregarRoleta();
    </script>
</body>
</html>
