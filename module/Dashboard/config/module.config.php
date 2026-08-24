<?php

declare(strict_types=1);

namespace Dashboard;

use Laminas\Router\Http\Literal;

return [
    'router' => [
        'routes' => [
            'dashboard' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/dashboard',
                    'defaults' => [
                        'controller' => Controller\DashboardController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\DashboardController::class => Controller\DashboardControllerFactory::class,
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'template_map' => [
            'dashboard/dashboard/index' => __DIR__ . '/../view/dashboard/dashboard/index.phtml',
        ],
    ],
];
