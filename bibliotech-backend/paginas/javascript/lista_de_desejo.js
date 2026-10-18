document.addEventListener("DOMContentLoaded", function () {
    const cards = document.querySelectorAll(".wishlist-card");
    
    if (typeof BibliotechCapas !== "undefined") {
        cards.forEach(card => {
            const titulo = card.dataset.titulo;
            const autor = card.dataset.autor;
            
            // Pega as divs do HTML
            const capaContainer = card.querySelector(".wishlist-capa");
            const fallback = card.querySelector(".wishlist-capa-fallback");
            
            // O seu capas.js já faz tudo: procura e injeta a <img> com a classe .livro-capa-imagem
            BibliotechCapas.buscarCapa(titulo, autor, capaContainer, fallback);
        });
    } else {
        console.error("Erro: capas.js não carregado.");
    }
});