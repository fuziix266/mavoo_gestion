<?php

declare(strict_types=1);

namespace ApplicationTest\Controller;

use Application\Controller\IndexController;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class IndexControllerTest extends AbstractHttpControllerTestCase
{
    public function setUp(): void
    {
        // The module configuration should still be applicable for tests.
        // You can override configuration here with test case specific values,
        // such as sample view templates, path stacks, module_listener_options,
        // etc.
        $configOverrides = [];

        $this->setApplicationConfig(ArrayUtils::merge(
            include __DIR__ . '/../../../../config/application.config.php',
            $configOverrides
        ));

        parent::setUp();
    }

    public function testIndexActionRedirectsToDashboard(): void
    {
        // "/" ya no renderiza una vista propia: IndexController::indexAction()
        // redirige siempre a la ruta 'dashboard' (ver Application\Controller\IndexController).
        // El AuthListener se encarga de redirigir a /login si no hay sesión activa.
        $this->dispatch('/', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertModuleName('application');
        $this->assertControllerName(IndexController::class); // as specified in router's controller name alias
        $this->assertControllerClass('IndexController');
        $this->assertMatchedRouteName('home');

        $response = $this->getResponse();
        $location = $response->getHeaders()->get('Location');
        $this->assertNotFalse($location, 'Se esperaba un header Location en la redirección.');
        $this->assertStringContainsString('/dashboard', $location->getFieldValue());
    }

    public function testInvalidRouteDoesNotCrash(): void
    {
        $this->dispatch('/invalid/route', 'GET');
        $this->assertResponseStatusCode(404);
    }
}
