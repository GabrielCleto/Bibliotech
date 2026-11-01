/* Script de controle dos relatórios */
document.addEventListener("DOMContentLoaded", function () {
    /* Elementos da página */
    const linksMenu = document.querySelectorAll(".menu-relatorios .btn-relatorio");
    const btnTodos = document.getElementById("btn-todos-relatorios");
    const btnImprimir = document.getElementById("btn-imprimir");
    const btnSalvar = document.getElementById("btn-salvar");
    const containerConteudo = document.getElementById("conteudo-relatorio");

    /* Lista de tipos de relatório disponíveis */
    const tiposRelatorio = [
        "populares",
        "emprestimos",
        "usuarios",
        "turma",
        "curso",
        "situacao",
        "historico"
    ];

    /* Ativa o filtro de pesquisa em todas as barras de busca dos relatórios */
    function ativarBuscaNasTabelas(escopo) {
        const inputsBusca = (escopo || document).querySelectorAll(".input-busca-relatorio");

        inputsBusca.forEach(function (input) {
            input.addEventListener("input", function () {
                const termo = input.value.toLowerCase().trim();
                const bloco = input.closest(".bloco-relatorio-individual") || containerConteudo;
                if (!bloco) return;

                const tbody = bloco.querySelector(".tabela-relatorios tbody");
                if (!tbody) return;

                const linhas = tbody.querySelectorAll("tr");
                linhas.forEach(function (linha) {
                    const texto = (linha.textContent || "").toLowerCase();
                    if (texto.includes(termo)) {
                        linha.style.display = "";
                    } else {
                        linha.style.display = "none";
                    }
                });
            });
        });
    }

    /* Destaca o botão do relatório ativo no menu */
    function marcarBotaoSelecionado(relatorioAtual) {
        let encontrou = false;

        linksMenu.forEach(function (link) {
            link.classList.remove("selecionado");
            link.removeAttribute("aria-current");

            if (link.getAttribute("href") === "?relatorio=" + relatorioAtual) {
                link.classList.add("selecionado");
                link.setAttribute("aria-current", "page");
                encontrou = true;
            }
        });

        if (!encontrou) {
            const linkPopulares = document.querySelector(
                '.menu-relatorios .btn-relatorio[href="?relatorio=populares"]'
            );
            if (linkPopulares) {
                linkPopulares.classList.add("selecionado");
                linkPopulares.setAttribute("aria-current", "page");
            }
        }
    }

    /* Carrega todos os relatórios na mesma tela */
    async function carregarTodosRelatorios() {
        if (!containerConteudo) {
            return;
        }

        marcarBotaoSelecionado("todos");
        containerConteudo.innerHTML =
            '<p class="mensagem-vazia">Carregando todos os relatórios...</p>';

        try {
            const respostas = await Promise.all(
                tiposRelatorio.map(function (tipo) {
                    return fetch("?relatorio=" + encodeURIComponent(tipo), {
                        credentials: "same-origin"
                    }).then(function (res) {
                        return res.text();
                    });
                })
            );

            const parser = new DOMParser();
            const fragmento = document.createDocumentFragment();

            respostas.forEach(function (html) {
                const doc = parser.parseFromString(html, "text/html");
                const conteudoRemoto = doc.getElementById("conteudo-relatorio") || doc.querySelector(".relatorios");

                if (!conteudoRemoto) {
                    return;
                }

                const cabecalho = conteudoRemoto.querySelector(".cabecalho-tabela-relatorio");
                const titulo = conteudoRemoto.querySelector("h2");
                const tabela = conteudoRemoto.querySelector(".tabela-container");
                const vazio = conteudoRemoto.querySelector(".mensagem-vazia");

                const secao = document.createElement("section");
                secao.className = "bloco-relatorio-individual";

                if (cabecalho) {
                    secao.appendChild(cabecalho.cloneNode(true));
                } else if (titulo) {
                    secao.appendChild(titulo.cloneNode(true));
                }

                if (tabela) {
                    secao.appendChild(tabela.cloneNode(true));
                } else if (vazio) {
                    secao.appendChild(vazio.cloneNode(true));
                }

                fragmento.appendChild(secao);
            });

            containerConteudo.innerHTML = "";
            containerConteudo.appendChild(fragmento);
            ativarBuscaNasTabelas(containerConteudo);
        } catch (erro) {
            console.error("Erro ao carregar todos os relatórios:", erro);
            containerConteudo.innerHTML =
                '<p class="mensagem-erro">Não foi possível carregar todos os relatórios.</p>';
        }
    }

    /* Função para imprimir o relatório */
    function imprimirRelatorio() {
        window.print();
    }

    /* Função para salvar o relatório em CSV */
    function salvarRelatorio() {
        if (!containerConteudo) {
            return;
        }

        const linhasCsv = [];
        const secoes = containerConteudo.querySelectorAll(".bloco-relatorio-individual");

        function extrairTabelaParaCsv(tabela) {
            const linhas = tabela.querySelectorAll("tr");
            linhas.forEach(function (tr) {
                if (tr.style.display === "none") {
                    return;
                }
                const colunas = Array.from(tr.querySelectorAll("th, td")).map(function (celula) {
                    const texto = (celula.textContent || "").trim().replace(/"/g, '""');
                    return '"' + texto + '"';
                });
                if (colunas.length > 0) {
                    linhasCsv.push(colunas.join(";"));
                }
            });
        }

        if (secoes.length > 0) {
            secoes.forEach(function (secao, indice) {
                const titulo = secao.querySelector("h2");
                const tabela = secao.querySelector("table");

                if (indice > 0) {
                    linhasCsv.push("");
                }
                if (titulo) {
                    linhasCsv.push('"' + titulo.textContent.trim().replace(/"/g, '""') + '"');
                }
                if (tabela) {
                    extrairTabelaParaCsv(tabela);
                }
            });
        } else {
            const titulo = containerConteudo.querySelector("h2");
            const tabela = containerConteudo.querySelector("table");

            if (titulo) {
                linhasCsv.push('"' + titulo.textContent.trim().replace(/"/g, '""') + '"');
            }
            if (tabela) {
                extrairTabelaParaCsv(tabela);
            }
        }

        if (linhasCsv.length === 0) {
            alert("Não há dados na tabela para salvar.");
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const nomeRelatorio = params.get("relatorio") || "populares";
        const conteudoFinal = "\uFEFF" + linhasCsv.join("\r\n");
        const blob = new Blob([conteudoFinal], { type: "text/csv;charset=utf-8;" });
        const url = URL.createObjectURL(blob);

        const linkDownload = document.createElement("a");
        linkDownload.href = url;
        linkDownload.download = "relatorio_" + nomeRelatorio + ".csv";
        document.body.appendChild(linkDownload);
        linkDownload.click();
        document.body.removeChild(linkDownload);
        URL.revokeObjectURL(url);
    }

    /* Eventos dos botões */
    if (btnTodos) {
        btnTodos.addEventListener("click", function (evento) {
            evento.preventDefault();
            if (window.history && window.history.pushState) {
                window.history.pushState(null, "", "?relatorio=todos");
            }
            carregarTodosRelatorios();
        });
    }

    if (btnImprimir) {
        btnImprimir.addEventListener("click", imprimirRelatorio);
    }

    if (btnSalvar) {
        btnSalvar.addEventListener("click", salvarRelatorio);
    }

    /* Inicialização ao abrir a página */
    ativarBuscaNasTabelas(containerConteudo);

    const paramsIniciais = new URLSearchParams(window.location.search);
    const relatorioInicial = paramsIniciais.get("relatorio") || "populares";

    if (relatorioInicial === "todos") {
        carregarTodosRelatorios();
    } else {
        marcarBotaoSelecionado(relatorioInicial);
    }
});
