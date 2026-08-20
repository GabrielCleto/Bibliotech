// Elementos da interface
const formLogin = document.getElementById('form-login');
const elementoMensagem = document.getElementById('mensagem');
const btnRegistrar = document.getElementById('btn-registrar');
const tituloForm = document.getElementById('titulo-form');
const btnSubmeter = document.getElementById('btn-submeter');

const camposCadastro = document.getElementById('campos-cadastro');
const campoConfirmarSenha = document.getElementById('campo-confirmar-senha');

const inputNome = document.getElementById('nome');
const inputEmail = document.getElementById('email');
const inputSenha = document.getElementById('senha');
const inputConfirmarSenha = document.getElementById('confirmar-senha');

let modoCadastro = false;

// Regex simples de validação de e-mail
const REGEX_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const TAMANHO_MINIMO_SENHA = 6;

// Alterna entre a tela de Login e a tela de Registro
btnRegistrar.addEventListener('click', function () {
  modoCadastro = !modoCadastro;
  exibirMensagem('', '');

  if (modoCadastro) {
    tituloForm.textContent = 'Criar Nova Conta';
    btnSubmeter.textContent = 'Cadastrar';
    btnRegistrar.textContent = 'Voltar para Login';

    camposCadastro.style.display = 'block';
    campoConfirmarSenha.style.display = 'block';
    inputNome.required = true;
    inputConfirmarSenha.required = true;
  } else {
    tituloForm.textContent = 'Acesso ao Sistema';
    btnSubmeter.textContent = 'Entrar';
    btnRegistrar.textContent = 'Registrar';

    camposCadastro.style.display = 'none';
    campoConfirmarSenha.style.display = 'none';
    inputNome.required = false;
    inputConfirmarSenha.required = false;

    // Limpa os campos exclusivos do cadastro ao voltar para login
    inputNome.value = '';
    inputConfirmarSenha.value = '';
  }
});

// Manipula a submissão do formulário (Login ou Cadastro)
formLogin.addEventListener('submit', async function (event) {
  event.preventDefault();

  const erro = modoCadastro ? validarCadastro() : validarLogin();

  if (erro) {
    exibirMensagem(erro, 'red');
    return;
  }

  const email = inputEmail.value.trim();
  const senha = inputSenha.value;

  if (modoCadastro) {
    const nome = inputNome.value.trim();

    await enviarRequisicao('php/cadastrar.php', { nome, email, senha });
  } else {
    await enviarRequisicao('php/login.php', { email, senha });
  }
});

// --- Validações ---

function validarLogin() {
  const email = inputEmail.value.trim();
  const senha = inputSenha.value;

  if (!email || !senha) {
    return 'Preencha e-mail e senha.';
  }
  if (!REGEX_EMAIL.test(email)) {
    return 'Digite um e-mail válido.';
  }
  return null;
}

function validarCadastro() {
  const nome = inputNome.value.trim();
  const email = inputEmail.value.trim();
  const senha = inputSenha.value;
  const confirmarSenha = inputConfirmarSenha.value;

  if (!nome || !email || !senha || !confirmarSenha) {
    return 'Todos os campos são obrigatórios.';
  }
  if (nome.length < 3) {
    return 'O nome deve ter pelo menos 3 caracteres.';
  }
  if (!REGEX_EMAIL.test(email)) {
    return 'Digite um e-mail válido.';
  }
  if (senha.length < TAMANHO_MINIMO_SENHA) {
    return `A senha deve ter pelo menos ${TAMANHO_MINIMO_SENHA} caracteres.`;
  }
  if (senha !== confirmarSenha) {
    return 'As senhas não coincidem.';
  }
  return null;
}

function exibirMensagem(texto, cor) {
  elementoMensagem.textContent = texto;
  elementoMensagem.style.color = cor;
}

// Função auxiliar para envio das requisições via fetch
async function enviarRequisicao(url, dados) {
  btnSubmeter.disabled = true;

  try {
    const resposta = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(dados)
    });

    if (!resposta.ok) {
      throw new Error('Resposta HTTP ' + resposta.status);
    }

    const resultado = await resposta.json();
    exibirMensagem(resultado.mensagem, resultado.sucesso ? 'green' : 'red');
  } catch (erro) {
    exibirMensagem(
      'Erro ao se comunicar com o servidor. Verifique se está rodando via PHP (ex: php -S localhost:8000), não abrindo o arquivo direto no navegador.',
      'red'
    );
  } finally {
    btnSubmeter.disabled = false;
  }
}
