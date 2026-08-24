<?php

namespace Jugador\Controller;

use Interop\Container\ContainerInterface;
use Jugador\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $dbAdapter = $container->get(Adapter::class);
        $auth = new AuthService;

        return new IndexController($dbAdapter, $auth);
    }
}
