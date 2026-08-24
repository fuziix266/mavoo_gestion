<?php

declare(strict_types=1);

namespace Whatsapp;

return [
    'controllers' => [
        'factories' => [
            Controller\AuthController::class => Controller\Factory\AuthControllerFactory::class,
            Controller\MetaWebhookController::class => Controller\Factory\MetaWebhookControllerFactory::class,
            Controller\WapiWebhookController::class => Controller\Factory\WapiWebhookControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Model\WhatsappLoginTable::class => Model\Factory\WhatsappLoginTableFactory::class,
            Model\WapiLidTable::class       => Model\Factory\WapiLidTableFactory::class,
            Model\ApiWhatsappTable::class   => Model\Factory\ApiWhatsappTableFactory::class,
            Model\WapiChatTable::class      => Model\Factory\WapiChatTableFactory::class,
            
            Service\MetaApiService::class      => Service\Factory\MetaApiServiceFactory::class,
            Service\WapiService::class         => Service\Factory\WapiServiceFactory::class,
            Service\WhatsappAuthService::class => Service\Factory\WhatsappAuthServiceFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'api.whatsapp.meta.webhook' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/api/whatsapp/meta/webhook',
                    'defaults' => [
                        'controller' => Controller\MetaWebhookController::class,
                    ],
                ],
            ],
            'api.whatsapp.baileys.webhook' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/api/whatsapp/baileys/webhook',
                    'defaults' => [
                        'controller' => Controller\WapiWebhookController::class,
                        'action'     => 'handle',
                    ],
                ],
            ],
            'api.whatsapp.auth.wapilogin' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/api/whatsapp/auth/wapilogin',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'wapiLogin',
                    ],
                ],
            ],
            'api.whatsapp.auth.wapilogin2' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'options' => [
                    'route' => '/api/whatsapp/auth/wapilogin2',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'wapiLogin2',
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
