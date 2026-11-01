-- Requer MySQL 8.0.16 ou superior: em versões anteriores os CHECK
-- são aceitos, mas ignorados (as regras deixariam de ser aplicadas).

DROP DATABASE IF EXISTS biblioteca;

CREATE DATABASE biblioteca
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE biblioteca;

-- ============================================================
-- TABELA: CURSO
-- ============================================================

CREATE TABLE curso (
    id_curso INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_curso VARCHAR(70) NOT NULL,

    -- BOOLEAN substitui o antigo ENUM('Sim','Não')
    Mtec BOOLEAN NOT NULL DEFAULT FALSE
);

-- ============================================================
-- TABELA: CONFIGURACAO
-- ============================================================

CREATE TABLE configuracao (
    id_configuracao INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    valor_multa_diaria DECIMAL(10,2) NOT NULL DEFAULT 1.00,

    -- Máximo de livros emprestados ao mesmo tempo por aluno/professor.
    limite_emprestimos INT NOT NULL DEFAULT 3,

    CONSTRAINT chk_limite_emprestimos
        CHECK (limite_emprestimos > 0)
);

-- ============================================================
-- TABELA: TURMA
-- ============================================================

CREATE TABLE turma (
    id_turma INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_curso INT NULL,
    turno ENUM('Manhã', 'Tarde', 'Noite') NOT NULL,
    ano_calendario INT NOT NULL,
    periodo_letivo INT NOT NULL,

    FOREIGN KEY (id_curso)
        REFERENCES curso(id_curso)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

-- ============================================================
-- TABELA: USUARIO (aluno, professor e bibliotecario)
-- ============================================================

CREATE TABLE usuario (
    id_usuario INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,

    tipo_usuario ENUM(
        'aluno',
        'professor',
        'bibliotecario'
    ) NOT NULL DEFAULT 'aluno',

    -- Conta ativa/inativa (quem altera é a bibliotecária).
    ativo BOOLEAN NOT NULL DEFAULT TRUE,

    -- Somente aluno: identificado apenas pelo RA (sem matrícula).
    RA INT NULL UNIQUE,
    id_turma INT NULL,

    -- Somente professor.
    disciplina VARCHAR(50) NULL,
    matricula BIGINT NULL UNIQUE,

    -- RNF07: campos criptografados (AES-256) antes de gravar,
    -- por isso maiores que o texto original e dataNascimento
    -- deixou de ser DATE (virou texto).
    CPF VARCHAR(60) NULL UNIQUE,
    dataNascimento VARCHAR(60) NULL,
    endereco VARCHAR(320) NULL,
    telefone VARCHAR(60) NULL,

    ultimo_login DATETIME NULL,

    token_redefinicao VARCHAR(255) NULL,
    token_expiracao DATETIME NULL,

    -- Bibliotecária que cadastrou a conta (NULL = carga inicial).
    criado_por INT NULL,

    -- Data de cadastro e data da última atualização dos dados.
    -- Dica: ao registrar o login, use
    --   UPDATE usuario SET ultimo_login = NOW(), atualizado_em = atualizado_em ...
    -- para o login não contar como "atualização de dados".
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_turma)
        REFERENCES turma(id_turma)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (criado_por)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    -- Cada tipo de usuário precisa dos seus campos e não pode ter os
    -- dos outros. (id_turma fica fora do CHECK porque o MySQL não
    -- permite CHECK em coluna com FK ON UPDATE CASCADE; a turma do
    -- aluno é exigida em sp_criar_usuario.)
    CONSTRAINT chk_dados_por_tipo
        CHECK (
            (
                tipo_usuario = 'aluno'
                AND RA IS NOT NULL
                AND CPF IS NOT NULL
                AND dataNascimento IS NOT NULL
                AND endereco IS NOT NULL
                AND telefone IS NOT NULL
                AND disciplina IS NULL
                AND matricula IS NULL
            )
            OR
            (
                tipo_usuario = 'professor'
                AND matricula IS NOT NULL
                AND disciplina IS NOT NULL
                AND telefone IS NOT NULL
                AND RA IS NULL
            )
            OR
            (
                tipo_usuario = 'bibliotecario'
                AND CPF IS NOT NULL
                AND telefone IS NOT NULL
                AND RA IS NULL
                AND disciplina IS NULL
                AND matricula IS NULL
            )
        )
);

CREATE INDEX idx_usuario_tipo
    ON usuario(tipo_usuario, ativo);

-- ============================================================
-- TABELA: CATEGORIAS
-- ============================================================

CREATE TABLE categorias (
    id_cat INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_cat VARCHAR(30) NOT NULL UNIQUE
);

-- ============================================================
-- TABELA: AUTORES
-- ============================================================

CREATE TABLE autores (
    id_autor INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_autor VARCHAR(70) NOT NULL,
    nacionalidade_autor VARCHAR(60)
);

-- ============================================================
-- TABELA: LIVROS
-- ============================================================

CREATE TABLE livros (
    id_livro INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    id_autor INT NOT NULL,
    ISBN VARCHAR(17) NOT NULL UNIQUE,
    publicacao YEAR NOT NULL,
    editora VARCHAR(100) NOT NULL,
    id_cat INT NOT NULL,

    quantidade_total INT NOT NULL DEFAULT 1,
    quantidade_disponivel INT NOT NULL DEFAULT 1,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_cat)
        REFERENCES categorias(id_cat)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (id_autor)
        REFERENCES autores(id_autor)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_qtd_disponivel
        CHECK (
            quantidade_disponivel BETWEEN 0 AND quantidade_total
        )
);

CREATE INDEX idx_livros_titulo
    ON livros(titulo);

-- ============================================================
-- TABELA: EMPRESTIMO
-- ============================================================

CREATE TABLE emprestimo (
    id_emprestimo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    emp_ativo BOOLEAN NOT NULL DEFAULT TRUE,
    reserva BOOLEAN NOT NULL DEFAULT FALSE,

    dev_pdia INT NOT NULL DEFAULT 0,

    data_emp DATE NOT NULL,
    data_dev_prevista DATE NOT NULL,
    data_dev DATE NULL,

    multa_pendente BOOLEAN NOT NULL DEFAULT FALSE,
    valor_multa DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    id_usuario INT NOT NULL,
    id_livro INT NOT NULL,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (id_livro)
        REFERENCES livros(id_livro)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_multa_dev_pdia
        CHECK (
            NOT multa_pendente OR dev_pdia > 0
        ),

    CONSTRAINT chk_valor_multa
        CHECK (
            valor_multa >= 0
        ),

    -- CORRIGIDO (autorizado pelo grupo de banco de dados): a versão
    -- original só reconhecia dois estados (ativo / devolvido) e não
    -- deixava existir uma solicitação pendente ainda não aprovada
    -- (emp_ativo = FALSE, reserva = TRUE, data_dev = NULL), que é um
    -- terceiro estado real do sistema. Adicionado esse terceiro caso.
    CONSTRAINT chk_emp_ativo_data_dev
        CHECK (
            (emp_ativo = TRUE AND data_dev IS NULL)
            OR
            (emp_ativo = FALSE AND reserva = TRUE AND data_dev IS NULL)
            OR
            (emp_ativo = FALSE AND reserva = FALSE AND data_dev IS NOT NULL)
        ),

    CONSTRAINT chk_datas_emprestimo
        CHECK (
            data_dev_prevista >= data_emp
            AND
            (data_dev IS NULL OR data_dev >= data_emp)
        )
);

CREATE INDEX idx_emprestimo_ativo_data
    ON emprestimo(emp_ativo, data_dev_prevista);

-- ============================================================
-- TABELA: RESERVA_FILA
-- ============================================================

CREATE TABLE reserva_fila (
    id_reserva INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,
    id_livro INT NOT NULL,

    posicao_fila INT NOT NULL,
    atendida BOOLEAN NOT NULL DEFAULT FALSE,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_reserva_fila_livro_posicao
        (id_livro, posicao_fila),

    CONSTRAINT fk_reserva_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_reserva_livro
        FOREIGN KEY (id_livro)
        REFERENCES livros(id_livro)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

INSERT INTO configuracao
    (valor_multa_diaria, limite_emprestimos)
VALUES
    (1.00, 3);

-- ============================================================
-- CATEGORIAS
-- ============================================================

INSERT INTO categorias
    (id_cat, nome_cat)
VALUES
(1, 'Suspense'),
(2, 'Ficção científica'),
(3, 'Romance'),
(4, 'Fantasia'),
(5, 'Distopia'),
(6, 'Terror');

-- ============================================================
-- AUTORES
-- ============================================================

INSERT INTO autores
    (id_autor, nome_autor, nacionalidade_autor)
VALUES
(1, 'Colleen Hoover', 'Americana'),
(2, 'Dan Brown', 'Americana'),
(3, 'Paula Hawkins', 'Britânica'),
(4, 'George Orwell', 'Britânica'),
(5, 'Frank Herbert', 'Americana'),
(6, 'Ernest Cline', 'Americana'),
(7, 'Elle Kennedy', 'Canadense'),
(8, 'Jojo Moyes', 'Britânica'),
(9, 'Jane Austen', 'Britânica'),
(10, 'C. S. Lewis', 'Britânica'),
(11, 'J. K. Rowling', 'Britânica'),
(12, 'J. R. R. Tolkien', 'Britânica'),
(13, 'Suzanne Collins', 'Americana'),
(14, 'Margaret Atwood', 'Canadense'),
(15, 'William Peter Blatty', 'Americana'),
(16, 'Stephen King', 'Americana');

-- ============================================================
-- CURSOS
-- ============================================================

INSERT INTO curso
    (id_curso, nome_curso, Mtec)
VALUES
(1, 'Desenvolvimento de Sistemas', TRUE),
(2, 'Gastronomia', TRUE),
(3, 'Administração', FALSE),
(4, 'Economia', TRUE),
(5, 'Ciências Biológicas', FALSE);

-- ============================================================
-- TURMAS
-- ============================================================

INSERT INTO turma
    (id_turma, id_curso, turno, ano_calendario, periodo_letivo)
VALUES
(1, 1, 'Manhã', 2026, 1),
(2, 1, 'Tarde', 2026, 1),
(3, 2, 'Noite', 2026, 1),
(4, 3, 'Manhã', 2026, 1),
(5, 4, 'Tarde', 2026, 1),
(6, 5, 'Noite', 2026, 1);

-- ============================================================
-- LIVROS
-- ============================================================

INSERT INTO livros
    (
        id_livro,
        titulo,
        id_autor,
        ISBN,
        publicacao,
        editora,
        id_cat,
        quantidade_total,
        quantidade_disponivel
    )
VALUES
(1, 'Verity', 1, '9788501117847', 2018, 'Galera Record', 1, 2, 1), -- ISBN corrigido: confirmado em catálogo (Galera Record)
(2, 'O Código Da Vinci', 2, '9788575421130', 2004, 'Sextante', 1, 2, 1),
(3, 'A Garota no Trem', 3, '9788501104656', 2015, 'Record', 1, 2, 2),
(4, '1984', 4, '9788535914849', 1949, 'Companhia das Letras', 2, 2, 1),
(5, 'Duna', 5, '9788576573135', 2017, 'Aleph', 2, 2, 2),
(6, 'Jogador Nº 1', 6, '9788580443110', 2012, 'Leya', 2, 2, 1), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(7, 'O Acordo', 7, '9788576864950', 2015, 'Paralela', 3, 2, 2), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(8, 'Como Eu Era Antes de Você', 8, '9788580573299', 2013, 'Intrínseca', 3, 2, 2),
(9, 'Orgulho e Preconceito', 9, '9788563560155', 2011, 'Penguin/Companhia das Letras', 3, 2, 2),
(10, 'As Crônicas de Nárnia', 10, '9788578270698', 1950, 'Martins Fontes', 4, 2, 2), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(11, 'Harry Potter e a Pedra Filosofal', 11, '9788532511010', 2000, 'Rocco', 4, 2, 1),
(12, 'O Senhor dos Anéis: A Sociedade do Anel', 12, '9788595084759', 2019, 'HarperCollins Brasil', 4, 2, 1),
(13, 'Jogos Vorazes', 13, '9788579800245', 2008, 'Rocco', 5, 2, 2), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(14, 'O Conto da Aia', 14, '9788532520388', 2017, 'Rocco', 5, 2, 1), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(15, 'A revolução dos bichos', 4, '9788535909555', 2007, 'Companhia das Letras', 5, 2, 2),
(16, 'O Exorcista', 15, '9788576570530', 1971, 'HarperCollins Brasil', 6, 2, 2), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(17, 'It: A Coisa', 16, '9788556510396', 2017, 'Suma', 6, 2, 2), -- ISBN: dígito verificador recalculado, conferir na capa/catálogo
(18, 'O Iluminado', 16, '9788581050485', 2012, 'Suma', 6, 2, 2); -- ISBN: dígito verificador recalculado, conferir na capa/catálogo

-- ============================================================
-- USUARIOS (aluno, professor e bibliotecario)
-- ============================================================

-- criado_por fica NULL nos cadastros iniciais (carga do script).
INSERT INTO usuario
    (
        id_usuario, nome, email, senha_hash, tipo_usuario,
        RA, id_turma, disciplina, matricula,
        CPF, dataNascimento, endereco, telefone
    )
VALUES
-- Bibliotecárias
(31, 'Patrícia Souza Lima', 'patricia.lima@escola.edu.br', 'HASH_AQUI', 'bibliotecario', NULL, NULL, NULL, NULL, 'OF0zu1TKP8w669YxnZK5fQ==', NULL, NULL, 'BoRkNzN2qlTT1LjkOs+RbQ=='),
(32, 'Rafael Costa Almeida', 'rafael.almeida@escola.edu.br', 'HASH_AQUI', 'bibliotecario', NULL, NULL, NULL, NULL, 'Yh6/zq2vXgeJ59BotaWi6A==', NULL, NULL, 'edcNooZiMMsCRt3G1lBO/Q=='),
(33, 'Vanessa Oliveira Ramos', 'vanessa.ramos@escola.edu.br', 'HASH_AQUI', 'bibliotecario', NULL, NULL, NULL, NULL, 'k57gPibRs5q3GDWrXMRWXw==', NULL, NULL, 'aH5tPAmbqhVQJJdin5+LQQ=='),

-- Professores
(28, 'Roberto Nogueira', 'roberto.nogueira@escola.edu.br', 'HASH_AQUI', 'professor', NULL, NULL, 'Desenvolvimento de Sistemas', 30261001, NULL, NULL, NULL, 'A4n9/MEpoKzZ8ObybdT8Jg=='),
(29, 'Camila Ferreira Rocha', 'camila.rocha@escola.edu.br', 'HASH_AQUI', 'professor', NULL, NULL, 'Gastronomia', 30261002, NULL, NULL, NULL, 'Pg+bcuN7tA6ERUlYVM7rig=='),
(30, 'Eduardo Martins Silva', 'eduardo.silva@escola.edu.br', 'HASH_AQUI', 'professor', NULL, NULL, 'Administração', 30261003, NULL, NULL, NULL, 'kWQ/BEfKSAq5AK/TzFYZ4w=='),
(34, 'Fernanda Lopes Barros', 'fernanda.barros@escola.edu.br', 'HASH_AQUI', 'professor', NULL, NULL, 'Economia', 30261004, NULL, NULL, NULL, '9qT+EVkz3CrHL3BbkxWA+g=='),
(35, 'Thiago Ribeiro Nunes', 'thiago.nunes@escola.edu.br', 'HASH_AQUI', 'professor', NULL, NULL, 'Ciências Biológicas', 30261005, NULL, NULL, NULL, 'kgFL7rjzEYsVNGIW26c2gw=='),

-- Alunos
(1, 'Felipe', 'felipe@gmail.com', 'HASH_AQUI', 'aluno', 1001, 1, NULL, NULL, 'JxEV32VZQ+ni2w1DcqHrpw==', 'mh4kATkJAIrlZh4Sc1JcMg==', 'dw7cmytF0oa4tp3n2Qr0CyrjosO2h/pQlhsPilQrGe4=', 'BS8HuwRiqdJsLQRP7NVodw=='),
(2, 'Murillo T', 'murillot@gmail.com', 'HASH_AQUI', 'aluno', 1002, 1, NULL, NULL, 'z8tdjCrze+xjyx6jIfGcfg==', 'aIYxZQyAiHSZeBHGkSMGwQ==', 'cFB39YMclTF9BqD4euJdTr374IDC71VFdMq69WgnLwY=', 'R0YTc3tJf/likFB4DwoXOA=='),
(3, 'Vinicius N', 'viniciusn@gmail.com', 'HASH_AQUI', 'aluno', 1003, 1, NULL, NULL, 'iZNlIKfrFi7F3NH71jyE7Q==', 'IJjzlpoHkE+qu3a/7acduA==', 'gM3kzN+Sb3oiDYHUz682k4rcQBtt7/IIN/iuEkq4PCE=', 'X441Gd1MxepXNu+O+AcwkA=='),
(4, 'Nicolas', 'nicolas@gmail.com', 'HASH_AQUI', 'aluno', 1004, 1, NULL, NULL, '4ARNDtDr3J/qne8Mcb2/Lw==', 'f2/9HVRXlp8KSdYrxp7KVw==', 'VnpwoSJHDP83k7ctOb6Wzvi0HUi+hBO2zB2bAVWN+0s=', 'hT1Qm5W+XzyooXIIp13kDw=='),
(5, 'Guilherme', 'guilherme@gmail.com', 'HASH_AQUI', 'aluno', 1005, 1, NULL, NULL, 'EI6gerDE/lQRCj9hVYJN4w==', 'WnHH7el9TBDVfk/Xlz6Jog==', 'SBwWG/jHyRrqO/Ah3fv831YrWWTe76FHDVZRyOELbB4=', 'phS5Rse3aX1VxFH4k8AWmg=='),
(6, 'Maicon', 'maicon@gmail.com', 'HASH_AQUI', 'aluno', 1006, 1, NULL, NULL, '6Jom/p7wzkgnX6vWl5tjZw==', 'vaw0f+0d4FnivHfU9Y7X6A==', 'srUA6q7eEVYO4bZViAyZYC+E4Q+8qgksAAKkQdL5DIs=', '89vlewEPue49h78T3twYKg=='),
(7, 'Isabelly', 'isabelly@gmail.com', 'HASH_AQUI', 'aluno', 1007, 2, NULL, NULL, 'BEInSSRb0OyjVGWj3Y1u5Q==', '1x7ZiH6IOWLOCyS2x6n2Hg==', 'BI5Jw9+Vxddf4ktMsipdEM43ozg55J1ioSCHv4N+nZA=', 'xyXfqoLN8zOW1h1WkTuYMA=='),
(8, 'Gabriel', 'gabriel@gmail.com', 'HASH_AQUI', 'aluno', 1008, 2, NULL, NULL, 'Rgw6Eff0/g2WXDHnsMqjpg==', 'Zue30qAxn+LlT73lYwc1ng==', 'V9jUgbhdj5lVmKEB7J21kw==', 'dE+3CaH5qTpnO5dY51/byg=='),
(9, 'Vinicius T', 'viniciust@gmail.com', 'HASH_AQUI', 'aluno', 1009, 2, NULL, NULL, 'mTzWA16JOBBolwpaLh9TfA==', '/qGc1SjrnidUwEQBglu5iQ==', 'ddbJY+SkjOHK1/VwomDH9Q==', 'Z/yHed7ypEi6D/LdObT2UQ=='),
(10, 'Murillo R', 'murillor@gmail.com', 'HASH_AQUI', 'aluno', 1010, 2, NULL, NULL, 'IbFqGDCHMIkuRuzVnduxrg==', 'qp6sZUHYzPacctttVNMv4g==', 'MJKk/RekpVU4FbLEBuYD73Yuer6nRWAvjkmnKip6Aws=', 'hV9A4nIAFHUSqcVffWFajQ=='),
(11, 'Bianca', 'bianca@gmail.com', 'HASH_AQUI', 'aluno', 1011, 2, NULL, NULL, 'S5amp8kK2QfCC7Uix5uwCQ==', 'dabGjOTzRwNbsAzfMJT6PQ==', '7hVIE5NHj+aVJ8rTkW6D9L3KI4S3MZ4zga9WXIFRbjc=', 'zG50Z78HShdqDv+OvaQpbw=='),
(12, 'Leonardo', 'leonardo@gmail.com', 'HASH_AQUI', 'aluno', 1012, 2, NULL, NULL, '7VNAZXvoY9BhWVEuPW/VfQ==', 'OfSE0jK+K9YGvmn1KRSaxA==', 'i4+KJfVIJHJC+jG1dAbr1pSwsPOZvlinPndS4+bJQqI=', 'pU8zHW+DI78JpLfNwlV+xg=='),
(13, 'Alessandro', 'alessandro@gmail.com', 'HASH_AQUI', 'aluno', 1013, 2, NULL, NULL, 'qtOHJV3sfGyjI0fAKGyNVQ==', 'hKnYt2BzZgtxh9PVFVABdA==', 'M7RHh7KW09M4wuThmj8gNdF/GNXfrQSQD8kL8qdmOco=', 'a2Qk0eYf5ktPoOo/LPtokg=='),
(14, 'Zaion', 'zaion@gmail.com', 'HASH_AQUI', 'aluno', 1014, 2, NULL, NULL, 'yOaQuNwx6nCiDeTOQCVmqg==', 'qXPzgmcDDx7O7keMzu6yyQ==', 'cFB39YMclTF9BqD4euJdTlO2kAL2Rrp4dRIHhAhdei0=', '/zFGFojs3x6zKT1LHSm0ig=='),
(15, 'Pedro', 'pedro@gmail.com', 'HASH_AQUI', 'aluno', 1015, 2, NULL, NULL, '36GefqgYUNoEdMIBGFDFng==', '9/3ypsJ4QkVtHdiDJGtz5w==', '2ck3Jm4cfvWHpXN8LqFddMphzT9R/LvXHhDh9TzrEes=', 'UFUuc/K1lgA1UBvbYZrXgg=='),
(16, 'Thiago', 'thiago@gmail.com', 'HASH_AQUI', 'aluno', 1016, 2, NULL, NULL, 'CcFROICXFSo2DA/jEsgG9A==', 'im9LePdwYJ9vD02vlGm6FA==', 'GosZvWK5m6ajSlVBo9jLWTdZ8fNYxcSAhkAfrVVQRL8=', 'z41MStNzLY08cXgIFJCc6w=='),
(17, 'Weslley', 'weslley@gmail.com', 'HASH_AQUI', 'aluno', 1017, 1, NULL, NULL, 'Sj0WOVAfJktvCoOmjOQNrQ==', 'M076gjCsH5P1Fv946D0MWQ==', 'xeaqtcZH5rIDXttRhgyqNrpHLM9k6FMw9oFyFWjHJOE=', 'PAShQm4NKhqb972d3kJ41g=='),
(18, 'Lucas', 'lucas@gmail.com', 'HASH_AQUI', 'aluno', 1018, 1, NULL, NULL, 'VhZLtX6xPqmgwN9an1w6Iw==', 'BoaeBFGVx5qhFhJErR/+eA==', 'hhQoFDTU+C0wOqMLTOuPdOkAwfT0RPG9KUJ79Itehjk=', 'EFxg9wDSTKV33pH8sKaMuw=='),
(19, 'Nicol', 'nicol@gmail.com', 'HASH_AQUI', 'aluno', 1019, 1, NULL, NULL, 'KCJ1WmmXjH+lV2krmuvYQg==', 'f5X/Vhmb2PGodsBhTsknRw==', 'CArRdDdPIcsXVYzNyyeDkBvsxONCCNOPj3fXLiVesRo=', 'O7uV1az+iUI2grUkUKYV2A=='),
(20, 'Arthur R', 'arthurr@gmail.com', 'HASH_AQUI', 'aluno', 1020, 4, NULL, NULL, 'fR5tYtNjW3lDsaV4sU52tw==', 'AyKzFXKTb5m3nP+XbBfjAQ==', 'gxran1bDqjdbl3X5fOyug+sBoQrEHGJA9Mo7+QrrO4E=', 'f2wngaP9lPOOHytFswCTew=='),
(21, 'João', 'joao@gmail.com', 'HASH_AQUI', 'aluno', 1021, 4, NULL, NULL, 'X4Joj/VN0jnmPU13NNNEcw==', '4dlz9ugH3Q8Qk8YaLCCqZg==', 'Eslm8ShbI8BCz9VmUBfLgaIkX0T/aTfK4OF8Fv0etpo=', 'HTdj/73RW0EPjkvBe1Wg6g=='),
(22, 'Arthur P', 'arthurp@gmail.com', 'HASH_AQUI', 'aluno', 1022, 5, NULL, NULL, 'egfKmfDs8XQHPLjdJP4J0g==', 'oaYSvzlVNr30D2D89pTLaw==', 'AEBRTVUC5kl9Et0NRdrZqPdmWlBYFEkSoT/XtQBZxRs=', 'em3/b2PI6+DNAuQqwnL52w=='),
(23, 'Enzo', 'enzo@gmail.com', 'HASH_AQUI', 'aluno', 1023, 3, NULL, NULL, 'QHKkwgvSVjCvPuBPe5wxew==', 'i4FV5A8QXRnwlwQ7TjRSlQ==', 'sac1TlIw+AwFcrfSt2N9wgdXiMZ3kJj/O/NBCXx6260=', 'taBfQLcgcGPtpOgreZa+0Q=='),
(24, 'Luiz', 'luiz@gmail.com', 'HASH_AQUI', 'aluno', 1024, 1, NULL, NULL, 'FlYpD0iHV2UltwWZvcxr7A==', '7BDslpSbAxWdzxDIARgOKw==', 'qiY8K6wTnNR/kKlNVHLFHOyWLSN3UoCjc7WrNJDr2jg=', '728na4ywVCS2ZoI/gAD7GA=='),
(25, 'Grazielle', 'grazielle@gmail.com', 'HASH_AQUI', 'aluno', 1025, 6, NULL, NULL, 'gNjC2EPBLR1XnKIDaRa0Aw==', 'wrDQ/zuLFbv6/CceE/5HHQ==', 'W6OnFfUEhKHhiR1+Nk3U8kTg32DDB25tbtq/RFqzuFE=', 'lrBdSJnlXenl0dFsCCHtxQ=='),
(26, 'Kauan', 'kauan@gmail.com', 'HASH_AQUI', 'aluno', 1026, 3, NULL, NULL, '2VAIynj8OJIRMaecalq+iw==', 'Xqu5BjNY04+8/gW3Cj047A==', 'pOHs4ra3GUxXSMwBZ+BBQup0/vMoJyiS0hmAoV5x4CE=', '74v9ndx8ytE+oEzS6W6b5Q=='),
(27, 'Isadora', 'isadora@gmail.com', 'HASH_AQUI', 'aluno', 1027, 3, NULL, NULL, 'BZMivgUUNW+Eofljy48t0g==', 'UHCAESuD3WnAbqfbbFqbdA==', 'wDgUqHV7uPCMkA7dl3t5XBYddWuYjOgF5tc0eA4IilQ=', '0LhZ8gjYLXFqLNqWRerAHA==');

-- ============================================================
-- EMPRESTIMOS INICIAIS
-- ============================================================

INSERT INTO emprestimo
    (
        emp_ativo,
        reserva,
        dev_pdia,
        data_emp,
        data_dev_prevista,
        data_dev,
        multa_pendente,
        valor_multa,
        id_usuario,
        id_livro
    )
VALUES
(TRUE,  FALSE, 0, '2026-09-01', '2026-09-15', NULL,         FALSE, 0.00, 1, 4),
(TRUE,  FALSE, 0, '2026-09-05', '2026-09-19', NULL,         FALSE, 0.00, 2, 11),
(FALSE, FALSE, 0, '2026-08-10', '2026-08-24', '2026-08-22', FALSE, 0.00, 3, 17),
(FALSE, FALSE, 0, '2026-08-12', '2026-08-26', '2026-08-26', FALSE, 0.00, 4, 9),
(TRUE,  FALSE, 6, '2026-08-20', '2026-09-03', NULL,         TRUE,  6.00, 5, 1),
(FALSE, FALSE, 0, '2026-07-15', '2026-07-29', '2026-07-28', FALSE, 0.00, 6, 5),
(TRUE,  TRUE,  0, '2026-09-10', '2026-09-24', NULL,         FALSE, 0.00, 7, 12),
(FALSE, FALSE, 5, '2026-08-01', '2026-08-15', '2026-08-20', FALSE, 5.00, 8, 16), -- CORREÇÃO: 5 dias de atraso, multa já quitada
(TRUE,  FALSE, 0, '2026-09-12', '2026-09-26', NULL,         FALSE, 0.00, 9, 2),
(FALSE, FALSE, 0, '2026-07-01', '2026-07-15', '2026-07-14', FALSE, 0.00, 10, 8),
(TRUE,  FALSE, 3, '2026-08-28', '2026-09-11', NULL,         TRUE,  3.00, 11, 14),
(FALSE, FALSE, 0, '2026-06-20', '2026-07-04', '2026-07-04', FALSE, 0.00, 12, 18),
(TRUE,  FALSE, 0, '2026-09-08', '2026-10-08', NULL,         FALSE, 0.00, 17, 6);

-- ============================================================
-- LISTA DE DESEJOS
-- ============================================================

CREATE TABLE lista_desejos (
    id_lista INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,
    id_livro INT NOT NULL,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_lista_usuario_livro
        (id_usuario, id_livro),

    CONSTRAINT fk_lista_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_lista_livro
        FOREIGN KEY (id_livro)
        REFERENCES livros(id_livro)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

-- ============================================================
-- NOTIFICACAO
-- ============================================================

CREATE TABLE notificacao (
    id_notificacao INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,

    tipo VARCHAR(40) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    mensagem VARCHAR(500) NOT NULL,

    lida BOOLEAN NOT NULL DEFAULT FALSE,

    criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lida_em DATETIME NULL,

    CONSTRAINT fk_notificacao_usuario
        FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE INDEX idx_notificacao_usuario_lida
    ON notificacao(id_usuario, lida);

-- ============================================================
-- TRIGGER: DEVOLUCAO
-- ============================================================

DELIMITER //

CREATE TRIGGER trg_emprestimo_calc_devolucao
BEFORE UPDATE ON emprestimo
FOR EACH ROW
BEGIN
    DECLARE v_multa_diaria DECIMAL(10,2);

    IF NEW.data_dev IS NOT NULL
       AND OLD.data_dev IS NULL THEN

        SET NEW.dev_pdia =
            GREATEST(
                DATEDIFF(
                    NEW.data_dev,
                    NEW.data_dev_prevista
                ),
                0
            );

        SELECT valor_multa_diaria
        INTO v_multa_diaria
        FROM configuracao
        ORDER BY id_configuracao
        LIMIT 1;

        SET NEW.valor_multa =
            NEW.dev_pdia * v_multa_diaria;

        SET NEW.multa_pendente =
            (NEW.dev_pdia > 0);

        SET NEW.emp_ativo = FALSE;

        -- CORREÇÃO: sem isto, devolver um empréstimo com reserva = TRUE
        -- (como o id 7 dos dados iniciais) violava o CHECK
        -- chk_emp_ativo_data_dev e a devolução dava erro.
        SET NEW.reserva = FALSE;

    END IF;
END //

DELIMITER ;

-- ============================================================
-- PROCEDURE: ATUALIZAR ATRASOS
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_atualizar_atrasos ()
BEGIN

    UPDATE emprestimo E

    JOIN (
        SELECT valor_multa_diaria
        FROM configuracao
        ORDER BY id_configuracao
        LIMIT 1
    ) C

    SET
        E.dev_pdia =
            GREATEST(
                DATEDIFF(
                    CURDATE(),
                    E.data_dev_prevista
                ),
                0
            ),

        E.valor_multa =
            GREATEST(
                DATEDIFF(
                    CURDATE(),
                    E.data_dev_prevista
                ),
                0
            ) * C.valor_multa_diaria,

        E.multa_pendente =
            (
                DATEDIFF(
                    CURDATE(),
                    E.data_dev_prevista
                ) > 0
            )

    WHERE E.emp_ativo = TRUE
      AND E.data_dev IS NULL
      AND CURDATE() > E.data_dev_prevista;

END //

DELIMITER ;

-- ============================================================
-- EVENT: ATUALIZAR ATRASOS (executa 1x por dia)
-- ============================================================

-- CORREÇÃO: sem o agendador ligado, o event nunca executa.
-- (exige usuário com privilégio, ex.: root)
SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS ev_atualizar_atrasos
ON SCHEDULE EVERY 1 DAY
STARTS (CURRENT_DATE + INTERVAL 1 DAY)
DO CALL sp_atualizar_atrasos();

-- CORREÇÃO: aplica o cálculo de atraso já nos dados iniciais
-- (antes eles ficavam desatualizados até o primeiro disparo do event).
-- SQL_SAFE_UPDATES bloquearia o UPDATE de dentro dessa procedure
-- (não usa uma coluna de chave no WHERE) — desligamos só pra essa
-- chamada, igual já fazemos com a senha de teste mais abaixo.
SET SQL_SAFE_UPDATES = 0;
CALL sp_atualizar_atrasos();
SET SQL_SAFE_UPDATES = 1;

-- ============================================================
-- REGRAS DE EMPRESTIMO NO BANCO (valem mesmo sem passar pelas
-- procedures): usuário aluno/professor e ativo, sem livro em
-- atraso e com menos livros ativos que o limite (padrão: 3).
-- Aplicadas quando um empréstimo nasce ativo/pendente (INSERT) e
-- quando uma solicitação pendente é aprovada (emp_ativo FALSE -> TRUE).
-- A carga inicial acima é anterior a estes triggers.
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_validar_regras_emprestimo (
    IN p_id_usuario INT
)
BEGIN

    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_ativo BOOLEAN DEFAULT FALSE;
    DECLARE v_tipo VARCHAR(20) DEFAULT NULL;
    DECLARE v_atraso INT DEFAULT 0;
    DECLARE v_ativos INT DEFAULT 0;
    DECLARE v_limite INT DEFAULT 3;

    SELECT
        COUNT(*),
        COALESCE(MAX(ativo), FALSE),
        MAX(tipo_usuario)
    INTO
        v_existe,
        v_ativo,
        v_tipo
    FROM usuario
    WHERE id_usuario = p_id_usuario;

    IF v_existe = 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    IF v_tipo NOT IN ('aluno', 'professor') THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente alunos e professores podem realizar empréstimos.';

    END IF;

    IF v_ativo = FALSE THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário está inativo e não pode realizar empréstimos.';

    END IF;

    SELECT COUNT(*)
    INTO v_atraso
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND emp_ativo = TRUE
      AND data_dev_prevista < CURDATE();

    IF v_atraso > 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário possui livro em atraso e não pode realizar novos empréstimos.';

    END IF;

    SELECT limite_emprestimos
    INTO v_limite
    FROM configuracao
    ORDER BY id_configuracao
    LIMIT 1;

    SELECT COUNT(*)
    INTO v_ativos
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND emp_ativo = TRUE;

    IF v_ativos >= v_limite THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Limite de livros emprestados ao mesmo tempo atingido.';

    END IF;

END //

CREATE TRIGGER trg_emprestimo_regras_insert
BEFORE INSERT ON emprestimo
FOR EACH ROW
BEGIN

    -- Linhas já devolvidas (histórico) não passam pela validação.
    IF NEW.emp_ativo = TRUE OR NEW.reserva = TRUE THEN

        CALL sp_validar_regras_emprestimo(NEW.id_usuario);

    END IF;

END //

CREATE TRIGGER trg_emprestimo_regras_update
BEFORE UPDATE ON emprestimo
FOR EACH ROW
FOLLOWS trg_emprestimo_calc_devolucao
BEGIN

    -- Aprovação de uma solicitação pendente (vira empréstimo ativo).
    IF OLD.emp_ativo = FALSE AND NEW.emp_ativo = TRUE THEN

        CALL sp_validar_regras_emprestimo(NEW.id_usuario);

    END IF;

END //

-- Turma por tipo de conta: aluno sempre tem turma; professor e
-- bibliotecária nunca têm.
CREATE TRIGGER trg_usuario_turma_insert
BEFORE INSERT ON usuario
FOR EACH ROW
BEGIN

    IF NEW.tipo_usuario = 'aluno' AND NEW.id_turma IS NULL THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O aluno precisa estar em uma turma.';

    END IF;

    IF NEW.tipo_usuario <> 'aluno' AND NEW.id_turma IS NOT NULL THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente o aluno pode ter turma.';

    END IF;

END //

CREATE TRIGGER trg_usuario_turma_update
BEFORE UPDATE ON usuario
FOR EACH ROW
BEGIN

    IF NEW.tipo_usuario = 'aluno' AND NEW.id_turma IS NULL THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O aluno precisa estar em uma turma.';

    END IF;

    IF NEW.tipo_usuario <> 'aluno' AND NEW.id_turma IS NOT NULL THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente o aluno pode ter turma.';

    END IF;

END //

DELIMITER ;

-- ============================================================
-- PROCEDURE: REALIZAR EMPRESTIMO
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_realizar_emprestimo (
    IN p_id_usuario INT,
    IN p_id_livro INT,
    IN p_dias_emprestimo INT
)
BEGIN

    DECLARE v_disponivel INT DEFAULT NULL;
    DECLARE v_usuario_existe INT DEFAULT 0;
    DECLARE v_usuario_ativo BOOLEAN DEFAULT FALSE;
    DECLARE v_tipo_usuario VARCHAR(20) DEFAULT NULL;
    DECLARE v_atraso INT DEFAULT 0;
    DECLARE v_multa_pendente INT DEFAULT 0;
    DECLARE v_emprestimos_ativos INT DEFAULT 0;
    DECLARE v_limite INT DEFAULT 3;
    DECLARE v_emprestimo_existente INT DEFAULT 0;

    -- Qualquer erro inesperado desfaz a transação.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Valida quantidade de dias.
    IF p_dias_emprestimo <= 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'A quantidade de dias deve ser maior que zero.';

    END IF;

    -- Verifica se o usuário existe. O FOR UPDATE trava a linha do
    -- usuário: dois empréstimos simultâneos da mesma pessoa não
    -- conseguem passar juntos pelo limite.
    SELECT
        COUNT(*),
        COALESCE(MAX(ativo), FALSE),
        MAX(tipo_usuario)
    INTO
        v_usuario_existe,
        v_usuario_ativo,
        v_tipo_usuario
    FROM usuario
    WHERE id_usuario = p_id_usuario
    FOR UPDATE;

    IF v_usuario_existe = 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    -- Somente alunos e professores pegam livros emprestados.
    IF v_tipo_usuario NOT IN ('aluno', 'professor') THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente alunos e professores podem realizar empréstimos.';

    END IF;

    -- Somente usuários ativos.
    IF v_usuario_ativo = FALSE THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário está inativo e não pode realizar empréstimos.';

    END IF;

    -- Quem tem livro atrasado não pega mais nenhum.
    SELECT COUNT(*)
    INTO v_atraso
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND emp_ativo = TRUE
      AND data_dev_prevista < CURDATE();

    IF v_atraso > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário possui livro em atraso e não pode realizar novos empréstimos.';

    END IF;

    -- Multa ainda não paga (inclusive de livros já devolvidos).
    SELECT COUNT(*)
    INTO v_multa_pendente
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND multa_pendente = TRUE;

    IF v_multa_pendente > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário possui multa pendente.';

    END IF;

    -- Limite de livros emprestados ao mesmo tempo (padrão: 3).
    SELECT limite_emprestimos
    INTO v_limite
    FROM configuracao
    ORDER BY id_configuracao
    LIMIT 1;

    SELECT COUNT(*)
    INTO v_emprestimos_ativos
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND emp_ativo = TRUE;

    IF v_emprestimos_ativos >= v_limite THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Limite de livros emprestados ao mesmo tempo atingido.';

    END IF;

    -- Impede dois empréstimos ativos do mesmo livro
    -- pelo mesmo usuário.
    SELECT COUNT(*)
    INTO v_emprestimo_existente
    FROM emprestimo
    WHERE id_usuario = p_id_usuario
      AND id_livro = p_id_livro
      AND emp_ativo = TRUE;

    IF v_emprestimo_existente > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário já possui este livro emprestado.';

    END IF;

    -- Trava o livro para evitar condição de corrida.
    SELECT quantidade_disponivel
    INTO v_disponivel
    FROM livros
    WHERE id_livro = p_id_livro
    FOR UPDATE;

    IF v_disponivel IS NULL THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Livro não encontrado.';

    ELSEIF v_disponivel <= 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Nenhum exemplar disponível para empréstimo.';

    ELSE

        UPDATE livros
        SET quantidade_disponivel =
            quantidade_disponivel - 1
        WHERE id_livro = p_id_livro;

        INSERT INTO emprestimo
        (
            emp_ativo,
            reserva,
            dev_pdia,
            data_emp,
            data_dev_prevista,
            data_dev,
            multa_pendente,
            valor_multa,
            id_usuario,
            id_livro
        )
        VALUES
        (
            TRUE,
            FALSE,
            0,
            CURDATE(),
            DATE_ADD(
                CURDATE(),
                INTERVAL p_dias_emprestimo DAY
            ),
            NULL,
            FALSE,
            0.00,
            p_id_usuario,
            p_id_livro
        );

        COMMIT;

    END IF;

END //

DELIMITER ;

-- ============================================================
-- PROCEDURE: DEVOLVER LIVRO
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_devolver_livro (
    IN p_id_emprestimo INT
)
BEGIN

    DECLARE v_id_livro INT DEFAULT NULL;
    DECLARE v_id_reserva INT DEFAULT NULL;
    DECLARE v_id_usuario_fila INT DEFAULT NULL;
    DECLARE v_titulo VARCHAR(150);

    -- CORREÇÃO: qualquer erro inesperado desfaz a transação.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT id_livro
    INTO v_id_livro
    FROM emprestimo
    WHERE id_emprestimo = p_id_emprestimo
      AND emp_ativo = TRUE
    FOR UPDATE;

    IF v_id_livro IS NULL THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Empréstimo não encontrado ou já devolvido.';

    ELSE

        -- A trigger calcula multa e encerra o empréstimo.
        UPDATE emprestimo
        SET data_dev = CURDATE()
        WHERE id_emprestimo = p_id_emprestimo;

        -- Devolve o exemplar para o estoque.
        UPDATE livros
        SET quantidade_disponivel =
            quantidade_disponivel + 1
        WHERE id_livro = v_id_livro;

        -- Procura o primeiro usuário da fila.
        SELECT
            id_reserva,
            id_usuario
        INTO
            v_id_reserva,
            v_id_usuario_fila
        FROM reserva_fila
        WHERE id_livro = v_id_livro
          AND atendida = FALSE
        ORDER BY posicao_fila
        LIMIT 1
        FOR UPDATE;

        IF v_id_reserva IS NOT NULL THEN

            SELECT titulo
            INTO v_titulo
            FROM livros
            WHERE id_livro = v_id_livro;

            UPDATE reserva_fila
            SET atendida = TRUE
            WHERE id_reserva = v_id_reserva;

            INSERT INTO notificacao
            (
                id_usuario,
                tipo,
                titulo,
                mensagem
            )
            VALUES
            (
                v_id_usuario_fila,
                'reserva_disponivel',
                'Livro disponível',
                CONCAT(
                    'O livro "',
                    v_titulo,
                    '" que você reservou já está disponível para retirada.'
                )
            );

        END IF;

        COMMIT;

    END IF;

END //

DELIMITER ;

-- ============================================================
-- PROCEDURE: CRIAR RESERVA
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_criar_reserva (
    IN p_id_usuario INT,
    IN p_id_livro INT
)
BEGIN

    DECLARE v_livro_trava INT DEFAULT NULL;
    DECLARE v_disponivel INT DEFAULT NULL;
    DECLARE v_proxima_posicao INT DEFAULT 1;
    DECLARE v_usuario_existe INT DEFAULT 0;
    DECLARE v_usuario_ativo BOOLEAN DEFAULT FALSE;
    DECLARE v_reserva_ativa INT DEFAULT 0;

    -- CORREÇÃO: qualquer erro inesperado desfaz a transação.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Verifica usuário.
    SELECT
        COUNT(*),
        COALESCE(MAX(ativo), FALSE)
    INTO
        v_usuario_existe,
        v_usuario_ativo
    FROM usuario
    WHERE id_usuario = p_id_usuario;

    IF v_usuario_existe = 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    IF v_usuario_ativo = FALSE THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário está inativo.';

    END IF;

    -- Trava o livro.
    SELECT
        id_livro,
        quantidade_disponivel
    INTO
        v_livro_trava,
        v_disponivel
    FROM livros
    WHERE id_livro = p_id_livro
    FOR UPDATE;

    IF v_livro_trava IS NULL THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Livro não encontrado.';

    END IF;

    -- CORREÇÃO:
    -- reserva só é necessária quando não há exemplar disponível.
    IF v_disponivel > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O livro possui exemplar disponível. Não é necessário reservar.';

    END IF;

    -- CORREÇÃO:
    -- verifica somente reservas ainda ativas.
    SELECT COUNT(*)
    INTO v_reserva_ativa
    FROM reserva_fila
    WHERE id_usuario = p_id_usuario
      AND id_livro = p_id_livro
      AND atendida = FALSE;

    IF v_reserva_ativa > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário já possui uma reserva ativa para este livro.';

    END IF;

    /*
    CORREÇÃO IMPORTANTE:

    O MAX não considera somente reservas não atendidas.

    Dessa forma, as posições antigas não são reutilizadas.

    Exemplo:

    Reserva antiga = posição 1
    Reserva antiga = posição 2
    Nova reserva   = posição 3

    Mesmo que as reservas 1 e 2 já tenham sido atendidas,
    a posição 3 continua única.
    */

    SELECT
        IFNULL(MAX(posicao_fila), 0) + 1
    INTO v_proxima_posicao
    FROM reserva_fila
    WHERE id_livro = p_id_livro;

    INSERT INTO reserva_fila
    (
        id_usuario,
        id_livro,
        posicao_fila,
        atendida
    )
    VALUES
    (
        p_id_usuario,
        p_id_livro,
        v_proxima_posicao,
        FALSE
    );

    COMMIT;

END //

DELIMITER ;

-- ============================================================
-- BIBLIOTECARIA: FUNCAO AUXILIAR
-- ============================================================

DELIMITER //

CREATE FUNCTION fn_bibliotecario_ativo (
    p_id_usuario INT
)
RETURNS BOOLEAN
READS SQL DATA
BEGIN

    RETURN EXISTS (
        SELECT 1
        FROM usuario
        WHERE id_usuario = p_id_usuario
          AND tipo_usuario = 'bibliotecario'
          AND ativo = TRUE
    );

END //

DELIMITER ;

-- ============================================================
-- BIBLIOTECARIA: CONSULTA DE ALUNOS E PROFESSORES
-- (nenhuma view expõe senha_hash nem tokens)
-- ============================================================

CREATE VIEW vw_alunos AS
SELECT
    U.id_usuario,
    U.RA,
    U.nome,
    U.email,
    U.telefone,
    U.ativo,
    C.id_curso,
    C.nome_curso,
    T.id_turma,
    T.turno,
    T.ano_calendario,
    T.periodo_letivo,
    U.criado_em,
    U.atualizado_em
FROM usuario U
LEFT JOIN turma T
    ON U.id_turma = T.id_turma
LEFT JOIN curso C
    ON T.id_curso = C.id_curso
WHERE U.tipo_usuario = 'aluno';

CREATE VIEW vw_professores AS
SELECT
    U.id_usuario,
    U.matricula,
    U.nome,
    U.email,
    U.telefone,
    U.disciplina,
    U.ativo,
    U.criado_em,
    U.atualizado_em
FROM usuario U
WHERE U.tipo_usuario = 'professor';

DELIMITER //

-- Somente a bibliotecária ativa consulta (p_id_bibliotecario = id de
-- quem está logada). Lista todos os alunos de todos os cursos e mostra
-- a situação (Ativo/Inativo). Cada filtro é opcional (NULL = sem filtro).
-- Exemplos (31 = id da bibliotecária):
--   CALL sp_listar_alunos(31, NULL, NULL, NULL, NULL);   -- todos
--   CALL sp_listar_alunos(31, 1, 'Manhã', NULL, NULL);   -- curso 1, manhã
--   CALL sp_listar_alunos(31, NULL, NULL, 2, 'ana');     -- turma 2, nome/RA com "ana"
CREATE PROCEDURE sp_listar_alunos (
    IN p_id_bibliotecario INT,
    IN p_id_curso INT,
    IN p_turno VARCHAR(10),
    IN p_id_turma INT,
    IN p_busca VARCHAR(100)
)
BEGIN

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode consultar alunos.';

    END IF;

    SELECT
        id_usuario,
        RA,
        nome,
        email,
        telefone,
        IF(ativo, 'Ativo', 'Inativo') AS situacao,
        nome_curso,
        turno,
        id_turma,
        ano_calendario,
        periodo_letivo,
        criado_em,
        atualizado_em
    FROM vw_alunos
    WHERE (p_id_curso IS NULL OR id_curso = p_id_curso)
      AND (p_turno IS NULL OR turno = p_turno)
      AND (p_id_turma IS NULL OR id_turma = p_id_turma)
      AND (
            p_busca IS NULL
            OR nome LIKE CONCAT('%', p_busca, '%')
            OR CAST(RA AS CHAR) LIKE CONCAT('%', p_busca, '%')
          )
    ORDER BY nome;

END //

CREATE PROCEDURE sp_listar_professores (
    IN p_id_bibliotecario INT,
    IN p_busca VARCHAR(100)
)
BEGIN

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode consultar professores.';

    END IF;

    SELECT
        id_usuario,
        matricula,
        nome,
        email,
        telefone,
        disciplina,
        IF(ativo, 'Ativo', 'Inativo') AS situacao,
        criado_em,
        atualizado_em
    FROM vw_professores
    WHERE p_busca IS NULL
       OR nome LIKE CONCAT('%', p_busca, '%')
       OR disciplina LIKE CONCAT('%', p_busca, '%')
    ORDER BY nome;

END //

DELIMITER ;

-- ============================================================
-- BIBLIOTECARIA: CRIAR, EDITAR, ATIVAR/INATIVAR E EXCLUIR CONTAS
-- Somente uma bibliotecária ativa consegue executar estas
-- procedures (p_id_bibliotecario = id_usuario de quem está logada).
-- Os campos criptografados (CPF, nascimento, endereço, telefone)
-- já devem chegar criptografados do backend.
-- ============================================================

DELIMITER //

CREATE PROCEDURE sp_criar_usuario (
    IN p_id_bibliotecario INT,
    IN p_nome VARCHAR(100),
    IN p_email VARCHAR(150),
    IN p_senha_hash VARCHAR(255),
    IN p_tipo_usuario VARCHAR(20),
    IN p_ra INT,
    IN p_id_turma INT,
    IN p_disciplina VARCHAR(50),
    IN p_matricula BIGINT,
    IN p_cpf VARCHAR(60),
    IN p_data_nascimento VARCHAR(60),
    IN p_endereco VARCHAR(320),
    IN p_telefone VARCHAR(60)
)
BEGIN

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode criar contas.';

    END IF;

    IF p_tipo_usuario IS NULL
       OR p_tipo_usuario NOT IN ('aluno', 'professor', 'bibliotecario') THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Tipo de usuário inválido.';

    END IF;

    IF p_tipo_usuario = 'aluno' AND p_id_turma IS NULL THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O aluno precisa estar em uma turma.';

    END IF;

    -- Campos que não pertencem ao tipo são recusados pelo CHECK
    -- chk_dados_por_tipo da tabela usuario.
    INSERT INTO usuario
    (
        nome, email, senha_hash, tipo_usuario,
        RA, id_turma, disciplina, matricula,
        CPF, dataNascimento, endereco, telefone,
        criado_por
    )
    VALUES
    (
        p_nome, p_email, p_senha_hash, p_tipo_usuario,
        p_ra,
        IF(p_tipo_usuario = 'aluno', p_id_turma, NULL),
        p_disciplina, p_matricula,
        p_cpf, p_data_nascimento, p_endereco, p_telefone,
        p_id_bibliotecario
    );

    SELECT LAST_INSERT_ID() AS id_usuario;

END //

-- Edita dados cadastrais. Parâmetro NULL = mantém o valor atual.
-- (O tipo da conta não muda; senha tem fluxo próprio.)
CREATE PROCEDURE sp_atualizar_usuario (
    IN p_id_bibliotecario INT,
    IN p_id_usuario INT,
    IN p_nome VARCHAR(100),
    IN p_email VARCHAR(150),
    IN p_ra INT,
    IN p_id_turma INT,
    IN p_disciplina VARCHAR(50),
    IN p_matricula BIGINT,
    IN p_cpf VARCHAR(60),
    IN p_data_nascimento VARCHAR(60),
    IN p_endereco VARCHAR(320),
    IN p_telefone VARCHAR(60)
)
BEGIN

    DECLARE v_existe INT DEFAULT 0;

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode editar contas.';

    END IF;

    SELECT COUNT(*)
    INTO v_existe
    FROM usuario
    WHERE id_usuario = p_id_usuario;

    IF v_existe = 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    UPDATE usuario
    SET
        nome = COALESCE(p_nome, nome),
        email = COALESCE(p_email, email),
        RA = IF(tipo_usuario = 'aluno', COALESCE(p_ra, RA), RA),
        id_turma = IF(tipo_usuario = 'aluno', COALESCE(p_id_turma, id_turma), id_turma),
        disciplina = IF(tipo_usuario = 'professor', COALESCE(p_disciplina, disciplina), disciplina),
        matricula = IF(tipo_usuario = 'professor', COALESCE(p_matricula, matricula), matricula),
        CPF = COALESCE(p_cpf, CPF),
        dataNascimento = COALESCE(p_data_nascimento, dataNascimento),
        endereco = COALESCE(p_endereco, endereco),
        telefone = COALESCE(p_telefone, telefone)
    WHERE id_usuario = p_id_usuario;

END //

CREATE PROCEDURE sp_alterar_status_usuario (
    IN p_id_bibliotecario INT,
    IN p_id_usuario INT,
    IN p_ativo BOOLEAN
)
BEGIN

    DECLARE v_existe INT DEFAULT 0;

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode ativar ou inativar contas.';

    END IF;

    SELECT COUNT(*)
    INTO v_existe
    FROM usuario
    WHERE id_usuario = p_id_usuario;

    IF v_existe = 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    IF p_id_usuario = p_id_bibliotecario AND p_ativo = FALSE THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'A bibliotecária não pode inativar a própria conta.';

    END IF;

    UPDATE usuario
    SET ativo = p_ativo
    WHERE id_usuario = p_id_usuario;

END //

-- Só exclui conta sem histórico de empréstimos; nos demais casos
-- o certo é inativar (o histórico é mantido).
CREATE PROCEDURE sp_excluir_usuario (
    IN p_id_bibliotecario INT,
    IN p_id_usuario INT
)
BEGIN

    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_historico INT DEFAULT 0;

    IF NOT fn_bibliotecario_ativo(p_id_bibliotecario) THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Somente a bibliotecária pode excluir contas.';

    END IF;

    SELECT COUNT(*)
    INTO v_existe
    FROM usuario
    WHERE id_usuario = p_id_usuario;

    IF v_existe = 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário não encontrado.';

    END IF;

    IF p_id_usuario = p_id_bibliotecario THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'A bibliotecária não pode excluir a própria conta.';

    END IF;

    SELECT COUNT(*)
    INTO v_historico
    FROM emprestimo
    WHERE id_usuario = p_id_usuario;

    IF v_historico > 0 THEN

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Usuário possui histórico de empréstimos e não pode ser excluído. Inative a conta.';

    END IF;

    -- Reservas, lista de desejos e notificações saem junto (CASCADE).
    DELETE FROM usuario
    WHERE id_usuario = p_id_usuario;

END //

DELIMITER ;

-- ============================================================
-- CONSULTAS DE VERIFICACAO
-- ============================================================

-- Livros com autores, categorias e estoque.

SELECT
    L.id_livro,
    L.titulo,
    A.nome_autor AS autor,
    A.nacionalidade_autor AS nacionalidade,
    L.ISBN,
    L.publicacao,
    L.editora,
    C.nome_cat AS categoria,
    L.quantidade_total,
    L.quantidade_disponivel
FROM livros L
INNER JOIN categorias C
    ON L.id_cat = C.id_cat
INNER JOIN autores A
    ON L.id_autor = A.id_autor
ORDER BY L.id_livro;

-- Empréstimos.

SELECT
    E.id_emprestimo,
    U.nome AS pessoa,
    U.tipo_usuario,
    L.titulo AS livro,
    A.nome_autor AS autor,
    C.nome_cat AS categoria,
    E.data_emp,
    E.data_dev_prevista,
    E.data_dev,
    E.emp_ativo,
    E.multa_pendente,
    E.dev_pdia,
    E.valor_multa
FROM emprestimo E
INNER JOIN usuario U
    ON E.id_usuario = U.id_usuario
INNER JOIN livros L
    ON E.id_livro = L.id_livro
INNER JOIN autores A
    ON L.id_autor = A.id_autor
INNER JOIN categorias C
    ON L.id_cat = C.id_cat
ORDER BY E.id_emprestimo;

-- Alunos, turmas e cursos.

SELECT
    RA,
    nome,
    email,
    IF(ativo, 'Ativo', 'Inativo') AS situacao,
    turno,
    ano_calendario,
    periodo_letivo,
    nome_curso,
    criado_em,
    atualizado_em
FROM vw_alunos
ORDER BY RA;

-- Ninguém pode ter mais empréstimos ativos que o limite (deve retornar ZERO linhas).

SELECT
    E.id_usuario,
    COUNT(*) AS emprestimos_ativos
FROM emprestimo E
WHERE E.emp_ativo = TRUE
GROUP BY E.id_usuario
HAVING COUNT(*) > (SELECT limite_emprestimos FROM configuracao ORDER BY id_configuracao LIMIT 1);

-- Teste de integridade do estoque (deve retornar ZERO linhas).

SELECT
    L.id_livro,
    L.titulo,
    L.quantidade_total - COUNT(E.id_emprestimo) AS esperado,
    L.quantidade_disponivel AS atual
FROM livros L
LEFT JOIN emprestimo E
    ON E.id_livro = L.id_livro
   AND E.emp_ativo = TRUE
GROUP BY L.id_livro
HAVING esperado <> atual;

-- ============================================================
-- CONSULTAS GERAIS
-- ============================================================

SELECT * FROM categorias;

SELECT * FROM autores;

SELECT * FROM usuario;

SELECT * FROM curso;

SELECT * FROM turma;

SELECT * FROM vw_alunos;

SELECT * FROM vw_professores;

SELECT * FROM livros;

SELECT * FROM emprestimo;

SELECT * FROM reserva_fila;

SELECT * FROM lista_desejos;

SELECT * FROM notificacao;

-- ============================================================
-- SENHA DE TESTE PARA TODO MUNDO (só para ambiente de
-- desenvolvimento/testes — NUNCA usar senha igual pra todos em
-- produção de verdade). Senha: 123456
-- ============================================================

SET SQL_SAFE_UPDATES = 0;

-- atualizado_em = atualizado_em: trocar a senha de teste não conta
-- como "atualização de dados" do cadastro.
UPDATE usuario
SET senha_hash = '$2y$10$lLCzEzINZ.LTzxL7lQ24J.l2WbvXD.WsS5yz2vWPvxVSbN75DsS7u',
    atualizado_em = atualizado_em
WHERE senha_hash = 'HASH_AQUI';

SET SQL_SAFE_UPDATES = 1;

-- ============================================================
-- TESTES DAS PROCEDURES (opcional)
-- Descomente para testar. Eles ALTERAM os dados; rode o script
-- do início novamente para restaurar o estado original.
--
-- CORREÇÃO: se for descomentar, lembre de também descomentar as
-- 2 linhas de SET SQL_SAFE_UPDATES abaixo — sem isso, o Workbench
-- bloqueia os UPDATEs de dentro das procedures (Error Code: 1175).
-- ============================================================

-- SET SQL_SAFE_UPDATES = 0;
-- CALL sp_realizar_emprestimo(13, 1, 14);  -- deve funcionar (livro 1 fica com 0 disponíveis)
-- CALL sp_realizar_emprestimo(5, 3, 14);   -- deve falhar: usuário com livro em atraso
-- CALL sp_criar_reserva(14, 1);            -- deve funcionar (livro sem exemplar disponível)
-- CALL sp_devolver_livro(14);              -- id do empréstimo criado acima
-- SELECT * FROM notificacao;               -- notificação para o usuário 14
-- CALL sp_devolver_livro(7);               -- empréstimo com reserva = TRUE: deve funcionar (antes dava erro de CHECK)
--
-- -- Limite de 3 livros (usuário 13 devolveu o livro 1 acima):
-- CALL sp_realizar_emprestimo(13, 3, 14);  -- deve funcionar (1º livro)
-- CALL sp_realizar_emprestimo(13, 5, 14);  -- deve funcionar (2º livro)
-- CALL sp_realizar_emprestimo(13, 7, 14);  -- deve funcionar (3º livro)
-- CALL sp_realizar_emprestimo(13, 8, 14);  -- deve falhar: limite de livros atingido
--
-- -- Somente usuários ativos / somente alunos e professores:
-- CALL sp_alterar_status_usuario(31, 15, FALSE);  -- bibliotecária inativa o usuário 15
-- CALL sp_realizar_emprestimo(15, 3, 14);         -- deve falhar: usuário inativo
-- CALL sp_realizar_emprestimo(31, 3, 14);         -- deve falhar: bibliotecária não empresta
--
-- -- Contas (somente bibliotecária):
-- CALL sp_criar_usuario(1, 'Teste', 'teste@gmail.com', 'HASH', 'aluno',
--                       1028, 1, NULL, NULL, 'cpf', 'nasc', 'end', 'tel');  -- deve falhar: usuário 1 é aluno
-- CALL sp_criar_usuario(31, 'Teste', 'teste@gmail.com', 'HASH', 'aluno',
--                       1028, 1, NULL, NULL, 'cpf', 'nasc', 'end', 'tel');  -- deve funcionar
-- CALL sp_atualizar_usuario(31, 36, 'Teste Editado', NULL, NULL, 2, NULL, NULL, NULL, NULL, NULL, NULL);
-- SELECT nome, id_turma, criado_em, atualizado_em FROM usuario WHERE id_usuario = 36;
-- CALL sp_excluir_usuario(31, 36);         -- deve funcionar (sem histórico de empréstimos)
-- CALL sp_excluir_usuario(31, 1);          -- deve falhar: tem histórico de empréstimos
--
-- -- Consulta da bibliotecária (só ela consegue; 31 = id dela):
-- CALL sp_listar_alunos(31, NULL, NULL, NULL, NULL);  -- TODOS os alunos, com Ativo/Inativo
-- CALL sp_listar_alunos(31, 1, 'Manhã', NULL, NULL);  -- alunos do curso 1 no turno da manhã
-- CALL sp_listar_alunos(31, NULL, NULL, 2, NULL);     -- alunos da turma 2
-- CALL sp_listar_alunos(1, NULL, NULL, NULL, NULL);   -- deve falhar: usuário 1 é aluno
-- CALL sp_listar_professores(31, NULL);
--
-- -- Regras valendo mesmo sem usar as procedures (INSERT direto).
-- -- (rodar depois dos testes acima: usuário 13 já está com 3 livros,
-- --  usuário 15 foi inativado e o usuário 5 tem livro em atraso)
-- INSERT INTO emprestimo (emp_ativo, reserva, data_emp, data_dev_prevista, id_usuario, id_livro)
--     VALUES (TRUE, FALSE, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 13, 8);  -- deve falhar: limite de 3
-- INSERT INTO emprestimo (emp_ativo, reserva, data_emp, data_dev_prevista, id_usuario, id_livro)
--     VALUES (TRUE, FALSE, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 15, 8);  -- deve falhar: usuário inativo
-- INSERT INTO emprestimo (emp_ativo, reserva, data_emp, data_dev_prevista, id_usuario, id_livro)
--     VALUES (TRUE, FALSE, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 5, 8);   -- deve falhar: livro em atraso
--
-- -- Turma por tipo de conta (INSERT direto):
-- INSERT INTO usuario (nome, email, senha_hash, tipo_usuario, RA, CPF, dataNascimento, endereco, telefone)
--     VALUES ('Sem Turma', 'semturma@gmail.com', 'HASH', 'aluno', 9999, 'cpf', 'nasc', 'end', 'tel');  -- deve falhar: aluno sem turma
-- SET SQL_SAFE_UPDATES = 1;
