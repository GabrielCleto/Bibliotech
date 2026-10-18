/* Configurações */

const ISBNDB_API_KEY = "";

const HARDCOVER_API_KEY = "";

const TEMPO_LIMITE_API = 5000;

const TEMPO_LIMITE_IMAGEM = 4000;


/* Busca principal */

async function buscarCapa(titulo, autor, capa, fallback) {

const titulos = obterTitulosAlternativos(titulo, autor);

for (const tituloBusca of titulos) {

try {

const resultados = await Promise.all([
buscarGoogleBooks(tituloBusca, autor),
buscarOpenLibrary(tituloBusca, autor)
]);

const capasGoogle = resultados[0];

const capasOpenLibrary = resultados[1];

const capaGoogle =
await encontrarImagemValida(capasGoogle);

if (capaGoogle) {

mostrarCapa(
capa,
fallback,
capaGoogle
);

return capaGoogle;

}

const capaOpenLibrary =
await encontrarImagemValida(capasOpenLibrary);

if (capaOpenLibrary) {

mostrarCapa(
capa,
fallback,
capaOpenLibrary
);

return capaOpenLibrary;

}

} catch (erro) {

console.warn(
"Erro nas fontes principais:",
erro
);

}

}

for (const tituloBusca of titulos) {

const encontrou =
await buscarFontesSecundarias(
tituloBusca,
autor,
capa,
fallback
);

if (encontrou) {

return encontrou;

}

}

mostrarFallback(
capa,
fallback
);

return null;

}


/* Títulos alternativos */

function obterTitulosAlternativos(titulo, autor) {

const normalizado =
normalizarTexto(titulo);

const autorNormalizado =
normalizarTexto(autor || "");

const titulos = [titulo];


/* O Senhor dos Anéis */

if (
normalizado.includes("o senhor dos aneis") &&
normalizado.includes("sociedade do anel")
) {

titulos.push(
"The Fellowship of the Ring"
);

titulos.push(
"The Lord of the Rings The Fellowship of the Ring"
);

}


/* Jogador Nº 1 */

if (
normalizado.includes("jogador") &&
normalizado.includes("1")
) {

titulos.push(
"Ready Player One"
);

}


/* It: A Coisa */

if (
normalizado.includes("it a coisa")
) {

titulos.push("It");

titulos.push(
"It: A Novel"
);

titulos.push(
"Stephen King It"
);

if (
autorNormalizado.includes("stephen king")
) {

titulos.push(
"It Stephen King"
);

titulos.push(
"It A Novel Stephen King"
);

}

}


/* Harry Potter */

if (
normalizado.includes("harry potter") &&
normalizado.includes("pedra filosofal")
) {

titulos.push(
"Harry Potter and the Philosopher's Stone"
);

titulos.push(
"Harry Potter and the Sorcerer's Stone"
);

}

return [
...new Set(titulos)
];

}


/* Normalização */

function normalizarTexto(texto) {

return String(texto)
.toLowerCase()
.normalize("NFD")
.replace(
/[\u0300-\u036f]/g,
""
)
.replace(
/[ºª]/g,
""
)
.replace(
/\s+/g,
" "
)
.trim();

}


/* Fontes secundárias */

async function buscarFontesSecundarias(
titulo,
autor,
capa,
fallback
) {

const fontes = await Promise.all([

buscarInternetArchive(
titulo,
autor
),

buscarISBNdb(
titulo,
autor
),

buscarHardcover(
titulo,
autor
)

]);

const capas = fontes.flat();

const imagem =
await encontrarImagemValida(capas);

if (imagem) {

mostrarCapa(
capa,
fallback,
imagem
);

return imagem;

}

return false;

}


/* Google Books */

async function buscarGoogleBooks(
titulo,
autor
) {

const consultas = [];

if (autor) {

consultas.push(
`intitle:${titulo} inauthor:${autor}`
);

}

consultas.push(
`intitle:${titulo}`
);

consultas.push(
titulo
);


/* It + Stephen King */

if (
normalizarTexto(titulo).includes("it") &&
normalizarTexto(autor || "").includes("stephen king")
) {

consultas.push(
"intitle:It inauthor:Stephen King"
);

consultas.push(
"It Stephen King"
);

}

for (
const consulta of [...new Set(consultas)]
) {

try {

const url =
`https://www.googleapis.com/books/v1/volumes?q=${encodeURIComponent(consulta)}&maxResults=10`;

const resposta =
await fetchComLimite(
url,
TEMPO_LIMITE_API
);

if (
!resposta ||
!resposta.ok
) {

continue;

}

const dados =
await resposta.json();

if (!dados.items) {

continue;

}

const capas = [];

for (
const item of dados.items
) {

const informacoes =
item.volumeInfo;

const imagem =
informacoes?.imageLinks;

if (!imagem) {

continue;

}

const urlCapa =
imagem.extraLarge ||
imagem.large ||
imagem.medium ||
imagem.small ||
imagem.thumbnail ||
imagem.smallThumbnail;

if (urlCapa) {

capas.push(
urlCapa
.replace(
"http://",
"https://"
)
.replace(
"&edge=curl",
""
)
);

}

}

if (
capas.length > 0
) {

return [
...new Set(capas)
];

}

} catch (erro) {

console.warn(
"Google Books indisponível:",
erro
);

}

}

return [];

}


/* Open Library */

async function buscarOpenLibrary(
titulo,
autor
) {

const consultas = [];

if (autor) {

consultas.push({
titulo: titulo,
autor: autor
});

}

consultas.push({
titulo: titulo
});


/* It + Stephen King */

if (
normalizarTexto(titulo).includes("it") &&
normalizarTexto(autor || "").includes("stephen king")
) {

consultas.push({
titulo: "It",
autor: "Stephen King"
});

consultas.push({
titulo: "It: A Novel",
autor: "Stephen King"
});

}

for (
const consulta of consultas
) {

try {

const parametros =
new URLSearchParams();

parametros.set(
"title",
consulta.titulo
);

parametros.set(
"limit",
"10"
);

parametros.set(
"fields",
"title,author_name,cover_i,edition_key"
);

if (consulta.autor) {

parametros.set(
"author",
consulta.autor
);

}

const url =
`https://openlibrary.org/search.json?${parametros.toString()}`;

const resposta =
await fetchComLimite(
url,
TEMPO_LIMITE_API
);

if (
!resposta ||
!resposta.ok
) {

continue;

}

const dados =
await resposta.json();

if (!dados.docs) {

continue;

}

const capas = [];

for (
const livro of dados.docs
) {

if (livro.cover_i) {

capas.push(
`https://covers.openlibrary.org/b/id/${livro.cover_i}-L.jpg`
);

}

if (
livro.edition_key
) {

for (
const edition of livro.edition_key.slice(0, 3)
) {

capas.push(
`https://covers.openlibrary.org/b/olid/${edition}-L.jpg`
);

}

}

}

if (
capas.length > 0
) {

return [
...new Set(capas)
];

}

} catch (erro) {

console.warn(
"Open Library indisponível:",
erro
);

}

}

return [];

}


/* Internet Archive */

async function buscarInternetArchive(
titulo,
autor
) {

try {

let consulta =
`title:("${titulo}")`;

if (autor) {

consulta +=
` AND creator:("${autor}")`;

}

const url =
`https://archive.org/advancedsearch.php?q=${encodeURIComponent(consulta)}&fl[]=identifier&rows=5&page=1&output=json`;

const resposta =
await fetchComLimite(
url,
TEMPO_LIMITE_API
);

if (
!resposta ||
!resposta.ok
) {

return [];

}

const dados =
await resposta.json();

if (
!dados.response?.docs
) {

return [];

}

return dados.response.docs
.filter(
livro =>
livro.identifier
)
.map(
livro =>
`https://archive.org/services/img/${encodeURIComponent(livro.identifier)}`
);

} catch (erro) {

console.warn(
"Internet Archive indisponível:",
erro
);

return [];

}

}


/* ISBNdb */

async function buscarISBNdb(
titulo,
autor
) {

if (!ISBNDB_API_KEY) {

return [];

}

try {

const consulta = autor
? `${titulo} ${autor}`
: titulo;

const url =
`https://api2.isbndb.com/books/${encodeURIComponent(consulta)}`;

const resposta =
await fetchComLimite(
url,
TEMPO_LIMITE_API,
{
headers: {
Authorization:
ISBNDB_API_KEY
}
}
);

if (
!resposta ||
!resposta.ok
) {

return [];

}

const dados =
await resposta.json();

if (!dados.books) {

return [];

}

return dados.books
.map(
livro =>
livro.image
)
.filter(Boolean);

} catch (erro) {

console.warn(
"ISBNdb indisponível:",
erro
);

return [];

}

}


/* Hardcover */

async function buscarHardcover(
titulo,
autor
) {

if (!HARDCOVER_API_KEY) {

return [];

}

try {

const consulta =
escaparGraphQL(titulo);

const query = `
query {
search(
query: "${consulta}"
query_type: "book"
) {
results
}
}
`;

const resposta =
await fetchComLimite(
"https://api.hardcover.app/v1/graphql",
TEMPO_LIMITE_API,
{
method: "POST",

headers: {
"Content-Type":
"application/json",

"Authorization":
`Bearer ${HARDCOVER_API_KEY}`
},

body: JSON.stringify({
query: query
})

}
);

if (
!resposta ||
!resposta.ok
) {

return [];

}

const dados =
await resposta.json();

const resultados =
dados.data?.search?.results;

if (!resultados) {

return [];

}

const lista =
Array.isArray(resultados)
? resultados
: resultados.results || [];

return lista
.map(
livro =>
livro.image ||
livro.cover_url ||
livro.cover?.url ||
livro.image_url
)
.filter(Boolean);

} catch (erro) {

console.warn(
"Hardcover indisponível:",
erro
);

return [];

}

}


/* Validação das capas */

async function encontrarImagemValida(capas) {

if (
!capas ||
capas.length === 0
) {

return null;

}

const lista = [
...new Set(capas)
];

const resultados =
await Promise.all(

lista.map(
async url => {

const valida =
await verificarImagem(
url
);

return {
url: url,
valida: valida
};

}
)

);

const encontrada =
resultados.find(
resultado =>
resultado.valida
);

return encontrada
? encontrada.url
: null;

}


/* Verificação da imagem */

function verificarImagem(url) {

return new Promise(
resolve => {

const imagem =
new Image();

let finalizado =
false;

const tempoLimite =
setTimeout(
() => {

if (finalizado) {

return;

}

finalizado =
true;

imagem.onload =
null;

imagem.onerror =
null;

resolve(false);

},
TEMPO_LIMITE_IMAGEM
);

imagem.onload =
() => {

if (finalizado) {

return;

}

finalizado =
true;

clearTimeout(
tempoLimite
);

const largura =
imagem.naturalWidth;

const altura =
imagem.naturalHeight;

if (
largura < 100 ||
altura < 100
) {

resolve(false);

return;

}

const proporcao =
largura / altura;

if (
proporcao < 0.45 ||
proporcao > 1.0
) {

resolve(false);

return;

}

resolve(true);

};

imagem.onerror =
() => {

if (finalizado) {

return;

}

finalizado =
true;

clearTimeout(
tempoLimite
);

resolve(false);

};

imagem.src =
url;

}
);

}


/* Limite das APIs */

async function fetchComLimite(
url,
tempo,
opcoes = {}
) {

const controlador =
new AbortController();

const temporizador =
setTimeout(
() => {

controlador.abort();

},
tempo
);

try {

return await fetch(
url,
{
...opcoes,
signal:
controlador.signal
}
);

} catch (erro) {

if (
erro.name ===
"AbortError"
) {

console.warn(
"API demorou demais:",
url
);

}

return null;

} finally {

clearTimeout(
temporizador
);

}

}


/* Exibição da capa */

function mostrarCapa(
capa,
fallback,
url
) {

if (!capa || !url) {

return;

}

const imagem =
new Image();

imagem.alt =
"Capa do livro";

imagem.className =
"livro-capa-imagem";

imagem.onload =
() => {

const largura =
imagem.naturalWidth;

const altura =
imagem.naturalHeight;

if (
largura < 100 ||
altura < 100
) {

mostrarFallback(
capa,
fallback
);

return;

}

capa.innerHTML =
"";

capa.appendChild(
imagem
);

if (fallback) {

fallback.remove();

}

};

imagem.onerror =
() => {

mostrarFallback(
capa,
fallback
);

};

imagem.src =
url;

}


/* Fallback */

function mostrarFallback(
capa,
fallback
) {

if (!capa || !fallback) {

return;

}

capa.innerHTML =
"";

capa.appendChild(
fallback
);

fallback.style.display =
"flex";

}


/* Segurança da consulta */

function escaparGraphQL(texto) {

return String(texto)
.replace(
/\\/g,
"\\\\"
)
.replace(
/"/g,
'\\"'
)
.replace(
/\n/g,
"\\n"
)
.replace(
/\r/g,
"\\r"
);

}

window.BibliotechCapas = {
buscarCapa: buscarCapa,
mostrarCapa: mostrarCapa,
mostrarFallback: mostrarFallback
};