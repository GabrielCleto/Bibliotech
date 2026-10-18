<?php
// Datas.php — funções pra mostrar datas no formato brasileiro
// (dia/mês/ano) nas páginas HTML, em vez do formato americano que
// vem direto do banco (ano-mês-dia). Pedido da professora.
//
// Importante: isso só se aplica ao que é MOSTRADO pra pessoa nas
// páginas (paginas/*.php). A API JSON (src/Controllers/*) continua
// devolvendo as datas no formato ISO (AAAA-MM-DD), que é o padrão
// esperado por quem consome a API (o front-end decide como formatar
// na tela dele, ou pode chamar essas mesmas funções se preferir).

/**
 * Converte uma data (AAAA-MM-DD, vinda do banco) pra DD/MM/AAAA.
 * Se vier vazia ou nula, devolve o texto do 2º parâmetro (padrão "—").
 */
function formatarDataBr(?string $data, string $vazio = "—"): string
{
    if ($data === null || $data === "") {
        return $vazio;
    }

    // DateTime::createFromFormat devolve false se o texto não bater
    // com o formato esperado — nesse caso devolve o texto original
    // sem quebrar a página (mais seguro do que esconder o dado)
    $objeto = DateTime::createFromFormat("Y-m-d", substr($data, 0, 10));
    if ($objeto === false) {
        return $data;
    }

    return $objeto->format("d/m/Y");
}

/**
 * Igual à de cima, mas pra campos DATETIME (ex.: criada_em), que
 * além da data têm hora. Mostra "dia/mês/ano hora:minuto".
 */
function formatarDataHoraBr(?string $dataHora, string $vazio = "—"): string
{
    if ($dataHora === null || $dataHora === "") {
        return $vazio;
    }

    $objeto = DateTime::createFromFormat("Y-m-d H:i:s", $dataHora);
    if ($objeto === false) {
        return $dataHora;
    }

    return $objeto->format("d/m/Y H:i");
}
