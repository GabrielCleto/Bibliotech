document.addEventListener("DOMContentLoaded", () => {
const livros = document.querySelectorAll(".livro-card");

livros.forEach((livro) => {
const titulo = livro.dataset.titulo;
const autor = livro.dataset.autor;
const capa = livro.querySelector(".livro-capa");
const fallback = livro.querySelector(".livro-capa-fallback");

if (!titulo || !capa) {
return;
}

buscarCapa(titulo, autor, capa, fallback);
});
});