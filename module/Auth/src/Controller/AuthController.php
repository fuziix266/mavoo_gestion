<?php

declare(strict_types=1);

namespace Auth\Controller;

use Auth\Service\AuthService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * AuthController
 * 
 * Maneja login y logout del sistema Mavoo Gestión en Laminas.
 * Equivalente al controlador de autenticación de Laravel Breeze.
 */
class AuthController extends AbstractActionController
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * GET/POST /login
     * Muestra el formulario de login y procesa el intento de autenticación.
     */
    public function loginAction(): ViewModel|\Laminas\Http\Response
    {
        // Si ya está autenticado, redirigir al dashboard
        if ($this->authService->isAuthenticated()) {
            return $this->redirect()->toRoute('dashboard');
        }

        $error = null;

        if ($this->getRequest()->isPost()) {
            $data     = $this->params()->fromPost();
            $email    = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($email) || empty($password)) {
                $error = 'Por favor ingresa tu email y contraseña.';
            } elseif ($this->authService->login($email, $password)) {
                // Login exitoso → redirigir al dashboard
                return $this->redirect()->toRoute('dashboard');
            } else {
                $error = 'Email o contraseña incorrectos.';
            }
        }

        $viewModel = new ViewModel(['error' => $error]);
        $viewModel->setTerminal(true);
        $viewModel->setTemplate('auth/auth/login');
        return $viewModel;
    }

    /**
     * GET /logout
     * Cierra la sesión y redirige al login.
     */
    public function logoutAction(): \Laminas\Http\Response
    {
        $this->authService->logout();
        return $this->redirect()->toRoute('auth.login');
    }
}
