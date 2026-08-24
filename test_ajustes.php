<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');

$sm = $app->getServiceManager();
$controllerManager = $sm->get('ControllerManager');
$controller = $controllerManager->get('Ligas\Controller\AjustesController');

// Mock auth
$auth = $sm->get(\Auth\Service\AuthService::class);

class MockAuth {
    public function getIdentity() {
        return ['id' => 1, 'email' => 'admin@gmail.com', 'role' => 'admin'];
    }
}
$reflection = new ReflectionClass(get_class($controller));
$property = $reflection->getProperty('auth');
$property->setAccessible(true);
$property->setValue($controller, new MockAuth());

$request = new \Laminas\Http\PhpEnvironment\Request();
$request->setUri('http://localhost/mavoo_gestion/gestion_laminas/ligas/padel/ajustes');
$routeMatch = new \Laminas\Router\Http\RouteMatch(['deporte' => 'padel', 'uuid' => null]);
$event = new \Laminas\Mvc\MvcEvent();
$event->setRequest($request);
$event->setRouteMatch($routeMatch);

$controller->setEvent($event);
try {
    $result = $controller->indexAction();
    echo "Controller indexAction succeeded.\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
} catch (\Error $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
