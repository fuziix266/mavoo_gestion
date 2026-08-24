<?php

declare(strict_types=1);

namespace Ligas;

use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Ligas\Controller\AjustesController;
use Ligas\Controller\AjustesControllerFactory;
use Ligas\Controller\CategoriasController;
use Ligas\Controller\CategoriasControllerFactory;
use Ligas\Controller\FixtureController;
use Ligas\Controller\FixtureControllerFactory;
use Ligas\Controller\IndexController;
use Ligas\Controller\LogsController;
use Ligas\Controller\LogsControllerFactory;
use Ligas\Controller\NominasController;
use Ligas\Controller\NominasControllerFactory;
use Ligas\Controller\NotificacionesController;
use Ligas\Controller\NotificacionesControllerFactory;
use Ligas\Controller\RankingController;
use Ligas\Controller\RankingControllerFactory;
use Ligas\Controller\SedesController;
use Ligas\Controller\SedesControllerFactory;

$config = [
    'router' => [
        'routes' => [
            // ... (se completa abajo)
        ],
    ],
];

// Definir las secciones y sus acciones
$secciones = [
    'ajustes' => [
        'AjustesController',
        [
            'guardar' => ['guardar'],
            'edit' => ['edit', ['uuid' => '[a-f0-9\-]+']],
        ],
    ],
    'sedes' => [
        'SedesController',
        [
            'agregar-dia' => ['agregarDia', ['uuid' => '[a-f0-9\-]+']],
            'agregar-cancha' => ['agregarCancha'],
            'agregar-restricciones' => ['agregarRestricciones'],
            'desactivar' => ['desactivar', ['uuid' => '[a-f0-9\-]+']],
            'desactivar-cancha' => ['desactivarCancha', ['uuid' => '[a-f0-9\-]+']],
            'store-sede-lista' => ['storeSedeLista', ['uuid' => '[a-f0-9\-]+']],
            'update-sede-lista' => ['updateSedeLista', ['uuid' => '[a-f0-9\-]+', 'id' => '[a-zA-Z0-9_-]+']],
            'get-sede-lista' => ['getSedeLista', ['uuid' => '[a-f0-9\-]+']],
        ],
    ],
    'categorias' => [
        'CategoriasController',
        [
            'nuevacat' => ['nuevacat', ['uuid' => '[a-f0-9\-]+']],
            'actcat' => ['actcat'],
            'guardarestructura' => ['guardarestructura'],
            'eliminar-categoria' => ['eliminarCategoria', ['uuid' => '[a-f0-9\-]+']],
            'disponibles' => ['disponibles', ['uuid' => '[a-f0-9\-]+']],
            'agregar-acceso' => ['agregarAcceso', ['uuid' => '[a-f0-9\-]+']],
            'eliminar-acceso' => ['eliminarAcceso', ['uuid' => '[a-f0-9\-]+', 'id' => '[a-zA-Z0-9_-]+']],
            'updateColor' => ['updateColor'],
            'reglas' => ['reglas'],
            'calcularestructuras' => ['calcularestructuras'],
        ],
    ],
    'nominas' => [
        'NominasController',
        [
            'verificar-jugador' => ['verificarJugador', ['uuid' => '[a-f0-9\-]+']],
            'agregar-jugador' => ['agregarJugador', ['uuid' => '[a-f0-9\-]+']],
            'inscribir' => ['inscribir', ['uuid' => '[a-f0-9\-]+']],
            'editar' => ['editar', ['uuid' => '[a-f0-9\-]+']],
            'borrar' => ['borrar', ['uuid' => '[a-f0-9\-]+']],
            'ordenar' => ['ordenar', ['uuid' => '[a-f0-9\-]+']],
            'mover' => ['mover', ['uuid' => '[a-f0-9\-]+']],
            'obt_rstr' => ['obtRstr', ['uuid' => '[a-f0-9\-]+']],
            'g_rstr' => ['gRstr', ['uuid' => '[a-f0-9\-]+']],
            'act_marca' => ['actMarca', ['uuid' => '[a-f0-9\-]+']],
            'act_tel' => ['actTel', ['uuid' => '[a-f0-9\-]+']],
            'act_mail' => ['actMail', ['uuid' => '[a-f0-9\-]+']],
            'act_nombre' => ['actNombre', ['uuid' => '[a-f0-9\-]+']],
            'act_ident' => ['actIdent', ['uuid' => '[a-f0-9\-]+']],
            'act_rnk' => ['actRnk', ['uuid' => '[a-f0-9\-]+']],
            'buscar_usuario' => ['buscarUsuario', ['uuid' => '[a-f0-9\-]+']],
            'exportar-excel' => ['exportarExcel', ['categoria_uuid' => '[a-f0-9\-]+']],
            'marcas-listar' => ['marcasListar', ['uuid' => '[a-f0-9\-]+']],
            'marcas-guardar' => ['marcasGuardar', ['uuid' => '[a-f0-9\-]+']],
            'marcas-toggle' => ['marcasToggle', ['uuid' => '[a-f0-9\-]+']],
            'marcas-reset' => ['marcasReset', ['uuid' => '[a-f0-9\-]+']],
            'marcas-aplicar' => ['marcasAplicar', ['uuid' => '[a-f0-9\-]+']],
            'marcas-eliminar' => ['marcasEliminar', ['uuid' => '[a-f0-9\-]+']],
            'marcas-reset-general' => ['marcasResetGeneral', ['uuid' => '[a-f0-9\-]+']],
            'marcas-editar' => ['marcasUpdate', ['uuid' => '[a-f0-9\-]+']],
        ],
    ],
    'ranking' => [
        'RankingController',
        [
            'aplicar' => ['aplicar', ['uuid' => '[a-f0-9\-]+']],
            'desasociar' => ['desasociar', ['uuid' => '[a-f0-9\-]+']],
            'destroy' => ['destroy', ['uuid' => '[a-f0-9\-]+', 'id' => '[a-zA-Z0-9_-]+']],
            'store' => ['store', ['uuid' => '[a-f0-9\-]+']],
            'edit' => ['edit', ['ranking_uuid' => '[a-f0-9\-]+']],
            'asociar' => ['asociar', ['uuid' => '[a-f0-9\-]+']],
            'manual' => ['manual', ['uuid' => '[a-f0-9\-]+', 'ranking_uuid' => '[a-f0-9\-]+']],
        ],
    ],
    'fixture' => [
        'FixtureController',
        [
            'reiniciar-avanzado' => ['reiniciarAvanzado', ['uuid' => '[a-f0-9\-]+']],
            'links' => ['links'],
            'toggle' => ['toggle'],
            'programar-grupos' => ['programarGrupos'],
            'programar-eliminatorias' => ['programarEliminatorias'],
            'reset' => ['reset'],
            'reset-total' => ['resetTotal'],
            'ranking-grupos' => ['rankingGrupos'],
            'categorias' => ['categorias'],
            'agregar' => ['agregar'],
            'partidos' => ['partidos'],
            'validar-movimiento' => ['validarMovimiento'],
            'mover' => ['mover'],
            'iniciar' => ['iniciar'],
            'duelo' => ['duelo'],
            'resultado' => ['resultado'],
        ],
    ],
    'notificaciones' => [
        'NotificacionesController',
        [
            'obtener' => ['obtener', ['uuid' => '[a-f0-9\-]+']],
            'enviar' => ['enviar', ['uuid' => '[a-f0-9\-]+']],
            'enviar-individual' => ['enviarIndividual'],
            'notificar-todos' => ['notificarTodos', ['uuid' => '[a-f0-9\-]+']],
        ],
    ],
    'logs' => [
        'LogsController',
        [],
    ],
];

$routes = [];
$routes['ligas'] = [
    'type' => Segment::class,
    'options' => [
        'route' => '/ligas[/:deporte]',
        'defaults' => [
            'controller' => IndexController::class,
            'action' => 'index',
            'deporte' => 'padel',
        ],
    ],
];

foreach ($secciones as $seccion => $info) {
    $controllerClass = $info[0];
    $actions = $info[1];

    // Mapear a FQCN absoluto (con backslash inicial) para evitar resolucion
    // contra el namespace actual 'Ligas' del archivo de configuracion.
    $controllerFqcn = match ($controllerClass) {
        'IndexController' => IndexController::class,
        'AjustesController' => AjustesController::class,
        'SedesController' => SedesController::class,
        'CategoriasController' => CategoriasController::class,
        'NominasController' => NominasController::class,
        'RankingController' => RankingController::class,
        'FixtureController' => FixtureController::class,
        'NotificacionesController' => NotificacionesController::class,
        'LogsController' => LogsController::class,
        default => null,
    };

    // Ruta principal
    $routes['ligas.'.$seccion] = [
        'type' => Segment::class,
        'options' => [
            'route' => '/ligas/:deporte/'.$seccion,
            'defaults' => [
                'controller' => $controllerFqcn,
                'action' => 'index',
            ],
            'constraints' => ['deporte' => '[a-zA-Z]+'],
        ],
    ];

    foreach ($actions as $actionName => $actionInfo) {
        $actionMethod = $actionInfo[0];
        $extraParams = isset($actionInfo[1]) ? $actionInfo[1] : [];
        $extraPath = '';
        $extraConstraints = ['deporte' => '[a-zA-Z]+'];
        foreach ($extraParams as $name => $pattern) {
            $extraPath .= '/'.$name;
            $extraConstraints[$name] = $pattern;
        }

        $routes['ligas.'.$seccion.'.'.$actionName] = [
            'type' => Segment::class,
            'options' => [
                'route' => '/ligas/:deporte/'.$seccion.'/'.$actionName.$extraPath,
                'defaults' => [
                    'controller' => $controllerFqcn,
                    'action' => $actionMethod,
                ],
                'constraints' => $extraConstraints,
                'may_terminate' => true,
            ],
        ];
    }
}

$config['router']['routes'] = $routes;

$config['controllers'] = [
    'factories' => [
        'Ligas\\Controller\\IndexController' => InvokableFactory::class,
        'Ligas\\Controller\\AjustesController' => AjustesControllerFactory::class,
        'Ligas\\Controller\\SedesController' => SedesControllerFactory::class,
        'Ligas\\Controller\\CategoriasController' => CategoriasControllerFactory::class,
        'Ligas\\Controller\\NominasController' => NominasControllerFactory::class,
        'Ligas\\Controller\\RankingController' => RankingControllerFactory::class,
        'Ligas\\Controller\\FixtureController' => FixtureControllerFactory::class,
        'Ligas\\Controller\\NotificacionesController' => NotificacionesControllerFactory::class,
        'Ligas\\Controller\\LogsController' => LogsControllerFactory::class,
    ],
];

$config['view_manager'] = [
    'template_path_stack' => [
        __DIR__.'/../view',
    ],
];

return $config;
