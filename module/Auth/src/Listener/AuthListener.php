<?php

declare(strict_types=1);

namespace Auth\Listener;

use Auth\Service\AuthService;
use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\ViewModel;

/**
 * AuthListener - Guard de autenticación global
 *
 * Se registra en el evento MvcEvent::EVENT_ROUTE para:
 * 1. Verificar si el usuario está autenticado (excepto en la ruta /login)
 * 2. Inyectar la identidad del usuario en el ViewModel global (layout)
 * 3. Redirigir al login si no está autenticado
 */
class AuthListener extends AbstractListenerAggregate
{
    private AuthService $authService;

    // Rutas públicas que NO requieren autenticación
    private array $publicRoutes = [
        'auth.login',
        'auth.logout',
        'home',
        'api.whatsapp.meta.webhook',
        'api.whatsapp.baileys.webhook',
        'api.whatsapp.auth.wapilogin',
        'api.whatsapp.auth.wapilogin2',
    ];

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'checkAuthentication'],
            -100
        );

        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_RENDER,
            [$this, 'injectIdentityInLayout'],
            100
        );
    }

    /**
     * Verifica si el usuario tiene una sesión activa.
     * Si no, redirige al login (excepto rutas públicas).
     */
    public function checkAuthentication(MvcEvent $event): ?object
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return null;
        }

        $routeName = $routeMatch->getMatchedRouteName();

        // Permitir acceso a rutas públicas sin autenticación
        if (in_array($routeName, $this->publicRoutes, true)) {
            return null;
        }

        // Si no está autenticado, redirigir al login
        if (!$this->authService->isAuthenticated()) {
            $response = $event->getResponse();
            $response->getHeaders()->addHeaderLine(
                'Location',
                $event->getRouter()->assemble([], ['name' => 'auth.login'])
            );
            $response->setStatusCode(302);
            return $response;
        }

        return null;
    }

    /**
     * Inyecta datos del usuario autenticado en el layout global.
     * Permite que layout.phtml acceda a $this->identity, $this->userRoles, etc.
     */
    public function injectIdentityInLayout(MvcEvent $event): void
    {
        $identity = $this->authService->getIdentity();
        if ($identity === null) {
            return;
        }

        $viewModel = $event->getViewModel();
        if (!$viewModel instanceof ViewModel) {
            return;
        }

        $viewModel->setVariable('identity', $identity);
        $viewModel->setVariable('userRoles', $this->authService->getUserRoles($identity['id']));
        $viewModel->setVariable('userPermissions', $this->authService->getUserPermissions($identity['id']));
    }
}
