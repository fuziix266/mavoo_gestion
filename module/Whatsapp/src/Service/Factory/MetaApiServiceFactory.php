<?php
declare(strict_types=1);

namespace Whatsapp\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Whatsapp\Service\MetaApiService;

class MetaApiServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('config');
        return new MetaApiService($config);
    }
}
