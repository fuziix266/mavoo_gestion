<?php
chdir(__DIR__);
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$_SERVER['REQUEST_URI'] = '/gestion_laminas/admin/ceo/usuarios/buscar_usuario?q=admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['q'] = 'admin';
$app->run();
