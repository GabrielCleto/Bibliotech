/* Controle dos campos dinâmicos e pesquisa de usuários */
document.addEventListener("DOMContentLoaded", function () {
    /* Alterna os campos do formulário conforme o tipo de usuário */
    window.mostrarCamposNovo = function () {
        const select = document.getElementById("tipo-novo");
        if (!select) return;

        const tipo = select.value;
        const camposAluno = document.getElementById("novo-campos-aluno");
        const camposProfessor = document.getElementById("novo-campos-professor");
        const camposCpf = document.getElementById("novo-campos-cpf");

        if (camposAluno) {
            camposAluno.style.display = (tipo === "aluno") ? "grid" : "none";
        }

        if (camposProfessor) {
            camposProfessor.style.display = (tipo === "professor") ? "grid" : "none";
        }

        if (camposCpf) {
            camposCpf.style.display = (tipo === "aluno" || tipo === "bibliotecario") ? "block" : "none";
        }
    };

    /* Inicializa os campos visíveis ao carregar a página */
    mostrarCamposNovo();

    /* Filtro de pesquisa para cada tabela (Base de Alunos, Corpo Docente, Administração do Sistema) */
    const camposBusca = document.querySelectorAll(".input-busca-tabela");

    camposBusca.forEach(function (input) {
        input.addEventListener("input", function () {
            const termo = input.value.toLowerCase().trim();
            const secao = input.closest(".table-section");
            if (!secao) return;

            const tbody = secao.querySelector(".data-table tbody");
            if (!tbody) return;

            const linhas = Array.from(tbody.querySelectorAll("tr"));

            linhas.forEach(function (linha) {
                if (linha.querySelector(".td-empty")) {
                    return;
                }
                const textoLinha = (linha.textContent || "").toLowerCase();
                if (textoLinha.includes(termo)) {
                    linha.style.display = "";
                } else {
                    linha.style.display = "none";
                }
            });
        });
    });
});

/* Limpeza de avisos soltos na raiz do body */
document.addEventListener("DOMContentLoaded", function () {
    const filhosDoBody = Array.from(document.body.childNodes);

    filhosDoBody.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE && node.nodeValue.trim() !== "") {
            node.remove();
        } else if (node.nodeType === Node.ELEMENT_NODE && (node.tagName === "B" || node.tagName === "BR")) {
            node.remove();
        }
    });
});
