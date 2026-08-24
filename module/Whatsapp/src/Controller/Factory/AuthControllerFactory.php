<?php
declare(strict_types=1);

namespace Whatsapp\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Whatsapp\Controller\AuthController;
use Whatsapp\Service\WhatsappAuthService;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $authService = $container->get(WhatsappAuthService::class);
        return new AuthController($authService);
    }
}
