<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class NotificacionesController extends AbstractActionController
{
    private AuthService $auth;

    private Adapter $db;

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
        $evento = null;
        $notificaciones = [];
        if ($uuid) {
            $eventoRow = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ? LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $evento = $eventoRow[0] ?? null;
            if ($evento) {
                $notifRows = $this->dbQuery(
                    'SELECT n.*, u.name AS usuario_nombre, u.email
                     FROM mod_gestion_notificaciones n
                     LEFT JOIN users u ON u.id = n.user_id
                     WHERE n.mod_evento_id = ?
                     ORDER BY n.created_at DESC LIMIT 50',
                    [$evento['id']]
                );
                $notificaciones = $notifRows;
            }
        }
        // Separar pendientes/enviados
        $pendientes = array_values(array_filter($notificaciones, fn ($n) => ($n['estado'] ?? '') === 'pendiente'));
        $enviados = array_values(array_filter($notificaciones, fn ($n) => ($n['estado'] ?? '') === 'enviado'));

        return new ViewModel([
            'deporte' => $deporte,
            'uuid' => $uuid,
            'evento' => $evento,
            'eventos' => $this->getEventos($deporte, $identity['id'] ?? 0),
            'ultimoAjuste' => null,
            'paises' => [],
            'notificaciones' => $notificaciones,
            'notificacionesPendientes' => $pendientes,
            'notificacionesEnviadas' => $enviados,
        ]);
    }

    public function obtenerAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$uuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        // Marcar todas las plantillas como pendientes para el evento
        $plantRows = $this->dbQuery(
            'SELECT * FROM mod_gestion_notificaciones_plantilla WHERE mod_evento_id = ?',
            [$eventoId]
        );
        $count = 0;
        foreach ($plantRows as $p) {
            // Buscar usuarios asociados al evento
            $userRows = $this->dbQuery(
                'SELECT user_id FROM evento_user WHERE mod_evento_id = ?',
                [$eventoId]
            );
            foreach ($userRows as $ur) {
                $newUuid = Uuid::uuid4()->toString();
                $ok = $this->dbWrite(
                    'INSERT INTO mod_gestion_notificaciones (uuid, mod_evento_id, user_id, tipo, estado, plantilla, created_at) VALUES (?, ?, ?, ?, "pendiente", ?, NOW())',
                    [$newUuid, $eventoId, (int) $ur['user_id'], $p['tipo'] ?? 'manual', $p['plantilla'] ?? null]
                );
                if ($ok) {
                    $count++;
                }
            }
        }

        return new JsonModel(['ok' => true, 'message' => "$count notificaciones creadas"]);
    }

    public function enviarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_notificaciones SET estado = "enviado", fecha_envio = NOW() WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?) AND estado = "pendiente"',
            [$uuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function enviarIndividualAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $id = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_notificaciones SET estado = "enviado", fecha_envio = NOW() WHERE id = ?',
            [$id]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function notificarTodosAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_notificaciones SET estado = "enviado", fecha_envio = NOW() WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?) AND estado = "pendiente"',
            [$uuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    // =================== HELPERS ===================

    private function getEventos(string $deporte, int $userId): array
    {
        if (! $this->tableExists('mod_eventos')) {
            return [];
        }
        try {
            return $this->dbQuery(
                'SELECT uuid, titulo, inscripcion, referencia_utc FROM mod_eventos WHERE deporte = ? ORDER BY referencia_utc DESC LIMIT 30',
                [$deporte]
            );
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
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare($sql);
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
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare($sql);
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
