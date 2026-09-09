<?php

declare(strict_types=1);
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\View\Helper\Url;
use Mavoo\Model\MavooDb;

if (! function_exists('asset')) {
    function asset(string $path): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $prefix = '';
        // Detectar el prefijo del proyecto (carpeta padre de /gestion_laminas/public/)
        if (preg_match('#^(.*?)/gestion_laminas/public/#', $script)) {
            // Servido bajo /xxx/gestion_laminas/public/index.php
            $prefix = preg_replace('#/gestion_laminas/public/.*$#', '', $script);
        } elseif (preg_match('#^(.*?)/public/#', $script)) {
            // Servido bajo /xxx/public/index.php
            $prefix = preg_replace('#/public/.*$#', '', $script);
        } elseif (preg_match('#^(.*?)/gestion_laminas#', $uri)) {
            $prefix = preg_replace('#/gestion_laminas.*$#', '', $uri);
        }

        $base = $prefix === '' ? '' : rtrim($prefix, '/').'/gestion_laminas/public';

        return $base.'/'.ltrim($path, '/');
    }
}

if (! function_exists('route')) {
    function route(string $name, array $params = []): string
    {
        try {
            $urlHelper = new Url;

            return $urlHelper->__invoke($name, $params);
        } catch (Throwable $e) {
            return '/mavoo_gestion/gestion_laminas/'.str_replace('.', '/', $name);
        }
    }
}

// Compatibilidad: url() es alias de route() (Laravel-style)
// Si se pasa una URL absoluta (empieza con /), se devuelve tal cual.
if (! function_exists('url')) {
    function url(string $name, array $params = []): string
    {
        if (str_starts_with($name, '/')) {
            return $name;  // URL absoluta
        }

        return route($name, $params);
    }
}

if (! function_exists('__')) {
    function __(string $key, array $replace = [])
    {
        static $langCache = [];
        $parts = explode('.', $key);
        $file = $parts[0];
        $path = explode('.', substr($key, strlen($file) + 1));

        if (! isset($langCache[$file])) {
            // BUG (pre-existente): las 2 rutas originales navegaban 1-2 niveles
            // ARRIBA de este archivo (que vive en la raíz de gestion_laminas),
            // asumiendo que gestion_laminas siempre corre como subcarpeta junto
            // al proyecto Laravel (que sí tiene resources/lang/es/). Eso
            // "funcionaba" en local por casualidad de la estructura de carpetas,
            // pero en producción gestion_laminas corre solo en su propio
            // contenedor Docker (sin el Laravel hermano al lado) -> nunca
            // encontraba los archivos y __() devolvía la key cruda
            // (ej. "deporte_padel.sin_limites") en vez del texto traducido.
            // Fix: copia propia y autocontenida en gestion_laminas/resources/lang/es/,
            // manteniendo las rutas viejas como fallback por compatibilidad.
            $candidates = [
                __DIR__.'/resources/lang/es/'.$file.'.php',
                dirname(__DIR__).'/resources/lang/es/'.$file.'.php',
                __DIR__.'/../../resources/lang/es/'.$file.'.php',
            ];
            $found = false;
            foreach ($candidates as $langPath) {
                if (file_exists($langPath)) {
                    $langCache[$file] = require $langPath;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $langCache[$file] = [];
            }
        }

        $current = $langCache[$file];
        foreach ($path as $p) {
            if (is_array($current) && isset($current[$p])) {
                $current = $current[$p];
            } else {
                return $key;
            }
        }

        if (is_string($current)) {
            foreach ($replace as $k => $v) {
                $current = str_replace(':'.$k, $v, $current);
            }

            return $current;
        }

        return $key;
    }
}

if (! function_exists('old')) {
    function old($key, $default = null)
    {
        return $default;
    }
}

if (! function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return '';
    }
}

if (! function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="">';
    }
}

if (! function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="'.htmlspecialchars($method, ENT_QUOTES, 'UTF-8').'">';
    }
}

if (! function_exists('collect')) {
    function collect($items = [])
    {
        return new class($items)
        {
            private array $items;

            public function __construct($items)
            {
                if (is_array($items)) {
                    $this->items = $items;
                } elseif (is_object($items)) {
                    $this->items = (array) $items;
                } else {
                    $this->items = [$items];
                }
            }

            public function toArray(): array
            {
                return $this->items;
            }

            public function keys(): array
            {
                return array_keys($this->items);
            }

            public function values(): array
            {
                return array_values($this->items);
            }

            public function pluck(string $key): array
            {
                $res = [];
                foreach ($this->items as $i) {
                    $arr = (array) $i;
                    if (isset($arr[$key])) {
                        $res[] = $arr[$key];
                    }
                }

                return $res;
            }
        };
    }
}

/**
 * Helper compatible con App\Http\Controllers\mod\padel\MenuhController::ha_pasado
 */
if (! function_exists('ha_pasado')) {
    function ha_pasado($fecha, ?string $locale = 'es'): string
    {
        if (empty($fecha)) {
            return '—';
        }
        try {
            $dt = $fecha instanceof DateTimeInterface ? $fecha : new DateTime((string) $fecha);
        } catch (Throwable $e) {
            return '—';
        }
        $now = new DateTime('now');
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 60) {
            return 'hace segundos';
        }
        if ($diff < 3600) {
            return 'hace '.intval($diff / 60).' min';
        }
        if ($diff < 86400) {
            return 'hace '.intval($diff / 3600).' h';
        }
        if ($diff < 604800) {
            return 'hace '.intval($diff / 86400).' d';
        }
        if ($diff < 2592000) {
            return 'hace '.intval($diff / 604800).' sem';
        }
        if ($diff < 31536000) {
            return 'hace '.intval($diff / 2592000).' mes';
        }

        return 'hace '.intval($diff / 31536000).' año(s)';
    }
}

/**
 * Helper compatible con App\Http\Controllers\Mod\Padel\MenuhController::calcularBloques
 */
if (! function_exists('calcular_bloques')) {
    function calcular_bloques($fechaInicio, $fechaCierre, ?string $intervalo = '01:00'): array
    {
        $bloques = [];
        $intervaloMinutos = 60;
        if (is_string($intervalo) && preg_match('/^(\d{1,2}):(\d{2})$/', $intervalo, $m)) {
            $intervaloMinutos = intval($m[1]) * 60 + intval($m[2]);
        }
        try {
            $inicio = $fechaInicio instanceof DateTimeInterface ? $fechaInicio : new DateTime((string) $fechaInicio);
            $fin = $fechaCierre instanceof DateTimeInterface ? $fechaCierre : new DateTime((string) $fechaCierre);
        } catch (Throwable $e) {
            return [];
        }
        if ($fin <= $inicio) {
            return [];
        }
        $cursor = clone $inicio;
        while ($cursor < $fin) {
            $bloques[] = $cursor->format('Y-m-d H:i');
            $cursor->modify('+'.$intervaloMinutos.' minutes');
        }

        return $bloques;
    }
}

if (! function_exists('mavoo_set_db')) {
    function mavoo_set_db(AdapterInterface $adapter): void
    {
        MavooDb::setAdapter($adapter);
    }
}

if (! function_exists('user_lookup')) {
    function user_lookup(string $column, $value): ?array
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || $value === null) {
                return null;
            }
            $row = $db->query("SELECT * FROM users WHERE {$column} = ? LIMIT 1", [$value])->current();

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('user_find')) {
    function user_find($id): ?array
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || $id === null) {
                return null;
            }
            $row = $db->query('SELECT * FROM users WHERE id = ? OR uuid = ? LIMIT 1', [$id, $id])->current();

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('foto_perfil_mini')) {
    function foto_perfil_mini(int $userId): ?string
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || ! $userId) {
                return null;
            }
            $row = $db->query(
                'SELECT mini_url FROM foto_perfil WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                [$userId]
            )->current();

            return $row['mini_url'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('mod_gestion_lookup')) {
    function mod_gestion_lookup(string $table, string $column, $value): ?array
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || $value === null) {
                return null;
            }
            $row = $db->query("SELECT * FROM {$table} WHERE {$column} = ? LIMIT 1", [$value])->current();

            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('mod_gestion_value')) {
    function mod_gestion_value(string $table, string $column, $value, string $field): ?string
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || $value === null) {
                return null;
            }
            $row = $db->query("SELECT {$field} FROM {$table} WHERE {$column} = ? LIMIT 1", [$value])->current();

            return $row ? (string) ($row[$field] ?? null) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (! function_exists('mod_nomina_count_for_uuid')) {
    function mod_nomina_count_for_uuid(string $uuid): int
    {
        try {
            $db = MavooDb::getAdapter();
            if (! $db || ! $uuid) {
                return 0;
            }
            $row = $db->query(
                'SELECT COUNT(*) AS c FROM mod_gestion_nominas WHERE JSON_CONTAINS(usuarios_uuids, JSON_QUOTE(?))',
                [$uuid]
            )->current();

            return (int) ($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
