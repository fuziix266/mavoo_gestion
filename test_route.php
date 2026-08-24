<?php
chdir(__DIR__);
require 'vendor/autoload.php';
\ = Laminas\Mvc\Application::init(require 'config/application.config.php');
\ = \->getServiceManager()->get('HttpRouter');
echo \->assemble([], ['name' => 'admin.ceo.usuarios/buscar_usuario']);
