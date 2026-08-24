<?php

/**
 * Configuración de Base de Datos - Laminas DB
 * Conecta a la misma BD MySQL que usa el sistema Laravel mavoo_gestion
 */

declare(strict_types=1);

return [
    'db' => [
        'driver'   => 'Pdo_Mysql',
        'hostname' => '127.0.0.1',
        'port'     => '3306',
        'database' => 'mavoo_gestion',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'driver_options' => [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        ],
    ],

    'service_manager' => [
        'factories' => [
            \Laminas\Db\Adapter\Adapter::class => \Laminas\Db\Adapter\AdapterServiceFactory::class,
        ],
    ],
];
