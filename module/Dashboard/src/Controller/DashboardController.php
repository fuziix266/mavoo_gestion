<?php

declare(strict_types=1);

namespace Dashboard\Controller;

use Auth\Service\AuthService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * DashboardController
 *
 * Panel principal del sistema Mavoo Gestión en Laminas.
 * Equivalente a: App\Http\Controllers\DashboardController de Laravel.
 * Muestra información diferenciada según el rol del usuario autenticado.
 */
class DashboardController extends AbstractActionController
{
    private AuthService $authService;
    private Adapter $db;

    public function __construct(AuthService $authService, Adapter $db)
    {
        $this->authService = $authService;
        $this->db          = $db;
    }

    public function indexAction(): ViewModel
    {
        $identity    = $this->authService->getIdentity();
        $roles       = $this->authService->getUserRoles($identity['id']);
        $permissions = $this->authService->getUserPermissions($identity['id']);

        $data = [
            'identity'    => $identity,
            'roles'       => $roles,
            'permissions' => $permissions,
        ];

        // Si es DIOS → datos globales del sistema
        if (in_array('dios', $roles, true)) {
            $data = array_merge($data, $this->getAdminData());
        }

        // Si tiene permisos de moderador deportivo
        foreach (['padel', 'tenis', 'pickleball', 'futbol'] as $sport) {
            $perm = "moderador {$sport} gestion";
            if (in_array($perm, $permissions, true)) {
                $data[$sport] = $this->getSportData($sport);
            }
        }

        $view = new ViewModel($data);
        $view->setTemplate('dashboard/dashboard/index');
        return $view;
    }

    /**
     * Estadísticas de alto nivel para el rol 'dios'
     */
    private function getAdminData(): array
    {
        $totalUsers = $this->db->query("SELECT COUNT(*) as total FROM users", [])->current()['total'] ?? 0;
        $newUsers30 = $this->db->query(
            "SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            []
        )->current()['total'] ?? 0;
        $noDetails  = $this->db->query(
            "SELECT COUNT(*) as total FROM users u LEFT JOIN users_detalle ud ON ud.user_id = u.id WHERE ud.id IS NULL",
            []
        )->current()['total'] ?? 0;

        $genderResult = $this->db->query(
            "SELECT genero, COUNT(*) as count FROM users_detalle GROUP BY genero",
            []
        );
        $genderData = [];
        foreach ($genderResult as $row) {
            $label = match ($row['genero']) {
                'hombre' => 'Masculino',
                'mujer'  => 'Femenino',
                'O'      => 'Otro',
                null     => 'Sin Datos',
                default  => 'Sin especificar',
            };
            $genderData[] = ['label' => $label, 'count' => $row['count']];
        }

        return [
            'role'               => 'dios',
            'totalUsers'         => $totalUsers,
            'newUsersLast30Days' => $newUsers30,
            'usersWithoutDetail' => $noDetails,
            'genderData'         => $genderData,
        ];
    }

    /**
     * Datos del moderador para un deporte específico
     */
    private function getSportData(string $sport): array
    {
        $eventos = $this->db->query(
            "SELECT e.*, a.fecha_inicio, a.inscripciones_inicio, a.inscripciones_cierre
             FROM mod_eventos e
             LEFT JOIN mod_gestion_ajustes a ON a.evento_id = e.id
             WHERE e.deporte = ?
             ORDER BY a.created_at DESC",
            [$sport]
        );

        $eventosArr     = [];
        $activeCount    = 0;
        $inscripAbierta = 0;
        $totalInscritos = 0;

        foreach ($eventos as $evento) {
            $eventosArr[] = $evento;
            if ($evento['fecha_inicio'] && strtotime($evento['fecha_inicio']) > time()) {
                $activeCount++;
            }
            if ($evento['inscripciones_inicio'] && $evento['inscripciones_cierre']) {
                $ahora = time();
                if ($ahora >= strtotime($evento['inscripciones_inicio']) &&
                    $ahora <= strtotime($evento['inscripciones_cierre'])) {
                    $inscripAbierta++;
                }
            }
        }

        $inscritosResult = $this->db->query(
            "SELECT SUM(c.inscritos) as total
             FROM mod_gestion_categorias c
             INNER JOIN mod_eventos e ON e.id = c.mod_evento_id
             WHERE e.deporte = ? AND c.activo = 1",
            [$sport]
        )->current();
        $totalInscritos = $inscritosResult['total'] ?? 0;

        return [
            'activeEventsCount'    => $activeCount,
            'openInscriptionsCount' => $inscripAbierta,
            'totalInscritos'       => $totalInscritos,
            'eventos'              => $eventosArr,
        ];
    }
}
