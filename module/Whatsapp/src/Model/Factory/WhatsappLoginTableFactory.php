<?php
declare(strict_types=1);

namespace Whatsapp\Model\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Whatsapp\Model\WhatsappLogin;
use Whatsapp\Model\WhatsappLoginTable;

class WhatsappLoginTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $dbAdapter = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        $resultSetPrototype->setArrayObjectPrototype(new WhatsappLogin());
        $tableGateway = new TableGateway('whatsapp_logins', $dbAdapter, null, $resultSetPrototype);
        
        return new WhatsappLoginTable($tableGateway);
    }
}
