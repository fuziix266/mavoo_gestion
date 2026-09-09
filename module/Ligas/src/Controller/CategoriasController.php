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

    /**
     * Genera y guarda los partidos (grupos y/o bracket de eliminatorias) de una
     * categoría, a partir de la estructura elegida en el modal (radio
     * `estructura_letra` + hidden `estructura_info_{letra}`, ver
     * ligas/categorias/index.phtml). Port fiel de
     * app/Http/Controllers/mod/padel/gestion/CategoriasController::guardarEstructura
     * del proyecto Laravel original.
     */
    public function guardarestructuraAction(): JsonModel
    {
        if (! $this->getRequest()->isPost()) {
            return new JsonModel(['ok' => false, 'error' => 'Método no permitido']);
        }

        $categoriaUuid = $this->params()->fromRoute('uuid') ?: $this->params()->fromRoute('id');
        if (! $categoriaUuid) {
            return new JsonModel(['ok' => false, 'error' => 'UUID requerido']);
        }

        $post = $this->getRequest()->getPost()->toArray();
        $letra = (string) ($post['estructura_letra'] ?? '');
        $info = $post['estructura_info_'.$letra] ?? null;
        if ($letra === '' || $info === null) {
            return new JsonModel(['ok' => false, 'error' => 'Debes seleccionar una estructura']);
        }

        $infoArray = is_array($info) ? $info : json_decode((string) $info, true);
        if (! is_array($infoArray)) {
            return new JsonModel(['ok' => false, 'error' => 'estructura_info inválida']);
        }

        $formato = $infoArray['Formato'] ?? '';
        $totalParticipantes = (int) ($infoArray['Total Participantes'] ?? 0);
        $clasificados = (int) ($infoArray['Clasificados'] ?? 0);

        // Selección dinámica de clasificados (formatos "Liga + PlayOff").
        if (! empty($infoArray['DynamicPlayoff']) && isset($post['custom_clasificados_value']) && $post['custom_clasificados_value'] !== '') {
            $dynamicClasificados = (int) $post['custom_clasificados_value'];
            if ($dynamicClasificados >= 2 && $dynamicClasificados <= $totalParticipantes) {
                $infoArray['Clasificados'] = $dynamicClasificados;
                $infoArray['Partidos Playoff'] = $dynamicClasificados - 1;
                $clasificados = $dynamicClasificados;
            }
        }

        $partidosTodos = [];

        $esFormatoLigaUnica = (($infoArray['Total Grupos'] ?? null) === 1)
            && ($formato === 'Liga Solo Ida' || $formato === 'Liga Ida y Vuelta' || str_contains($formato, 'PlayOff'));

        if ($esFormatoLigaUnica) {
            $rondas = (int) ($infoArray['Rondas'] ?? 1);
            $partidosTodos = self::generarPartidosLiga($totalParticipantes, $rondas);
        } else {
            $grupos = self::generarGruposGusano($infoArray);
            foreach ($grupos as $grupo) {
                $grupoLetra = $grupo['nombre'];
                $parejas = $grupo['parejas'];
                if (count($parejas) === 3) {
                    $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[1]];
                    $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[2]];
                    $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[1], 'parejaB' => $parejas[2]];
                }
                if (count($parejas) === 4) {
                    if (($infoArray['Tipo Grupos de 4'] ?? null) === 'A') {
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[3]];
                        $p1 = count($partidosTodos) - 1;
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[1], 'parejaB' => $parejas[2]];
                        $p2 = count($partidosTodos) - 1;
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => 'GP'.($p1 + 1), 'parejaB' => 'PP'.($p2 + 1)];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => 'PP'.($p1 + 1), 'parejaB' => 'GP'.($p2 + 1)];
                    }
                    if (($infoArray['Tipo Grupos de 4'] ?? null) === 'B') {
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[1]];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[2]];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[0], 'parejaB' => $parejas[3]];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[1], 'parejaB' => $parejas[2]];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[1], 'parejaB' => $parejas[3]];
                        $partidosTodos[] = ['fase' => 'GRUPOS', 'letra' => $grupoLetra, 'parejaA' => $parejas[2], 'parejaB' => $parejas[3]];
                    }
                }
            }
        }

        if ($clasificados > 1 && (int) ($infoArray['Partidos Playoff'] ?? 0) > 0) {
            $esFormatoLiga = (($infoArray['Total Grupos'] ?? null) === 1) && str_contains($formato, 'Liga');

            if ($esFormatoLiga) {
                $t3 = 0;
                $t4 = 0;
                $pgrup = 0;
            } else {
                $t3 = (int) ($infoArray['G3'] ?? 0);
                $t4 = (int) ($infoArray['G4A'] ?? 0) + (int) ($infoArray['G4B'] ?? 0);
                $pgrup = (int) ($infoArray['Total Grupos'] ?? 0);
            }

            $partidosPlayoff = self::generarCuadroDesdeEstructura($clasificados, $t3, $t4, $pgrup);

            if ($esFormatoLiga) {
                foreach ($partidosPlayoff as &$partido) {
                    foreach (['parejaA', 'parejaB'] as $key) {
                        if (is_numeric($partido[$key])) {
                            $partido[$key] .= 'A';
                        }
                    }
                }
                unset($partido);
            }

            // Reajustar referencias GP/PP para que apunten al índice global (fase de grupos + playoff).
            foreach ($partidosPlayoff as &$partido) {
                foreach (['parejaA', 'parejaB'] as $campo) {
                    if (preg_match('/^GP(\d+)$/', (string) $partido[$campo], $match)) {
                        $partido[$campo] = 'GP'.((int) $match[1] + count($partidosTodos));
                    } elseif (preg_match('/^PP(\d+)$/', (string) $partido[$campo], $match)) {
                        $partido[$campo] = 'PP'.((int) $match[1] + count($partidosTodos));
                    }
                }
            }
            unset($partido);

            $partidosTodos = array_merge($partidosTodos, $partidosPlayoff);
        }

        $ok = $this->dbWrite(
            'UPDATE mod_gestion_categorias SET estructura = ?, estructura_info = ?, partidos = ?, updated_at = NOW() WHERE uuid = ?',
            [$letra, json_encode($infoArray), json_encode($partidosTodos), $categoriaUuid]
        );

        return new JsonModel([
            'ok' => (bool) $ok,
            'message' => $ok ? 'Estructura guardada' : 'Error al guardar',
            'total_partidos' => count($partidosTodos),
        ]);
    }

    /**
     * Lista las combinaciones posibles de estructura (grupos de 3/4, solo
     * playoff, liga con o sin playoff dinámico) para $aConsiderar participantes.
     * Port fiel de CategoriasController::calcularEstructurasF (Laravel).
     */
    public function calcularestructurasAction(): JsonModel
    {
        $n = (int) $this->params()->fromRoute('a_considerar', 0);
        if ($n < 2) {
            return new JsonModel(['error' => 'Se necesitan al menos 2 equipos']);
        }

        $resultados = [];

        // 1.1. Solo Playoff
        $clasificados = $n;
        $playoffPartidos = $clasificados > 1 ? $clasificados - 1 : 0;
        $resultados[] = [
            'G3' => 0, 'G4A' => 0, 'G4B' => 0, 'Tipo Grupos de 4' => 'Ninguno',
            'Total Participantes' => $n, 'Total Grupos' => 0, 'Partidos Fase' => 0,
            'Clasificados' => $clasificados, 'Partidos Playoff' => $playoffPartidos,
            'Total Partidos' => $playoffPartidos, 'Formato' => 'Solo Playoff',
            'DynamicPlayoff' => false,
        ];

        // 1.2. Fase + Playoff (combinaciones de grupos de 3 y 4)
        for ($g3 = 0; $g3 <= intdiv($n, 3); $g3++) {
            for ($g4 = 1; $g4 <= intdiv($n, 4); $g4++) {
                if ($g3 * 3 + $g4 * 4 === $n) {
                    foreach (['A' => 4, 'B' => 6] as $tipo => $partidosPorGrupo) {
                        $gruposTotal = $g3 + $g4;
                        $clasificados = $gruposTotal * 2;
                        $fasePartidos = $g3 * 3 + $g4 * $partidosPorGrupo;
                        $playoffPartidos = $clasificados > 1 ? $clasificados - 1 : 0;
                        $resultados[] = [
                            'G3' => $g3, 'G4A' => $tipo === 'A' ? $g4 : 0,
                            'G4B' => $tipo === 'B' ? $g4 : 0, 'Tipo Grupos de 4' => $tipo,
                            'Total Participantes' => $n, 'Total Grupos' => $gruposTotal,
                            'Partidos Fase' => $fasePartidos, 'Clasificados' => $clasificados,
                            'Partidos Playoff' => $playoffPartidos,
                            'Total Partidos' => $fasePartidos + $playoffPartidos, 'Formato' => 'Fase + Playoff',
                            'DynamicPlayoff' => false,
                        ];
                    }
                }
            }
        }
        for ($g3 = 1; $g3 <= intdiv($n, 3); $g3++) {
            if ($g3 * 3 === $n) {
                $gruposTotal = $g3;
                $clasificados = $gruposTotal * 2;
                $fasePartidos = $g3 * 3;
                $playoffPartidos = $clasificados > 1 ? $clasificados - 1 : 0;
                $resultados[] = [
                    'G3' => $g3, 'G4A' => 0, 'G4B' => 0, 'Tipo Grupos de 4' => 'Ninguno',
                    'Total Participantes' => $n, 'Total Grupos' => $gruposTotal,
                    'Partidos Fase' => $fasePartidos, 'Clasificados' => $clasificados,
                    'Partidos Playoff' => $playoffPartidos,
                    'Total Partidos' => $fasePartidos + $playoffPartidos, 'Formato' => 'Fase + Playoff',
                    'DynamicPlayoff' => false,
                ];
            }
        }

        // 2. Formatos de Liga
        $partidosSoloIda = intdiv($n * ($n - 1), 2);
        $partidosIdaVuelta = $n * ($n - 1);
        $opcionesLiga = [
            ['Formato' => 'Liga Solo Ida', 'Partidos Fase' => $partidosSoloIda, 'Rondas' => 1, 'DynamicPlayoff' => false, 'Clasificados' => $n, 'Partidos Playoff' => 0],
            ['Formato' => 'Liga Ida y Vuelta', 'Partidos Fase' => $partidosIdaVuelta, 'Rondas' => 2, 'DynamicPlayoff' => false, 'Clasificados' => $n, 'Partidos Playoff' => 0],
            ['Formato' => 'Liga Solo Ida + PlayOff', 'Partidos Fase' => $partidosSoloIda, 'Rondas' => 1, 'DynamicPlayoff' => true, 'Clasificados' => 2, 'Partidos Playoff' => 1],
            ['Formato' => 'Liga Ida y Vuelta + PlayOff', 'Partidos Fase' => $partidosIdaVuelta, 'Rondas' => 2, 'DynamicPlayoff' => true, 'Clasificados' => 2, 'Partidos Playoff' => 1],
        ];
        foreach ($opcionesLiga as $opcion) {
            $resultados[] = [
                'G3' => 0, 'G4A' => 0, 'G4B' => 0, 'Tipo Grupos de 4' => 'Ninguno',
                'Total Participantes' => $n, 'Total Grupos' => 1,
                'Partidos Fase' => $opcion['Partidos Fase'],
                'Clasificados' => $opcion['Clasificados'],
                'Partidos Playoff' => $opcion['Partidos Playoff'],
                'Total Partidos' => $opcion['Partidos Fase'] + $opcion['Partidos Playoff'],
                'Formato' => $opcion['Formato'],
                'Rondas' => $opcion['Rondas'],
                'DynamicPlayoff' => $opcion['DynamicPlayoff'],
            ];
        }

        // Deduplicar (misma clave que el original: Formato|Clasificados|Tipo Grupos de 4|Dynamic)
        $vistos = [];
        $unicos = [];
        foreach ($resultados as $r) {
            $clave = $r['Formato'].'|'.$r['Clasificados'].'|'.$r['Tipo Grupos de 4'].'|'.($r['DynamicPlayoff'] ? 'D' : 'F');
            if (! isset($vistos[$clave])) {
                $vistos[$clave] = true;
                $unicos[] = $r;
            }
        }

        return new JsonModel($unicos);
    }

    // =========================================================
    // Algoritmos de generación de grupos/bracket
    // Port fiel de app/Http/Controllers/mod/padel/gestion/CategoriasController.php
    // y app/Http/Controllers/mod/padel/MenuhController.php (Laravel original).
    // =========================================================

    /** Round-robin (todos contra todos) para formatos de Liga. */
    private static function generarPartidosLiga(int $n, int $rondas = 1): array
    {
        $partidos = [];
        $participantes = [];
        for ($i = 1; $i <= $n; $i++) {
            $participantes[] = "$i";
        }

        if ($rondas === 2) {
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $partidos[] = ['fase' => 'LIGA', 'letra' => 'A', 'parejaA' => $participantes[$i], 'parejaB' => $participantes[$j]];
                    $partidos[] = ['fase' => 'LIGA', 'letra' => 'A', 'parejaA' => $participantes[$j], 'parejaB' => $participantes[$i]];
                }
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $partidos[] = ['fase' => 'LIGA', 'letra' => 'A', 'parejaA' => $participantes[$i], 'parejaB' => $participantes[$j]];
                }
            }
        }

        return $partidos;
    }

    /** Distribución en grupos con seeding "gusano"/serpentina (boustrophedon). */
    private static function generarGruposGusano(array $infoArray): array
    {
        $totalParejas = (int) ($infoArray['Total Participantes'] ?? 0);
        $listaParejas = [];
        for ($i = 1; $i <= $totalParejas; $i++) {
            $listaParejas[] = "$i";
        }

        $esFormatoLiga = (($infoArray['Total Grupos'] ?? null) === 1) && str_contains($infoArray['Formato'] ?? '', 'Liga');
        if ($esFormatoLiga || (($infoArray['Total Grupos'] ?? null) === 0 && $totalParejas > 0)) {
            return [['nombre' => 'A', 'parejas' => $listaParejas]];
        }

        $estructura = [];
        foreach (['G3' => 3, 'G4A' => 4, 'G4B' => 4] as $clave => $tam) {
            for ($i = 0; $i < (int) ($infoArray[$clave] ?? 0); $i++) {
                $estructura[] = $tam;
            }
        }
        $cantidadGrupos = count($estructura);
        $maxFilas = ! empty($estructura) ? max($estructura) : 0;
        $grupos = [];
        for ($i = 0; $i < $cantidadGrupos; $i++) {
            $grupos[$i] = [];
        }
        $indicePareja = 0;
        for ($fila = 0; $fila < $maxFilas; $fila++) {
            $orden = ($fila % 2 === 0) ? range(0, $cantidadGrupos - 1) : array_reverse(range(0, $cantidadGrupos - 1));
            foreach ($orden as $col) {
                if (count($grupos[$col]) < $estructura[$col] && $indicePareja < $totalParejas) {
                    $grupos[$col][] = $listaParejas[$indicePareja++];
                }
            }
        }
        $resultado = [];
        foreach ($grupos as $i => $parejas) {
            $resultado[] = ['nombre' => chr(65 + $i), 'parejas' => $parejas];
        }

        return $resultado;
    }

    /** Bracket de eliminatorias (con byes/fase previa si corresponde) a partir del seeding oficial. */
    private static function generarCuadroDesdeEstructura(int $clasificados, int $t3, int $t4, int $pgrup): array
    {
        $estructura = self::devuelveEstructura($clasificados, $t3, $t4, $pgrup)['array'];
        $partidos = [];
        $gp = 1;
        $ronda = [];

        if (count($estructura) === 1) {
            $ronda = $estructura[0];
            for ($i = 0; $i < count($ronda); $i += 2) {
                if (! isset($ronda[$i + 1])) {
                    continue;
                }
                $partidos[] = [
                    'fase' => self::nombreFase(count($ronda)),
                    'parejaA' => self::normalizar($ronda[$i]),
                    'parejaB' => self::normalizar($ronda[$i + 1]),
                ];
                $ronda[$i / 2] = 'GP'.$gp++;
            }
            $ronda = array_slice($ronda, 0, (int) ceil(count($ronda) / 2));
        } elseif (count($estructura) === 2) {
            $previa = $estructura[0];
            $principal = $estructura[1];
            $gpMap = [];
            for ($i = 0; $i < count($previa); $i += 2) {
                if (! isset($previa[$i + 1])) {
                    continue;
                }
                $partidos[] = [
                    'fase' => 'FASE PREVIA',
                    'parejaA' => self::normalizar($previa[$i]),
                    'parejaB' => self::normalizar($previa[$i + 1]),
                ];
                $gpMap[] = 'GP'.$gp++;
            }
            $ronda = [];
            $gpIndex = 0;
            foreach ($principal as $pos) {
                $ronda[] = $pos === '' ? $gpMap[$gpIndex++] : $pos;
            }
        }

        while (count($ronda) > 1) {
            $fase = self::nombreFase(count($ronda));
            $siguiente = [];
            for ($i = 0; $i < count($ronda); $i += 2) {
                if (! isset($ronda[$i + 1])) {
                    continue;
                }
                $partidos[] = [
                    'fase' => $fase,
                    'parejaA' => self::normalizar($ronda[$i]),
                    'parejaB' => self::normalizar($ronda[$i + 1]),
                ];
                $siguiente[] = 'GP'.$gp++;
            }
            $ronda = $siguiente;
        }

        return $partidos;
    }

    private static function normalizar(string $nombre): string
    {
        return str_starts_with($nombre, 'GP') ? $nombre : "$nombre";
    }

    private static function nombreFase(int $cantidad): string
    {
        return match ($cantidad) {
            2 => 'FINAL',
            4 => 'SEMIFINAL',
            8 => 'CUARTOS DE FINAL',
            16 => 'OCTAVOS DE FINAL',
            32 => '16AVOS DE FINAL',
            64 => '32AVOS DE FINAL',
            default => 'FASE PREVIA',
        };
    }

    /**
     * Tablas de seeding oficial (bracket directo 2-32 clasificados sin fase de
     * grupos, y bracket "post-grupos" 2-28 validado FEPACHI). Port literal de
     * MenuhController::devuelveEstructura (Laravel).
     */
    private static function devuelveEstructura(int $clasificados, int $t3, int $t4, int $pgrup): array
    {
        $orden = [];
        $auxiliar = 0;

        if ($t3 == 0 && $t4 == 0 && $pgrup == 0) {
            if ($clasificados == 2) { $orden[] = ['1', '2']; $auxiliar = 2; }
            if ($clasificados == 3) { $orden[] = ['2', '3']; $orden[] = ['1', '']; $auxiliar = 2; }
            if ($clasificados == 4) { $orden[] = ['1', '4', '2', '3']; $auxiliar = 4; }
            if ($clasificados == 5) { $orden[] = ['4', '5']; $orden[] = ['1', '', '2', '3']; $auxiliar = 4; }
            if ($clasificados == 6) { $orden[] = ['4', '5', '3', '6']; $orden[] = ['1', '', '2', '']; $auxiliar = 4; }
            if ($clasificados == 7) { $orden[] = ['4', '5', '2', '7', '3', '6']; $orden[] = ['1', '', '', '']; $auxiliar = 4; }
            if ($clasificados == 8) { $orden[] = ['1', '8', '4', '5', '2', '7', '3', '6']; $auxiliar = 8; }
            if ($clasificados == 9) { $orden[] = ['8', '9']; $orden[] = ['1', '', '4', '5', '2', '7', '3', '6']; $auxiliar = 8; }
            if ($clasificados == 10) { $orden[] = ['8', '9', '7', '10']; $orden[] = ['1', '', '4', '5', '2', '', '3', '6']; $auxiliar = 8; }
            if ($clasificados == 11) { $orden[] = ['8', '9', '7', '10', '6', '11']; $orden[] = ['1', '', '4', '5', '2', '', '3', '']; $auxiliar = 8; }
            if ($clasificados == 12) { $orden[] = ['8', '9', '5', '12', '7', '10', '6', '11']; $orden[] = ['1', '', '4', '', '2', '', '3', '']; $auxiliar = 8; }
            if ($clasificados == 13) { $orden[] = ['8', '9', '4', '13', '5', '12', '7', '10', '6', '11']; $orden[] = ['1', '', '', '', '2', '', '3', '']; $auxiliar = 8; }
            if ($clasificados == 14) { $orden[] = ['8', '9', '4', '13', '5', '12', '7', '10', '3', '14', '6', '11']; $orden[] = ['1', '', '', '', '2', '', '', '']; $auxiliar = 8; }
            if ($clasificados == 15) { $orden[] = ['8', '9', '4', '13', '5', '12', '2', '15', '7', '10', '3', '14', '6', '11']; $orden[] = ['1', '', '', '', '', '', '', '']; $auxiliar = 8; }
            if ($clasificados == 16) { $orden[] = ['1', '16', '8', '9', '4', '13', '5', '12', '2', '15', '7', '10', '3', '14', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 17) { $orden[] = ['16', '17']; $orden[] = ['1', '', '8', '9', '4', '13', '5', '12', '2', '15', '7', '10', '3', '14', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 18) { $orden[] = ['16', '17', '15', '18']; $orden[] = ['1', '', '8', '9', '4', '13', '5', '12', '2', '', '7', '10', '3', '14', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 19) { $orden[] = ['16', '17', '15', '18', '14', '19']; $orden[] = ['1', '', '8', '9', '4', '13', '5', '12', '2', '', '7', '10', '3', '', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 20) { $orden[] = ['16', '17', '13', '20', '15', '18', '14', '19']; $orden[] = ['1', '', '8', '9', '4', '', '5', '12', '2', '', '7', '10', '3', '', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 21) { $orden[] = ['16', '17', '13', '20', '12', '21', '15', '18', '14', '19']; $orden[] = ['1', '', '8', '9', '4', '', '5', '', '2', '', '7', '10', '3', '', '6', '11']; $auxiliar = 16; }
            if ($clasificados == 22) { $orden[] = ['16', '17', '13', '20', '12', '21', '15', '18', '14', '19', '11', '22']; $orden[] = ['1', '', '8', '9', '4', '', '5', '', '2', '', '7', '10', '3', '', '6', '']; $auxiliar = 16; }
            if ($clasificados == 23) { $orden[] = ['16', '17', '13', '20', '12', '21', '15', '18', '10', '23', '14', '19', '11', '22']; $orden[] = ['1', '', '8', '9', '4', '', '5', '', '2', '', '7', '', '3', '', '6', '']; $auxiliar = 16; }
            if ($clasificados == 24) { $orden[] = ['16', '17', '9', '24', '13', '20', '12', '21', '15', '18', '10', '23', '14', '19', '11', '22']; $orden[] = ['1', '', '8', '', '4', '', '5', '', '2', '', '7', '', '3', '', '6', '']; $auxiliar = 16; }
            if ($clasificados == 25) { $orden[] = ['16', '17', '8', '25', '9', '24', '13', '20', '12', '21', '15', '18', '10', '23', '14', '19', '11', '22']; $orden[] = ['1', '', '', '', '4', '', '5', '', '2', '', '7', '', '3', '', '6', '']; $auxiliar = 16; }
            if ($clasificados == 26) { $orden[] = ['16', '17', '8', '25', '9', '24', '13', '20', '12', '21', '15', '18', '7', '26', '10', '23', '14', '19', '11', '22']; $orden[] = ['1', '', '', '', '4', '', '5', '', '2', '', '', '', '3', '', '6', '']; $auxiliar = 16; }
            if ($clasificados == 27) { $orden[] = ['16', '17', '8', '25', '9', '24', '13', '20', '12', '21', '15', '18', '7', '26', '10', '23', '14', '19', '6', '27', '11', '22']; $orden[] = ['1', '', '', '', '4', '', '5', '', '2', '', '', '', '3', '', '', '']; $auxiliar = 16; }
            if ($clasificados == 28) { $orden[] = ['16', '17', '8', '25', '9', '24', '13', '20', '5', '28', '12', '21', '15', '18', '7', '26', '10', '23', '14', '19', '6', '27', '11', '22']; $orden[] = ['1', '', '', '', '4', '', '', '', '2', '', '', '', '3', '', '', '']; $auxiliar = 16; }
            if ($clasificados == 29) { $orden[] = ['16', '17', '8', '25', '9', '24', '4', '29', '13', '20', '5', '28', '12', '21', '15', '18', '7', '26', '10', '23', '14', '19', '6', '27', '11', '22']; $orden[] = ['1', '', '', '', '', '', '', '', '2', '', '', '', '3', '', '', '']; $auxiliar = 16; }
            if ($clasificados == 30) { $orden[] = ['16', '17', '8', '25', '9', '24', '4', '29', '13', '20', '5', '28', '12', '21', '15', '18', '7', '26', '10', '23', '3', '30', '14', '19', '6', '27', '11', '22']; $orden[] = ['1', '', '', '', '', '', '', '', '2', '', '', '', '', '', '', '']; $auxiliar = 16; }
            if ($clasificados == 31) { $orden[] = ['16', '17', '8', '25', '9', '24', '4', '29', '13', '20', '5', '28', '12', '21', '2', '31', '15', '18', '7', '26', '10', '23', '3', '30', '14', '19', '6', '27', '11', '22']; $orden[] = ['1', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']; $auxiliar = 16; }
            if ($clasificados == 32) { $orden[] = ['1', '32', '16', '17', '8', '25', '9', '24', '4', '29', '13', '20', '5', '28', '12', '21', '2', '31', '15', '18', '7', '26', '10', '23', '3', '30', '14', '19', '6', '27', '11', '22']; $auxiliar = 32; }
        } else {
            if ($clasificados == 2) { $orden[] = ['1A', '2A']; $auxiliar = 2; }
            if ($clasificados == 4) { $orden[] = ['1A', '2B', '2A', '1B']; $auxiliar = 4; }
            if ($clasificados == 6) { $orden[] = ['2B', '2C', '2A', '1C']; $orden[] = ['1A', '', '', '1B']; $auxiliar = 4; }
            if ($clasificados == 8) { $orden[] = ['1A', '2C', '2B', '1D', '1C', '2A', '2D', '1B']; $auxiliar = 8; }
            if ($clasificados == 10) { $orden[] = ['2C', '2B', '2D', '2E']; $orden[] = ['1A', '', '1E', '1D', '1C', '2A', '', '1B']; $auxiliar = 8; }
            if ($clasificados == 12) { $orden[] = ['2F', '2B', '2C', '1E', '1F', '2D', '2A', '2E']; $orden[] = ['1A', '', '', '1D', '1C', '', '', '1B']; $auxiliar = 8; }
            if ($clasificados == 14) { $orden[] = ['2G', '2B', '2C', '1E', '2F', '1D', '1C', '2E', '1F', '2D', '2A', '1G']; $orden[] = ['1A', '', '', '', '', '', '', '1B']; $auxiliar = 8; }
            if ($clasificados == 16) { $orden[] = ['1A', '2G', '1H', '2B', '1E', '2C', '1D', '2F', '1C', '2E', '1F', '2D', '1G', '2A', '1B', '2H']; $auxiliar = 16; }
            if ($clasificados == 18) { $orden[] = ['2B', '2G', '2I', '2E']; $orden[] = ['1A', '', '1H', '1I', '2C', '1E', '2F', '1D', '1C', '', '1F', '2D', '2A', '1G', '2H', '1B']; $auxiliar = 16; }
            if ($clasificados == 20) { $orden[] = ['2B', '2G', '2F', '2J', '2I', '2E', '2A', '2H']; $orden[] = ['1A', '', '1H', '1I', '2C', '1E', '1D', '', '1C', '', '1F', '2D', '1J', '1G', '1B', '']; $auxiliar = 16; }
            if ($clasificados == 22) { $orden[] = ['2B', '2G', '2C', '2K', '2F', '2J', '2I', '2E', '2D', '1K', '2A', '2H']; $orden[] = ['1A', '', '1H', '1I', '', '1E', '', '1D', '1C', '', '1F', '', '1J', '1G', '1B', '']; $auxiliar = 16; }
            if ($clasificados == 24) { $orden[] = ['2B', '2G', '1I', '1L', '2C', '2K', '2F', '2J', '2I', '2E', '2D', '1K', '1J', '2L', '2A', '2H']; $orden[] = ['1A', '', '1H', '', '', '1E', '', '1D', '1C', '', '1F', '', '', '1G', '1B', '']; $auxiliar = 16; }
            if ($clasificados == 26) { $orden[] = ['2B', '2G', '1H', '1M', '1I', '1L', '2C', '2K', '2F', '2J', '2I', '2E', '1F', '2M', '2D', '1K', '1J', '2L', '2A', '2H']; $orden[] = ['1A', '', '', '', '', '1E', '', '1D', '1C', '', '', '', '', '1G', '1B', '']; $auxiliar = 16; }
            if ($clasificados == 28) {
                $orden[] = ['2B', '2G', '1H', '1M', '1I', '1L', '2C', '2K', '2F', '2J', '2I', '2E', '1F', '2M', '2D', '1K'];
                $orden[] = ['1J', '2L', '2A', '2H', '1A', '', '1N', '1E', '1D', '1C', '2N', '', '1G', '1B', '', ''];
                $auxiliar = 16;
            }
        }

        return ['array' => $orden, 'variable' => $auxiliar, 'otra' => ($t3 == 0 && $t4 == 0 && $pgrup == 0) ? 'si' : 'no'];
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
