<?php

declare(strict_types=1);

namespace Mavoot\Model;

use Laminas\Db\Adapter\AdapterInterface;

/**
 * Singleton storage para el Adapter de base de datos del módulo Mavoot.
 * Permite a las funciones helper globales acceder a la BD sin necesidad
 * de inyectar dependencias en cada .phtml.
 */
final class MavooDb
{
    private static ?AdapterInterface $adapter = null;

    public static function setAdapter(AdapterInterface $adapter): void
    {
        self::$adapter = $adapter;
    }

    public static function getAdapter(): ?AdapterInterface
    {
        return self::$adapter;
    }
}
