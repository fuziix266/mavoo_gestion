<?php
declare(strict_types=1);
namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class CategoriasControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CategoriasController
    {
        return new CategoriasController(
            $container->get(AuthService::class),
            $container->get(Adapter::class)
        );
    }
}
