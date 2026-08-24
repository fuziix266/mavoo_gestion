<?php
declare(strict_types=1);

namespace Whatsapp\Model\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Whatsapp\Model\WapiLid;
use Whatsapp\Model\WapiLidTable;

class WapiLidTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $dbAdapter = $container->get(AdapterInterface::class);
        $resultSetPrototype = new ResultSet();
        $resultSetPrototype->setArrayObjectPrototype(new WapiLid());
        $tableGateway = new TableGateway('wapi_lids', $dbAdapter, null, $resultSetPrototype);
        
        return new WapiLidTable($tableGateway);
    }
}
