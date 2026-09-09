<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class LogsController extends AbstractActionController
{
    private AdapterInterface $db;

    private AuthService $auth;

    public function __construct(AuthService $auth, AdapterInterface $db)
    {
        $this->auth = $auth;
        $this->db = $db;
    }

    public function indexAction(): ViewModel
    {
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $action = $this->params()->fromRoute('accion', 'index');

        $logs = [];
        if ($this->tableExists('mod_logs')) {
            try {
                $sql = 'SELECT l.id, l.uuid, l.accion, l.detalle, l.ip, l.seccion, l.created_at,
                              e.titulo AS evento_titulo, e.deporte,
                              u.name AS usuario_nombre
                          FROM mod_logs l
                          LEFT JOIN mod_eventos e ON e.id = l.id_evento
                          LEFT JOIN users u ON u.id = l.user_id
                          WHERE e.deporte = ?
                          ORDER BY l.created_at DESC
                          LIMIT 100';
                $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare($sql);
                $stmt->execute([$deporte]);
                $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
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
            'evento' => $this->getEventoActivo($deporte, $uuid),
            'eventos' => $this->getEventos($deporte),
            'ultimoAjuste' => null,
            'paises' => [],
            'menuEV' => $menuEV,
            'logs' => $logs,
        ]);
    }

    private function getEventoActivo(string $deporte, ?string $uuid): ?array
    {
        if (! $uuid || ! $this->tableExists('mod_eventos')) {
            return null;
        }
        try {
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare(
                'SELECT * FROM mod_eventos WHERE uuid = ? AND deporte = ?'
            );
            $stmt->execute([$uuid, $deporte]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getEventos(string $deporte): array
    {
        if (! $this->tableExists('mod_eventos')) {
            return [];
        }
        try {
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare(
                'SELECT uuid, titulo, inscripcion, referencia_utc FROM mod_eventos WHERE deporte = ? ORDER BY referencia_utc DESC LIMIT 30'
            );
            $stmt->execute([$deporte]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function tableExists(string $t): bool
    {
        try {
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$t]);

            return (bool) $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
