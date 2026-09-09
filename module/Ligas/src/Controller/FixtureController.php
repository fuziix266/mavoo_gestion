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

    /**
     * Asigna fecha/hora/cancha a los partidos de fase GRUPOS/LIGA ya generados
     * (ver CategoriasController::guardarestructuraAction). Port fiel de
     * FixtureController::programarGrupos del Laravel original
     * (app/Http/Controllers/mod/padel/gestion/FixtureController.php:1120-1423),
     * adaptado a SQL nativo. Corrige un bug del original: el regex de
     * referencias a partidos usaba '/^(PP|PG)(\d+)$/' (PG no existe; el
     * generador de brackets usa GP/PP) y por eso nunca priorizaba
     * correctamente los partidos que ganan/pierden un cruce previo.
     */
    public function programarGruposAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Method not allowed']);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        $identity = $this->auth->getIdentity();
        if (! $eventoId) {
            return new JsonModel(['success' => false, 'error' => 'evento_id requerido']);
        }
        $tieneAcceso = $this->fetchOne(
            'SELECT 1 FROM mod_eventos e INNER JOIN evento_user eu ON eu.mod_evento_id = e.id WHERE e.id = ? AND eu.user_id = ?',
            [$eventoId, $identity['id'] ?? 0]
        );
        if (! $tieneAcceso) {
            return new JsonModel(['success' => false, 'error' => 'Evento no encontrado']);
        }

        try {
            $categorias = $this->fetchCategoriasParaProgramacion($eventoId);
            $sedes = $this->fetchSedesParaProgramacion($eventoId);
            if (empty($sedes)) {
                return new JsonModel(['success' => false, 'error' => 'El evento no tiene sedes activas configuradas']);
            }
            $restriccionesById = $this->fetchRestriccionesById(array_column($sedes, 'id'));

            $criterioDescanso1 = true;
            $criterioDescanso2 = true;
            $maxIntentos = 10;

            $programacion = $this->cargarProgramacionActual($eventoId);
            $fixtureMap = [];
            foreach ($programacion as $sedeBloques) {
                foreach ($sedeBloques as $canchasArr) {
                    foreach ($canchasArr as $p) {
                        if (! empty($p['categoria_id']) && isset($p['partido_idx'])) {
                            $fixtureMap[$p['categoria_id'].'-'.$p['partido_idx']] = true;
                        }
                    }
                }
            }

            $parejasProgramadas = [];
            $jugadoresProgramados = [];
            $partidosProgramados = [];
            $noProgramados = [];
            $ordenProgramacion = [];
            mt_srand((int) (microtime(true) * 1000000));

            [$bloques, $bloqueos, $sedeIdBloques, $cantidadCanchas] = $this->construirBloquesDisponibles($sedes);
            if (empty($bloques)) {
                return new JsonModel(['success' => false, 'error' => 'No hay bloques de horario disponibles (revisa fechas y horas de las sedes)']);
            }

            // Solo partidos de fase GRUPOS/LIGA (los de eliminatorias los procesa programarEliminatoriasAction).
            $todosLosPartidos = [];
            foreach ($categorias as $categoria) {
                foreach ($categoria['partidos'] as $idx => $partido) {
                    $partidoIdx = $idx + 1;
                    if (isset($fixtureMap[$categoria['id'].'-'.$partidoIdx])) {
                        continue;
                    }
                    if (in_array($partido['fase'] ?? '', ['GRUPOS', 'LIGA'], true)) {
                        $todosLosPartidos[] = [
                            'categoria_id' => $categoria['id'],
                            'partido_idx' => $partidoIdx,
                            'partido' => $partido,
                            'nominas' => $categoria['nominas'],
                        ];
                    }
                }
            }

            // Clasificación en 4 grupos de prioridad (idéntica al original):
            // Primero = partidos con restricción horaria (ordenados por bloque más
            // tardío primero), Segundo = partidos referenciados por otros (GP/PP),
            // Tercero = partidos cuya pareja aún no es numérica (dependen de un
            // resultado previo), Cuarto = el resto.
            //
            // Nota: para la agrupación "Primero" el original usa $sede->id de la
            // ÚLTIMA sede iterada en el foreach anterior (variable "filtrada" del
            // scope de PHP, no una elección explícita). Se preserva ese
            // comportamiento aquí en vez de "corregirlo" silenciosamente, ya que
            // solo afecta el orden de prioridad inicial, no la validez final del
            // resultado (cada partido se revalida por sede real al programarse).
            $sedeRefId = end($sedes)['id'];

            $grupoPrimero = [];
            foreach ($todosLosPartidos as $item) {
                $restriccionesPareja = $this->getRestriccionesPareja(
                    $item['partido']['parejaA'] ?? null,
                    $item['partido']['parejaB'] ?? null,
                    $item['nominas'],
                    $restriccionesById,
                    $sedeRefId
                );
                if (! empty($restriccionesPareja)) {
                    $grupoPrimero[] = ['item' => $item, 'primer_bloque' => $this->calcularPrimerBloqueValido($restriccionesPareja, $bloques)];
                }
            }
            usort($grupoPrimero, fn ($a, $b) => strcmp($b['primer_bloque'], $a['primer_bloque']));
            $subgruposPrimero = [];
            foreach ($grupoPrimero as $p) {
                $subgruposPrimero[$p['primer_bloque']][] = $p['item'];
            }

            $referenciados = [];
            foreach ($todosLosPartidos as $item) {
                foreach (['parejaA', 'parejaB'] as $k) {
                    $p = $item['partido'][$k] ?? null;
                    if ($p && preg_match('/^(GP|PP)(\d+)$/', (string) $p, $m)) {
                        $referenciados[$item['categoria_id'].'_'.(int) $m[2]] = true;
                    }
                }
            }
            $enPrimero = [];
            foreach ($subgruposPrimero as $sub) {
                foreach ($sub as $item) {
                    $enPrimero[$item['categoria_id'].'_'.$item['partido_idx']] = true;
                }
            }
            $grupoSegundo = [];
            $enSegundo = [];
            foreach ($todosLosPartidos as $item) {
                $key = $item['categoria_id'].'_'.$item['partido_idx'];
                if (isset($referenciados[$key])) {
                    $grupoSegundo[] = $item;
                    $enSegundo[$key] = true;
                }
            }
            $grupoTercero = [];
            $enTercero = [];
            foreach ($todosLosPartidos as $item) {
                $key = $item['categoria_id'].'_'.$item['partido_idx'];
                if (isset($enSegundo[$key])) {
                    continue;
                }
                $parejaA = $item['partido']['parejaA'] ?? null;
                $parejaB = $item['partido']['parejaB'] ?? null;
                if (($parejaA && ! is_numeric($parejaA)) || ($parejaB && ! is_numeric($parejaB))) {
                    $grupoTercero[] = $item;
                    $enTercero[$key] = true;
                }
            }
            $grupoCuarto = [];
            foreach ($todosLosPartidos as $item) {
                $key = $item['categoria_id'].'_'.$item['partido_idx'];
                if (! isset($enPrimero[$key]) && ! isset($enSegundo[$key]) && ! isset($enTercero[$key])) {
                    $grupoCuarto[] = $item;
                }
            }

            $gruposAProgramar = [
                ['nombre' => 'Primero', 'datos' => array_values($subgruposPrimero)],
                ['nombre' => 'Segundo', 'datos' => [$grupoSegundo]],
                ['nombre' => 'Tercero', 'datos' => [$grupoTercero]],
                ['nombre' => 'Cuarto', 'datos' => [$grupoCuarto]],
            ];

            foreach ($gruposAProgramar as $grupo) {
                $nombreGrupo = $grupo['nombre'];
                foreach ($grupo['datos'] as $subgrupo) {
                    shuffle($subgrupo);
                    foreach ($subgrupo as $item) {
                        $this->programarUnPartido(
                            $item, $nombreGrupo, $bloques, $bloqueos, $sedeIdBloques, $cantidadCanchas,
                            $restriccionesById, $todosLosPartidos, $maxIntentos, $criterioDescanso1, $criterioDescanso2,
                            $programacion, $parejasProgramadas, $jugadoresProgramados, $partidosProgramados,
                            $ordenProgramacion, $noProgramados
                        );
                    }
                }
            }

            $this->guardarProgramacion($eventoId, $programacion);

            return new JsonModel(['success' => true, 'fixture' => $programacion, 'no_programados' => $noProgramados]);
        } catch (\Throwable $e) {
            return new JsonModel(['success' => false, 'error' => 'Error al programar grupos: '.$e->getMessage()]);
        }
    }

    /**
     * Asigna fecha/hora/cancha a los partidos que NO son de fase GRUPOS/LIGA
     * (fase previa, cuartos, semis, final, etc.). Port fiel de
     * FixtureController::programarEliminatorias del Laravel original
     * (líneas 1628-1828). A diferencia de programarGrupos, procesa los
     * partidos en un solo grupo (sin shuffle) y usa bloque_minimo3 (que
     * también considera partidos de tipo "1A"/"2B" ya jugados en fase de
     * grupos), y un bloque con CUALQUIER cancha ocupada se descarta entero
     * (fiel al original, que ahí sí compara por bloque completo).
     */
    public function programarEliminatoriasAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Method not allowed']);
        }
        $body = json_decode($this->getRequest()->getContent(), true) ?: [];
        $eventoId = (int) ($body['evento_id'] ?? 0);
        $identity = $this->auth->getIdentity();
        if (! $eventoId) {
            return new JsonModel(['success' => false, 'error' => 'evento_id requerido']);
        }
        $tieneAcceso = $this->fetchOne(
            'SELECT 1 FROM mod_eventos e INNER JOIN evento_user eu ON eu.mod_evento_id = e.id WHERE e.id = ? AND eu.user_id = ?',
            [$eventoId, $identity['id'] ?? 0]
        );
        if (! $tieneAcceso) {
            return new JsonModel(['success' => false, 'error' => 'Evento no encontrado']);
        }

        try {
            $categorias = $this->fetchCategoriasParaProgramacion($eventoId);
            $sedes = $this->fetchSedesParaProgramacion($eventoId);
            if (empty($sedes)) {
                return new JsonModel(['success' => false, 'error' => 'El evento no tiene sedes activas configuradas']);
            }
            $restriccionesById = $this->fetchRestriccionesById(array_column($sedes, 'id'));

            $criterioDescanso1 = true;
            $criterioDescanso2 = true;
            $maxIntentos = 10;

            $programacion = $this->cargarProgramacionActual($eventoId);
            $programacionGR = $programacion;

            $parejasProgramadas = [];
            $jugadoresProgramados = [];
            $partidosProgramados = [];
            $noProgramados = [];
            $ordenProgramacion = [];
            mt_srand((int) (microtime(true) * 1000000));

            [$bloques, $bloqueos, $sedeIdBloques, $cantidadCanchas] = $this->construirBloquesDisponibles($sedes);
            if (empty($bloques)) {
                return new JsonModel(['success' => false, 'error' => 'No hay bloques de horario disponibles']);
            }

            $todosLosPartidos = [];
            foreach ($categorias as $categoria) {
                foreach ($categoria['partidos'] as $idx => $partido) {
                    if (in_array($partido['fase'] ?? '', ['GRUPOS', 'LIGA'], true)) {
                        continue;
                    }
                    $todosLosPartidos[] = [
                        'categoria_id' => $categoria['id'],
                        'partido_idx' => $idx + 1,
                        'partido' => $partido,
                        'nominas' => $categoria['nominas'],
                    ];
                }
            }

            foreach ($todosLosPartidos as $item) {
                $this->programarUnPartidoEliminatoria(
                    $item, $bloques, $bloqueos, $sedeIdBloques, $cantidadCanchas,
                    $restriccionesById, $todosLosPartidos, $maxIntentos, $criterioDescanso1, $criterioDescanso2,
                    $programacionGR, $programacion, $parejasProgramadas, $jugadoresProgramados, $partidosProgramados,
                    $ordenProgramacion, $noProgramados
                );
            }

            $this->guardarProgramacion($eventoId, $programacion);

            return new JsonModel(['success' => true, 'fixture' => $programacion, 'no_programados' => $noProgramados]);
        } catch (\Throwable $e) {
            return new JsonModel(['success' => false, 'error' => 'Error al programar eliminatorias: '.$e->getMessage()]);
        }
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

    // =================== Scheduling: datos ===================

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->dbQuery($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * Categorías activas del evento con sus partidos (JSON) y nóminas
     * (indexadas por 'orden', que es el número de pareja referenciado en
     * $partido['parejaA']/['parejaB']), cada una con sus restricciones
     * horarias asignadas y la lista de user_uuid de sus jugadores.
     */
    private function fetchCategoriasParaProgramacion(int $eventoId): array
    {
        $categorias = [];
        $catRows = $this->dbQuery(
            'SELECT id, uuid, partidos FROM mod_gestion_categorias WHERE mod_evento_id = ? AND activo = 1',
            [$eventoId]
        );
        foreach ($catRows as $cat) {
            $nominasRows = $this->dbQuery(
                'SELECT uuid, orden, restricciones FROM mod_gestion_nominas WHERE mod_gestion_categoria_uuid = ? ORDER BY orden',
                [$cat['uuid']]
            );
            $nominas = [];
            foreach ($nominasRows as $n) {
                $detalles = $this->dbQuery(
                    'SELECT user_uuid FROM mod_gestion_nominas_detalles WHERE mod_gestion_nomina_uuid = ? AND activo = 1',
                    [$n['uuid']]
                );
                $nominas[(string) $n['orden']] = [
                    'restricciones' => json_decode($n['restricciones'] ?? '[]', true) ?: [],
                    'jugadores' => array_column($detalles, 'user_uuid'),
                ];
            }
            $categorias[] = [
                'id' => (int) $cat['id'],
                'partidos' => json_decode($cat['partidos'] ?? '[]', true) ?: [],
                'nominas' => $nominas,
            ];
        }

        return $categorias;
    }

    /**
     * Sedes activas del evento con su capacidad de canchas, bloques de
     * horario configurados (mod_gestion_ajustes.bloques_grupo) y los
     * bloqueos manuales ya guardados (mod_gestion_fixture.bloqueos).
     * Crea la fila de mod_gestion_fixture si aún no existe (igual que el
     * firstOrCreate del original).
     */
    private function fetchSedesParaProgramacion(int $eventoId): array
    {
        $sedes = [];
        $sedeRows = $this->dbQuery(
            'SELECT id, fecha_inicio, fecha_cierre FROM mod_gestion_sedes WHERE mod_evento_id = ? AND activo = 1',
            [$eventoId]
        );
        $ajuste = $this->fetchOne(
            'SELECT bloques_grupo FROM mod_gestion_ajustes WHERE evento_id = ? ORDER BY created_at DESC LIMIT 1',
            [$eventoId]
        );
        $bloquesGrupo = $ajuste['bloques_grupo'] ?? '01:00';

        foreach ($sedeRows as $s) {
            $sedeId = (int) $s['id'];
            $canchas = $this->fetchOne(
                'SELECT COALESCE(SUM(cantidad), 0) AS total FROM mod_gestion_sedes_canchas WHERE mod_gestion_sede_id = ? AND activo = 1',
                [$sedeId]
            );
            $fixture = $this->fetchOne(
                'SELECT bloqueos FROM mod_gestion_fixture WHERE mod_evento_id = ? AND sede_id = ?',
                [$eventoId, $sedeId]
            );
            if (! $fixture) {
                $this->dbWrite(
                    'INSERT INTO mod_gestion_fixture (uuid, mod_evento_id, sede_id, bloqueos, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                    [Uuid::uuid4()->toString(), $eventoId, $sedeId, '[]']
                );
                $fixture = ['bloqueos' => '[]'];
            }
            $sedes[] = [
                'id' => $sedeId,
                'fecha_inicio' => $s['fecha_inicio'],
                'fecha_cierre' => $s['fecha_cierre'],
                'canchas_total' => (int) ($canchas['total'] ?? 0),
                'bloques_grupo' => $bloquesGrupo ?: '01:00',
                'bloqueos' => json_decode($fixture['bloqueos'] ?? '[]', true) ?: [],
            ];
        }

        return $sedes;
    }

    /** @return array<int, array{sede_id:int, hora_inicio:string}> indexado por id de restricción */
    private function fetchRestriccionesById(array $sedeIds): array
    {
        if (empty($sedeIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($sedeIds), '?'));
        $rows = $this->dbQuery(
            "SELECT id, mod_gestion_sede_id, hora_inicio FROM mod_gestion_sedes_restricciones WHERE mod_gestion_sede_id IN ($placeholders) AND activo = 1",
            $sedeIds
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = ['sede_id' => (int) $r['mod_gestion_sede_id'], 'hora_inicio' => $r['hora_inicio']];
        }

        return $out;
    }

    private function cargarProgramacionActual(int $eventoId): array
    {
        $row = $this->fetchOne('SELECT fixture FROM mod_gestion_fixture_prog WHERE mod_evento_id = ?', [$eventoId]);
        if (! $row) {
            $this->dbWrite(
                'INSERT INTO mod_gestion_fixture_prog (uuid, mod_evento_id, fixture, los_at, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), NOW())',
                [Uuid::uuid4()->toString(), $eventoId, '[]']
            );

            return [];
        }

        return json_decode($row['fixture'] ?? '[]', true) ?: [];
    }

    private function guardarProgramacion(int $eventoId, array $programacion): void
    {
        $this->dbWrite(
            'UPDATE mod_gestion_fixture_prog SET fixture = ?, los_at = NOW(), updated_at = NOW() WHERE mod_evento_id = ?',
            [json_encode($programacion), $eventoId]
        );
    }

    /**
     * @return array{0: string[], 1: array, 2: int[], 3: array<int,int>}
     *              [bloques, bloqueos, sedeIdBloques, cantidadCanchas]
     */
    private function construirBloquesDisponibles(array $sedes): array
    {
        $bloques = [];
        $bloqueos = [];
        $sedeIdBloques = [];
        $cantidadCanchas = [];
        foreach ($sedes as $sede) {
            $bloquesS = $this->calcularBloques($sede['fecha_inicio'], $sede['fecha_cierre'], $sede['bloques_grupo']);
            if (empty($bloquesS)) {
                continue;
            }
            // Bloque extra "de cierre" (mismo día, minuto 59) para que el último
            // slot real no quede fuera de rango al comparar con strtotime().
            $nuevo = substr(end($bloquesS), 0, -2).'59';
            $bloquesS[] = $nuevo;
            $bloques = array_merge($bloques, $bloquesS);
            $sedeIdBloques = array_merge($sedeIdBloques, array_fill(0, count($bloquesS), $sede['id']));
            $cantidadCanchas[$sede['id']] = $sede['canchas_total'];

            $bloqueosS = $sede['bloqueos'];
            for ($i = 1; $i <= $sede['canchas_total']; $i++) {
                $bloqueosS[$nuevo][$i] = true;
            }
            $bloqueos = array_merge($bloqueos, $bloqueosS);
        }

        return [$bloques, $bloqueos, $sedeIdBloques, $cantidadCanchas];
    }

    private function calcularBloques(string $inicio, string $cierre, string $duracionHHMM): array
    {
        $bloques = [];
        try {
            $inicioDT = new \DateTime($inicio);
            $cierreDT = new \DateTime($cierre);
            [$h, $m] = array_pad(explode(':', $duracionHHMM), 2, 0);
            $duracionMin = ((int) $h * 60) + (int) $m;
            if ($duracionMin <= 0) {
                return [];
            }
            while ((clone $inicioDT)->modify("+{$duracionMin} minutes") <= $cierreDT) {
                $bloques[] = $inicioDT->format('Y-m-d H:i:s');
                $inicioDT->modify("+{$duracionMin} minutes");
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $bloques;
    }

    // =================== Scheduling: núcleo del algoritmo ===================

    /**
     * Intenta programar un único partido (fase GRUPOS/LIGA), mutando por
     * referencia $programacion/$parejasProgramadas/etc. Port de la porción
     * central de FixtureController::programarGrupos (Laravel), extraída a un
     * método para no duplicarla entre los 4 grupos de prioridad.
     */
    private function programarUnPartido(
        array $item,
        string $nombreGrupo,
        array $bloques,
        array $bloqueos,
        array $sedeIdBloques,
        array $cantidadCanchas,
        array $restriccionesById,
        array $todosLosPartidos,
        int $maxIntentos,
        bool $criterioDescanso1,
        bool $criterioDescanso2,
        array &$programacion,
        array &$parejasProgramadas,
        array &$jugadoresProgramados,
        array &$partidosProgramados,
        array &$ordenProgramacion,
        array &$noProgramados
    ): void {
        $categoriaId = $item['categoria_id'];
        $partidoIdx = $item['partido_idx'];
        $partido = $item['partido'];
        $nominas = $item['nominas'];
        $claveP = $categoriaId.'_'.$partidoIdx;
        if (isset($partidosProgramados[$claveP])) {
            return;
        }

        $parejaA = $partido['parejaA'] ?? 'No definida';
        $parejaB = $partido['parejaB'] ?? 'No definida';
        $nombreParejaA = isset($nominas[(string) $parejaA]) ? "Pareja {$parejaA}" : $parejaA;
        $nombreParejaB = isset($nominas[(string) $parejaB]) ? "Pareja {$parejaB}" : $parejaB;

        $programado = false;
        $intentos = 0;
        while ($intentos < $maxIntentos && ! $programado) {
            $bloqueMinimo = $nombreGrupo === 'Tercero'
                ? $this->bloqueMinimo($partido, $categoriaId, $partidosProgramados, $bloques, $ordenProgramacion, $criterioDescanso1)
                : $this->bloqueMinimo2($partido, $categoriaId, $parejasProgramadas, $bloques, $nominas, $criterioDescanso1);

            foreach ($bloques as $idB => $bloque) {
                $sedeIIDD = $sedeIdBloques[$idB];
                $totalCanchas = $cantidadCanchas[$sedeIIDD] ?? 0;
                if (strtotime($bloque) < strtotime($bloqueMinimo)) {
                    continue;
                }

                $restriccionesPareja = $this->getRestriccionesPareja($parejaA, $parejaB, $nominas, $restriccionesById, $sedeIIDD);
                if ($nombreGrupo === 'Tercero') {
                    $restriccionesPareja = array_values(array_unique(array_merge(
                        $restriccionesPareja,
                        $this->getRestriccionesHeredadas($partido, $categoriaId, $todosLosPartidos, $restriccionesById, $sedeIIDD)
                    )));
                }
                if (in_array($bloque, $restriccionesPareja, true)) {
                    continue;
                }

                $parejasInvolucradas = $this->parejasEnPartido($partido, $todosLosPartidos, $nominas, $categoriaId);
                if ($this->algunaParejaOcupada($parejasInvolucradas, $categoriaId, $bloque, $parejasProgramadas)) {
                    continue;
                }
                if ($criterioDescanso2 && ! $this->jugadoresOcupados($partido, $nominas, $bloque, $jugadoresProgramados)) {
                    continue;
                }

                for ($cancha = 1; $cancha <= $totalCanchas; $cancha++) {
                    if (isset($bloqueos[$bloque][$cancha]) || isset($programacion[$sedeIIDD][$bloque][$cancha])) {
                        continue;
                    }
                    $programacion[$sedeIIDD][$bloque][$cancha] = [
                        'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                        'parejaA' => $nombreParejaA, 'parejaB' => $nombreParejaB,
                    ];
                    foreach ($parejasInvolucradas as $pareja) {
                        $parejasProgramadas[$pareja][$categoriaId][] = $bloque;
                    }
                    if ($criterioDescanso2) {
                        foreach ($this->getJugadoresPareja($parejaA, $parejaB, $nominas) as $jid) {
                            $jugadoresProgramados[$jid][] = $bloque;
                        }
                    }
                    $partidosProgramados[$claveP] = true;
                    $ordenProgramacion[] = [
                        'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                        'grupo' => $nombreGrupo, 'bloque' => $bloque,
                        'parejaA' => $parejaA, 'parejaB' => $parejaB,
                    ];
                    $programado = true;
                    break 2;
                }
            }
            $intentos++;
            if (! $programado) {
                $noProgramados[] = [
                    'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                    'razon' => "No se encontró bloque válido tras {$maxIntentos} intentos",
                ];
            }
        }
    }

    /**
     * Igual que programarUnPartido pero para fase de eliminatorias: usa
     * bloque_minimo3 (considera resultados de grupos tipo "1A"/"2B") y
     * descarta el bloque completo si CUALQUIER cancha está bloqueada (fiel
     * al FixtureController::programarEliminatorias original).
     */
    private function programarUnPartidoEliminatoria(
        array $item,
        array $bloques,
        array $bloqueos,
        array $sedeIdBloques,
        array $cantidadCanchas,
        array $restriccionesById,
        array $todosLosPartidos,
        int $maxIntentos,
        bool $criterioDescanso1,
        bool $criterioDescanso2,
        array $programacionGR,
        array &$programacion,
        array &$parejasProgramadas,
        array &$jugadoresProgramados,
        array &$partidosProgramados,
        array &$ordenProgramacion,
        array &$noProgramados
    ): void {
        $categoriaId = $item['categoria_id'];
        $partidoIdx = $item['partido_idx'];
        $partido = $item['partido'];
        $nominas = $item['nominas'];
        $claveP = $categoriaId.'_'.$partidoIdx;
        if (isset($partidosProgramados[$claveP])) {
            return;
        }

        $parejaA = $partido['parejaA'] ?? 'No definida';
        $parejaB = $partido['parejaB'] ?? 'No definida';
        $nombreParejaA = isset($nominas[(string) $parejaA]) ? "Pareja {$parejaA}" : $parejaA;
        $nombreParejaB = isset($nominas[(string) $parejaB]) ? "Pareja {$parejaB}" : $parejaB;

        $programado = false;
        $intentos = 0;
        while ($intentos < $maxIntentos && ! $programado) {
            $bloqueMinimo = $this->bloqueMinimo3($partido, $categoriaId, $bloques, $criterioDescanso1, $programacionGR, $ordenProgramacion);

            foreach ($bloques as $idB => $bloque) {
                $sedeIIDD = $sedeIdBloques[$idB];
                $totalCanchas = $cantidadCanchas[$sedeIIDD] ?? 0;
                if (strtotime($bloque) < strtotime($bloqueMinimo)) {
                    continue;
                }
                if (isset($bloqueos[$bloque])) {
                    continue;
                }

                $restriccionesPareja = array_values(array_unique(array_merge(
                    $this->getRestriccionesPareja($parejaA, $parejaB, $nominas, $restriccionesById, $sedeIIDD),
                    $this->getRestriccionesHeredadas($partido, $categoriaId, $todosLosPartidos, $restriccionesById, $sedeIIDD)
                )));
                if (in_array($bloque, $restriccionesPareja, true)) {
                    continue;
                }

                $parejasInvolucradas = $this->parejasEnPartido($partido, $todosLosPartidos, $nominas, $categoriaId);
                if ($this->algunaParejaOcupada($parejasInvolucradas, $categoriaId, $bloque, $parejasProgramadas)) {
                    continue;
                }
                if ($criterioDescanso2 && ! $this->jugadoresOcupados($partido, $nominas, $bloque, $jugadoresProgramados)) {
                    continue;
                }

                for ($cancha = 1; $cancha <= $totalCanchas; $cancha++) {
                    if (isset($bloqueos[$bloque][$cancha]) || isset($programacion[$sedeIIDD][$bloque][$cancha])) {
                        continue;
                    }
                    $programacion[$sedeIIDD][$bloque][$cancha] = [
                        'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                        'parejaA' => $nombreParejaA, 'parejaB' => $nombreParejaB,
                    ];
                    foreach ($parejasInvolucradas as $pareja) {
                        $parejasProgramadas[$pareja][$categoriaId][] = $bloque;
                    }
                    if ($criterioDescanso2) {
                        foreach ($this->getJugadoresPareja($parejaA, $parejaB, $nominas) as $jid) {
                            $jugadoresProgramados[$jid][] = $bloque;
                        }
                    }
                    $partidosProgramados[$claveP] = true;
                    $ordenProgramacion[] = [
                        'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                        'grupo' => 'Primero', 'bloque' => $bloque,
                        'parejaA' => $parejaA, 'parejaB' => $parejaB,
                    ];
                    $programado = true;
                    break 2;
                }
            }
            $intentos++;
            if (! $programado) {
                $noProgramados[] = [
                    'categoria_id' => $categoriaId, 'partido_idx' => $partidoIdx,
                    'razon' => "No se encontró bloque válido tras {$maxIntentos} intentos",
                ];
            }
        }
    }

    private function algunaParejaOcupada(array $parejasInvolucradas, int $categoriaId, string $bloque, array $parejasProgramadas): bool
    {
        foreach ($parejasInvolucradas as $pareja) {
            if (isset($parejasProgramadas[$pareja][$categoriaId]) && in_array($bloque, $parejasProgramadas[$pareja][$categoriaId], true)) {
                return true;
            }
        }

        return false;
    }

    private function calcularPrimerBloqueValido(array $restricciones, array $bloques): string
    {
        foreach ($bloques as $b) {
            if (! in_array($b, $restricciones, true)) {
                return $b;
            }
        }

        return end($bloques) ?: ($bloques[0] ?? '');
    }

    /**
     * Horarios (hora_inicio) restringidos para la pareja A/B de un partido en
     * una sede dada, según las restricciones asignadas a cada nómina.
     */
    private function getRestriccionesPareja($parejaA, $parejaB, array $nominas, array $restriccionesById, int $sedeId): array
    {
        $idsRestriccion = [];
        foreach ([$parejaA, $parejaB] as $p) {
            if ($p !== null && isset($nominas[(string) $p])) {
                $idsRestriccion = array_merge($idsRestriccion, $nominas[(string) $p]['restricciones'] ?? []);
            }
        }
        if (empty($idsRestriccion)) {
            return [];
        }
        $horaInicios = [];
        foreach ($idsRestriccion as $rid) {
            if (isset($restriccionesById[$rid]) && $restriccionesById[$rid]['sede_id'] === $sedeId) {
                $horaInicios[] = $restriccionesById[$rid]['hora_inicio'];
            }
        }

        return array_values(array_unique($horaInicios));
    }

    /**
     * Restricciones heredadas de los partidos que este partido referencia
     * (GP/PP = ganador/perdedor de un partido anterior).
     */
    private function getRestriccionesHeredadas(array $partido, int $categoriaId, array $todosLosPartidos, array $restriccionesById, int $sedeId): array
    {
        $out = [];
        foreach (['parejaA', 'parejaB'] as $k) {
            $p = $partido[$k] ?? null;
            if ($p && preg_match('/^(GP|PP)(\d+)$/', (string) $p, $m)) {
                $refIdx = (int) $m[2];
                foreach ($todosLosPartidos as $ref) {
                    if ($ref['categoria_id'] === $categoriaId && $ref['partido_idx'] === $refIdx) {
                        $out = array_merge($out, $this->getRestriccionesPareja(
                            $ref['partido']['parejaA'] ?? null,
                            $ref['partido']['parejaB'] ?? null,
                            $ref['nominas'],
                            $restriccionesById,
                            $sedeId
                        ));
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Números de pareja (nomina->orden) realmente involucrados en un partido, resolviendo referencias GP/PP. */
    private function parejasEnPartido(array $partido, array $todosLosPartidos, array $nominas, int $categoriaId): array
    {
        $resolver = function ($p) use ($todosLosPartidos, $nominas, $categoriaId) {
            $out = [];
            if ($p !== null && is_numeric($p)) {
                $out[] = (string) $p;
            } elseif ($p && preg_match('/^(GP|PP)(\d+)$/', (string) $p, $m)) {
                $refIdx = (int) $m[2];
                foreach ($todosLosPartidos as $ref) {
                    if ($ref['categoria_id'] === $categoriaId && $ref['partido_idx'] === $refIdx) {
                        foreach (['parejaA', 'parejaB'] as $k) {
                            $rp = $ref['partido'][$k] ?? null;
                            if ($rp && is_numeric($rp) && isset($nominas[(string) $rp])) {
                                $out[] = (string) $rp;
                            }
                        }
                    }
                }
            }

            return $out;
        };

        return array_values(array_unique(array_merge(
            $resolver($partido['parejaA'] ?? null),
            $resolver($partido['parejaB'] ?? null)
        )));
    }

    /** Usado para el grupo "Tercero" (partidos con dependencia de un GP/PP ya programado). */
    private function bloqueMinimo(array $partido, int $categoriaId, array $partidosProgramados, array $bloques, array $ordenProgramacion, bool $criterioDescanso1): string
    {
        $bloqueMinimo = $bloques[0];
        $bloqueMinimoRR = $bloques[0];
        foreach ([$partido['parejaA'] ?? null, $partido['parejaB'] ?? null] as $p) {
            if ($p && preg_match('/^(GP|PP)(\d+)$/', (string) $p, $m)) {
                $refIdx = (int) $m[2];
                if (isset($partidosProgramados[$categoriaId.'_'.$refIdx])) {
                    foreach ($ordenProgramacion as $prog) {
                        if ($prog['partido_idx'] == $refIdx && $prog['categoria_id'] == $categoriaId && strtotime($prog['bloque']) > strtotime($bloqueMinimo)) {
                            $idx = array_search($prog['bloque'], $bloques, true);
                            $bloqueMinimo = $bloques[$idx + 1] ?? $bloques[$idx];
                        }
                    }
                }
            }
        }
        if ($criterioDescanso1 && $bloqueMinimo !== $bloqueMinimoRR) {
            $idx = array_search($bloqueMinimo, $bloques, true);
            $bloqueMinimo = $bloques[$idx + 1] ?? $bloques[$idx];
        }

        return $bloqueMinimo;
    }

    /** Usado para los grupos "Primero"/"Segundo"/"Cuarto": exige 2 bloques de descanso entre partidos de la misma pareja. */
    private function bloqueMinimo2(array $partido, int $categoriaId, array $parejasProgramadas, array $bloques, array $nominas, bool $criterioDescanso1): string
    {
        $bloqueMinimo = $bloques[0];
        if ($criterioDescanso1) {
            foreach ($this->parejasEnPartido($partido, [], $nominas, $categoriaId) as $pareja) {
                foreach ($parejasProgramadas[$pareja][$categoriaId] ?? [] as $bloqueProg) {
                    $idx = array_search($bloqueProg, $bloques, true);
                    if ($idx !== false && $idx + 2 < count($bloques)) {
                        $sig = $bloques[$idx + 2];
                        if (strtotime($sig) > strtotime($bloqueMinimo)) {
                            $bloqueMinimo = $sig;
                        }
                    }
                }
            }
        }

        return $bloqueMinimo;
    }

    /** Usado en programarEliminatorias: considera resultados de grupos ("1A"/"2B") y referencias GP/PP. */
    private function bloqueMinimo3(array $partido, int $categoriaId, array $bloques, bool $criterioDescanso1, array $programacionGR, array $ordenProgramacion): string
    {
        $bloqueMinimo = $bloques[0];
        $bloqueMinimoRR = $bloques[0];
        foreach ([$partido['parejaA'] ?? null, $partido['parejaB'] ?? null] as $p) {
            if ($p && preg_match('/^(\d+)([A-Z])$/', (string) $p)) {
                $horax = [];
                $horaxi = [];
                foreach ($programacionGR as $sedeBloques) {
                    foreach ($sedeBloques as $hRef => $canchasArr) {
                        foreach ($canchasArr as $pp) {
                            if (! empty($pp['categoria_id']) && $pp['categoria_id'] == $categoriaId) {
                                $horax[] = $hRef;
                                $horaxi[] = strtotime($hRef);
                            }
                        }
                    }
                }
                if (! empty($horaxi)) {
                    $maxPos = array_keys($horaxi, max($horaxi))[0];
                    $idx = array_search($horax[$maxPos], $bloques, true);
                    $bloqueMinimo = $bloques[$idx + 1] ?? $bloques[$idx];
                }
            }
        }
        foreach ([$partido['parejaA'] ?? null, $partido['parejaB'] ?? null] as $p) {
            if ($p && preg_match('/^(GP|PP)(\d+)$/', (string) $p, $m)) {
                $refIdx = (int) $m[2];
                foreach ($ordenProgramacion as $prog) {
                    if ($prog['partido_idx'] == $refIdx && $prog['categoria_id'] == $categoriaId && strtotime($prog['bloque']) > strtotime($bloqueMinimo)) {
                        $idx = array_search($prog['bloque'], $bloques, true);
                        $bloqueMinimo = $bloques[$idx + 1] ?? $bloques[$idx];
                    }
                }
            }
        }
        if ($criterioDescanso1 && $bloqueMinimo !== $bloqueMinimoRR) {
            $idx = array_search($bloqueMinimo, $bloques, true);
            $bloqueMinimo = $bloques[$idx + 1] ?? $bloques[$idx];
        }

        return $bloqueMinimo;
    }

    private function jugadoresOcupados(array $partido, array $nominas, string $bloque, array $jugadoresProgramados): bool
    {
        foreach ($this->getJugadoresPareja($partido['parejaA'] ?? null, $partido['parejaB'] ?? null, $nominas) as $jid) {
            if (isset($jugadoresProgramados[$jid]) && in_array($bloque, $jugadoresProgramados[$jid], true)) {
                return false;
            }
        }

        return true;
    }

    private function getJugadoresPareja($parejaA, $parejaB, array $nominas): array
    {
        $jugadores = [];
        foreach ([$parejaA, $parejaB] as $p) {
            if ($p !== null && is_numeric($p) && isset($nominas[(string) $p])) {
                $jugadores = array_merge($jugadores, $nominas[(string) $p]['jugadores'] ?? []);
            }
        }

        return array_values(array_unique($jugadores));
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

    /**
     * BUG (pre-existente): antes llamaba prepare() directamente sobre
     * Laminas\Db\Adapter\Driver\Pdo\Connection, que NO tiene método fetch()
     * -> toda consulta lanzaba "Call to undefined method ...::fetch()",
     * silenciada por el catch(), y esta función devolvía [] SIEMPRE. Debe
     * usarse getPdo() (getResource() sobre la conexión) para obtener el
     * \PDO nativo, igual que ya hacían CategoriasController/SedesController.
     */
    private function dbQuery(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->getPdo()->prepare($sql);
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
            $stmt = $this->getPdo()->prepare($sql);
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
