SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DROP DATABASE IF EXISTS biblioteca;

CREATE DATABASE biblioteca
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE biblioteca;


-- TABELA: USUARIO

CREATE TABLE usuario (
    id_usuario INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,

    tipo_usuario ENUM(
        'aluno',
        'professor',
        'bibliotecario',
        'admin'
    ) NOT NULL DEFAULT 'aluno',

    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    ultimo_login DATETIME NULL,

    token_redefinicao VARCHAR(255) NULL,
    token_expiracao DATETIME NULL,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: CURSO

CREATE TABLE curso (
    id_curso INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_curso VARCHAR(70) NOT NULL,

    -- BOOLEAN substitui o antigo ENUM('Sim','Não')
    Mtec BOOLEAN NOT NULL DEFAULT FALSE
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: CONFIGURACAO

CREATE TABLE configuracao (
    id_configuracao INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    valor_multa_diaria DECIMAL(10,2) NOT NULL DEFAULT 1.00
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: TURMA

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: CATEGORIAS

CREATE TABLE categorias (
    id_cat INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_cat VARCHAR(30) NOT NULL UNIQUE
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: AUTORES

CREATE TABLE autores (
    id_autor INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome_autor VARCHAR(70) NOT NULL,
    nacionalidade_autor VARCHAR(60)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: LIVROS

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_livros_titulo
    ON livros(titulo);


-- TABELA: ALUNO

CREATE TABLE aluno (
    RA INT NOT NULL PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_turma INT NOT NULL,
    CPF_aluno VARCHAR(60) NOT NULL UNIQUE,
    dataNascimento VARCHAR(60) NOT NULL,
    endereco VARCHAR(320) NOT NULL,
    telefone_aluno VARCHAR(60) NOT NULL,

    situacao ENUM(
        'Ativo',
        'Trancado',
        'Formado',
        'Evadido'
    ) NOT NULL DEFAULT 'Ativo',

    matricula BIGINT NOT NULL UNIQUE,

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (id_turma)
        REFERENCES turma(id_turma)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    UNIQUE KEY uq_aluno_usuario (id_usuario)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: PROFESSOR

CREATE TABLE professor (
    id_professor INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,

    -- RNF07: criptografado (AES-256)
    telefone_prof VARCHAR(60) NOT NULL,
    disciplina VARCHAR(50) NOT NULL,
    matricula BIGINT NOT NULL UNIQUE,

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    UNIQUE KEY uq_professor_usuario (id_usuario)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: BIBLIOTECARIO

CREATE TABLE bibliotecario (
    id_bibliotecario INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    id_usuario INT NOT NULL,

    -- RNF07: criptografados (AES-256)
    CPF_biblio VARCHAR(60) NOT NULL UNIQUE,
    telefone_biblio VARCHAR(60) NOT NULL,

    FOREIGN KEY (id_usuario)
        REFERENCES usuario(id_usuario)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    UNIQUE KEY uq_biblio_usuario (id_usuario)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELA: EMPRESTIMO

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_emprestimo_ativo_data
    ON emprestimo(emp_ativo, data_dev_prevista);

-- TABELA: RESERVA_FILA

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- DADOS INICIAIS

INSERT INTO configuracao
    (valor_multa_diaria)
VALUES
    (1.00);

-- CATEGORIAS

INSERT INTO categorias
    (id_cat, nome_cat)
VALUES
(1, 'Suspense'),
(2, 'Ficção científica'),
(3, 'Romance'),
(4, 'Fantasia'),
(5, 'Distopia'),
(6, 'Terror');


-- AUTORES

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


-- CURSOS

INSERT INTO curso
    (id_curso, nome_curso, Mtec)
VALUES
(1, 'Desenvolvimento de Sistemas', TRUE),
(2, 'Gastronomia', TRUE),
(3, 'Administração', FALSE),
(4, 'Economia', TRUE),
(5, 'Ciências Biológicas', FALSE);


-- TURMAS

INSERT INTO turma
    (id_turma, id_curso, turno, ano_calendario, periodo_letivo)
VALUES
(1, 1, 'Manhã', 2026, 1),
(2, 1, 'Tarde', 2026, 1),
(3, 2, 'Noite', 2026, 1),
(4, 3, 'Manhã', 2026, 1),
(5, 4, 'Tarde', 2026, 1),
(6, 5, 'Noite', 2026, 1);


-- LIVROS

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
(1, 'Verity', 1, '9788501117847', 2018, 'Galera Record', 1, 2, 1), 
(2, 'O Código Da Vinci', 2, '9788575421130', 2004, 'Sextante', 1, 2, 1),
(3, 'A Garota no Trem', 3, '9788501104656', 2015, 'Record', 1, 2, 2),
(4, '1984', 4, '9788535914849', 1949, 'Companhia das Letras', 2, 2, 1),
(5, 'Duna', 5, '9788576573135', 2017, 'Aleph', 2, 2, 2),
(6, 'Jogador Nº 1', 6, '9788580443110', 2012, 'Leya', 2, 2, 1), 
(7, 'O Acordo', 7, '9788576864950', 2015, 'Paralela', 3, 2, 2),
(8, 'Como Eu Era Antes de Você', 8, '9788580573299', 2013, 'Intrínseca', 3, 2, 2),
(9, 'Orgulho e Preconceito', 9, '9788563560155', 2011, 'Penguin/Companhia das Letras', 3, 2, 2),
(10, 'As Crônicas de Nárnia', 10, '9788578270698', 1950, 'Martins Fontes', 4, 2, 2), 
(11, 'Harry Potter e a Pedra Filosofal', 11, '9788532511010', 2000, 'Rocco', 4, 2, 1),
(12, 'O Senhor dos Anéis: A Sociedade do Anel', 12, '9788595084759', 2019, 'HarperCollins Brasil', 4, 2, 1),
(13, 'Jogos Vorazes', 13, '9788579800245', 2008, 'Rocco', 5, 2, 2), 
(14, 'O Conto da Aia', 14, '9788532520388', 2017, 'Rocco', 5, 2, 1), 
(15, 'A revolução dos bichos', 4, '9788535909555', 2007, 'Companhia das Letras', 5, 2, 2),
(16, 'O Exorcista', 15, '9788576570530', 1971, 'HarperCollins Brasil', 6, 2, 2), 
(17, 'It: A Coisa', 16, '9788556510396', 2017, 'Suma', 6, 2, 2), 
(18, 'O Iluminado', 16, '9788581050485', 2012, 'Suma', 6, 2, 2); 

-- USUARIOS

INSERT INTO usuario
    (id_usuario, nome, email, senha_hash, tipo_usuario)
VALUES
(1, 'Felipe', 'felipe@gmail.com', 'HASH_AQUI', 'aluno'),
(2, 'Murillo T', 'murillot@gmail.com', 'HASH_AQUI', 'aluno'),
(3, 'Vinicius N', 'viniciusn@gmail.com', 'HASH_AQUI', 'aluno'),
(4, 'Nicolas', 'nicolas@gmail.com', 'HASH_AQUI', 'aluno'),
(5, 'Guilherme', 'guilherme@gmail.com', 'HASH_AQUI', 'aluno'),
(6, 'Maicon', 'maicon@gmail.com', 'HASH_AQUI', 'aluno'),
(7, 'Isabelly', 'isabelly@gmail.com', 'HASH_AQUI', 'aluno'),
(8, 'Gabriel', 'gabriel@gmail.com', 'HASH_AQUI', 'aluno'),
(9, 'Vinicius T', 'viniciust@gmail.com', 'HASH_AQUI', 'aluno'),
(10, 'Murillo R', 'murillor@gmail.com', 'HASH_AQUI', 'aluno'),
(11, 'Bianca', 'bianca@gmail.com', 'HASH_AQUI', 'aluno'),
(12, 'Leonardo', 'leonardo@gmail.com', 'HASH_AQUI', 'aluno'),
(13, 'Alessandro', 'alessandro@gmail.com', 'HASH_AQUI', 'aluno'),
(14, 'Zaion', 'zaion@gmail.com', 'HASH_AQUI', 'aluno'),
(15, 'Pedro', 'pedro@gmail.com', 'HASH_AQUI', 'aluno'),
(16, 'Thiago', 'thiago@gmail.com', 'HASH_AQUI', 'aluno'),
(17, 'Weslley', 'weslley@gmail.com', 'HASH_AQUI', 'aluno'),
(18, 'Lucas', 'lucas@gmail.com', 'HASH_AQUI', 'aluno'),
(19, 'Nicol', 'nicol@gmail.com', 'HASH_AQUI', 'aluno'),
(20, 'Arthur R', 'arthurr@gmail.com', 'HASH_AQUI', 'aluno'),
(21, 'João', 'joao@gmail.com', 'HASH_AQUI', 'aluno'),
(22, 'Arthur P', 'arthurp@gmail.com', 'HASH_AQUI', 'aluno'),
(23, 'Enzo', 'enzo@gmail.com', 'HASH_AQUI', 'aluno'),
(24, 'Luiz', 'luiz@gmail.com', 'HASH_AQUI', 'aluno'),
(25, 'Grazielle', 'grazielle@gmail.com', 'HASH_AQUI', 'aluno'),
(26, 'Kauan', 'kauan@gmail.com', 'HASH_AQUI', 'aluno'),
(27, 'Isadora', 'isadora@gmail.com', 'HASH_AQUI', 'aluno'),

(28, 'Roberto Nogueira', 'roberto.nogueira@escola.edu.br', 'HASH_AQUI', 'professor'),
(29, 'Camila Ferreira Rocha', 'camila.rocha@escola.edu.br', 'HASH_AQUI', 'professor'),
(30, 'Eduardo Martins Silva', 'eduardo.silva@escola.edu.br', 'HASH_AQUI', 'professor'),

(31, 'Patrícia Souza Lima', 'patricia.lima@escola.edu.br', 'HASH_AQUI', 'bibliotecario'),
(32, 'Rafael Costa Almeida', 'rafael.almeida@escola.edu.br', 'HASH_AQUI', 'bibliotecario'),
(33, 'Vanessa Oliveira Ramos', 'vanessa.ramos@escola.edu.br', 'HASH_AQUI', 'bibliotecario'),

(34, 'Fernanda Lopes Barros', 'fernanda.barros@escola.edu.br', 'HASH_AQUI', 'professor'),
(35, 'Thiago Ribeiro Nunes', 'thiago.nunes@escola.edu.br', 'HASH_AQUI', 'professor');

-- ALUNOS

INSERT INTO aluno
    (
        RA,
        id_usuario,
        id_turma,
        CPF_aluno,
        dataNascimento,
        endereco,
        telefone_aluno,
        situacao,
        matricula
    )
VALUES
(1001, 1, 1, 'JxEV32VZQ+ni2w1DcqHrpw==', 'mh4kATkJAIrlZh4Sc1JcMg==', 'dw7cmytF0oa4tp3n2Qr0CyrjosO2h/pQlhsPilQrGe4=', 'BS8HuwRiqdJsLQRP7NVodw==', 'Ativo', 20261001),
(1002, 2, 1, 'z8tdjCrze+xjyx6jIfGcfg==', 'aIYxZQyAiHSZeBHGkSMGwQ==', 'cFB39YMclTF9BqD4euJdTr374IDC71VFdMq69WgnLwY=', 'R0YTc3tJf/likFB4DwoXOA==', 'Ativo', 20261002),
(1003, 3, 1, 'iZNlIKfrFi7F3NH71jyE7Q==', 'IJjzlpoHkE+qu3a/7acduA==', 'gM3kzN+Sb3oiDYHUz682k4rcQBtt7/IIN/iuEkq4PCE=', 'X441Gd1MxepXNu+O+AcwkA==', 'Ativo', 20261003),
(1004, 4, 1, '4ARNDtDr3J/qne8Mcb2/Lw==', 'f2/9HVRXlp8KSdYrxp7KVw==', 'VnpwoSJHDP83k7ctOb6Wzvi0HUi+hBO2zB2bAVWN+0s=', 'hT1Qm5W+XzyooXIIp13kDw==', 'Ativo', 20261004),
(1005, 5, 1, 'EI6gerDE/lQRCj9hVYJN4w==', 'WnHH7el9TBDVfk/Xlz6Jog==', 'SBwWG/jHyRrqO/Ah3fv831YrWWTe76FHDVZRyOELbB4=', 'phS5Rse3aX1VxFH4k8AWmg==', 'Ativo', 20261005),
(1006, 6, 1, '6Jom/p7wzkgnX6vWl5tjZw==', 'vaw0f+0d4FnivHfU9Y7X6A==', 'srUA6q7eEVYO4bZViAyZYC+E4Q+8qgksAAKkQdL5DIs=', '89vlewEPue49h78T3twYKg==', 'Ativo', 20261006),
(1007, 7, 2, 'BEInSSRb0OyjVGWj3Y1u5Q==', '1x7ZiH6IOWLOCyS2x6n2Hg==', 'BI5Jw9+Vxddf4ktMsipdEM43ozg55J1ioSCHv4N+nZA=', 'xyXfqoLN8zOW1h1WkTuYMA==', 'Ativo', 20261007),
(1008, 8, 2, 'Rgw6Eff0/g2WXDHnsMqjpg==', 'Zue30qAxn+LlT73lYwc1ng==', 'V9jUgbhdj5lVmKEB7J21kw==', 'dE+3CaH5qTpnO5dY51/byg==', 'Ativo', 20261008),
(1009, 9, 2, 'mTzWA16JOBBolwpaLh9TfA==', '/qGc1SjrnidUwEQBglu5iQ==', 'ddbJY+SkjOHK1/VwomDH9Q==', 'Z/yHed7ypEi6D/LdObT2UQ==', 'Ativo', 20261009),
(1010, 10, 2, 'IbFqGDCHMIkuRuzVnduxrg==', 'qp6sZUHYzPacctttVNMv4g==', 'MJKk/RekpVU4FbLEBuYD73Yuer6nRWAvjkmnKip6Aws=', 'hV9A4nIAFHUSqcVffWFajQ==', 'Ativo', 20261010),
(1011, 11, 2, 'S5amp8kK2QfCC7Uix5uwCQ==', 'dabGjOTzRwNbsAzfMJT6PQ==', '7hVIE5NHj+aVJ8rTkW6D9L3KI4S3MZ4zga9WXIFRbjc=', 'zG50Z78HShdqDv+OvaQpbw==', 'Ativo', 20261011),
(1012, 12, 2, '7VNAZXvoY9BhWVEuPW/VfQ==', 'OfSE0jK+K9YGvmn1KRSaxA==', 'i4+KJfVIJHJC+jG1dAbr1pSwsPOZvlinPndS4+bJQqI=', 'pU8zHW+DI78JpLfNwlV+xg==', 'Ativo', 20261012),
(1013, 13, 2, 'qtOHJV3sfGyjI0fAKGyNVQ==', 'hKnYt2BzZgtxh9PVFVABdA==', 'M7RHh7KW09M4wuThmj8gNdF/GNXfrQSQD8kL8qdmOco=', 'a2Qk0eYf5ktPoOo/LPtokg==', 'Ativo', 20261013),
(1014, 14, 2, 'yOaQuNwx6nCiDeTOQCVmqg==', 'qXPzgmcDDx7O7keMzu6yyQ==', 'cFB39YMclTF9BqD4euJdTlO2kAL2Rrp4dRIHhAhdei0=', '/zFGFojs3x6zKT1LHSm0ig==', 'Ativo', 20261014),
(1015, 15, 2, '36GefqgYUNoEdMIBGFDFng==', '9/3ypsJ4QkVtHdiDJGtz5w==', '2ck3Jm4cfvWHpXN8LqFddMphzT9R/LvXHhDh9TzrEes=', 'UFUuc/K1lgA1UBvbYZrXgg==', 'Ativo', 20261015),
(1016, 16, 2, 'CcFROICXFSo2DA/jEsgG9A==', 'im9LePdwYJ9vD02vlGm6FA==', 'GosZvWK5m6ajSlVBo9jLWTdZ8fNYxcSAhkAfrVVQRL8=', 'z41MStNzLY08cXgIFJCc6w==', 'Ativo', 20261016),
(1017, 17, 1, 'Sj0WOVAfJktvCoOmjOQNrQ==', 'M076gjCsH5P1Fv946D0MWQ==', 'xeaqtcZH5rIDXttRhgyqNrpHLM9k6FMw9oFyFWjHJOE=', 'PAShQm4NKhqb972d3kJ41g==', 'Ativo', 20261017),
(1018, 18, 1, 'VhZLtX6xPqmgwN9an1w6Iw==', 'BoaeBFGVx5qhFhJErR/+eA==', 'hhQoFDTU+C0wOqMLTOuPdOkAwfT0RPG9KUJ79Itehjk=', 'EFxg9wDSTKV33pH8sKaMuw==', 'Ativo', 20261018),
(1019, 19, 1, 'KCJ1WmmXjH+lV2krmuvYQg==', 'f5X/Vhmb2PGodsBhTsknRw==', 'CArRdDdPIcsXVYzNyyeDkBvsxONCCNOPj3fXLiVesRo=', 'O7uV1az+iUI2grUkUKYV2A==', 'Ativo', 20261019),
(1020, 20, 4, 'fR5tYtNjW3lDsaV4sU52tw==', 'AyKzFXKTb5m3nP+XbBfjAQ==', 'gxran1bDqjdbl3X5fOyug+sBoQrEHGJA9Mo7+QrrO4E=', 'f2wngaP9lPOOHytFswCTew==', 'Ativo', 20261020),
(1021, 21, 4, 'X4Joj/VN0jnmPU13NNNEcw==', '4dlz9ugH3Q8Qk8YaLCCqZg==', 'Eslm8ShbI8BCz9VmUBfLgaIkX0T/aTfK4OF8Fv0etpo=', 'HTdj/73RW0EPjkvBe1Wg6g==', 'Ativo', 20261021),
(1022, 22, 5, 'egfKmfDs8XQHPLjdJP4J0g==', 'oaYSvzlVNr30D2D89pTLaw==', 'AEBRTVUC5kl9Et0NRdrZqPdmWlBYFEkSoT/XtQBZxRs=', 'em3/b2PI6+DNAuQqwnL52w==', 'Ativo', 20261022),
(1023, 23, 3, 'QHKkwgvSVjCvPuBPe5wxew==', 'i4FV5A8QXRnwlwQ7TjRSlQ==', 'sac1TlIw+AwFcrfSt2N9wgdXiMZ3kJj/O/NBCXx6260=', 'taBfQLcgcGPtpOgreZa+0Q==', 'Ativo', 20261023),
(1024, 24, 1, 'FlYpD0iHV2UltwWZvcxr7A==', '7BDslpSbAxWdzxDIARgOKw==', 'qiY8K6wTnNR/kKlNVHLFHOyWLSN3UoCjc7WrNJDr2jg=', '728na4ywVCS2ZoI/gAD7GA==', 'Ativo', 20261024),
(1025, 25, 6, 'gNjC2EPBLR1XnKIDaRa0Aw==', 'wrDQ/zuLFbv6/CceE/5HHQ==', 'W6OnFfUEhKHhiR1+Nk3U8kTg32DDB25tbtq/RFqzuFE=', 'lrBdSJnlXenl0dFsCCHtxQ==', 'Ativo', 20261025),
(1026, 26, 3, '2VAIynj8OJIRMaecalq+iw==', 'Xqu5BjNY04+8/gW3Cj047A==', 'pOHs4ra3GUxXSMwBZ+BBQup0/vMoJyiS0hmAoV5x4CE=', '74v9ndx8ytE+oEzS6W6b5Q==', 'Ativo', 20261026),
(1027, 27, 3, 'BZMivgUUNW+Eofljy48t0g==', 'UHCAESuD3WnAbqfbbFqbdA==', 'wDgUqHV7uPCMkA7dl3t5XBYddWuYjOgF5tc0eA4IilQ=', '0LhZ8gjYLXFqLNqWRerAHA==', 'Ativo', 20261027);

-- PROFESSORES

INSERT INTO professor
    (id_professor, id_usuario, telefone_prof, disciplina, matricula)
VALUES
(1, 28, 'A4n9/MEpoKzZ8ObybdT8Jg==', 'Desenvolvimento de Sistemas', 30261001),
(2, 29, 'Pg+bcuN7tA6ERUlYVM7rig==', 'Gastronomia', 30261002),
(3, 30, 'kWQ/BEfKSAq5AK/TzFYZ4w==', 'Administração', 30261003),
(4, 34, '9qT+EVkz3CrHL3BbkxWA+g==', 'Economia', 30261004),
(5, 35, 'kgFL7rjzEYsVNGIW26c2gw==', 'Ciências Biológicas', 30261005);

-- BIBLIOTECARIOS

INSERT INTO bibliotecario
    (id_bibliotecario, id_usuario, CPF_biblio, telefone_biblio)
VALUES
(1, 31, 'OF0zu1TKP8w669YxnZK5fQ==', 'BoRkNzN2qlTT1LjkOs+RbQ=='),
(2, 32, 'Yh6/zq2vXgeJ59BotaWi6A==', 'edcNooZiMMsCRt3G1lBO/Q=='),
(3, 33, 'k57gPibRs5q3GDWrXMRWXw==', 'aH5tPAmbqhVQJJdin5+LQQ==');

-- EMPRESTIMOS INICIAIS

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
(TRUE,  FALSE, 0, '2026-09-10', '2026-09-24', NULL,         FALSE, 0.00, 7, 12),
(FALSE, FALSE, 5, '2026-08-01', '2026-08-15', '2026-08-20', FALSE, 5.00, 8, 16), 
(TRUE,  FALSE, 0, '2026-09-12', '2026-09-26', NULL,         FALSE, 0.00, 9, 2),
(FALSE, FALSE, 0, '2026-07-01', '2026-07-15', '2026-07-14', FALSE, 0.00, 10, 8),
(TRUE,  FALSE, 3, '2026-08-28', '2026-09-11', NULL,         TRUE,  3.00, 11, 14),
(FALSE, FALSE, 0, '2026-06-20', '2026-07-04', '2026-07-04', FALSE, 0.00, 12, 18),
(TRUE,  FALSE, 0, '2026-09-08', '2026-10-08', NULL,         FALSE, 0.00, 17, 6);

-- LISTA DE DESEJOS

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTIFICACAO

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
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_notificacao_usuario_lida
    ON notificacao(id_usuario, lida);

-- TRIGGER: DEVOLUCAO

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

    END IF;
END //

DELIMITER ;

-- PROCEDURE: ATUALIZAR ATRASOS

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

-- EVENT: ATUALIZAR ATRASOS (executa 1x por dia)

SET GLOBAL event_scheduler = ON;

CREATE EVENT IF NOT EXISTS ev_atualizar_atrasos
ON SCHEDULE EVERY 1 DAY
STARTS (CURRENT_DATE + INTERVAL 1 DAY)
DO CALL sp_atualizar_atrasos();
SET SQL_SAFE_UPDATES = 0;
CALL sp_atualizar_atrasos();
SET SQL_SAFE_UPDATES = 1;

-- PROCEDURE: REALIZAR EMPRESTIMO

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
    DECLARE v_multa_pendente INT DEFAULT 0;
    DECLARE v_emprestimo_existente INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    IF p_dias_emprestimo <= 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'A quantidade de dias deve ser maior que zero.';

    END IF;

    SELECT COUNT(*), COALESCE(MAX(ativo), FALSE)
    INTO v_usuario_existe, v_usuario_ativo
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
                'Usuário está inativo e não pode realizar empréstimos.';

    END IF;

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

-- PROCEDURE: DEVOLVER LIVRO

DELIMITER //

CREATE PROCEDURE sp_devolver_livro (
    IN p_id_emprestimo INT
)
BEGIN

    DECLARE v_id_livro INT DEFAULT NULL;
    DECLARE v_id_reserva INT DEFAULT NULL;
    DECLARE v_id_usuario_fila INT DEFAULT NULL;
    DECLARE v_titulo VARCHAR(150);

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

        UPDATE emprestimo
        SET data_dev = CURDATE()
        WHERE id_emprestimo = p_id_emprestimo;

        UPDATE livros
        SET quantidade_disponivel =
            quantidade_disponivel + 1
        WHERE id_livro = v_id_livro;

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

-- PROCEDURE: CRIAR RESERVA

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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

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

    IF v_disponivel > 0 THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O livro possui exemplar disponível. Não é necessário reservar.';

    END IF;

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

-- PROCEDURE: PAGAR MULTA

DELIMITER //

CREATE PROCEDURE sp_pagar_multa (
    IN p_id_emprestimo INT
)
BEGIN

    DECLARE v_id_emprestimo INT DEFAULT NULL;
    DECLARE v_emp_ativo BOOLEAN DEFAULT FALSE;
    DECLARE v_multa_pendente BOOLEAN DEFAULT FALSE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT
        id_emprestimo,
        emp_ativo,
        multa_pendente
    INTO
        v_id_emprestimo,
        v_emp_ativo,
        v_multa_pendente
    FROM emprestimo
    WHERE id_emprestimo = p_id_emprestimo
    FOR UPDATE;

    IF v_id_emprestimo IS NULL THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Empréstimo não encontrado.';

    END IF;

    IF v_multa_pendente = FALSE THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Este empréstimo não possui multa pendente.';

    END IF;

    IF v_emp_ativo = TRUE THEN

        ROLLBACK;

        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'O livro ainda não foi devolvido. Devolva o livro antes de pagar a multa.';

    END IF;

    -- valor_multa e dev_pdia ficam gravados como histórico do valor pago
    UPDATE emprestimo
    SET multa_pendente = FALSE
    WHERE id_emprestimo = p_id_emprestimo;

    COMMIT;

END //

DELIMITER ;

-- CONSULTAS DE VERIFICACAO

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
    A.RA,
    U.nome,
    U.email,
    A.situacao,
    T.turno,
    T.ano_calendario,
    T.periodo_letivo,
    C.nome_curso
FROM aluno A
INNER JOIN usuario U
    ON A.id_usuario = U.id_usuario
LEFT JOIN turma T
    ON A.id_turma = T.id_turma
LEFT JOIN curso C
    ON T.id_curso = C.id_curso
ORDER BY A.RA;

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

-- CONSULTAS GERAIS

SELECT * FROM categorias;

SELECT * FROM autores;

SELECT * FROM usuario;

SELECT * FROM curso;

SELECT * FROM turma;

SELECT * FROM aluno;

SELECT * FROM professor;

SELECT * FROM bibliotecario;

SELECT * FROM livros;

SELECT * FROM emprestimo;

SELECT * FROM reserva_fila;

SELECT * FROM lista_desejos;

SELECT * FROM notificacao;

-- SENHA DE TESTE PARA TODO MUNDO: 123456

SET SQL_SAFE_UPDATES = 0;

UPDATE usuario
SET senha_hash = '$2y$10$lLCzEzINZ.LTzxL7lQ24J.l2WbvXD.WsS5yz2vWPvxVSbN75DsS7u'
WHERE senha_hash = 'HASH_AQUI';

SET SQL_SAFE_UPDATES = 1;

-- 1. Collation de cada coluna de texto (deve ser só utf8mb4_unicode_ci)
SELECT table_name, column_name, collation_name
FROM information_schema.columns
WHERE table_schema = 'biblioteca'
  AND collation_name IS NOT NULL
  AND collation_name <> 'utf8mb4_unicode_ci';
-- Esperado: ZERO linhas

-- 2. Collation de cada tabela
SELECT table_name, table_collation
FROM information_schema.tables
WHERE table_schema = 'biblioteca'
  AND table_collation <> 'utf8mb4_unicode_ci';
-- Esperado: ZERO linhas

-- 3. Collation das procedures
SELECT routine_name, database_collation, collation_connection
FROM information_schema.routines
WHERE routine_schema = 'biblioteca';


-- TESTES DAS PROCEDURES (opcional)

-- SET SQL_SAFE_UPDATES = 0;
-- CALL sp_realizar_emprestimo(13, 1, 14);  -- deve funcionar (livro 1 fica com 0 disponíveis)
-- CALL sp_realizar_emprestimo(5, 3, 14);   -- deve falhar: usuário com multa pendente
-- CALL sp_criar_reserva(14, 1);            -- deve funcionar (livro sem exemplar disponível)
-- CALL sp_devolver_livro(14);              -- id do empréstimo criado acima
-- SELECT * FROM notificacao;               -- notificação para o usuário 14
-- CALL sp_pagar_multa(5);                  -- deve falhar: livro do empréstimo 5 ainda não foi devolvido
-- CALL sp_devolver_livro(5);               -- devolve com atraso, a multa continua pendente
-- CALL sp_pagar_multa(5);                  -- deve funcionar: quita a multa
-- CALL sp_pagar_multa(5);                  -- deve falhar: sem multa pendente
-- SET SQL_SAFE_UPDATES = 1;
-- SELECT * FROM emprestimo WHERE id_emprestimo = 14;