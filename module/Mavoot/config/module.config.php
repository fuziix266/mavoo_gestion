<?php

namespace Mavoot;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

return [
    'router' => [
        'routes' => [
            'mavoot-api' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '[/api]/mavoot',
                    'defaults' => [
                        'controller' => Controller\MavootApiController::class,
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'send-message' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/send-message',
                            'defaults' => [
                                'action' => 'sendMessage',
                            ],
                        ],
                    ],
                    'latest-response' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/latest-response',
                            'defaults' => [
                                'action' => 'latestResponse',
                            ],
                        ],
                    ],
                    'pending-jobs' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/pending-jobs',
                            'defaults' => [
                                'action' => 'pendingJobs',
                            ],
                        ],
                    ],
                    'claim' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:id/claim',
                            'constraints' => ['id' => '[0-9]+'],
                            'defaults' => [
                                'action' => 'claim',
                            ],
                        ],
                    ],
                    'context' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/context/:session_id',
                            'constraints' => ['session_id' => '[0-9]+'],
                            'defaults' => [
                                'action' => 'context',
                            ],
                        ],
                    ],
                    'response' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/response',
                            'defaults' => [
                                'action' => 'saveResponse',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\MavootApiController::class => function ($container) {
                $sessionTable = $container->get(Model\MavootSessionTable::class);
                $messageTable = $container->get(Model\MavootMessageTable::class);

                return new Controller\MavootApiController($sessionTable, $messageTable);
            },
        ],
    ],
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
