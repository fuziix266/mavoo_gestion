<?php

declare(strict_types=1);

namespace Auth;

use Auth\Listener\AuthListener;
use Auth\Service\AuthService;
use Laminas\Mvc\MvcEvent;

class Module
{
    public function getConfig(): array
    {
        return require __DIR__ . '/../config/module.config.php';
    }

    /**
     * Registra el AuthListener en el evento Bootstrap de la aplicación.
     * Esto activa el guard de autenticación y la inyección de identidad para TODAS las rutas.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        $application  = $event->getApplication();
        $eventManager = $application->getEventManager();
        $serviceManager = $application->getServiceManager();

        $authService = $serviceManager->get(AuthService::class);
        $listener    = new AuthListener($authService);
        $listener->attach($eventManager);
    }
}
