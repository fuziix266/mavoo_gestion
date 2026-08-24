<?php

declare(strict_types=1);

namespace Auth\Service;

use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class AuthServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AuthService
    {
        $dbAdapter   = $container->get(Adapter::class);
        $authService = new AuthenticationService();

        return new AuthService($authService, $dbAdapter);
    }
}
