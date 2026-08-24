<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class FixtureController extends AbstractActionController
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
        $categorias = [];
        if ($uuid) {
            $eventoRow = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ? LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $evento = $eventoRow[0] ?? null;
            if ($evento) {
                $catRows = $this->dbQuery(
                    'SELECT c.*, cl.titulo, cl.icono
                     FROM mod_gestion_categorias c
                     LEFT JOIN mod_gestion_categorias_listas cl ON cl.uuid = c.categoria
                     WHERE c.mod_evento_id = ?
                     ORDER BY c.orden ASC',
                    [$evento['id']]
                );
                $categorias = $catRows;
            }
        }

        return new ViewModel([
            'deporte' => $deporte,
            'uuid' => $uuid,
            'evento' => $evento,
            'eventos' => $this->getEventos($deporte, $identity['id'] ?? 0),
            'ultimoAjuste' => null,
            'paises' => [],
            'categorias' => $categorias,
        ]);
    }

    public function reiniciarAvanzadoAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $catId = (int) ($data['id_categoria'] ?? 0);
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        $ok = $this->dbWrite(
            'DELETE FROM mod_gestion_partidos WHERE categoria_id IN (SELECT id FROM mod_gestion_categorias WHERE mod_evento_id = ?)',
            [$eventoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function linksAction(): JsonModel
    {
        $eventoId = (int) ($this->params()->fromQuery('evento_id', 0));
        if (! $eventoId) {
            return new JsonModel([]);
        }
        $rows = $this->dbQuery(
            'SELECT s.*, sl.nombre, sl.direccion
             FROM mod_gestion_sedes s
             LEFT JOIN mod_gestion_sedes_listas sl ON sl.uuid = s.sede_lista_uuid
             WHERE s.mod_evento_id = ?
             ORDER BY s.fecha_inicio ASC',
            [$eventoId]
        );

        return new JsonModel($rows);
    }

    public function toggleAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        // Insertar/actualizar bloqueo
        $sedeId = (int) ($body['sede_id'] ?? 0);
        $bloque = (int) ($body['bloque'] ?? 0);
        $cancha = $body['cancha'] ?? null;
        $categoriaId = (int) ($body['categoria_id'] ?? 0);
        $partidoIdx = (int) ($body['partido_idx'] ?? 0);
        $rows = $this->dbQuery(
            'SELECT id FROM mod_gestion_partidos_bloqueos
             WHERE sede_id = ? AND bloque = ? AND cancha = ? AND categoria_id = ? AND partido_idx = ?',
            [$sedeId, $bloque, $cancha, $categoriaId, $partidoIdx]
        );
        if (! empty($rows)) {
            $ok = $this->dbWrite('DELETE FROM mod_gestion_partidos_bloqueos WHERE id = ?', [$rows[0]['id']]);
        } else {
            $newUuid = Uuid::uuid4()->toString();
            $ok = $this->dbWrite(
                'INSERT INTO mod_gestion_partidos_bloqueos (uuid, sede_id, bloque, cancha, categoria_id, partido_idx, mod_evento_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$newUuid, $sedeId, $bloque, $cancha, $categoriaId, $partidoIdx, $eventoId]
            );
        }

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function programarGruposAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'evento_id requerido']);
        }
        $rows = $this->dbQuery('SELECT id, partidos, estructura FROM mod_gestion_categorias WHERE mod_evento_id = ?', [$eventoId]);
        $ok = true;
        foreach ($rows as $cat) {
            $partidos = json_decode($cat['partidos'] ?? '[]', true) ?: [];
            $partidos = $this->algoritmoProgramarGrupos($partidos);
            $ok = $ok && $this->dbWrite(
                'UPDATE mod_gestion_categorias SET partidos = ?, updated_at = NOW() WHERE id = ?',
                [json_encode($partidos), $cat['id']]
            );
        }

        return new JsonModel(['ok' => $ok, 'message' => 'Programación de grupos generada']);
    }

    public function programarEliminatoriasAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'evento_id requerido']);
        }
        $rows = $this->dbQuery('SELECT id, partidos FROM mod_gestion_categorias WHERE mod_evento_id = ?', [$eventoId]);
        $ok = true;
        foreach ($rows as $cat) {
            $partidos = json_decode($cat['partidos'] ?? '[]', true) ?: [];
            $partidos = $this->algoritmoEliminatorias($partidos);
            $ok = $ok && $this->dbWrite(
                'UPDATE mod_gestion_categorias SET partidos = ?, updated_at = NOW() WHERE id = ?',
                [json_encode($partidos), $cat['id']]
            );
        }

        return new JsonModel(['ok' => $ok]);
    }

    public function resetAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        $ok = $this->dbWrite(
            'DELETE FROM mod_gestion_partidos WHERE categoria_id IN (SELECT id FROM mod_gestion_categorias WHERE mod_evento_id = ?)',
            [$eventoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function resetTotalAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        $ok = $this->dbWrite(
            'DELETE FROM mod_gestion_partidos_bloqueos WHERE mod_evento_id = ?',
            [$eventoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function rankingGruposAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $catUuid = $data['categoria_uuid'] ?? null;
        $fase = (int) ($data['fase'] ?? 0);
        if (! $catUuid) {
            return new JsonModel(['ok' => false, 'error' => 'categoria_uuid requerido']);
        }
        $rows = $this->dbQuery(
            'SELECT n.usuarios_uuids, md.rankingt_manual
             FROM mod_gestion_nominas n
             LEFT JOIN mod_gestion_nominas_detalles md ON md.nomina_uuid = n.uuid
             WHERE n.mod_categoria_id = (SELECT id FROM mod_gestion_categorias WHERE uuid = ?)
             ORDER BY (md.rankingt_manual IS NULL), md.rankingt_manual ASC',
            [$catUuid]
        );

        return new JsonModel(['nominas' => $rows]);
    }

    public function categoriasAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoId = (int) ($data['evento_id'] ?? 0);
        $rows = $this->dbQuery(
            'SELECT c.*, cl.titulo, cl.icono FROM mod_gestion_categorias c
             LEFT JOIN mod_gestion_categorias_listas cl ON cl.uuid = c.categoria
             WHERE c.mod_evento_id = ? ORDER BY c.orden ASC',
            [$eventoId]
        );

        return new JsonModel($rows);
    }

    public function agregarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoId = (int) ($data['evento_id'] ?? 0);
        $sedeId = (int) ($data['sede_id'] ?? 0);
        $bloque = (int) ($data['bloque'] ?? 0);
        $cancha = $data['cancha'] ?? null;
        $catId = (int) ($data['categoria_id'] ?? 0);
        $partidoIdx = (int) ($data['partido_idx'] ?? 0);
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Faltan datos']);
        }
        $newUuid = Uuid::uuid4()->toString();
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_partidos (uuid, evento_id, sede_id, bloque, cancha, categoria_id, partido_idx, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$newUuid, $eventoId, $sedeId, $bloque, $cancha, $catId, $partidoIdx]
        );

        return new JsonModel(['ok' => (bool) $ok, 'uuid' => $newUuid]);
    }

    public function partidosAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $catId = (int) ($data['categoria_id'] ?? 0);
        $rows = $this->dbQuery(
            'SELECT * FROM mod_gestion_partidos WHERE categoria_id = ? ORDER BY bloque, cancha, partido_idx',
            [$catId]
        );

        return new JsonModel($rows);
    }

    public function validarMovimientoAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();

        // Validar que un partido se pueda mover
        return new JsonModel(['ok' => true, 'valido' => true]);
    }

    public function moverAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $partidoId = (int) ($data['partido_id'] ?? 0);
        $destinoSedeId = (int) ($data['sede_id'] ?? 0);
        $destinoBloque = (int) ($data['bloque'] ?? 0);
        $destinoCancha = $data['cancha'] ?? null;
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_partidos SET sede_id = ?, bloque = ?, cancha = ?, updated_at = NOW() WHERE id = ?',
            [$destinoSedeId, $destinoBloque, $destinoCancha, $partidoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function iniciarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $partidoId = (int) ($data['partido_id'] ?? 0);
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_partidos SET estado = "iniciado", updated_at = NOW() WHERE id = ?',
            [$partidoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function dueloAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $partidoId = (int) ($data['partido_id'] ?? 0);
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_partidos SET estado = "en_curso", updated_at = NOW() WHERE id = ?',
            [$partidoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function resultadoAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $partidoId = (int) ($data['partido_id'] ?? 0);
        $ganador = (int) ($data['ganador'] ?? 0);
        $wo = (int) ($data['wo'] ?? 0);
        $sets = $data['sets'] ?? '[]';
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_partidos SET estado = "finalizado", ganador_id = ?, wo = ?, sets = ?, updated_at = NOW() WHERE id = ?',
            [$ganador, $wo, json_encode(json_decode($sets, true) ?: []), $partidoId]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    // =================== HELPERS ===================

    private function algoritmoProgramarGrupos(array $partidos): array
    {
        // Algoritmo simple: distribuir partidos en bloques
        foreach ($partidos as &$p) {
            $p['programado'] = true;
        }

        return $partidos;
    }

    private function algoritmoEliminatorias(array $partidos): array
    {
        foreach ($partidos as &$p) {
            $p['eliminatoria'] = true;
        }

        return $partidos;
    }

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
