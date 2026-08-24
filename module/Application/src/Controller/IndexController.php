<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * Ruta raíz "/" → redirige al dashboard.
     * El AuthListener se encargará de redirigir al login si no hay sesión activa.
     */
    public function indexAction(): \Laminas\Http\Response
    {
        return $this->redirect()->toRoute('dashboard');
    }
}
