<?php

declare(strict_types=1);

namespace Jugador\Controller;

use Jugador\Service\AuthService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    private AdapterInterface $db;

    private AuthService $auth;

    public function __construct(AdapterInterface $db, AuthService $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    public function indexAction()
    {
        $identity = $this->auth->getIdentity() ?? [];
        $userId = (int) ($identity['id'] ?? 0);

        // Tarjetas del dashboard (index.phtml). "Próximos partidos" y "Ranking"
        // dependen de en qué categoría/deporte se está evaluando al jugador
        // (un mismo usuario puede tener nóminas y rankings distintos por
        // categoría) y no hay todavía una noción de "categoría actual" en
        // este dashboard general, así que se dejan en 0 en vez de inventar
        // cuál mostrar; "Eventos activos" y "Disciplinas" sí son cálculos
        // sin ambigüedad (eventos en los que el usuario está inscrito).
        $statEventos = 0;
        $statDisciplinas = 0;
        if ($userId && $this->tableExists('evento_user') && $this->tableExists('mod_eventos')) {
            try {
                // OJO: Laminas\Db\Adapter\Driver\Pdo\Connection->prepare() devuelve un
                // wrapper SIN método fetch(); hay que bajar al \PDO nativo con
                // getResource() (mismo bug ya visto y corregido en Ligas\FixtureController).
                $pdo = $this->db->getDriver()->getConnection()->getResource();
                $stmt = $pdo->prepare(
                    'SELECT COUNT(DISTINCT e.id) AS total_eventos, COUNT(DISTINCT e.deporte) AS total_disciplinas
                       FROM mod_eventos e
                       INNER JOIN evento_user eu ON eu.mod_evento_id = e.id
                      WHERE eu.user_id = ?'
                );
                $stmt->execute([$userId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $statEventos = (int) ($row['total_eventos'] ?? 0);
                $statDisciplinas = (int) ($row['total_disciplinas'] ?? 0);
            } catch (\Throwable $e) { /* fallback en 0 */
            }
        }

        return new ViewModel([
            'user' => $identity,
            'stat_eventos' => $statEventos,
            'stat_partidos' => 0,
            'stat_ranking' => 0,
            'stat_disciplinas' => $statDisciplinas,
        ]);
    }

    /**
     * Carrera Deportiva > Inicio
     */
    public function carreraAction()
    {
        $userId = $this->auth->getUserId();
        $eventos = [];
        $partidos = [];

        if ($userId && $this->tableExists('mod_eventos')) {
            try {
                $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare(
                    'SELECT e.uuid, e.titulo, e.deporte, e.referencia_utc,
                            a.nombre_evento, a.fecha_inicio, a.afiche_promocional_min
                       FROM mod_eventos e
                       LEFT JOIN mod_gestion_ajustes a ON a.evento_id = e.id
                       WHERE e.user_id = ? OR e.user_id IN (SELECT user_id FROM evento_user WHERE mod_evento_id = e.id)
                       ORDER BY e.referencia_utc ASC
                       LIMIT 20'
                );
                $stmt->execute([$userId]);
                $eventos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'eventos' => $eventos,
        ]);
    }

    /**
     * Carrera Deportiva > Disciplinas
     */
    public function disciplinasAction()
    {
        $deportes = [];
        if ($this->tableExists('deportes')) {
            try {
                $deportes = $this->db->getDriver()->getConnection()
                    ->query('SELECT uuid, deporte, alias, activo FROM deportes WHERE activo = 1 ORDER BY deporte')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'deportes' => $deportes,
        ]);
    }

    /**
     * Carrera Deportiva > Eventos
     */
    public function eventosAction()
    {
        $eventos = [];
        if ($this->tableExists('mod_eventos')) {
            try {
                $eventos = $this->db->getDriver()->getConnection()
                    ->query('SELECT uuid, titulo, deporte, inscripcion, referencia_utc FROM mod_eventos ORDER BY referencia_utc ASC LIMIT 30')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'eventos' => $eventos,
        ]);
    }

    /**
     * Carrera Deportiva > Ajustes
     */
    public function carreraAjustesAction()
    {
        $user = $this->auth->getIdentity() ?? [];

        return new ViewModel([
            'user' => $user,
            'profile' => $user,
        ]);
    }

    /**
     * Mis Disciplinas > Padel|Tenis|Futbol|Ciclismo
     */
    public function deporteAction()
    {
        $deporte = (string) $this->params()->fromRoute('id', 'padel');
        $rankings = [];
        $eventos = [];

        if ($this->tableExists('mod_eventos')) {
            try {
                $stmt = $this->db->getDriver()->getConnection()->getResource()->prepare(
                    'SELECT uuid, titulo, inscripcion, referencia_utc
                       FROM mod_eventos
                       WHERE deporte = ?
                       ORDER BY referencia_utc ASC
                       LIMIT 10'
                );
                $stmt->execute([$deporte]);
                $eventos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'deporte' => $deporte,
            'eventos' => $eventos,
            'rankings' => $rankings,
        ]);
    }

    /**
     * Entrenador > Reservas
     */
    public function reservasAction()
    {
        $reservas = [];
        if ($this->tableExists('reservas')) {
            try {
                $reservas = $this->db->getDriver()->getConnection()
                    ->query('SELECT id, fecha, hora_inicio, hora_fin, total_pago, estado
                               FROM reservas ORDER BY fecha DESC LIMIT 30')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'reservas' => $reservas,
        ]);
    }

    /**
     * Entrenador > Cursos
     */
    public function cursosAction()
    {
        $cursos = [];
        if ($this->tableExists('mod_gestion_ajustes')) {
            try {
                $cursos = $this->db->getDriver()->getConnection()
                    ->query('SELECT id, nombre_evento, deporte, fecha_inicio, valor_inscripcion
                               FROM mod_gestion_ajustes
                               WHERE fecha_inicio >= CURDATE()
                               ORDER BY fecha_inicio ASC LIMIT 30')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'cursos' => $cursos,
        ]);
    }

    /**
     * Entrenador > Clases
     */
    public function clasesAction()
    {
        $sedes = [];
        if ($this->tableExists('mod_gestion_sedes')) {
            try {
                $sedes = $this->db->getDriver()->getConnection()
                    ->query('SELECT uuid, fecha_inicio, fecha_cierre, activo
                               FROM mod_gestion_sedes
                               WHERE activo = 1
                               ORDER BY fecha_inicio ASC LIMIT 30')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'sedes' => $sedes,
        ]);
    }

    /**
     * Entrenador > Nóminas
     */
    public function nominasAction()
    {
        $nominas = [];
        if ($this->tableExists('mod_gestion_nominas')) {
            try {
                $nominas = $this->db->getDriver()->getConnection()
                    ->query('SELECT id, uuid, rankingt_auto, rankingt_manual, orden
                               FROM mod_gestion_nominas ORDER BY orden LIMIT 30')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* fallback */
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'nominas' => $nominas,
        ]);
    }

    /**
     * Entrenador > Estadísticas
     */
    public function estadisticasAction()
    {
        $stats = ['eventos' => 0, 'usuarios' => 0, 'sedes' => 0, 'reservas' => 0];
        if ($this->tableExists('mod_eventos')) {
            try {
                $row = $this->db->getDriver()->getConnection()
                    ->query('SELECT COUNT(*) AS c FROM mod_eventos')->current();
                $stats['eventos'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
            }
        }
        if ($this->tableExists('users')) {
            try {
                $row = $this->db->getDriver()->getConnection()
                    ->query('SELECT COUNT(*) AS c FROM users')->current();
                $stats['usuarios'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
            }
        }
        if ($this->tableExists('mod_gestion_sedes')) {
            try {
                $row = $this->db->getDriver()->getConnection()
                    ->query('SELECT COUNT(*) AS c FROM mod_gestion_sedes')->current();
                $stats['sedes'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
            }
        }
        if ($this->tableExists('reservas')) {
            try {
                $row = $this->db->getDriver()->getConnection()
                    ->query('SELECT COUNT(*) AS c FROM reservas')->current();
                $stats['reservas'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
            }
        }

        return new ViewModel([
            'user' => $this->auth->getIdentity() ?? [],
            'stats' => $stats,
        ]);
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = $this->db->query('SHOW TABLES LIKE ?', [$table])->current();

            return (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
