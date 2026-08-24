<?php

/**
 * Configuración de Base de Datos - Laminas DB
 *
 * Conecta a la misma BD MySQL que usa el sistema Laravel mavoo_gestion.
 *
 * Variables de entorno (usadas en producción/Dokploy):
 *   DB_HOST       - Hostname del servidor MySQL/MariaDB
 *   DB_PORT       - Puerto (default: 3306)
 *   DB_DATABASE   - Nombre de la base de datos
 *   DB_USERNAME   - Usuario de la BD
 *   DB_PASSWORD   - Contraseña de la BD
 *   DB_CHARSET    - Charset (default: utf8mb4)
 *
 * Si no se definen, se usan los defaults de desarrollo local (127.0.0.1 / root / sin password).
 */

declare(strict_types=1);

// Helper para leer env vars con default
$env = static fn(string $key, string $default = ''): string => getenv($key) !== false ? (string) getenv($key) : $default;

return [
    'db' => [
        'driver'   => 'Pdo_Mysql',
        'hostname' => $env('DB_HOST', '127.0.0.1'),
        'port'     => $env('DB_PORT', '3306'),
        'database' => $env('DB_DATABASE', 'mavoo_gestion'),
        'username' => $env('DB_USERNAME', 'root'),
        'password' => $env('DB_PASSWORD', ''),
        'charset'  => $env('DB_CHARSET', 'utf8mb4'),
        'driver_options' => [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        ],
        'platform' => new \Laminas\Db\Adapter\Platform\Mysql(),
    ],

    'service_manager' => [
        'factories' => [
            \Laminas\Db\Adapter\Adapter::class => \Laminas\Db\Adapter\AdapterServiceFactory::class,
        ],
    ],
];
