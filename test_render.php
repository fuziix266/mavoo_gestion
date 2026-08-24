<?php
chdir(__DIR__);
require 'public/index.php';
$app = \Laminas\Mvc\Application::init(require 'config/application.config.php');
$req = new \Laminas\Http\PhpEnvironment\Request();
$req->setUri('http://localhost/mavoo_gestion/gestion_laminas/admin/ceo/categorias');
// spoof auth if necessary, or just run it to let it crash
try {
    $app->run();
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
