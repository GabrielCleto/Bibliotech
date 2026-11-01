/* Busca automática das capas dos livros */
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

/* Filtro de pesquisa de livros em tempo real */
document.addEventListener("DOMContentLoaded", () => {
    const inputBusca = document.getElementById("inputBuscaLivros");
    const cards = document.querySelectorAll(".livro-card");

    if (!inputBusca) return;

    inputBusca.addEventListener("input", (e) => {
        const termo = e.target.value.toLowerCase().trim();

        cards.forEach((card) => {
            const titulo = card.getAttribute("data-titulo")?.toLowerCase() || "";
            const autor = card.getAttribute("data-autor")?.toLowerCase() || "";

            /* Exibe o card se o título ou autor contiver o termo pesquisado */
            if (titulo.includes(termo) || autor.includes(termo)) {
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        });
    });
});
