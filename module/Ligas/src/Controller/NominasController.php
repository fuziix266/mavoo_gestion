<?php

declare(strict_types=1);

namespace Ligas\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

class NominasController extends AbstractActionController
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
        $nominas = [];
        $evento = null;
        if ($uuid) {
            $eventoRow = $this->dbQuery(
                'SELECT e.* FROM mod_eventos e
                 INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                 WHERE e.uuid = ? AND eu.user_id = ?
                 LIMIT 1',
                [$uuid, $identity['id'] ?? 0]
            );
            $evento = $eventoRow[0] ?? null;
            if ($evento) {
                $nominasRows = $this->dbQuery(
                    'SELECT n.*, u.name, u.email
                     FROM mod_gestion_nominas n
                     LEFT JOIN mod_gestion_nominas_detalles md ON md.nomina_uuid = n.uuid
                     LEFT JOIN users u ON u.id = md.user_id
                     WHERE n.mod_categoria_id IN (SELECT id FROM mod_gestion_categorias WHERE mod_evento_id = ?)
                     ORDER BY n.orden ASC',
                    [$evento['id']]
                );
                $nominas = $nominasRows;
            }
        }

        return new ViewModel([
            'deporte' => $deporte,
            'uuid' => $uuid,
            'evento' => $evento,
            'eventos' => $this->getEventos($deporte, $identity['id'] ?? 0),
            'ultimoAjuste' => null,
            'paises' => [],
            'nominas' => $nominas,
        ]);
    }

    public function verificarJugadorAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $email = $data['email'] ?? null;
        $doc = $data['documento'] ?? null;
        $exists = false;
        $userData = null;
        if ($email) {
            $rows = $this->dbQuery('SELECT id, name, email FROM users WHERE email = ? LIMIT 1', [$email]);
            if (! empty($rows)) {
                $exists = true;
                $userData = $rows[0];
            }
        }
        if (! $exists && $doc) {
            $rows = $this->dbQuery('SELECT user_id, documento FROM users_detalle WHERE documento = ? LIMIT 1', [$doc]);
            if (! empty($rows)) {
                $exists = true;
                $r = $rows[0];
                $rows2 = $this->dbQuery('SELECT id, name, email FROM users WHERE id = ?', [$r['user_id']]);
                $userData = $rows2[0] ?? ['id' => $r['user_id'], 'name' => '', 'email' => ''];
            }
        }

        return new JsonModel(['exists' => $exists, 'user' => $userData]);
    }

    public function agregarJugadorAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        if (! $eventoId) {
            return new JsonModel(['ok' => false, 'error' => 'Evento no encontrado']);
        }
        $userId = (int) ($data['user_id'] ?? 0);
        if (! $userId) {
            $newUuid = Uuid::uuid4()->toString();
            $passHash = password_hash('temporal1234', PASSWORD_BCRYPT);
            $ok = $this->dbWrite(
                'INSERT INTO users (uuid, pais_uuid, name, email, email_verified_at, password, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), ?, NOW(), NOW())',
                [$newUuid, $data['paisUuid'] ?? null, $data['nombre'] ?? 'Sin nombre', $data['email'] ?? null, $passHash]
            );
            if ($ok) {
                $rows = $this->dbQuery('SELECT LAST_INSERT_ID() AS id');
                $userId = (int) ($rows[0]['id'] ?? 0);
            }
        }
        if (! $userId) {
            return new JsonModel(['ok' => false, 'error' => 'No se pudo crear el usuario']);
        }
        $okDetalle = $this->dbWrite(
            'INSERT INTO users_detalle (uuid, user_id, nacimiento, genero, idioma, documento, created_at, updated_at) VALUES (?, ?, ?, ?, "es", ?, NOW(), NOW())',
            [Uuid::uuid4()->toString(), $userId, $data['nacimiento'] ?? null, $data['genero'] ?? 'hombre', $data['documento'] ?? null]
        );

        return new JsonModel(['ok' => $okDetalle, 'user_id' => $userId]);
    }

    public function inscribirAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $categoriaUuid = $data['mod_gestion_categoria_uuid'] ?? null;
        $usuarios = $data['usuarios_uuids'] ?? [];
        if (! $eventoUuid || ! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Datos faltantes']);
        }
        $catRow = $this->dbQuery('SELECT id FROM mod_gestion_categorias WHERE uuid = ?', [$categoriaUuid]);
        $catId = $catRow[0]['id'] ?? 0;
        if (! $catId) {
            return new JsonModel(['ok' => false, 'error' => 'Categoría no encontrada']);
        }
        $nominaUuid = Uuid::uuid4()->toString();
        $usuariosArr = is_array($usuarios) ? $usuarios : [$usuarios];
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_nominas (uuid, mod_categoria_id, usuarios_uuids, orden, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())',
            [$nominaUuid, $catId, json_encode($usuariosArr)]
        );
        if ($ok && ! empty($usuariosArr)) {
            foreach ($usuariosArr as $userUuid) {
                $this->dbWrite(
                    'INSERT INTO mod_gestion_nominas_detalles (nomina_uuid, user_uuid, rankingt_auto, created_at) VALUES (?, ?, 0, NOW())',
                    [$nominaUuid, $userUuid]
                );
            }
        }

        return new JsonModel(['ok' => (bool) $ok, 'message' => 'Inscripción realizada']);
    }

    public function editarAction(): JsonModel
    {
        return $this->inscribirAction();
    }

    public function borrarAction(): JsonModel
    {
        $data = $this->getRequest()->isPost() ? $this->getRequest()->getPost()->toArray() : [];
        $nominaUuid = $data['nomina_uuid'] ?? null;
        if (! $nominaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Nomina requerida']);
        }
        $this->dbWrite('DELETE FROM mod_gestion_nominas_detalles WHERE nomina_uuid = ?', [$nominaUuid]);
        $ok = $this->dbWrite('DELETE FROM mod_gestion_nominas WHERE uuid = ?', [$nominaUuid]);

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function ordenarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $catUuid = $data['categoria_uuid'] ?? null;
        $tipo = $data['tipo'] ?? 'ranking';
        if (! $catUuid) {
            return new JsonModel(['ok' => false, 'error' => 'Categoría requerida']);
        }
        $rows = $this->dbQuery(
            'SELECT md.nomina_uuid, md.rankingt_auto
             FROM mod_gestion_nominas_detalles md
             INNER JOIN mod_gestion_nominas n ON n.uuid = md.nomina_uuid
             WHERE n.mod_categoria_id = (SELECT id FROM mod_gestion_categorias WHERE uuid = ?)
             ORDER BY md.rankingt_auto DESC',
            [$catUuid]
        );
        $i = 1;
        foreach ($rows as $r) {
            $this->dbWrite(
                'UPDATE mod_gestion_nominas_detalles SET rankingt_manual = ?, updated_at = NOW() WHERE nomina_uuid = ?',
                [$tipo === 'ranking' ? $i : mt_rand(1, 100), $r['nomina_uuid']]
            );
            $i++;
        }

        return new JsonModel(['ok' => true]);
    }

    public function moverAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $origenId = (int) ($data['origen_id'] ?? 0);
        $destinoId = (int) ($data['destino_id'] ?? 0);
        if (! $origenId || ! $destinoId) {
            return new JsonModel(['ok' => false]);
        }
        $this->dbWrite('UPDATE mod_gestion_nominas SET orden = ? WHERE id = ?', [-1, $origenId]);
        $this->dbWrite('UPDATE mod_gestion_nominas SET orden = ? WHERE id = ?', [$origenId, $destinoId]);
        $this->dbWrite('UPDATE mod_gestion_nominas SET orden = ? WHERE id = ?', [$destinoId, -1]);

        return new JsonModel(['ok' => true]);
    }

    public function obtRstrAction(): JsonModel
    {
        $nominaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $nominaUuid) {
            return new JsonModel([]);
        }
        $rows = $this->dbQuery(
            'SELECT restricciones FROM mod_gestion_nominas_detalles WHERE nomina_uuid = ?',
            [$nominaUuid]
        );

        return new JsonModel($rows[0] ?? []);
    }

    public function gRstrAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $nominaUuid = $data['nomina_uuid'] ?? null;
        $userUuid = $data['user_uuid'] ?? null;
        $restricciones = $data['restricciones'] ?? [];
        if (! $nominaUuid || ! $userUuid) {
            return new JsonModel(['ok' => false]);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_nominas_detalles SET restricciones = ?, updated_at = NOW() WHERE nomina_uuid = ? AND user_uuid = ?',
            [json_encode($restricciones), $nominaUuid, $userUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function actMarcaAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $userUuid = $data['user_uuid'] ?? null;
        $nominaUuid = $data['nomina_uuid'] ?? null;
        $index = (int) ($data['index'] ?? -1);
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        $rows = $this->dbQuery('SELECT id FROM mod_gestion_marcas WHERE user_uuid = ? AND nomina_uuid = ?', [$userUuid, $nominaUuid]);
        if (! empty($rows)) {
            $ok = $this->dbWrite('DELETE FROM mod_gestion_marcas WHERE id = ?', [$rows[0]['id']]);
        } else {
            $newUuid = Uuid::uuid4()->toString();
            $ok = $this->dbWrite(
                'INSERT INTO mod_gestion_marcas (uuid, user_uuid, nomina_uuid, mod_evento_id, `index`, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [$newUuid, $userUuid, $nominaUuid, $eventoId, $index]
            );
        }

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function actTelAction(): JsonModel
    {
        return $this->genericUserField('telefono');
    }

    public function actMailAction(): JsonModel
    {
        return $this->genericUserField('email');
    }

    public function actNombreAction(): JsonModel
    {
        return $this->genericUserField('name');
    }

    public function actIdentAction(): JsonModel
    {
        $data = $this->getRequest()->getPost()->toArray();
        $userUuid = $data['user_uuid'] ?? null;
        $paisUuid = $data['pais_uuid'] ?? null;
        $documento = $data['documento'] ?? null;
        if (! $userUuid) {
            return new JsonModel(['ok' => false, 'error' => 'user_uuid requerido']);
        }
        $ok = $this->dbWrite(
            'UPDATE users_detalle SET pais_uuid = ?, documento = ?, updated_at = NOW() WHERE user_id = (SELECT id FROM users WHERE uuid = ?)',
            [$paisUuid, $documento, $userUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function actRnkAction(): JsonModel
    {
        $data = $this->getRequest()->getPost()->toArray();
        $nominaUuid = $data['nomina_uuid'] ?? null;
        $manual = $data['rankingt_manual'] ?? null;
        if (! $nominaUuid) {
            return new JsonModel(['ok' => false]);
        }
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_nominas_detalles SET rankingt_manual = ?, updated_at = NOW() WHERE nomina_uuid = ?',
            [$manual === '' || $manual === null ? null : (int) $manual, $nominaUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function buscarUsuarioAction(): JsonModel
    {
        $q = (string) $this->params()->fromQuery('q', '');
        $page = (int) $this->params()->fromQuery('page', 1);
        $rows = $this->dbQuery(
            "SELECT uuid, name, email FROM users WHERE (LOWER(name) LIKE ? OR LOWER(email) LIKE ?) AND id NOT IN (SELECT model_id FROM model_has_roles WHERE model_type = 'App\\Models\\User' AND role_id IN (SELECT id FROM roles WHERE name IN ('dios','ceo'))) ORDER BY name LIMIT 10 OFFSET ?",
            ['%'.strtolower($q).'%', '%'.strtolower($q).'%', ($page - 1) * 10]
        );

        return new JsonModel(['results' => $rows, 'pagination' => ['more' => count($rows) === 10]]);
    }

    public function exportarExcelAction()
    {
        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $rows = $this->dbQuery(
            'SELECT n.uuid, u.name, u.email
             FROM mod_gestion_nominas n
             INNER JOIN mod_gestion_nominas_detalles md ON md.nomina_uuid = n.uuid
             LEFT JOIN users u ON u.uuid = md.user_uuid
             WHERE n.mod_categoria_id = (SELECT id FROM mod_gestion_categorias WHERE uuid = ?)',
            [$categoriaUuid]
        );
        $csv = "uuid,nombre,email\n";
        foreach ($rows as $r) {
            $csv .= $r['uuid'].','.str_replace([',', '"'], ' ', $r['name']).','.$r['email']."\n";
        }
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->getHeaders()->addHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="nomina_'.$categoriaUuid.'.csv"',
        ]);
        $response->setContent($csv);

        return $response;
    }

    public function marcasListarAction(): JsonModel
    {
        $eventoUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        $rows = $this->dbQuery(
            'SELECT m.*, u.name FROM mod_gestion_marcas m
             LEFT JOIN users u ON u.uuid = m.user_uuid
             WHERE m.mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)',
            [$eventoUuid]
        );

        return new JsonModel($rows);
    }

    public function marcasGuardarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoUuid = $body['evento_uuid'] ?? null;
        $titulo = $body['titulo'] ?? 'Marcas';
        $obligatorio = (int) ($body['obligatorio'] ?? 1);
        $opciones = $body['opciones'] ?? [];
        $estilo = $body['estilo'] ?? 'opciones';
        $elegido = $body['elegido'] ?? null;
        $newUuid = Uuid::uuid4()->toString();
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        $ok = $this->dbWrite(
            'INSERT INTO mod_gestion_marcas_definicion (uuid, mod_evento_id, titulo, opciones, estilo, elegido, obligatorio, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$newUuid, $eventoId, $titulo, json_encode($opciones), $estilo, $elegido, $obligatorio]
        );

        return new JsonModel(['ok' => (bool) $ok, 'uuid' => $newUuid]);
    }

    public function marcasToggleAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $data['evento_uuid'] ?? null;
        $userUuid = $data['user_uuid'] ?? null;
        $nominaUuid = $data['nomina_uuid'] ?? null;
        $rows = $this->dbQuery('SELECT id FROM mod_gestion_marcas WHERE user_uuid = ? AND nomina_uuid = ?', [$userUuid, $nominaUuid]);
        if (! empty($rows)) {
            $ok = $this->dbWrite('DELETE FROM mod_gestion_marcas WHERE id = ?', [$rows[0]['id']]);
        } else {
            $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$eventoUuid]);
            $eventoId = $eventoRow[0]['id'] ?? 0;
            $ok = $this->dbWrite(
                'INSERT INTO mod_gestion_marcas (uuid, user_uuid, nomina_uuid, mod_evento_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [Uuid::uuid4()->toString(), $userUuid, $nominaUuid, $eventoId]
            );
        }

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function marcasResetAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $data['evento_uuid'] ?? null;
        $ok = $this->dbWrite('DELETE FROM mod_gestion_marcas WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)', [$eventoUuid]);

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function marcasAplicarAction(): JsonModel
    {
        return $this->marcasToggleAction();
    }

    public function marcasEliminarAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $id = (int) ($data['id'] ?? 0);
        $ok = $this->dbWrite('DELETE FROM mod_gestion_marcas WHERE id = ?', [$id]);

        return new JsonModel(['ok' => (bool) $ok]);
    }

    public function marcasResetGeneralAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoUuid = $data['evento_uuid'] ?? null;
        $this->dbWrite('DELETE FROM mod_gestion_marcas WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)', [$eventoUuid]);
        $this->dbWrite('DELETE FROM mod_gestion_marcas_definicion WHERE mod_evento_id = (SELECT id FROM mod_eventos WHERE uuid = ?)', [$eventoUuid]);

        return new JsonModel(['ok' => true]);
    }

    public function marcasUpdateAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false]);
        }
        $data = $this->getRequest()->getPost()->toArray();
        $eventoRow = $this->dbQuery('SELECT id FROM mod_eventos WHERE uuid = ?', [$data['evento_uuid'] ?? null]);
        $eventoId = $eventoRow[0]['id'] ?? 0;
        $opciones = json_decode($data['opciones'] ?? '[]', true) ?: [];
        $ok = $this->dbWrite(
            'UPDATE mod_gestion_marcas_definicion SET titulo = ?, opciones = ?, estilo = ?, elegido = ?, obligatorio = ?, updated_at = NOW() WHERE id = ?',
            [$data['titulo'] ?? '', json_encode($opciones), $data['estilo'] ?? 'opciones', $data['elegido'] ?? null, (int) ($data['obligatorio'] ?? 1), (int) ($data['id'] ?? 0)]
        );

        return new JsonModel(['ok' => (bool) $ok]);
    }

    // =================== HELPERS ===================

    private function genericUserField(string $field): JsonModel
    {
        $data = $this->getRequest()->getPost()->toArray();
        $userUuid = $data['user_uuid'] ?? null;
        $value = $data[$field === 'name' ? 'nombre' : $field] ?? null;
        if (! $userUuid) {
            return new JsonModel(['ok' => false, 'error' => 'user_uuid requerido']);
        }
        $ok = $this->dbWrite(
            "UPDATE users SET $field = ?, updated_at = NOW() WHERE uuid = ?",
            [$value, $userUuid]
        );

        return new JsonModel(['ok' => (bool) $ok]);
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
