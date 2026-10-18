<?php

/**
 * Classe Usuario
 *
 * Representa uma pessoa que usa o sistema (aluno, professor,
 * bibliotecário ou admin). Todo login usa só a tabela "usuario"
 * do banco, que já tem nome, e-mail, senha e o tipo de cada um.
 */
class Usuario
{
    private ?int $id_usuario;
    private string $nome;
    private string $email;
    private string $tipo_usuario;
    private bool $ativo;

    /**
     * Construtor: guarda os dados recebidos dentro do objeto,
     * assim que ele é criado com "new Usuario(...)".
     */
    public function __construct(
        string $nome,
        string $email,
        string $tipo_usuario,
        bool $ativo,
        ?int $id_usuario = null
    ) {
        $this->id_usuario = $id_usuario;
        $this->nome = $nome;
        $this->email = $email;
        $this->tipo_usuario = $tipo_usuario;
        $this->ativo = $ativo;
    }

    // Getters: só devolvem o valor guardado em cada atributo

    public function pegarId(): ?int
    {
        return $this->id_usuario;
    }

    public function pegarNome(): string
    {
        return $this->nome;
    }

    public function pegarEmail(): string
    {
        return $this->email;
    }

    public function pegarTipoUsuario(): string
    {
        return $this->tipo_usuario;
    }

    public function estaAtivo(): bool
    {
        return $this->ativo;
    }

    /**
     * Função de login. Confere os campos um de cada vez, na ordem,
     * e para assim que encontra um problema — devolvendo a mensagem
     * daquele problema específico.
     *
     * Sempre devolve um array com:
     * - "sucesso": true ou false
     * - "mensagem": o texto explicando o que aconteceu
     * - "usuario": o usuário encontrado (só quando sucesso é true)
     */
    public static function autenticar(string $email, string $senha): array
    {
        // 1) O campo de e-mail foi preenchido?
        if (trim($email) === "") {
            return [
                "sucesso" => false,
                "mensagem" => "Campo de e-mail vazio. Preencha e tente novamente.",
            ];
        }

        // 2) O campo de senha foi preenchido?
        if (trim($senha) === "") {
            return [
                "sucesso" => false,
                "mensagem" => "Campo de senha vazio. Preencha e tente novamente.",
            ];
        }

        // Tira espaços em branco e deixa tudo minúsculo, pra
        // "TESTE@etec.com" e " teste@etec.com " serem tratados
        // do mesmo jeito que "teste@etec.com"
        $email = strtolower(trim($email));

        // 3) O e-mail tem um formato válido (com "@", domínio, etc.)?
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                "sucesso" => false,
                "mensagem" => "Formato de e-mail inválido.",
            ];
        }

        // Pega a conexão com o banco e procura o e-mail na tabela usuario
        $conexao = Conexao::getConexao();
        $comando = $conexao->prepare("SELECT * FROM usuario WHERE email = :email");
        $comando->bindParam(":email", $email);
        $comando->execute();
        $dados = $comando->fetch();

        // 4) Existe algum usuário com esse e-mail e a senha bate?
        if (!$dados || !password_verify($senha, $dados["senha_hash"])) {
            return [
                "sucesso" => false,
                "mensagem" => "E-mail ou senha incorretos.",
            ];
        }

        // 5) A conta está ativa? (campo "ativo" da tabela usuario)
        if ((int) $dados["ativo"] === 0) {
            return [
                "sucesso" => false,
                "mensagem" => "Conta desativada. Entre em contato com o administrador.",
            ];
        }

        // Se passou por todas as checagens, o login deu certo.
        // Aproveita e grava a hora desse login (coluna já existia
        // no banco, ninguém tinha usado ainda).
        //
        // "atualizado_em = atualizado_em" é de propósito: no schema
        // novo essa coluna tem ON UPDATE CURRENT_TIMESTAMP, ou seja,
        // QUALQUER UPDATE na linha (mesmo só do ultimo_login) bateria
        // ela de novo. Sem esse truque, logar pareceria "atualização
        // de dados cadastrais" quando não é nada disso.
        $atualizar = $conexao->prepare(
            "UPDATE usuario SET ultimo_login = NOW(), atualizado_em = atualizado_em WHERE id_usuario = :id"
        );
        $atualizar->bindParam(":id", $dados["id_usuario"]);
        $atualizar->execute();

        $usuario = new Usuario(
            $dados["nome"],
            $dados["email"],
            $dados["tipo_usuario"],
            (bool) $dados["ativo"],
            $dados["id_usuario"]
        );

        return [
            "sucesso" => true,
            "mensagem" => "Login realizado com sucesso.",
            "usuario" => $usuario,
        ];
    }
}
