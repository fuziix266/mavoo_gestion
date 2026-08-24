<?php

declare(strict_types=1);

namespace Dashboard\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class DashboardControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): DashboardController
    {
        $authService = $container->get(AuthService::class);
        $db          = $container->get(Adapter::class);
        return new DashboardController($authService, $db);
    }
}
