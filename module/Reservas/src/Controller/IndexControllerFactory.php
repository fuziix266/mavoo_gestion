<?php

namespace Reservas\Controller;

use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Reservas\Service\AuthService;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $dbAdapter = $container->get(Adapter::class);
        $auth = new AuthService;

        return new IndexController($dbAdapter, $auth);
    }
}
