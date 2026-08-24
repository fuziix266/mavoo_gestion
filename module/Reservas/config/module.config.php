<?php

declare(strict_types=1);

namespace Reservas;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

return [
    'router' => [
        'routes' => [
            'reservas' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/reservas[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id' => '[a-zA-Z0-9_-]+',
                    ],
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'principal',
                    ],
                ],
            ],
            'mod.arriendo.principal' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/principal',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'principal',
                    ],
                ],
            ],
            'mod.arriendo.dashboard' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/dashboard',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'dashboard',
                    ],
                ],
            ],
            'mod.arriendo.dashboard.data' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/dashboard/data',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'dashboardData',
                    ],
                ],
            ],
            'mod.arriendo.dashboard.export' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/dashboard/export',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'dashboardExport',
                    ],
                ],
            ],
            'mod.arriendo.galeria.upload' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/galeria/upload',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'galeriaUpload',
                    ],
                ],
            ],
            'mod.arriendo.galeria.delete' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/galeria/delete',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'galeriaDelete',
                    ],
                ],
            ],
            'mod.arriendo.qr.generate' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/qr/generate',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'qrGenerate',
                    ],
                ],
            ],
            'mod.arriendo.permisos.save' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/permisos/save',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'permisosSave',
                    ],
                ],
            ],
            'mod.arriendo.store' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/mod/arriendo/store',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'store',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => Controller\IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__.'/../view',
        ],
    ],
];
