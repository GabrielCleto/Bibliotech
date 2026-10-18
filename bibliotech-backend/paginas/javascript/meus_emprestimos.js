document.addEventListener("DOMContentLoaded", function () {

const cards = Array.from(
document.querySelectorAll(".emprestimo-card")
);

const detalhes = Array.from(
document.querySelectorAll(".emprestimo-detalhes")
);

const indicadores = document.querySelector(
".carousel-indicators"
);

const botaoAnterior = document.querySelector(
".carousel-prev"
);

const botaoProximo = document.querySelector(
".carousel-next"
);

const carousel = document.querySelector(
".livros-carousel"
);

let indiceAtual = 0;

if (cards.length === 0) {
return;
}

/* Indicadores */

criarIndicadores();

/* Cards */

cards.forEach(function (card, indice) {

card.addEventListener("click", function () {

selecionarLivro(indice);

});

});

/* Botão anterior */

if (botaoAnterior) {

botaoAnterior.addEventListener("click", function () {

selecionarLivro(indiceAtual - 1);

});

}

/* Botão próximo */

if (botaoProximo) {

botaoProximo.addEventListener("click", function () {

selecionarLivro(indiceAtual + 1);

});

}

/* Teclado */

document.addEventListener("keydown", function (evento) {

if (evento.key === "ArrowLeft") {

selecionarLivro(indiceAtual - 1);

}

if (evento.key === "ArrowRight") {

selecionarLivro(indiceAtual + 1);

}

});

/* Primeiro livro */

selecionarLivro(0);

/* Capas */

cards.forEach(function (card) {

const titulo =
card.dataset.titulo || "";

const autor =
card.dataset.autor || "";

const capa =
card.querySelector(
".emprestimo-capa"
);

const fallback =
card.querySelector(
".emprestimo-capa-fallback"
);

if (!titulo || !capa) {
return;
}

if (
typeof BibliotechCapas === "undefined"
) {

console.error(
"capas.js não foi carregado."
);

return;

}

BibliotechCapas.buscarCapa(
titulo,
autor,
capa,
fallback
).then(function (url) {

if (url) {

atualizarCapaDetalhes(
capa,
url
);

}

});

});

/* Seleciona livro */

function selecionarLivro(indice) {

if (cards.length === 0) {
return;
}

if (indice < 0) {
indice = cards.length - 1;
}

if (indice >= cards.length) {
indice = 0;
}

indiceAtual = indice;

/* Ativa card */

cards.forEach(function (card, i) {

card.classList.toggle(
"active",
i === indiceAtual
);

});

/* Atualiza indicador */

atualizarIndicadores();

/* Atualiza detalhes */

atualizarDetalhes();

/* Move carrossel */

centralizarLivro();

}

/* Cria indicadores */

function criarIndicadores() {

if (!indicadores) {
return;
}

indicadores.innerHTML = "";

cards.forEach(function (_, indice) {

const indicador =
document.createElement("button");

indicador.type = "button";

indicador.className =
"carousel-indicator";

indicador.setAttribute(
"aria-label",
"Selecionar livro " +
(indice + 1)
);

indicador.addEventListener(
"click",
function () {

selecionarLivro(indice);

}
);

indicadores.appendChild(
indicador
);

});

atualizarIndicadores();

}

/* Atualiza indicadores */

function atualizarIndicadores() {

if (!indicadores) {
return;
}

const botoes =
indicadores.querySelectorAll(
".carousel-indicator"
);

botoes.forEach(
function (botao, indice) {

botao.classList.toggle(
"active",
indice === indiceAtual
);

}
);

}

/* Centraliza o livro */

function centralizarLivro() {

if (!carousel) {
return;
}

const card =
cards[indiceAtual];

if (!card) {
return;
}

const larguraCarousel =
carousel.clientWidth;

const larguraCard =
card.offsetWidth;

const posicaoCard =
card.offsetLeft;

const centroCard =
posicaoCard +
(larguraCard / 2);

const centroCarousel =
larguraCarousel / 2;

const deslocamento =
centroCard -
centroCarousel;

carousel.scrollTo({

left: Math.max(
0,
deslocamento
),

behavior: "smooth"

});

}

/* Atualiza detalhes */

function atualizarDetalhes() {

detalhes.forEach(
function (detalhe, indice) {

if (indice === indiceAtual) {

detalhe.style.display =
"flex";

} else {

detalhe.style.display =
"none";

}

}
);

}

/* Atualiza capa dos detalhes */

function atualizarCapaDetalhes(
elementoCapa,
url
) {

const card =
elementoCapa.closest(
".emprestimo-card"
);

if (!card) {
return;
}

const cardsAtuais =
Array.from(
document.querySelectorAll(
".emprestimo-card"
)
);

const indice =
cardsAtuais.indexOf(card);

if (indice === -1) {
return;
}

const detalhe =
detalhes[indice];

if (!detalhe) {
return;
}

const area =
detalhe.querySelector(
".emprestimo-detalhes-capa"
);

if (!area) {
return;
}

let imagem =
area.querySelector("img");

if (!imagem) {

imagem =
document.createElement("img");

area.innerHTML = "";

area.appendChild(imagem);

}

imagem.src = url;

imagem.alt =
"Capa do livro";

}
});