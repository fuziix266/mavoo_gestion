<?php

namespace Mavoot;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Mvc\MvcEvent;

class Module
{
    public function getConfig()
    {
        return include __DIR__.'/../config/module.config.php';
    }

    public function onBootstrap(MvcEvent $e)
    {
        try {
            $container = $e->getApplication()->getServiceManager();
            $adapter = $container->get(AdapterInterface::class);
            Model\MavooDb::setAdapter($adapter);
        } catch (\Throwable $err) {
            // Silenciar: usar fallbacks
        }
    }

    public function getServiceConfig()
    {
        $tableGateway = function ($container, $table) {
            $dbAdapter = $container->get(AdapterInterface::class);
            $resultSetPrototype = new ResultSet;

            return new TableGateway($table, $dbAdapter, null, $resultSetPrototype);
        };

        return [
            'factories' => [
                Model\MavootSessionTable::class => function ($container) use ($tableGateway) {
                    return new Model\MavootSessionTable($tableGateway($container, 'mavoot_sessions'));
                },
                Model\MavootMessageTable::class => function ($container) use ($tableGateway) {
                    return new Model\MavootMessageTable($tableGateway($container, 'mavoot_messages'));
                },
            ],
        ];
    }

    /**
     * Bootstrap: inyecta el Adapter de BD en el singleton MavooDb.
     * NO usamos init() porque el ModuleManager de Laminas falla al
     * manejar los typehints en clases con muchos métodos.
     * En su lugar, usamos onBootstrap() que se invoca al inicio de Mvc.
     */
}
