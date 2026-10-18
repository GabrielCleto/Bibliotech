<?php

// CORREÇÃO: o PHP, sem isso, usa UTC como fuso padrão — mas o banco
// (e a escola) usa o horário de Brasília (-03). Entre 21h e 23h59 no
// horário local, já é "o dia seguinte" em UTC, então o PHP calculava
// data de hoje errada (um dia a mais) em tudo que usa `new DateTime()`
// ou `date()` sem fuso explícito: data de empréstimo, data de
// devolução, cálculo de dias de atraso/multa, renovação (RN02) etc.
// Setado aqui porque esse arquivo é carregado por quase toda página
// e pela API — um lugar só garante que PHP e banco concordem sobre
// "que dia é hoje".
require_once __DIR__ . '/../src/Support/Env.php';
require_once __DIR__ . '/../src/Support/Logger.php';

date_default_timezone_set(\Bibliotech\Support\Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

/**
 * Classe Conexao
 *
 * Responsável por gerenciar a conexão PDO com o MySQL via Singleton,
 * carregando dinamicamente as credenciais do ambiente (.env).
 */
class Conexao
{
    private static string $host = "localhost";
    private static string $porta = "3306";
    private static string $banco = "biblioteca";
    private static string $usuario = "root";
    private static string $senha = "";

    private static ?PDO $instancia = null;

    private function __construct()
    {
    }

    public static function getConexao(): PDO
    {
        if (self::$instancia === null) {
            try {
                $host = (string) \Bibliotech\Support\Env::get('DB_HOST', self::$host);
                $porta = (string) \Bibliotech\Support\Env::get('DB_PORT', self::$porta);
                $banco = (string) \Bibliotech\Support\Env::get('DB_NAME', self::$banco);
                $usuario = (string) \Bibliotech\Support\Env::get('DB_USER', self::$usuario);
                $senha = (string) \Bibliotech\Support\Env::get('DB_PASS', self::$senha);

                $dsn = "mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4";

                self::$instancia = new PDO($dsn, $usuario, $senha, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                ]);
            } catch (PDOException $e) {
                \Bibliotech\Support\Logger::error("Falha de conexão com o banco de dados: " . $e->getMessage());
                die("Erro ao conectar ao banco de dados: " . $e->getMessage());
            }
        }

        return self::$instancia;
    }
}
