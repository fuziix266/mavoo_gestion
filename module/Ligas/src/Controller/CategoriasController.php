<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class CategoriasController extends AbstractActionController
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
        $categorias = [];
        $evento = null;

        if ($uuid) {
            $eventoResult = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ?
                 LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $evento = $eventoResult[0] ?? null;

            if ($evento) {
                $categoriasResult = $this->dbQuery(
                    'SELECT c.*, cl.titulo, cl.icono
                     FROM mod_gestion_categorias c
                     LEFT JOIN mod_gestion_categorias_listas cl ON cl.uuid = c.categoria
                     WHERE c.mod_evento_id = ?
                     ORDER BY c.orden ASC, c.created_at ASC',
                    [$evento['id']]
                );
                $categorias = $categoriasResult;
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

    public function nuevacatAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $post = $this->getRequest()->getPost()->toArray();
        $categoriaUuid = $post['categoria'] ?? null;
        $color = $post['color'] ?? '#3D5A80';
        $limite = (int) ($post['limite'] ?? 16);
        $aConsiderar = (int) ($post['a_considerar'] ?? 4);
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $categoriaUuid || ! $eventoUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Datos incompletos']);
        }
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        $newUuid = Uuid::uuid4()->toString();
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_categorias (uuid, mod_evento_id, categoria, color, limite, a_considerar, estructura, estructura_info, inscritos, partidos, variables, activo, orden, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "grupos", "[]", 0, "[]", "[]", 1, 0, NOW(), NOW())',
            [$newUuid, $eventoId, $categoriaUuid, $color, $limite, $aConsiderar]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'uuid' => $newUuid,
            'message' => $ok ? 'Categoría creada' : 'Error al crear',
        ]);
    }

    public function actcatAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $post = $this->getRequest()->getPost()->toArray();
        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $limite = (int) ($post['limite'] ?? 16);
        $aConsiderar = (int) ($post['a_considerar'] ?? 4);
        if (! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'UUID requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_categorias SET limite = ?, a_considerar = ?, updated_at = NOW() WHERE uuid = ?',
            [$limite, $aConsiderar, $categoriaUuid]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Categoría actualizada' : 'Error al actualizar',
        ]);
    }

    public function guardarestructuraAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $post = $this->getRequest()->getPost()->toArray();
        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $estructura = $post['custom_clasificados_value'] ?? 'grupos';
        $estructuraInfo = $post['estructura_info'] ?? '{}';
        if (! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'UUID requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_categorias SET estructura = ?, variables = ?, updated_at = NOW() WHERE uuid = ?',
            [$estructura, $estructuraInfo, $categoriaUuid]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Estructura guardada' : 'Error al guardar',
        ]);
    }

    public function eliminarCategoriaAction(): JsonModel
    {
        if ($this->getRequest()->getMethod() !== 'DELETE') {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'UUID requerido']);
        }
        $ok = $this->dbWrite('DELETE FROM mod_gestion_categorias WHERE uuid = ?', [$categoriaUuid]);

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Categoría eliminada' : 'Error al eliminar',
        ]);
    }

    public function disponiblesAction(): JsonModel
    {
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $eventoUuid) {
            return new JsonModel([]);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $rows = $this->dbQuery(
            'SELECT uuid, titulo, icono FROM mod_gestion_categorias_listas WHERE deporte = ? ORDER BY titulo',
            [$deporte]
        );

        return new JsonModel($rows);
    }

    public function agregarAccesoAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $categoriaListaId = $body['categoria_lista_id'] ?? null;
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $categoriaListaId || ! $eventoUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Datos incompletos']);
        }
        $deporte = $this->params()->fromRoute('deporte', 'padel');
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_categorias_lista_acceso (categoria_lista_id, mod_evento_id, deporte, created_at) VALUES (?, ?, ?, NOW())',
            [$categoriaListaId, $eventoUuid, $deporte]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function eliminarAccesoAction(): JsonModel
    {
        if ($this->getRequest()->getMethod() !== 'DELETE') {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $id = $this->params()->fromRoute('id');
        if (! $id) {
            return new JsonModel(['ok' => false, 'error' => 'ID requerido']);
        }
        $ok = $this->dbWrite('DELETE FROM mod_gestion_categorias_lista_acceso WHERE id = ?', [$id]);

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function updateColorAction(): JsonModel
    {
        if ($this->getRequest()->getMethod() !== 'PUT') {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $color = $body['color'] ?? '#3D5A80';
        if (! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'UUID requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_categorias SET color = ?, updated_at = NOW() WHERE uuid = ?',
            [$color, $categoriaUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function reglasAction(): JsonModel
    {
        $id = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $id) {
            return new JsonModel([]);
        }
        $rows = $this->dbQuery(
            'SELECT variables FROM mod_gestion_categorias WHERE uuid = ?',
            [$id]
        );
        if (empty($rows)) {
            return new JsonModel([]);
        }
        $vars = $rows[0]['variables'] ?? '[]';

        return new JsonModel(['variables' => $vars]);
    }

    public function calcularestructurasAction(): JsonModel
    {
        $n = (int) ($this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id'));
        if ($n < 2) {
            return new JsonModel(['error' => 'Se necesitan al menos 2 equipos']);
        }
        // Devuelve una sugerencia simple de grupos
        $grupos = [];
        $grupoCount = max(2, min(8, (int) ceil(sqrt($n))));
        for ($g = 0; $g < $grupoCount; $g++) {
            $grupos[] = ['nombre' => 'Grupo '.chr(65 + $g), 'tamano' => 0];
        }

        return new JsonModel([
            'grupos' => $grupoCount,
            'estructura' => 'grupos',
            'detalle' => $grupos,
        ]);
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
