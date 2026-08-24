<?php
declare(strict_types=1);

namespace Whatsapp\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Whatsapp\Controller\MetaWebhookController;
use Whatsapp\Model\ApiWhatsappTable;

class MetaWebhookControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $apiWhatsappTable = $container->get(ApiWhatsappTable::class);
        return new MetaWebhookController($apiWhatsappTable);
    }
}
