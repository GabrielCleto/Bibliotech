<?php
// Criptografia.php — criptografa/descriptografa dados pessoais
// sensíveis antes de gravar no banco (RNF07: CPF, telefone, endereço,
// data de nascimento).
//
// Como funciona: usamos AES-256 (um jeito de embaralhar o texto que
// só quem tem a "chave" secreta consegue desembaralhar de volta).
//
// DECISÃO IMPORTANTE (documentar isso pro grupo): usamos uma chave E
// um "IV" (um número que ajuda a embaralhar) FIXOS, sempre os mesmos.
// Isso faz o mesmo CPF sempre virar o mesmo texto criptografado —
// o que é necessário aqui, porque o banco precisa conferir "esse CPF
// já existe?" (chave UNIQUE) e isso só funciona comparando o texto
// criptografado diretamente.
//
// Numa aplicação de produção de verdade, o ideal seria: (1) a chave
// ficar fora do código-fonte (variável de ambiente do servidor, não
// commitada no Git); (2) cada dado ser criptografado com um IV
// diferente e ALEATÓRIO (mais seguro), e a checagem de duplicidade
// ser feita comparando um "hash" separado (outra coluna), não o
// próprio texto criptografado. Não fizemos assim aqui pra manter o
// projeto simples de entender/testar, mas fica registrado o porquê.

require_once __DIR__ . '/../src/Support/Env.php';

final class Criptografia
{
    private const CHAVE_HEX_PADRAO = 'e3aae9c1dac832d504c067c5642442ef730094bfe80bade9d742b7a890f46772';
    private const IV_HEX_PADRAO = '8bbc0ae95ea41dff43ee6fca2036ee09';
    private const METODO = 'aes-256-cbc';

    private static function getChaveBin(): string
    {
        $hex = (string) \Bibliotech\Support\Env::get('APP_KEY', self::CHAVE_HEX_PADRAO);
        return hex2bin($hex);
    }

    private static function getIvBin(): string
    {
        $hex = (string) \Bibliotech\Support\Env::get('APP_IV', self::IV_HEX_PADRAO);
        return hex2bin($hex);
    }

    public static function criptografar(?string $textoPuro): ?string
    {
        if ($textoPuro === null || $textoPuro === '') {
            return $textoPuro;
        }

        $chave = self::getChaveBin();
        $iv = self::getIvBin();

        $criptografado = openssl_encrypt($textoPuro, self::METODO, $chave, OPENSSL_RAW_DATA, $iv);

        // base64 pra virar um texto seguro de guardar em VARCHAR
        // (o resultado "cru" da criptografia tem bytes estranhos)
        return base64_encode($criptografado);
    }

    public static function descriptografar(?string $textoCriptografado): ?string
    {
        if ($textoCriptografado === null || $textoCriptografado === '') {
            return $textoCriptografado;
        }

        $chave = hex2bin(self::CHAVE_HEX);
        $iv = hex2bin(self::IV_HEX);

        $bruto = base64_decode($textoCriptografado, true);
        if ($bruto === false) {
            return null; // não era um texto criptografado válido
        }

        $textoPuro = openssl_decrypt($bruto, self::METODO, $chave, OPENSSL_RAW_DATA, $iv);

        return $textoPuro === false ? null : $textoPuro;
    }
}
