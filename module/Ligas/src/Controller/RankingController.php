<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class RankingController extends AbstractActionController
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
        $rankings = [];
        if ($uuid) {
            $eventoRow = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ? LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $evento = $eventoRow[0] ?? null;
            if ($evento) {
                $rankingsRows = $this->dbQuery(
                    'SELECT * FROM mod_rankings WHERE evento_id = ? ORDER BY id ASC',
                    [$evento['id']]
                );
                $rankings = $rankingsRows;
            }
        }

        return new ViewModel([
            'deporte' => $deporte,
            'uuid' => $uuid,
            'evento' => $evento,
            'eventos' => $this->getEventos($deporte, $identity['id'] ?? 0),
            'ultimoAjuste' => null,
            'paises' => [],
            'rankings' => $rankings,
        ]);
    }

    public function aplicarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $catRows = $this->dbQuery(
            'SELECT id FROM mod_gestion_categorias WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)',
            [$uuid]
        );
        $ok = true;
        foreach ($catRows as $cat) {
            $nomRows = $this->dbQuery(
                'SELECT n.uuid, u.name, md.rankingt_auto
                 FROM mod_gestion_nominas n
                 INNER JOIN mod_gestion_nominas_detalles md ON md.nomina_uuid = n.uuid
                 LEFT JOIN users u ON u.id = md.user_id
                 WHERE n.mod_categoria_id = ?
                 ORDER BY (md.rankingt_auto IS NULL), md.rankingt_auto DESC, u.name',
                [$cat['id']]
            );
            $pos = 1;
            foreach ($nomRows as $nr) {
                $ok = $ok && $this->dbWrite(
                    'UPDATE mod_gestion_nominas_detalles SET rankingt_manual = ? WHERE nomina_uuid = ?',
                    [$pos, $nr['uuid']]
                );
                $pos++;
            }
        }

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function desasociarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $ok = $this->dbWrite(
            'UPDATE mod_rankings SET aplicado = 0 WHERE evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)',
            [$uuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function destroyAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $id = (int) ($data['id'] ?? 0);
        $ok = $this->dbWrite('DELETE FROM mod_rankings WHERE id = ?', [$id]);

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function storeAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $nombre = $data['nombre_ranking'] ?? 'Ranking '.date('Y');
        $puntos = array_filter($data, fn ($k) => strpos($k, 'puntos_') === 0, ARRAY_FILTER_USE_KEY);
        $puntosJson = json_encode($puntos);
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$uuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        $newUuid = Uuid::uuid4()->toString();
        $ok = $this->dbWrite(
            'INSERT INTO mod_rankings (uuid, evento_id, nombre, puntos, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$newUuid, $eventoId, $nombre, $puntosJson]
        );

        return new JsonModel(['ok' => (bool) $ok, 'uuid' => $newUuid]);
    }

    public function editAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $id = (int) ($data['ranking_uuid'] ?? $data['id'] ?? 0);
        $nombre = $data['nombre_ranking'] ?? 'Ranking';
        $puntos = array_filter($data, fn ($k) => strpos($k, 'puntos_') === 0, ARRAY_FILTER_USE_KEY);
        $puntosJson = json_encode($puntos);
        $ok = $this->dbWrite(
            'UPDATE mod_rankings SET nombre = ?, puntos = ?, updated_at = NOW() WHERE uuid = ?',
            [$nombre, $puntosJson, $uuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function asociarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $uuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $rankingUuid = $data['ranking_uuid'] ?? null;
        $categorias = $data['categorias'] ?? [];
        $porcentajes = $data['porcentajes'] ?? [];
        if (! $rankingUuid) {
            return new JsonModel(['ok' => false, 'error' => 'ranking_uuid requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_rankings SET categorias = ?, porcentajes = ?, aplicado = 1, updated_at = NOW() WHERE uuid = ?',
            [json_encode($categorias), json_encode($porcentajes), $rankingUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function manualAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $nominaUuid = $data['nominaUuid'] ?? null;
        $puntos = (int) ($data['nuevoRanking'] ?? 0);
        if (! $nominaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'nominaUuid requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_nominas_detalles SET rankingt_manual = ?, updated_at = NOW() WHERE nomina_uuid = ?',
            [$puntos, $nominaUuid]
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
            $stmt = $this->db->getDriver()->getConnection()->prepare($sql);
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
            $stmt = $this->db->getDriver()->getConnection()->prepare($sql);
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
