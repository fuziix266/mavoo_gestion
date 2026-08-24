<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class SedesController extends AbstractActionController
{
    private Adapter $db;

    private AuthService $auth;

    public function __construct(AuthService $auth, Adapter $db)
    {
        $this->auth = $auth;
        $this->db = $db;
    }

    public function indexAction(): ViewModel
    {
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $identity = $this->auth->getIdentity();
        $sedes = [];
        $sedesLista = [];
        $evento = null;
        $total_bloques = 0;
        $total_requerido = 0;

        if ($uuid) {
            $eventoResult = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ?
                 LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $eventoRow = $eventoResult[0] ?? null;
            $evento = $eventoRow ?: null;

            if ($evento) {
                $slResult = $this->dbQuery(
                    'SELECT * FROM mod_gestion_sedes_listas
                     WHERE deporte = ?
                     ORDER BY nombre ASC',
                    [$deporte]
                );
                $sedesLista = $slResult;
            }
        }

        $menuEV = (object) [
            'uuid' => $uuid ?? '',
            'sedes' => true, 'categorias' => true, 'nominas' => true,
            'fixture' => true, 'ranking' => true, 'notificaciones' => true, 'logs' => true,
        ];

        return new ViewModel([
            'deporte' => $deporte,
            'uuid' => $uuid,
            'evento' => $evento,
            'eventos' => $this->getEventos($deporte, $identity['id'] ?? 0),
            'sedes' => $sedes,
            'sedesLista' => $sedesLista,
            'ultimoAjuste' => null,
            'paises' => [],
            'menuEV' => $menuEV,
            'total_bloques' => 0,
            'total_requerido' => 0,
        ]);
    }

    public function agregarDiaAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $post = $this->getRequest()->getPost()->toArray();
        $fechaInicio = $post['fecha_inicio'] ?? null;
        $fechaCierre = $post['fecha_cierre'] ?? null;
        if (! $fechaInicio || ! $fechaCierre) {
            return new JsonModel(['ok' => false, 'error' => 'Fechas requeridas']);
        }
        $newUuid = Uuid::uuid4()->toString();
        $identity = $this->auth->getIdentity();
        $userId = $identity['id'] ?? 0;
        // Find evento_id
        $eventoRow = $this->dbQuery(
            'SELECT id FROM mod_eventos WHERE uuid = ?',
            [$uuid]
        );
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_sedes (uuid, mod_evento_id, fecha_inicio, fecha_cierre, activo, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, NOW(), NOW())',
            [$newUuid, $eventoId, $fechaInicio, $fechaCierre]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'uuid' => $newUuid,
            'message' => $ok ? 'Día creado correctamente' : 'Error al crear',
        ]);
    }

    public function agregarCanchaAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $post = $this->getRequest()->getPost()->toArray();
        $sedeId = (int) ($post['mod_gestion_sede_id'] ?? 0);
        $sedeListaUuid = $post['sede_lista_uuid'] ?? null;
        $cantidad = (int) ($post['cantidad'] ?? 1);
        if (! $sedeId || ! $sedeListaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Datos requeridos']);
        }
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_sedes_canchas (sede_lista_uuid, mod_gestion_sede_id, cantidad, activo, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())',
            [$sedeListaUuid, $sedeId, $cantidad]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Cancha agregada' : 'Error al agregar',
        ]);
    }

    public function agregarRestriccionesAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $post = $this->getRequest()->getPost()->toArray();
        $sedeId = (int) ($post['mod_gestion_sede_id'] ?? 0);
        $horas = (array) ($post['hora_inicio'] ?? []);
        if (! $sedeId || empty($horas)) {
            return new JsonModel(['ok' => false, 'error' => 'Datos requeridos']);
        }
        // Borrar restricciones existentes y crear nuevas
        $this->dbWrite('DELETE FROM mod_gestion_sedes_restricciones WHERE mod_gestion_sede_id = ?', [$sedeId]);
        $ok = true;
        foreach ($horas as $h) {
            $h = trim((string) $h);
            if ($h === '') {
                continue;
            }
            $r = $this->dbWrite(
                'INSERT INTO mod_gestion_sedes_restricciones (mod_gestion_sede_id, hora_inicio, activo, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())',
                [$sedeId, $h]
            );
            $ok = $ok && $r;
        }

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Restricciones actualizadas' : 'Error parcial',
        ]);
    }

    public function desactivarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $id = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite('UPDATE mod_gestion_sedes SET activo = 0 WHERE uuid = ?', [$id]);

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Sede desactivada' : 'Error',
        ]);
    }

    public function desactivarCanchaAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $id = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite('UPDATE mod_gestion_sedes_canchas SET activo = 0 WHERE sede_lista_uuid = ?', [$id]);

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Cancha desactivada' : 'Error',
        ]);
    }

    public function getSedeListaAction(): JsonModel
    {
        $id = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $rows = $this->dbQuery(
            'SELECT nombre, direccion FROM mod_gestion_sedes_listas WHERE uuid = ?',
            [$id]
        );
        if (empty($rows)) {
            return new JsonModel(['error' => 'Sede no encontrada']);
        }

        return new JsonModel($rows[0]);
    }

    public function storeSedeListaAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $post = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $nombre = $post['nombre'] ?? '';
        $direccion = $post['direccion'] ?? '';
        if (! $nombre) {
            return new JsonModel(['ok' => false, 'error' => 'Nombre requerido']);
        }
        if ($uuid) {
            // Update
            $ok = $this->dbWrite(
                'UPDATE mod_gestion_sedes_listas SET nombre = ?, direccion = ?, updated_at = NOW() WHERE uuid = ?',
                [$nombre, $direccion, $uuid]
            );
        } else {
            // Insert
            $newUuid = Uuid::uuid4()->toString();
            $ok = $this->dbWrite(
                'INSERT INTO mod_gestion_sedes_listas (uuid, deporte, nombre, direccion, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$newUuid, $deporte, $nombre, $direccion]
            );
            $uuid = $newUuid;
        }

        return new JsonModel([
            'ok' => (bool) $ok,
            'uuid' => $uuid,
            'message' => $ok ? 'Sede guardada' : 'Error al guardar',
        ]);
    }

    public function updateSedeListaAction(): JsonModel
    {
        // Reutiliza storeSedeListaAction con uuid
        return $this->storeSedeListaAction();
    }

    // =================== HELPERS ===================

    private function getEventos(string $deporte, int $userId): array
    {
        if (! $this->tableExists('mod_eventos')) {
            return [];
        }
        try {
            $rows = $this->dbQuery(
                'SELECT e.uuid, e.titulo, e.inscripcion, e.referencia_utc
                 FROM mod_eventos e
                 WHERE e.deporte = ?
                 ORDER BY e.referencia_utc DESC LIMIT 30',
                [$deporte]
            );

            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function tableExists(string $t): bool
    {
        try {
            $row = $this->db->query('SHOW TABLES LIKE ?', [$t])->current();

            return (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function dbQuery(string $sql, array $params = []): array
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare($sql);
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

    private function dbWrite(string $sql, array $params = []): bool
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Cache de conexión PDO singleton para visibilidad entre statements.
     */
    private ?\PDO $pdoSingleton = null;

    private function getPdo(): \PDO
    {
        if (! $this->pdoSingleton) {
            $this->pdoSingleton = $this->db->getDriver()->getConnection()->getResource();
        }

        return $this->pdoSingleton;
    }
}
