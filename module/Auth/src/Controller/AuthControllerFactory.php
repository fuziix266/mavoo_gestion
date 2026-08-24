<?php

declare(strict_types=1);

namespace Auth\Controller;

use Auth\Service\AuthService;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AuthController
    {
        $authService = $container->get(AuthService::class);
        return new AuthController($authService);
    }
}
