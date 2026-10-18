document.addEventListener("DOMContentLoaded", function () {
    // A função tem de ser global para que o atributo 'onchange' do HTML consiga chamá-la.
    window.mostrarCampoNovo = function(tipo) {
        if (tipo === "autor") {
            const select = document.getElementById("id_autor");
            const campo = document.getElementById("novo_autor");
            if (select && campo) {
                // Se a opção selecionada for 'novo', exibe o input; caso contrário, oculta.
                campo.style.display = (select.value === "novo") ? "block" : "none";
            }
        } else if (tipo === "genero") {
            const select = document.getElementById("id_cat");
            const campo = document.getElementById("novo_genero");
            if (select && campo) {
                campo.style.display = (select.value === "novo") ? "block" : "none";
            }
        }
    };
});