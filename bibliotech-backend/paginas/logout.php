<?php
// logout.php — encerra a sessão da pessoa (sair do sistema)

session_start();
session_destroy();

header("Location: login.php");
exit;
