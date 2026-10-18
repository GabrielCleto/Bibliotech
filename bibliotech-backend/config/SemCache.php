<?php
// SemCache.php — impede o navegador de guardar em cache as páginas
// que só deveriam aparecer pra quem está logado.
//
// Sem isso, depois de sair (logout) ou trocar de usuário, apertar
// o botão "voltar" do navegador podia mostrar uma cópia salva da
// tela antiga, com dado de outra pessoa.

function sem_cache(): void
{
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
}

/**
 * Gera um scriptzinho que força a página a recarregar de verdade se
 * o navegador tentar mostrar ela "congelada" do botão voltar/avançar
 * (isso é mais confiável que só o Cache-Control no Chrome moderno).
 * Chama isso dentro do <head> da página.
 */
function sem_cache_js(): string
{
    return '<script>
        window.addEventListener("pageshow", function (evento) {
            if (evento.persisted) {
                window.location.reload();
            }
        });
    </script>';
}
