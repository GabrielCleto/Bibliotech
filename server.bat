@echo off
title Servidor Local PHP - Sistema de Biblioteca

echo ====================================================
echo Iniciando o servidor PHP em http://localhost:8000
echo ====================================================

:: Abre o navegador padrao na URL do projeto
start http://localhost:8000

:: Inicia o servidor embutido do PHP na porta 8000
php -S localhost:8000

pause