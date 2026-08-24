<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

/**
 * AjustesController – Módulo Ligas/Laminas
 *
 * Equivalente a: App\Http\Controllers\mod\padel\gestion\AjustesController (Laravel)
 * Gestiona la configuración de eventos deportivos (torneos) por deporte.
 *
 * Ruta principal: /ligas/{deporte}/ajustes[/{accion}][/{uuid}]
 */
class AjustesController extends AbstractActionController
{
    private AuthService $auth;

    private Adapter $db;

    private Sql $sql;

    public function __construct(AuthService $auth, Adapter $db)
    {
        $this->auth = $auth;
        $this->db = $db;
        $this->sql = new Sql($db);
    }

    // =====================================================================
    //  INDEX – Lista de eventos del moderador
    // =====================================================================
    public function indexAction(): ViewModel
    {
        file_put_contents('C:\xampp_php8\htdocs\mavoo_gestion/guardar.log',
            '[INDEX] isPost='.($this->getRequest()->isPost() ? '1' : '0')."\n", FILE_APPEND);
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $uuid = $this->params()->fromRoute('uuid');
        $identity = $this->auth->getIdentity();

        $evento = null;
        $ultimoAjuste = null;

        if ($uuid) {
            $evento = $this->getEvento($uuid, $identity['id']);
            $ultimoAjuste = $evento ? $this->getUltimoAjuste($evento['id']) : null;
        }

        $eventos = $this->getEventosPorUsuario($identity['id'], $deporte);
        $paises = $this->getPaises();

        // Mock menuEV
        $menuEV = (object) [
            'uuid' => $uuid ?? '1234',
            'sedes' => true,
            'categorias' => true,
            'nominas' => true,
            'fixture' => true,
            'ranking' => true,
            'notificaciones' => true,
            'logs' => true,
        ];

        return new ViewModel([
            'deporte' => $deporte,
            'evento' => $evento,
            'ultimoAjuste' => $ultimoAjuste,
            'eventos' => $eventos,
            'paises' => $paises,
            'menuEV' => $menuEV,
        ]);
    }

    // =====================================================================
    //  GUARDAR – Crea o actualiza ajuste de evento
    // =====================================================================
    public function guardarAction()
    {
        file_put_contents('C:\xampp_php8\htdocs\mavoo_gestion/guardar.log',
            '[GUARDAR] isPost='.($this->getRequest()->isPost() ? '1' : '0')."\n", FILE_APPEND);
        if (! $this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('ligas.ajustes', [
                'deporte' => $this->params()->fromRoute('deporte', 'padel'),
            ]);
        }

        $post = $this->getRequest()->getPost()->toArray();
        file_put_contents('C:\xampp_php8\htdocs\mavoo_gestion/guardar.log',
            '[GUARDAR] post='.json_encode($post)."\n", FILE_APPEND);
        $identity = $this->auth->getIdentity();
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $uuidPost = $post['uuid'] ?? null;

        // Obtener o crear el evento
        if ($uuidPost) {
            // Buscar evento sin restricción de user_id (admin puede ver todos)
            $eventoRows = $this->dbQuery(
                'SELECT * FROM mod_eventos WHERE uuid = ? LIMIT 1',
                [$uuidPost]
            );
            $evento = $eventoRows[0] ?? null;
            if (! $evento) {
                $this->getResponse()->setStatusCode(403);

                return new JsonModel(['error' => 'Acceso denegado']);
            }
        } else {
            $newUuid = Uuid::uuid4()->toString();
            file_put_contents('C:\xampp_php8\htdocs\mavoo_gestion/guardar.log',
                "[INSERT] newUuid={$newUuid} user={$identity['id']} deporte={$deporte}\n", FILE_APPEND);
            $insertResult = $this->dbWrite(
                'INSERT INTO mod_eventos (uuid, user_id, deporte, referencia_utc, created_at, updated_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$newUuid, $identity['id'], $deporte]
            );
            file_put_contents('C:\xampp_php8\htdocs\mavoo_gestion/guardar.log',
                '[INSERT] result='.var_export($insertResult, true)."\n", FILE_APPEND);
            $eventoRows = $this->dbQuery(
                'SELECT * FROM mod_eventos WHERE uuid = ? LIMIT 1',
                [$newUuid]
            );
            $evento = $eventoRows[0] ?? null;
            $uuidPost = $newUuid;

            // Agregar al usuario como moderador del evento
            $this->dbWrite(
                'INSERT IGNORE INTO evento_user (mod_evento_id, user_id) VALUES (?, ?)',
                [$evento['id'], $identity['id']]
            );
        }

        // Actualizar título del evento
        if (! empty($post['nombre_evento'])) {
            $this->dbQuery(
                'UPDATE mod_eventos SET titulo = ? WHERE id = ?',
                [$post['nombre_evento'], $evento['id']]
            );
        }

        // Obtener ajuste anterior (para detectar cambios)
        $ultimoAjuste = $this->getUltimoAjuste($evento['id']);
        $camposBloqueados = $ultimoAjuste['campos_bloqueados']
            ? json_decode($ultimoAjuste['campos_bloqueados'], true)
            : [];

        $camposFormulario = [
            'nombre_evento', 'fecha_inicio', 'pais_uuid', 'direccion',
            'correo_notificaciones', 'telefono', 'bloques_grupo', 'modalidad',
            'pagina_web', 'inscripciones_inicio', 'inscripciones_cierre',
            'restriccion_total', 'restriccion_diaria', 'descripcion_evento', 'valor_inscripcion',
        ];

        $datosAjuste = [
            'evento_id' => $evento['id'],
            'deporte' => $deporte,
            'afiche_promocional' => $ultimoAjuste['afiche_promocional'] ?? null,
            'afiche_promocional_min' => $ultimoAjuste['afiche_promocional_min'] ?? null,
            'campos_bloqueados' => json_encode($camposBloqueados),
            'referencia_utc' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $cambios = [];
        foreach ($camposFormulario as $campo) {
            $nuevoValor = $campo === 'descripcion_evento'
                ? ($post['editor'] ?? null)
                : ($post[$campo] ?? null);

            if (in_array($campo, $camposBloqueados)) {
                $datosAjuste[$campo] = $ultimoAjuste[$campo] ?? null;
            } else {
                $datosAjuste[$campo] = $nuevoValor;
                if ($ultimoAjuste) {
                    $valorAnterior = $ultimoAjuste[$campo] ?? null;
                    if ($valorAnterior !== $nuevoValor) {
                        $cambios[$campo] = ['anterior' => $valorAnterior, 'nuevo' => $nuevoValor];
                    }
                } else {
                    $cambios[$campo] = ['anterior' => null, 'nuevo' => $nuevoValor];
                }
            }
        }

        // Insertar nuevo ajuste (versionado)
        $cols = implode(', ', array_keys($datosAjuste));
        $placeholders = implode(', ', array_fill(0, count($datosAjuste), '?'));
        $this->dbWrite(
            "INSERT INTO mod_gestion_ajustes ({$cols}) VALUES ({$placeholders})",
            array_values($datosAjuste)
        );

        // Registrar cambios en log
        if (! empty($cambios)) {
            $this->dbWrite(
                "INSERT INTO mod_logs (uuid, id_evento, accion, detalle, ip, seccion, user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'seccion_ajustes', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [
                    Uuid::uuid4()->toString(),
                    $evento['id'],
                    $ultimoAjuste ? 'ajustes_actualizacion' : 'ajustes_nuevo',
                    json_encode($cambios),
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $identity['id'],
                ]
            );
        }

        return $this->redirect()->toRoute('ligas.ajustes', [
            'deporte' => $deporte,
            'accion' => 'editar',
            'uuid' => $uuidPost,
        ]);
    }

    // =====================================================================
    //  HELPERS PRIVADOS
    // =====================================================================

    private function getEvento(string $uuid, int $userId): ?array
    {
        $result = $this->dbQuery(
            'SELECT e.* FROM mod_eventos e WHERE e.uuid = ? LIMIT 1',
            [$uuid]
        );
        $row = $result[0] ?? null;

        return $row ? (array) $row : null;
    }

    private function getUltimoAjuste(int $eventoId): ?array
    {
        $result = $this->dbQuery(
            'SELECT * FROM mod_gestion_ajustes WHERE evento_id = ? ORDER BY created_at DESC LIMIT 1',
            [$eventoId]
        );
        $row = $result[0] ?? null;

        return $row ? (array) $row : null;
    }

    private function getEventosPorUsuario(int $userId, string $deporte): array
    {
        $result = $this->dbQuery(
            'SELECT e.* FROM mod_eventos e
             INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
             WHERE eu.user_id = ? AND e.deporte = ?
             ORDER BY e.created_at DESC',
            [$userId, $deporte]
        );
        $eventos = [];
        foreach ($result as $row) {
            $eventos[] = (array) $row;
        }

        return $eventos;
    }

    private function getPaises(): array
    {
        $result = $this->dbQuery('SELECT uuid, pais, codigo FROM paises ORDER BY pais ASC');
        $paises = [];
        foreach ($result as $row) {
            $paises[] = (array) $row;
        }

        return $paises;
    }

    /**
     * Helper: ejecuta query y devuelve array de filas.
     */
    private function dbQuery(string $sql, array $params = []): array
    {
        try {
            if (! $this->pdoSingleton) {
                $this->pdoSingleton = $this->db->getDriver()->getConnection()->getResource();
            }
            $stmt = $this->pdoSingleton->prepare($sql);
            $stmt->execute($params);
            $out = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[] = $row;
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Helper: ejecuta query y devuelve la primera fila.
     */
    private function dbCurrent(string $sql, array $params = []): ?array
    {
        $rows = $this->dbQuery($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Cache de conexión PDO para evitar problemas de visibilidad entre prepare() calls.
     */
    private ?\PDO $pdoSingleton = null;

    /**
     * Helper: ejecuta INSERT/UPDATE/DELETE.
     */
    private function dbWrite(string $sql, array $params = []): int|false
    {
        try {
            if (! $this->pdoSingleton) {
                $this->pdoSingleton = $this->db->getDriver()->getConnection()->getResource();
            }
            $stmt = $this->pdoSingleton->prepare($sql);
            $stmt->execute($params);
            $lastId = (int) $this->pdoSingleton->lastInsertId();

            return $lastId > 0 ? $lastId : 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Helper: ejecuta INSERT/UPDATE/DELETE, devuelve true/false.
     */
    private function dbExecute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->db->getDriver()->getConnection()->prepare($sql);
            $stmt->execute($params);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
