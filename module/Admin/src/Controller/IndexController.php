<?php

declare(strict_types=1);

namespace Admin\Controller;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Http\Client;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;

/**
 * IndexController - Módulo Admin
 *
 * Reescrito para usar Laminas DB directamente (sin Laravel Eloquent).
 * Mantiene la API pública (nombres de métodos y firmas) intacta para
 * no romper las rutas existentes en Admin/module.config.php.
 */
class IndexController extends AbstractActionController
{
    private Adapter $db;

    public function __construct(Adapter $db)
    {
        $this->db = $db;
    }

    public function indexAction()
    {
        return new ViewModel;
    }

    public function moderadoresAction()
    {
        return $this->renderRolesList();
    }

    public function ceoAdminsAction()
    {
        return $this->renderRolesList();
    }

    public function diosCeoAction()
    {
        return $this->renderRolesList();
    }

    public function diosDiosAction()
    {
        return $this->renderRolesList();
    }

    /**
     * Pantalla de gestión de roles (moderador/ceo/dios/...).
     * Equivalente a renderRolesList() pero con SQL nativo.
     */
    private function renderRolesList(): ViewModel
    {
        $prefixes = $this->getPrefixes();
        $accessCL = $prefixes['accessCL'];

        // Usuarios que SÍ tienen el rol
        $usuarios = $this->fetchUsersWithRole($accessCL);

        // Usuarios que NO tienen ese rol
        $usuariosSinRol = $this->fetchUsersWithoutRole($accessCL);

        $viewModel = new ViewModel([
            'usuarios' => $usuarios,
            'usuariosSinRol' => $usuariosSinRol,
            'accessCL' => $accessCL,
            'baseCL' => $prefixes['baseCL'],
            'tituloCL' => $prefixes['tituloCL'],
            'TITtituloCL' => $prefixes['TITtituloCL'],
        ]);
        $viewModel->setTemplate('admin/index/dios');

        return $viewModel;
    }

    /**
     * Devuelve usuarios que tienen el rol indicado, con sus relaciones:
     * detalle, whatsapps, foto_perfil y pais (como objetos simples).
     *
     * @return array<int, object>
     */
    private function fetchUsersWithRole(string $roleName): array
    {
        $sql = <<<'SQL'
        SELECT u.id, u.uuid, u.name, u.email, u.pais_uuid, u.created_at,
               ud.nacimiento, ud.genero, ud.documento,
               p.sigla AS pais_sigla,
               fp.url AS foto_url, fp.mini_url AS foto_mini_url
          FROM users u
          INNER JOIN model_has_roles mhr
                 ON mhr.model_id = u.id AND mhr.model_type = ?
          INNER JOIN roles r ON r.id = mhr.role_id AND r.name = ?
          LEFT JOIN users_detalle ud ON ud.user_id = u.id
          LEFT JOIN paises p ON p.uuid = u.pais_uuid
          LEFT JOIN foto_perfil fp ON fp.user_id = u.id
         ORDER BY u.created_at DESC
SQL;

        $rows = $this->fetchAll($sql, ['App\\Models\\User', $roleName]);

        // Adjuntar whatsapps por usuario
        $userIds = array_map(fn ($r) => (int) $r['id'], $rows);
        $whatsapps = $this->fetchWhatsappsByUserIds($userIds);

        foreach ($rows as &$r) {
            $r['whatsapps'] = $whatsapps[(int) $r['id']] ?? [];
            $r['created_at'] = $r['created_at'] ?? null;
            $r['foto'] = new \stdClass;
            $r['foto']->url = $r['foto_url'] ?? null;
            $r['foto']->mini_url = $r['foto_mini_url'] ?? null;
            $r['detalle'] = new \stdClass;
            $r['detalle']->genero = $r['genero'] ?? null;
            $r['detalle']->documento = $r['documento'] ?? null;
            $r['detalle']->nacimiento = $r['nacimiento'] ?? null;
            $r['pais'] = new \stdClass;
            $r['pais']->sigla = $r['pais_sigla'] ?? null;
            unset($r['foto_url'], $r['foto_mini_url'], $r['pais_sigla']);
        }

        return $this->arrayRowsToObjects($rows);
    }

    /**
     * Devuelve usuarios que NO tienen el rol indicado.
     *
     * @return array<int, object>
     */
    private function fetchUsersWithoutRole(string $roleName): array
    {
        $sql = <<<'SQL'
        SELECT u.id, u.uuid, u.name, u.email
          FROM users u
         WHERE NOT EXISTS (
             SELECT 1 FROM model_has_roles mhr
             INNER JOIN roles r ON r.id = mhr.role_id
             WHERE mhr.model_id = u.id
               AND mhr.model_type = ?
               AND r.name = ?
         )
         ORDER BY u.name
         LIMIT 100
SQL;
        $rows = $this->fetchAll($sql, ['App\\Models\\User', $roleName]);

        return $this->arrayRowsToObjects($rows);
    }

    /**
     * Devuelve un array [user_id => [ {id, numero, defecto}, ... ]]
     */
    private function fetchWhatsappsByUserIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "SELECT w.id, w.numero, uw.defecto, uw.user_id
                  FROM whatsapp w
                  INNER JOIN user_whatsapp uw ON uw.whatsapp_id = w.id
                 WHERE uw.user_id IN ($placeholders)";
        $rows = $this->fetchAll($sql, $userIds);
        $grouped = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            if (! isset($grouped[$uid])) {
                $grouped[$uid] = [];
            }
            $obj = new \stdClass;
            $obj->id = (int) $r['id'];
            $obj->numero = $r['numero'];
            $obj->pivot = new \stdClass;
            $obj->pivot->defecto = (bool) $r['defecto'];
            $grouped[$uid][] = $obj;
        }

        return $grouped;
    }

    private function arrayRowsToObjects(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $obj = new \stdClass;
                foreach ($r as $k => $v) {
                    $obj->{$k} = $v;
                }
                $out[] = $obj;
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    public function usuariosAction()
    {
        $viewModel = new ViewModel([
            'accessCL' => 'ceo',
            'baseCL' => 'admin.ceo.usuarios',
            'tituloCL' => 'Gestión de Usuarios',
            'TITtituloCL' => 'CEO',
        ]);
        $viewModel->setTemplate('admin/index/usuarios');

        return $viewModel;
    }

    public function estadisticasAction()
    {
        // Total de usuarios
        $totalUsuarios = (int) ($this->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);

        // Usuarios con foto
        $conFoto = (int) ($this->fetchOne('SELECT COUNT(DISTINCT user_id) AS c FROM foto_perfil WHERE url IS NOT NULL')['c'] ?? 0);
        $porcConFoto = $totalUsuarios > 0 ? round(($conFoto / $totalUsuarios) * 100, 2) : 0;

        // Usuarios con al menos un WhatsApp
        $usuariosConWhatsapp = (int) ($this->fetchOne('SELECT COUNT(DISTINCT uw.user_id) AS c FROM user_whatsapp uw')['c'] ?? 0);
        $porcConWhatsapp = $totalUsuarios > 0 ? round(($usuariosConWhatsapp / $totalUsuarios) * 100, 2) : 0;

        // Promedio de WhatsApps por usuario (ratio global)
        $totalWhatsapps = (int) ($this->fetchOne('SELECT COUNT(*) AS c FROM user_whatsapp')['c'] ?? 0);
        $promWhatsapp = $totalUsuarios > 0 ? round($totalWhatsapps / $totalUsuarios, 2) : 0;

        // Nuevos usuarios por mes (últimos 12) - formato MySQL portable
        $nuevosPorMesRaw = $this->fetchAll("SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes_key,
                    DATE_FORMAT(created_at, '%b')     AS mes_label,
                    COUNT(*)                         AS total
               FROM users
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
              GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
              ORDER BY MIN(created_at)"
        );
        $nuevosUsuariosPorMes = [];
        foreach ($nuevosPorMesRaw as $row) {
            $nuevosUsuariosPorMes[$row['mes_label']] = (int) $row['total'];
        }

        // Top 10 países (LEFT JOIN directo, sin Eloquent)
        $paisesRaw = $this->fetchAll("SELECT COALESCE(p.sigla, 'N/D') AS pais, COUNT(*) AS total
               FROM users u
               LEFT JOIN paises p ON p.uuid = u.pais_uuid
              WHERE u.pais_uuid IS NOT NULL
              GROUP BY p.sigla
              ORDER BY total DESC
              LIMIT 10");

        // Distribución por género
        $generoRaw = $this->fetchAll("SELECT COALESCE(genero, 'N/D') AS genero, COUNT(*) AS total
               FROM users_detalle
              WHERE genero IS NOT NULL
              GROUP BY genero");
        $genero = [];
        foreach ($generoRaw as $g) {
            $genero[$g['genero']] = (int) $g['total'];
        }

        // Sin WhatsApp
        $sinWhatsapp = $totalUsuarios - $usuariosConWhatsapp;

        // Cantidad de WhatsApps por usuario
        $cantidades = $this->fetchAll(
            'SELECT t.cantidad AS cuantos, COUNT(*) AS usuarios
               FROM (
                   SELECT COUNT(uw.whatsapp_id) AS cantidad
                     FROM users u
                     LEFT JOIN user_whatsapp uw ON uw.user_id = u.id
                    GROUP BY u.id
               ) t
              GROUP BY t.cantidad'
        );
        $usuarios1 = $usuarios2 = $usuarios3mas = 0;
        foreach ($cantidades as $c) {
            $cuantos = (int) $c['cuantos'];
            $uCount = (int) $c['usuarios'];
            if ($cuantos === 1) {
                $usuarios1 = $uCount;
            } elseif ($cuantos === 2) {
                $usuarios2 = $uCount;
            } elseif ($cuantos >= 3) {
                $usuarios3mas += $uCount;
            }
        }

        // Rangos etarios
        $rangos = [
            '<18' => [0, 17],
            '18-24' => [18, 24],
            '25-34' => [25, 34],
            '35-44' => [35, 44],
            '45-54' => [45, 54],
            '55-64' => [55, 64],
            '65+' => [65, 200],
        ];
        $usuariosPorEdad = [];
        $nacimientos = $this->fetchAll('SELECT nacimiento FROM users_detalle WHERE nacimiento IS NOT NULL');
        $now = new \DateTimeImmutable;
        foreach ($nacimientos as $n) {
            try {
                $fnac = new \DateTimeImmutable($n['nacimiento']);
                $edad = (int) $now->diff($fnac)->y;
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($rangos as $rango => [$min, $max]) {
                if ($edad >= $min && $edad <= $max) {
                    $usuariosPorEdad[$rango] = ($usuariosPorEdad[$rango] ?? 0) + 1;
                    break;
                }
            }
        }
        // Asegurar todas las claves
        foreach ($rangos as $rango => $_) {
            $usuariosPorEdad[$rango] = $usuariosPorEdad[$rango] ?? 0;
        }

        $tituloCL = 'Estadísticas del sistema';

        $viewModel = new ViewModel(compact(
            'tituloCL', 'totalUsuarios', 'porcConFoto', 'porcConWhatsapp',
            'promWhatsapp', 'nuevosUsuariosPorMes', 'paisesRaw',
            'genero', 'sinWhatsapp', 'usuariosConWhatsapp',
            'usuarios1', 'usuarios2', 'usuarios3mas', 'usuariosPorEdad'
        ));
        $viewModel->setTemplate('admin/index/estadisticas');

        return $viewModel;
    }

    public function buscarUsuarioAction()
    {
        $term = trim((string) $this->params()->fromQuery('q', ''));
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 20;

        $rows = $this->fetchAll(
            "SELECT id, name, email
               FROM users
              WHERE name NOT IN ('ceo','dios')
                AND id NOT IN (
                    SELECT u.id FROM users u
                    INNER JOIN model_has_roles mhr ON mhr.model_id = u.id
                    INNER JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_type = 'App\\\\Models\\\\User'
                      AND r.name IN ('ceo','dios')
                )
                AND (LOWER(name) LIKE ? OR LOWER(email) LIKE ?)
              ORDER BY name
              LIMIT ? OFFSET ?",
            ['%'.strtolower($term).'%', '%'.strtolower($term).'%', $perPage, ($page - 1) * $perPage]
        );

        $results = array_map(
            fn ($u) => ['id' => (int) $u['id'], 'text' => $u['name'].' ('.$u['email'].')'],
            $rows
        );

        return new JsonModel(['results' => $results, 'more' => count($rows) === $perPage]);
    }

    public function modalUsuarioAction()
    {
        $userId = (int) $this->params()->fromRoute('user');
        $row = $this->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
        if (! $row) {
            return $this->redirect()->toRoute('admin.ceo.usuarios');
        }
        $user = (object) $row;

        $detalle = $this->fetchOne('SELECT * FROM users_detalle WHERE user_id = ?', [$userId]) ?: [];
        $user->detalle = (object) $detalle;

        // editar-modal.phtml lee $user->foto->mini_url/->url (igual que dios.phtml);
        // sin esto la propiedad no existe y cada render emite un warning de
        // "Undefined property" antes de caer al avatar por defecto.
        $foto = $this->fetchOne('SELECT url, mini_url FROM foto_perfil WHERE user_id = ?', [$userId]) ?: [];
        $user->foto = (object) ['url' => $foto['url'] ?? null, 'mini_url' => $foto['mini_url'] ?? null];

        $paises = $this->fetchAll('SELECT uuid, pais FROM paises ORDER BY pais');

        $viewModel = new ViewModel([
            'user' => $user,
            'baseCL' => 'admin.ceo.usuarios',
            'tituloCL' => 'Gestión de Usuarios',
            'TITtituloCL' => 'CEO',
            'paises' => $paises,
        ]);
        $viewModel->setTerminal(true);
        $viewModel->setTemplate('admin/index/editar-modal');

        return $viewModel;
    }

    public function updateUsuarioAction()
    {
        $_SESSION['mavoo_flash'][] = ['type' => 'success', 'message' => 'Usuario actualizado con éxito'];

        return $this->redirect()->toRoute('admin.ceo.usuarios');
    }

    // =========================================================
    // CEO / Accesos
    // =========================================================

    public function accesosAction()
    {
        $deportes = $this->fetchAll('SELECT uuid, deporte FROM deportes ORDER BY deporte');

        $viewModel = new ViewModel([
            'deportes' => $this->arrayRowsToObjects($deportes),
            'tituloCL' => 'Gestión de Accesos',
            'TITtituloCL' => 'CEO',
            'baseCL' => 'admin.ceo.accesos',
        ]);
        $viewModel->setTemplate('admin/index/accesos');

        return $viewModel;
    }

    /** Select2 AJAX: accesos de un deporte */
    public function buscarAccesosPorDeporteAction()
    {
        $uuid = $this->params()->fromRoute('deporte_uuid');
        $term = strtolower((string) $this->params()->fromQuery('q', ''));
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 20;

        $rows = $this->fetchAll('SELECT uuid, nombre
               FROM deporte_accesos
              WHERE deporte_uuid = ?
                AND LOWER(nombre) LIKE ?
              ORDER BY nombre
              LIMIT ? OFFSET ?',
            [$uuid, '%'.$term.'%', $perPage, ($page - 1) * $perPage]);

        $results = array_map(fn ($a) => ['id' => $a['uuid'], 'text' => $a['nombre']], $rows);

        return new JsonModel([
            'results' => $results,
            'more' => count($rows) === $perPage,
        ]);
    }

    /** POST AJAX: devuelve HTML de filas para la tabla de usuarios con ese acceso */
    public function listarAccesosAction()
    {
        $deporteUuid = $this->params()->fromPost('deporte_uuid');
        $accesoUuid = $this->params()->fromPost('acceso_uuid');

        $acceso = $this->fetchOne(
            'SELECT roles, permisos FROM deporte_accesos WHERE uuid = ?',
            [$accesoUuid]
        );

        if (! $acceso) {
            return new JsonModel(['html' => '<tr><td colspan="4" class="text-muted">Acceso no encontrado</td></tr>']);
        }

        $roles = json_decode($acceso['roles'] ?? '[]', true) ?: [];
        $permisos = json_decode($acceso['permisos'] ?? '[]', true) ?: [];

        // Candidatos con algún rol o permiso
        $usuarios = $this->fetchAll(
            'SELECT DISTINCT u.id, u.name, u.email
               FROM users u
              WHERE u.id IN (
                    SELECT model_id FROM model_has_roles
                     WHERE model_type = ? AND role_id IN (SELECT r.id FROM roles r WHERE r.name IN ('.$this->placeholders($roles).'))
              ) OR u.id IN (
                    SELECT model_id FROM model_has_permissions
                     WHERE model_type = ? AND permission_id IN (SELECT p.id FROM permissions p WHERE p.name IN ('.$this->placeholders($permisos).'))
              )',
            array_merge(['App\\Models\\User'], $roles, ['App\\Models\\User'], $permisos)
        );

        $html = '';
        foreach ($usuarios as $u) {
            $userRoles = $this->fetchAll('SELECT r.name FROM roles r
                 INNER JOIN model_has_roles mhr ON mhr.role_id = r.id
                 WHERE mhr.model_id = ? AND mhr.model_type = ?',
                [$u['id'], 'App\\Models\\User']);
            $userPerms = $this->fetchAll('SELECT p.name FROM permissions p
                 INNER JOIN model_has_permissions mhp ON mhp.permission_id = p.id
                 WHERE mhp.model_id = ? AND mhp.model_type = ?',
                [$u['id'], 'App\\Models\\User']);

            $userRoles = array_filter(
                array_map(fn ($r) => $r['name'], $userRoles),
                fn ($r) => ! in_array(strtolower($r), ['ceo', 'dios', 'admin'], true)
            );
            $userPerms = array_map(fn ($p) => $p['name'], $userPerms);

            $rolesHtml = empty($userRoles)
                ? '<span class="text-muted">—</span>'
                : implode('', array_map(
                    fn ($r) => '<span class="badge bg-light text-dark me-1">'.htmlspecialchars($r).'</span>',
                    $userRoles
                ));
            $permsHtml = empty($userPerms)
                ? '<span class="text-muted">—</span>'
                : implode('', array_map(
                    fn ($p) => '<span class="badge bg-light text-dark me-1">'.htmlspecialchars($p).'</span>',
                    $userPerms
                ));

            $reqRoles = $roles ? implode(', ', $roles) : '—';
            $reqPerms = $permisos ? implode(', ', $permisos) : '—';

            $html .= "<tr>
                <td>
                    <p class='fs-6'>".htmlspecialchars($u['name'])."</p>
                    <p class='f-w-500 text-muted small'>".htmlspecialchars($u['email'])."</p>
                </td>
                <td>{$rolesHtml}</td>
                <td>{$permsHtml}</td>
                <td>
                    <details><summary class='small text-muted'>Requisitos del acceso</summary>
                    <div class='mt-1 small'>
                        <strong>Roles:</strong> {$reqRoles}<br>
                        <strong>Permisos:</strong> {$reqPerms}
                    </div></details>
                </td>
            </tr>";
        }

        return new JsonModel(['html' => $html]);
    }

    /** POST: otorga roles y permisos de un acceso a un usuario */
    public function otorgarAccesoAction()
    {
        $userId = (int) $this->params()->fromPost('user_id');
        $accesoUuid = (string) $this->params()->fromPost('acceso_uuid');

        $acceso = $this->fetchOne(
            'SELECT roles, permisos FROM deporte_accesos WHERE uuid = ?',
            [$accesoUuid]
        );

        if (! $acceso || ! $userId) {
            $_SESSION['mavoo_flash'][] = ['type' => 'error', 'message' => 'Datos inválidos'];

            return $this->redirect()->toRoute('admin.ceo.accesos');
        }

        $roles = json_decode($acceso['roles'] ?? '[]', true) ?: [];
        $permisos = json_decode($acceso['permisos'] ?? '[]', true) ?: [];

        foreach ($roles as $r) {
            $rol = $this->fetchOne('SELECT id FROM roles WHERE name = ?', [$r]);
            if (! $rol) {
                $this->dbQuery(
                    "INSERT INTO roles (name, guard_name, created_at, updated_at) VALUES (?, 'web', NOW(), NOW())",
                    [$r]
                );
                $rol = $this->fetchOne('SELECT id FROM roles WHERE name = ?', [$r]);
            }
            $exists = $this->fetchOne(
                'SELECT 1 FROM model_has_roles WHERE role_id = ? AND model_id = ? AND model_type = ?',
                [$rol['id'], $userId, 'App\\Models\\User']
            );
            if (! $exists) {
                $this->dbWrite(
                    'INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, ?, ?)',
                    [$rol['id'], 'App\\Models\\User', $userId]
                );
            }
        }
        foreach ($permisos as $p) {
            $perm = $this->fetchOne('SELECT id FROM permissions WHERE name = ?', [$p]);
            if (! $perm) {
                $this->dbWrite(
                    "INSERT INTO permissions (name, guard_name, created_at, updated_at) VALUES (?, 'web', NOW(), NOW())",
                    [$p]
                );
                $perm = $this->fetchOne('SELECT id FROM permissions WHERE name = ?', [$p]);
            }
            $exists = $this->fetchOne(
                'SELECT 1 FROM model_has_permissions WHERE permission_id = ? AND model_id = ? AND model_type = ?',
                [$perm['id'], $userId, 'App\\Models\\User']
            );
            if (! $exists) {
                $this->dbWrite(
                    'INSERT INTO model_has_permissions (permission_id, model_type, model_id) VALUES (?, ?, ?)',
                    [$perm['id'], 'App\\Models\\User', $userId]
                );
            }
        }

        $_SESSION['mavoo_flash'][] = ['type' => 'success', 'message' => 'Acceso otorgado correctamente'];

        return $this->redirect()->toRoute('admin.ceo.accesos');
    }

    // =========================================================
    // CEO / Categorías
    // =========================================================

    public function categoriasAction()
    {
        $deportes = $this->fetchAll('SELECT id, uuid, deporte, alias FROM deportes ORDER BY deporte');
        $viewModel = new ViewModel([
            'deportes' => $this->arrayRowsToObjects($deportes),
            'tituloCL' => 'Gestión de Categorías',
            'TITtituloCL' => 'CEO',
            'baseCL' => 'admin.ceo.categorias',
        ]);
        $viewModel->setTemplate('admin/index/categorias');

        return $viewModel;
    }

    public function listarCategoriasAction()
    {
        $alias = $this->params()->fromRoute('alias');
        $rows = $this->fetchAll('SELECT id, uuid, icono, titulo, subtitulo, deporte, orden
               FROM mod_gestion_categorias_listas
              WHERE deporte = ?
              ORDER BY orden',
            [$alias]);

        return new JsonModel(['categorias' => $rows]);
    }

    public function storeCategoriaAction()
    {
        $body = json_decode($this->getRequest()->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['titulo'])) {
            $errors['titulo'] = ['El título es requerido'];
        }
        if (empty($body['subtitulo'])) {
            $errors['subtitulo'] = ['El subtítulo es requerido'];
        }
        if (empty($body['icono'])) {
            $errors['icono'] = ['El icono es requerido'];
        }
        if (empty($body['deporte'])) {
            $errors['deporte'] = ['El deporte es requerido'];
        }

        if (! empty($errors)) {
            $this->getResponse()->setStatusCode(422);

            return new JsonModel(['errors' => $errors]);
        }

        $maxOrden = (int) ($this->fetchOne(
            'SELECT COALESCE(MAX(orden), 0) AS m FROM mod_gestion_categorias_listas WHERE deporte = ?',
            [$body['deporte']]
        )['m'] ?? 0);

        $uuid = Uuid::uuid4()->toString();
        $this->dbWrite(
            'INSERT INTO mod_gestion_categorias_listas (uuid, icono, titulo, subtitulo, deporte, orden, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $uuid,
                '<i class="'.htmlspecialchars($body['icono']).' text-primary" style="font-size: 2rem;"></i>',
                $body['titulo'],
                $body['subtitulo'],
                $body['deporte'],
                $maxOrden + 1,
            ]
        );

        return new JsonModel([
            'message' => 'Categoría Creada',
            'categoria' => [
                'uuid' => $uuid, 'icono' => $body['icono'],
                'titulo' => $body['titulo'], 'subtitulo' => $body['subtitulo'],
                'deporte' => $body['deporte'], 'orden' => $maxOrden + 1,
            ],
        ]);
    }

    public function updateCategoriaAction()
    {
        $uuid = $this->params()->fromRoute('uuid');
        $body = json_decode($this->getRequest()->getContent(), true) ?? [];

        $errors = [];
        if (empty($body['titulo'])) {
            $errors['titulo'] = ['El título es requerido'];
        }
        if (empty($body['subtitulo'])) {
            $errors['subtitulo'] = ['El subtítulo es requerido'];
        }
        if (empty($body['icono'])) {
            $errors['icono'] = ['El icono es requerido'];
        }

        if (! empty($errors)) {
            $this->getResponse()->setStatusCode(422);

            return new JsonModel(['errors' => $errors]);
        }

        $this->dbQuery(
            'UPDATE mod_gestion_categorias_listas
                SET icono = ?, titulo = ?, subtitulo = ?, updated_at = NOW()
              WHERE uuid = ?',
            [
                '<i class="'.htmlspecialchars($body['icono']).' text-primary" style="font-size: 2rem;"></i>',
                $body['titulo'],
                $body['subtitulo'],
                $uuid,
            ]
        );

        return new JsonModel([
            'message' => 'Categoría Actualizada',
            'categoria' => [
                'uuid' => $uuid, 'icono' => $body['icono'],
                'titulo' => $body['titulo'], 'subtitulo' => $body['subtitulo'],
            ],
        ]);
    }

    public function guardarOrdenCategoriaAction()
    {
        $body = json_decode($this->getRequest()->getContent(), true) ?? [];
        $orden = $body['orden'] ?? [];

        foreach ($orden as $item) {
            if (isset($item['uuid'], $item['posicion'])) {
                $this->dbQuery(
                    'UPDATE mod_gestion_categorias_listas SET orden = ?, updated_at = NOW() WHERE uuid = ?',
                    [(int) $item['posicion'], $item['uuid']]
                );
            }
        }

        return new JsonModel(['message' => 'Orden actualizado']);
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function placeholders(array $values): string
    {
        if (empty($values)) {
            return "''";
        }

        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Helper: ejecuta una query y devuelve la primera fila como array, o [] si vacía.
     */
    private function fetchOne(string $sql, array $params = []): array
    {
        $rows = $this->dbQuery($sql, $params);
        $row = $rows[0] ?? [];

        return is_array($row) ? $row : [];
    }

    /**
     * Helper: ejecuta una query y devuelve todas las filas como array de arrays.
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        return $this->dbQuery($sql, $params);
    }

    /**
     * Helper: ejecuta INSERT/UPDATE/DELETE. Devuelve last_insert_id o true/false.
     */
    private function dbWrite(string $sql, array $params = []): int|false
    {
        try {
            $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare($sql);
            $stmt->execute($params);

            return $this->dbLastInsertId() ?: 1;
        } catch (\Throwable $e) {
            error_log('dbWrite ERROR: '.$e->getMessage().' SQL: '.$sql);

            return false;
        }
    }

    private function getPrefixes(): array
    {
        $routeMatch = $this->getEvent()->getRouteMatch();
        $routeName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';
        $action = $routeMatch ? $routeMatch->getParam('action') : '';

        if (strpos($routeName, 'admin.dios.ceo') !== false || $action === 'diosCeo') {
            return ['accessCL' => 'ceo', 'baseCL' => 'admin.dios.ceo', 'tituloCL' => 'Moderación de perfil CEO', 'TITtituloCL' => 'DIOS'];
        }
        if (strpos($routeName, 'admin.dios.dios') !== false || $action === 'diosDios') {
            return ['accessCL' => 'dios', 'baseCL' => 'admin.dios.dios', 'tituloCL' => 'Moderación de perfil DIOS', 'TITtituloCL' => 'DIOS'];
        }
        if (strpos($routeName, 'admin.ceo.admins') !== false || $action === 'ceoAdmins') {
            return ['accessCL' => 'admin', 'baseCL' => 'admin.ceo.admins', 'tituloCL' => 'Moderación de perfil Administrador', 'TITtituloCL' => 'CEO'];
        }
        if (strpos($routeName, 'admin.moderadores') !== false || $action === 'moderadores') {
            return ['accessCL' => 'moderador', 'baseCL' => 'admin.moderadores', 'tituloCL' => 'Moderación de perfil Moderadores', 'TITtituloCL' => 'Administrador'];
        }

        return ['accessCL' => '', 'baseCL' => '', 'tituloCL' => '', 'TITtituloCL' => ''];
    }

    // =========================================================
    // Acciones de gestión de roles (Dios/CEO/Admin)
    // =========================================================

    public function diosAsignarRolAction()
    {
        $prefixes = $this->getPrefixes();
        $userId = (int) $this->params()->fromPost('user_id');

        if ($userId && $prefixes['accessCL']) {
            $rol = $this->fetchOne('SELECT id FROM roles WHERE name = ?', [$prefixes['accessCL']]);
            if ($rol) {
                $exists = $this->fetchOne(
                    'SELECT 1 FROM model_has_roles WHERE role_id = ? AND model_id = ? AND model_type = ?',
                    [$rol['id'], $userId, 'App\\Models\\User']
                );
                if (! $exists) {
                    $this->dbQuery(
                        'INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES (?, ?, ?)',
                        [$rol['id'], 'App\\Models\\User', $userId]
                    );
                }
            }
        }

        return $this->redirect()->toRoute($prefixes['baseCL']);
    }

    public function diosDestroyAction()
    {
        $prefixes = $this->getPrefixes();
        $userId = (int) $this->params()->fromRoute('user');

        if ($userId && $prefixes['accessCL']) {
            $this->dbQuery(
                'DELETE mhr FROM model_has_roles mhr
                 INNER JOIN roles r ON r.id = mhr.role_id
                 WHERE mhr.model_id = ? AND mhr.model_type = ? AND r.name = ?',
                [$userId, 'App\\Models\\User', $prefixes['accessCL']]
            );
        }

        return $this->redirect()->toRoute($prefixes['baseCL']);
    }

    public function diosWhatsappDestroyAction()
    {
        $prefixes = $this->getPrefixes();
        $whatsappId = (int) $this->params()->fromRoute('id');
        $userId = (int) $this->params()->fromPost('user_id');

        if ($whatsappId && $userId) {
            $this->dbQuery(
                'DELETE FROM user_whatsapp WHERE user_id = ? AND whatsapp_id = ?',
                [$userId, $whatsappId]
            );

            // Si quedan otros, marcar el más reciente como defecto.
            // dbWrite() es para INSERT/UPDATE/DELETE (devuelve el last_insert_id
            // o 1); usado aquí con un SELECT devolvía siempre el entero 1, así
            // que $restantes['whatsapp_id'] jamás existía y este número nunca
            // quedaba marcado como el nuevo WhatsApp por defecto. Debe usarse
            // dbQuery(), que sí devuelve las filas.
            $restantesRows = $this->dbQuery(
                'SELECT uw.whatsapp_id, uw.created_at FROM user_whatsapp uw
                 WHERE uw.user_id = ?
                 ORDER BY uw.created_at DESC LIMIT 1',
                [$userId]
            );
            $restantes = $restantesRows[0] ?? null;

            $this->dbWrite('UPDATE user_whatsapp SET defecto = 0 WHERE user_id = ?', [$userId]);
            if ($restantes) {
                $this->dbWrite(
                    'UPDATE user_whatsapp SET defecto = 1 WHERE user_id = ? AND whatsapp_id = ?',
                    [$userId, $restantes['whatsapp_id']]
                );
            }
        }

        return $this->redirect()->toRoute($prefixes['baseCL']);
    }

    public function diosUpdateAction()
    {
        $prefixes = $this->getPrefixes();
        $userId = (int) $this->params()->fromRoute('user');

        if (! $userId) {
            return $this->redirect()->toRoute($prefixes['baseCL']);
        }

        $body = $this->getRequest()->getPost()->toArray();

        // Actualizar datos principales
        $this->dbQuery(
            'UPDATE users SET
                name = COALESCE(?, name),
                email = COALESCE(?, email),
                pais_uuid = COALESCE(?, pais_uuid),
                updated_at = NOW()
              WHERE id = ?',
            [$body['name'] ?? null, $body['email'] ?? null, $body['pais_uuid'] ?? null, $userId]
        );

        // Detalle: upsert
        $detalleExists = $this->fetchOne('SELECT id FROM users_detalle WHERE user_id = ?', [$userId]);
        if ($detalleExists) {
            $this->dbWrite(
                'UPDATE users_detalle SET documento = ?, nacimiento = ?, genero = ?, updated_at = NOW() WHERE user_id = ?',
                [$body['documento'] ?? '', $body['nacimiento'] ?? null, $body['genero'] ?? null, $userId]
            );
        } else {
            $this->dbWrite(
                'INSERT INTO users_detalle (uuid, user_id, documento, nacimiento, genero, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    Uuid::uuid4()->toString(),
                    $userId,
                    $body['documento'] ?? '',
                    $body['nacimiento'] ?? null,
                    $body['genero'] ?? null,
                ]
            );
        }

        // WhatsApp
        $numeroRaw = $body['nuevo_whatsapp'] ?? '';
        $numeroLimpio = preg_replace('/\D+/', '', $numeroRaw);

        if ($numeroLimpio !== '') {
            $wa = $this->fetchOne('SELECT id FROM whatsapp WHERE numero = ?', [$numeroLimpio]);
            if (! $wa) {
                $waUuid = Uuid::uuid4()->toString();
                $this->dbQuery(
                    'INSERT INTO whatsapp (uuid, numero, defecto, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())',
                    [$waUuid, $numeroLimpio]
                );
                $wa = $this->fetchOne('SELECT id FROM whatsapp WHERE numero = ?', [$numeroLimpio]);
            }

            $exists = $this->fetchOne(
                'SELECT 1 FROM user_whatsapp WHERE user_id = ? AND whatsapp_id = ?',
                [$userId, $wa['id']]
            );
            if (! $exists) {
                $this->dbWrite(
                    'INSERT INTO user_whatsapp (user_id, whatsapp_id, defecto, created_at) VALUES (?, ?, 0, NOW())',
                    [$userId, $wa['id']]
                );
            }

            if (! empty($body['nuevo_defecto'])) {
                $this->dbWrite(
                    'UPDATE user_whatsapp SET defecto = (CASE WHEN whatsapp_id = ? THEN 1 ELSE 0 END) WHERE user_id = ?',
                    [$wa['id'], $userId]
                );
            }
        } elseif (! empty($body['whatsapp_defecto'])) {
            $this->dbWrite(
                'UPDATE user_whatsapp SET defecto = (CASE WHEN whatsapp_id = ? THEN 1 ELSE 0 END) WHERE user_id = ?',
                [(int) $body['whatsapp_defecto'], $userId]
            );
        }

        return $this->redirect()->toRoute($prefixes['baseCL']);
    }

    public function diosBuscarUsuarioAction()
    {
        $prefixes = $this->getPrefixes();
        $accessCL = $prefixes['accessCL'];
        $term = (string) $this->getRequest()->getQuery('q', '');
        $page = max(1, (int) $this->getRequest()->getQuery('page', 1));
        $perPage = 20;

        $rows = $this->fetchAll(
            'SELECT u.id, u.name, u.email
               FROM users u
              WHERE u.id NOT IN (
                  SELECT mhr.model_id FROM model_has_roles mhr
                  INNER JOIN roles r ON r.id = mhr.role_id
                  WHERE mhr.model_type = ? AND r.name = ?
              )
                AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)
              ORDER BY u.name
              LIMIT ? OFFSET ?',
            ['App\\Models\\User', $accessCL, '%'.strtolower($term).'%', '%'.strtolower($term).'%', $perPage, ($page - 1) * $perPage]
        );

        $countRow = $this->fetchOne(
            'SELECT COUNT(*) AS c
               FROM users u
              WHERE u.id NOT IN (
                  SELECT mhr.model_id FROM model_has_roles mhr
                  INNER JOIN roles r ON r.id = mhr.role_id
                  WHERE mhr.model_type = ? AND r.name = ?
              )
                AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)',
            ['App\\Models\\User', $accessCL, '%'.strtolower($term).'%', '%'.strtolower($term).'%']
        );
        $countTotal = (int) ($countRow['c'] ?? 0);

        $results = array_map(
            fn ($u) => ['id' => (int) $u['id'], 'text' => $u['name'].' ('.$u['email'].')'],
            $rows
        );

        return new JsonModel([
            'results' => $results,
            'pagination' => ['more' => ($page * $perPage) < $countTotal],
        ]);
    }

    public function wapiBotAction()
    {
        $config = $this->getEvent()->getApplication()->getServiceManager()->get('config');
        $wapiUrl = $config['whatsapp']['wapi']['api_url'] ?? 'http://localhost:3001';

        $status = 'unknown';
        $qr = null;
        $error = null;

        try {
            $client = new Client($wapiUrl.'/api/status');
            $client->setOptions(['timeout' => 5]);
            $response = $client->send();

            if ($response->isSuccess()) {
                $data = json_decode($response->getBody(), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $status = $data['status'] ?? 'unknown';
                    $qr = $data['qr'] ?? null;
                } else {
                    $error = 'Respuesta malformada del WAPI (No JSON)';
                }
            } else {
                $error = 'Error del servidor WAPI: '.$response->getStatusCode().' - Puede que el contenedor esté reiniciándose.';
            }
        } catch (\Exception $e) {
            $error = 'Error conectando internamente al WAPI: '.$e->getMessage();
        }

        $viewModel = new ViewModel([
            'tituloCL' => 'Gestión Bot WhatsApp',
            'TITtituloCL' => 'CEO',
            'baseCL' => 'admin.ceo.wapi',
            'status' => $status,
            'qr' => $qr,
            'error' => $error,
            'config' => $config,
        ]);
        $viewModel->setTemplate('admin/index/wapi-bot');

        return $viewModel;
    }

    /**
     * Helper: ejecuta query y devuelve todas las filas como array.
     * Tolerante a errores (devuelve [] en caso de fallo).
     */
    private function dbQuery(string $sql, array $params = []): array
    {
        try {
            $rows = $this->db->getDriver()->getConnection()->execute($sql, $params);
            if ($rows instanceof ResultSet) {
                return $rows->toArray();
            }
            if ($rows instanceof StatementInterface) {
                $out = [];
                foreach ($rows as $r) {
                    $out[] = is_array($r) ? $r : (array) $r;
                }

                return $out;
            }
        } catch (\Throwable $e) {
        }

        return [];
    }

    private function dbCurrent(string $sql, array $params = []): ?array
    {
        $rows = $this->dbQuery($sql, $params);

        return $rows[0] ?? null;
    }

    private function dbExecute(string $sql, array $params = []): bool
    {
        try {
            $this->db->getDriver()->getConnection()->execute($sql, $params);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
