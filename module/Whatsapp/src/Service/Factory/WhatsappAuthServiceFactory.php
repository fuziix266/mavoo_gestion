<?php
declare(strict_types=1);

namespace Whatsapp\Service\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Whatsapp\Service\WhatsappAuthService;
use Whatsapp\Model\WhatsappLoginTable;
use Whatsapp\Model\WapiLidTable;
use Whatsapp\Service\WapiService;

class WhatsappAuthServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $loginTable  = $container->get(WhatsappLoginTable::class);
        $lidTable    = $container->get(WapiLidTable::class);
        $wapiService = $container->get(WapiService::class);
        
        return new WhatsappAuthService($loginTable, $lidTable, $wapiService);
    }
}
