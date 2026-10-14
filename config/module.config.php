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
                            // ── React bricks: ALL active modules' bundles, concatenated ──
                            // One request instead of ~50 (each per-brick asset URL costs a full
                            // Melis bootstrap). Literal `.js` route so Apache/mod_deflate gzips it
                            // and the browser treats it as a script.
                            'bricks-bundle' => [
                                'type'    => 'Literal',
                                'options' => [
                                    'route'    => '/bricks-bundle.js',
                                    'defaults' => ['action' => 'bricksBundle'],
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
                            // ── Dashboard KPI stats + recent activity ──────────
                            'dashboard-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/dashboard/stats[/]',
                                    'defaults' => ['action' => 'dashboardStats'],
                                ],
                            ],
                            // ── Legacy dashboard plugins discovery ──────────────
                            'dashboard-legacy-plugins' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/dashboard/legacy-plugins[/]',
                                    'defaults' => ['action' => 'legacyDashboardPlugins'],
                                ],
                            ],
                            // ── Dashboard plugins for the user-RIGHTS editor (authoritative list,
                            //    module-ordered) — distinct from the dashboard display palette above ──
                            'rights-dashboard-plugins' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/rights/dashboard-plugins[/]',
                                    'defaults' => ['action' => 'rightsDashboardPlugins'],
                                ],
                            ],
                            // ── Capacités d'outils déclarées (pour l'éditeur de droits) ──
                            'rights-capabilities' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/rights/capabilities[/]',
                                    'defaults' => ['action' => 'rightsCapabilities'],
                                ],
                            ],
                            // ── Catalogue des microservices déclarés (section « API / microservices »
                            //    de l'éditeur de droits — un seul droit d'accès par service, audit C4) ──
                            'rights-microservices' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/rights/microservices[/]',
                                    'defaults' => ['action' => 'rightsMicroservices'],
                                ],
                            ],
                            // ── Dashboard layout (GET=load, POST=save) ──────────
                            'dashboard-layout' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/dashboard/layout[/]',
                                    'defaults' => ['action' => 'dashboardLayout'],
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
            'MelisReactApi\Controller\MelisReactApi'         => \MelisReactApi\Controller\MelisReactApiController::class,
        ],
    ],
    // La déclaration des capacités d'outils vit dans un fichier SÉPARÉ par module
    // (`<module>/config/react.capabilities.php`, clé mergée `melisReactToolCapabilities`),
    // pas ici — cf. MelisReactApi\Service\Capabilities. Annonces → melis-core/config/react.capabilities.php.
];
