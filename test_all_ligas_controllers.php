<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\RouteMatch;

$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$sm = $app->getServiceManager();
$controllerManager = $sm->get('ControllerManager');

$session = new \Laminas\Session\Container('auth');
$session->identity = ['id' => 1, 'email' => 'admin@admin.cl'];

$controllersToTest = [
    'Ajustes' => 'Ligas\Controller\AjustesController',
    'Categorias' => 'Ligas\Controller\CategoriasController',
    'Fixture' => 'Ligas\Controller\FixtureController',
    'Logs' => 'Ligas\Controller\LogsController',
    'Nominas' => 'Ligas\Controller\NominasController',
    'Notificaciones' => 'Ligas\Controller\NotificacionesController',
    'Ranking' => 'Ligas\Controller\RankingController',
    'Sedes' => 'Ligas\Controller\SedesController',
];

// Fetch the uuid of the event we seeded
$db = clone $sm->get(\Laminas\Db\Adapter\Adapter::class);
$uuidResult = $db->query("SELECT uuid FROM mod_eventos WHERE titulo = 'Torneo En Curso' LIMIT 1")->execute();
$eventoUuid = $uuidResult->current()['uuid'] ?? 'dummy-uuid';

foreach ($controllersToTest as $name => $className) {
    echo "====================================\n";
    echo "Testando Controlador: $name\n";
    try {
        if (!$controllerManager->has($className)) {
            echo "[ERR] Controlador no registrado en ControllerManager: $className\n";
            continue;
        }

        $controller = $controllerManager->get($className);
        
        // Mock request
        $request = new Request();
        $routeMatch = new RouteMatch([
            'deporte' => 'padel',
            'uuid'    => $eventoUuid
        ]);
        
        $event = new MvcEvent();
        $event->setRequest($request);
        $event->setRouteMatch($routeMatch);
        $controller->setEvent($event);
        


        $viewModel = $controller->indexAction();
        echo "[OK] indexAction retornó exitosamente un ViewModel o Response.\n";
        
        if ($viewModel instanceof \Laminas\View\Model\ViewModel) {
            echo "     Variables pasadas a la vista: " . implode(', ', array_keys((array)$viewModel->getVariables())) . "\n";
        }
        
    } catch (\Throwable $e) {
        echo "[FAIL] Excepción en $name: " . $e->getMessage() . "\n";
        // echo $e->getTraceAsString() . "\n";
    }
}
echo "====================================\n";
echo "Pruebas finalizadas.\n";
