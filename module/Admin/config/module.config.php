<?php

declare(strict_types=1);

namespace Admin;

use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

$adminChildRoutes = [
    'asignar-rol' => [
        'type' => Literal::class,
        'options' => [
            'route' => '/asignar-rol',
            'defaults' => ['action' => 'diosAsignarRol'],
        ],
    ],
    'update' => [
        'type' => Segment::class,
        'options' => [
            'route' => '/update/:user',
            'defaults' => ['action' => 'diosUpdate'],
        ],
    ],
    'destroy' => [
        'type' => Segment::class,
        'options' => [
            'route' => '/destroy/:user',
            'defaults' => ['action' => 'diosDestroy'],
        ],
    ],
    'whatsapp_destroy' => [
        'type' => Segment::class,
        'options' => [
            'route' => '/whatsapp/destroy/:id',
            'defaults' => ['action' => 'diosWhatsappDestroy'],
        ],
    ],
    'buscar_usuario' => [
        'type' => Literal::class,
        'options' => [
            'route' => '/buscar_usuario',
            'defaults' => ['action' => 'diosBuscarUsuario'],
        ],
    ],
];

return [
    'router' => [
        'routes' => [
            'admin.moderadores' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/moderadores',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'moderadores',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => $adminChildRoutes,
            ],
            'admin.ceo.admins' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/administradores',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'ceoAdmins',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => $adminChildRoutes,
            ],
            // Alias para mantener compatibilidad con URL legacy /admin/ceo/admins
            'admin.ceo.admins.alias' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/admins',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'ceoAdmins',
                    ],
                ],
            ],
            'admin.ceo.estadisticas' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/estadisticas',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'estadisticas'],
                ],
            ],
            'admin.ceo.usuarios' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/usuarios',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'usuarios'],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'buscar_usuario' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/buscar_usuario',
                            'defaults' => ['action' => 'buscarUsuario'],
                        ],
                    ],
                    'modal' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:user/modal',
                            'defaults' => ['action' => 'modalUsuario'],
                        ],
                    ],
                    'update' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:user/update',
                            'defaults' => ['action' => 'updateUsuario'],
                        ],
                    ],
                ],
            ],
            'admin.ceo.accesos' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/accesos',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'accesos'],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'listar' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/listar',
                            'defaults' => ['action' => 'listarAccesos'],
                        ],
                    ],
                    'otorgar' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/otorgar',
                            'defaults' => ['action' => 'otorgarAcceso'],
                        ],
                    ],
                    'buscar-por-deporte' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/deportes/:deporte_uuid/accesos',
                            'defaults' => ['action' => 'buscarAccesosPorDeporte'],
                        ],
                    ],
                ],
            ],
            'admin.ceo.categorias' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/categorias',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'categorias'],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'listar' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/listar/:alias',
                            'defaults' => ['action' => 'listarCategorias'],
                        ],
                    ],
                    'store' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/store',
                            'defaults' => ['action' => 'storeCategoria'],
                        ],
                    ],
                    'update' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/update/:uuid',
                            'defaults' => ['action' => 'updateCategoria'],
                        ],
                    ],
                    'orden' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/orden',
                            'defaults' => ['action' => 'guardarOrdenCategoria'],
                        ],
                    ],
                ],
            ],
            'admin.ceo.wapi' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/ceo/wapi',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'wapiBot'],
                ],
            ],
            'admin.dios.ceo' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/dios/ceo',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'diosCeo'],
                ],
                'may_terminate' => true,
                'child_routes' => $adminChildRoutes,
            ],
            'admin.dios.dios' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/dios/dios',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'diosDios'],
                ],
                'may_terminate' => true,
                'child_routes' => $adminChildRoutes,
            ],
            'admin.dios.deportes' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/dios/deportes',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'categorias'],
                ],
            ],
            'admin.dios.accesos' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/admin/dios/accesos',
                    'defaults' => ['controller' => Controller\IndexController::class, 'action' => 'accesos'],
                ],
            ],
            'admin' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/admin[/:action]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => function ($container) {
                $db = $container->get(Adapter::class);

                return new Controller\IndexController($db);
            },
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__.'/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
