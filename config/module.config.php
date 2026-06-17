<?php

return [
    'router' => [
        'routes' => [
            'melis-backoffice' => [
                'child_routes' => [
                    'melis-react-api' => [
                        'type'          => 'Literal',
                        'options'       => [
                            'route'    => 'react-api',
                            'defaults' => [
                                '__NAMESPACE__' => 'MelisReactApi\Controller',
                                'controller'    => 'MelisReactApi',
                                'action'        => 'me',
                            ],
                        ],
                        'may_terminate' => false,
                        'child_routes'  => [
                            // ── Assets ───────────────────────────────────────
                            'assets' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/assets[/]',
                                    'defaults' => ['action' => 'assets'],
                                ],
                            ],
                            // ── Auth / Menu ──────────────────────────────────
                            'me' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/me[/]',
                                    'defaults' => ['action' => 'me'],
                                ],
                            ],
                            'menu' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/menu[/]',
                                    'defaults' => ['action' => 'menu'],
                                ],
                            ],
                            // ── Back-office languages + current session locale ─
                            'langs' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/langs[/]',
                                    'defaults' => ['action' => 'langs'],
                                ],
                            ],
                            // ── React bricks discovery (modular UI) ──────────
                            'react-modules' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/react-modules[/]',
                                    'defaults' => ['action' => 'reactModules'],
                                ],
                            ],
                            // ── Dashboard top bubbles (News/Updates/Notif/Msg) ─
                            'dashboard-bubbles' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/dashboard/bubbles[/]',
                                    'defaults' => ['action' => 'dashboardBubbles'],
                                ],
                            ],
                            // ── Roles (MelisCore user roles) ──────────────────
                            'roles' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/roles[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'roles',
                                    ],
                                ],
                            ],
                            // ── Users (MelisCore tool) ────────────────────────
                            'users-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/users[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'users-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/users/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'users-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/users/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'users-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/users/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'users-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/users/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            'users-connections' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/users/:id/connections',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'connections',
                                    ],
                                ],
                            ],
                            'users-microservice' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/users/:id/microservice',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'microservice',
                                    ],
                                ],
                            ],
                            'users-microservice-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/users/:id/microservice/save',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiUser',
                                        'action'        => 'microserviceSave',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'controllers' => [
        'invokables' => [
            'MelisReactApi\Controller\MelisReactApi'     => \MelisReactApi\Controller\MelisReactApiController::class,
            'MelisReactApi\Controller\MelisReactApiUser' => \MelisReactApi\Controller\MelisReactApiUserController::class,
        ],
    ],
];
