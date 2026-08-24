<?php

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NotificacionesControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return new NotificacionesController(
            $container->get(AuthService::class),
            $container->get(AdapterInterface::class)
        );
    }
}
