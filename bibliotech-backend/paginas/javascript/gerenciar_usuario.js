document.addEventListener("DOMContentLoaded", function () {
    
    // Associa a função ao escopo global para o onchange do HTML conseguir invocá-la
    window.mostrarCamposNovo = function() {
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

    // Executa a função imediatamente ao carregar a página 
    // para garantir que os campos certos aparecem consoante o valor default
    mostrarCamposNovo();
});


// BLOQUEADOR DE ERROS PHP (Front-End)

document.addEventListener("DOMContentLoaded", function () {
    // Como o PHP injeta os erros diretamente na raiz do <body>, 
    // vamos apagar tudo o que seja lixo (textos soltos e tags de formatação do PHP).
    const filhosDoBody = Array.from(document.body.childNodes);
    
    filhosDoBody.forEach(node => {
        // 1. Apaga nós de texto soltos que tenham conteúdo (ex: "Warning: Undefined array key...")
        if (node.nodeType === Node.TEXT_NODE && node.nodeValue.trim() !== "") {
            node.remove();
        }
        // 2. Apaga as tags <b> e <br> soltas na raiz (que o PHP usa nativamente para formatar os Warnings)
        else if (node.nodeType === Node.ELEMENT_NODE && (node.tagName === 'B' || node.tagName === 'BR')) {
            node.remove();
        }
    });
});