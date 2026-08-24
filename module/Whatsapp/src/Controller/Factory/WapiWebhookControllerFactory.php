<?php
declare(strict_types=1);

namespace Whatsapp\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Whatsapp\Controller\WapiWebhookController;
use Whatsapp\Model\WapiChatTable;

class WapiWebhookControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $wapiChatTable = $container->get(WapiChatTable::class);
        return new WapiWebhookController($wapiChatTable);
    }
}
