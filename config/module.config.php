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
                            // ── Dashboard layout (GET=load, POST=save) ──────────
                            'dashboard-layout' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/dashboard/layout[/]',
                                    'defaults' => ['action' => 'dashboardLayout'],
                                ],
                            ],
                            // ── Modules (MelisCore tool : activation + ordre) ──
                            'modules-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/modules[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiModules',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'modules-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/modules/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiModules',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            // ── Other Config (MelisCore tool : politique connexion/mot de passe) ──
                            'otherconfig-get' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/otherconfig[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiOtherConfig',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            'otherconfig-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/otherconfig/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiOtherConfig',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            // ── Platform Scheme (MelisCore tool : thème / couleurs du BO) ──
                            'platformscheme-get' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platformscheme[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatformScheme',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            'platformscheme-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platformscheme/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatformScheme',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'platformscheme-reset' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platformscheme/reset[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatformScheme',
                                        'action'        => 'reset',
                                    ],
                                ],
                            ],
                            // ── Thème du back-office REACT (interface distincte du legacy) ──
                            'platformscheme-react-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platformscheme-react/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatformSchemeReact',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'platformscheme-react-get' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platformscheme-react[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatformSchemeReact',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Auth publique (i18n / mot de passe oublié / réinitialisation) ──
                            // Ces routes sont exclues de l'auth (cf. melis-core/config/excluded.routes.php)
                            'auth-i18n' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/i18n[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAuth',
                                        'action'        => 'i18n',
                                    ],
                                ],
                            ],
                            'auth-forgot-password' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/forgot-password[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAuth',
                                        'action'        => 'forgotPassword',
                                    ],
                                ],
                            ],
                            'auth-reset-password' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/reset-password[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAuth',
                                        'action'        => 'resetPassword',
                                    ],
                                ],
                            ],
                            // ── Sites (MelisCms tool : liste + meta tunnel création) ──
                            'cms-sites-meta' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-sites/meta[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'meta',
                                    ],
                                ],
                            ],
                            'cms-sites-create' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-sites/create[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'create',
                                    ],
                                ],
                            ],
                            'cms-sites-config' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-sites/:id/config[/]',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'config',
                                    ],
                                ],
                            ],
                            'cms-sites-modules' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-sites/:id/modules[/]',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'modules',
                                    ],
                                ],
                            ],
                            'cms-sites-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-sites/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            'cms-sites-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-sites[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSites',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            // ── GDPR / RGPD (MelisCore tool : droits des personnes) ──
                            'gdpr-search' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/gdpr/search[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiGdpr',
                                        'action'        => 'search',
                                    ],
                                ],
                            ],
                            'gdpr-extract' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/gdpr/extract[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiGdpr',
                                        'action'        => 'extract',
                                    ],
                                ],
                            ],
                            'gdpr-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/gdpr/delete[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiGdpr',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            // GDPR › SMTP
                            'gdpr-smtp-get' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/smtp[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'smtpGet']],
                            ],
                            'gdpr-smtp-save' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/smtp/save[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'smtpSave']],
                            ],
                            'gdpr-smtp-delete' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/smtp/delete[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'smtpDelete']],
                            ],
                            // GDPR › Banners
                            'gdpr-banner-meta' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/banner/meta[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'bannerMeta']],
                            ],
                            'gdpr-banner-save' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/banner/save[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'bannerSave']],
                            ],
                            'gdpr-banner-get' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/banner[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'bannerGet']],
                            ],
                            // GDPR › Auto-Delete
                            'gdpr-ad-meta' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/meta[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adMeta']],
                            ],
                            'gdpr-ad-configs' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/configs[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adConfigs']],
                            ],
                            'gdpr-ad-config-save' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/config/save[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adSave']],
                            ],
                            'gdpr-ad-config-delete' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/config/delete[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adDelete']],
                            ],
                            'gdpr-ad-config-get' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/config[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adConfig']],
                            ],
                            'gdpr-ad-run' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/run[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adRun']],
                            ],
                            'gdpr-ad-logs' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/gdpr/autodelete/logs[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiGdpr', 'action' => 'adLogs']],
                            ],
                            // ── Emails Management (MelisCore tool) ─────────────
                            'emails-list' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/emails[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiEmails', 'action' => 'list']],
                            ],
                            'emails-save' => [
                                'type' => 'Segment',
                                'priority' => 100, // matche avant la route générique /emails/:codename
                                'options' => ['route' => '/emails/save[/]', 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiEmails', 'action' => 'save']],
                            ],
                            'emails-delete' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/emails/delete/:codename', 'constraints' => ['codename' => '[\w]+'], 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiEmails', 'action' => 'delete']],
                            ],
                            'emails-get' => [
                                'type' => 'Segment',
                                'options' => ['route' => '/emails/:codename', 'constraints' => ['codename' => '[\w]+'], 'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiEmails', 'action' => 'get']],
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
                            // ── Platforms (MelisCore tool) ────────────────────
                            'platforms-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platforms[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatform',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'platforms-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platforms/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatform',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'platforms-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/platforms/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatform',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'platforms-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/platforms/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatform',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'platforms-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/platforms/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiPlatform',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Languages (MelisCore tool) ────────────────────
                            'languages-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/languages[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLanguage',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'languages-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/languages/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLanguage',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'languages-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/languages/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLanguage',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'languages-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/languages/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLanguage',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'languages-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/languages/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLanguage',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Logs (MelisCore tool, lecture seule) ──────────
                            'logs-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/logs[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLog',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'logs-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/logs/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLog',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'logs-filters' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/logs/filters[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiLog',
                                        'action'        => 'filters',
                                    ],
                                ],
                            ],
                            // ── Announcements (MelisCore tool) ────────────────
                            'announcements-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/announcements[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAnnouncement',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'announcements-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/announcements/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAnnouncement',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'announcements-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/announcements/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAnnouncement',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'announcements-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/announcements/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAnnouncement',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'announcements-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/announcements/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiAnnouncement',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Site Redirects 301 (MelisCms tool, UI via brick) ──
                            'site-redirects-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/site-redirects[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'site-redirects-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/site-redirects/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'site-redirects-sites' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/site-redirects/sites[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'sites',
                                    ],
                                ],
                            ],
                            'site-redirects-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/site-redirects/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'site-redirects-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/site-redirects/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'site-redirects-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/site-redirects/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiSiteRedirect',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Slider (MelisCmsSlider tool, UI via brick) — slider > slides > slide ──
                            // Ordre : routes spécifiques AVANT la catch-all /sliders/:id.
                            'sliders-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'sliders-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'sliders-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],
                            'sliders-slides-reorder' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders/slides/reorder[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'reorder',
                                    ],
                                ],
                            ],
                            'sliders-slide-upload' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders/slide/upload[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'slideUpload',
                                    ],
                                ],
                            ],
                            'sliders-slide-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/sliders/slide/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'slideSave',
                                    ],
                                ],
                            ],
                            'sliders-slide-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/sliders/slide/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'slideDelete',
                                    ],
                                ],
                            ],
                            'sliders-slide-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/sliders/slide/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'slideGet',
                                    ],
                                ],
                            ],
                            'sliders-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/sliders/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'sliders-slides' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/sliders/:id/slides[/]',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'slides',
                                    ],
                                ],
                            ],
                            'sliders-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/sliders/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiCmsSlider',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            // ── Templates (MelisCms tool, UI via brick ; create/edit legacy) ──
                            'templates-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/templates[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'list',
                                    ],
                                ],
                            ],
                            'templates-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/templates/stats[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'stats',
                                    ],
                                ],
                            ],
                            'templates-sites' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/templates/sites[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'sites',
                                    ],
                                ],
                            ],
                            'templates-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/templates/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'delete',
                                    ],
                                ],
                            ],
                            'templates-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/templates/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'get',
                                    ],
                                ],
                            ],
                            'templates-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/templates/save[/]',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'MelisReactApi\Controller',
                                        'controller'    => 'MelisReactApiTemplate',
                                        'action'        => 'save',
                                    ],
                                ],
                            ],

                            // ── CMS Languages (MelisCms tool, UI via brick) ──
                            'cms-languages-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-languages[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsLanguage', 'action' => 'list'],
                                ],
                            ],
                            'cms-languages-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-languages/stats[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsLanguage', 'action' => 'stats'],
                                ],
                            ],
                            'cms-languages-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-languages/save[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsLanguage', 'action' => 'save'],
                                ],
                            ],
                            'cms-languages-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-languages/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsLanguage', 'action' => 'delete'],
                                ],
                            ],
                            'cms-languages-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-languages/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsLanguage', 'action' => 'get'],
                                ],
                            ],

                            // ── CMS Platforms IDs (MelisCms tool, UI via brick) ──
                            'cms-platform-ids-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-platform-ids[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsPlatformId', 'action' => 'list'],
                                ],
                            ],
                            'cms-platform-ids-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-platform-ids/stats[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsPlatformId', 'action' => 'stats'],
                                ],
                            ],
                            'cms-platform-ids-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-platform-ids/save[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsPlatformId', 'action' => 'save'],
                                ],
                            ],
                            'cms-platform-ids-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-platform-ids/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsPlatformId', 'action' => 'delete'],
                                ],
                            ],
                            'cms-platform-ids-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-platform-ids/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsPlatformId', 'action' => 'get'],
                                ],
                            ],

                            // ── CMS Styles (MelisCms tool, UI via brick) ──
                            'cms-styles-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-styles[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'list'],
                                ],
                            ],
                            'cms-styles-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-styles/stats[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'stats'],
                                ],
                            ],
                            'cms-styles-sites' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-styles/sites[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'sites'],
                                ],
                            ],
                            'cms-styles-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-styles/save[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'save'],
                                ],
                            ],
                            'cms-styles-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-styles/delete/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'delete'],
                                ],
                            ],
                            'cms-styles-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'       => '/cms-styles/:id',
                                    'constraints' => ['id' => '[0-9]+'],
                                    'defaults'    => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsStyle', 'action' => 'get'],
                                ],
                            ],

                            // ── CMS Mini-Template Manager (MelisCms tool, UI via brick) ──
                            // Identificateur composite (site_module + template_name) → pas de :id numérique.
                            // item / delete utilisent query params ou body JSON pour la clé composite.
                            'cms-mini-templates-list' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'list'],
                                ],
                            ],
                            'cms-mini-templates-stats' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates/stats[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'stats'],
                                ],
                            ],
                            'cms-mini-templates-sites' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates/sites[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'sites'],
                                ],
                            ],
                            'cms-mini-templates-item' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates/item[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'item'],
                                ],
                            ],
                            'cms-mini-templates-save' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates/save[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'save'],
                                ],
                            ],
                            'cms-mini-templates-delete' => [
                                'type'    => 'Segment',
                                'options' => [
                                    'route'    => '/cms-mini-templates/delete[/]',
                                    'defaults' => ['__NAMESPACE__' => 'MelisReactApi\Controller', 'controller' => 'MelisReactApiCmsMiniTemplate', 'action' => 'delete'],
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
            'MelisReactApi\Controller\MelisReactApiUser'     => \MelisReactApi\Controller\MelisReactApiUserController::class,
            'MelisReactApi\Controller\MelisReactApiModules'  => \MelisReactApi\Controller\MelisReactApiModulesController::class,
            'MelisReactApi\Controller\MelisReactApiOtherConfig' => \MelisReactApi\Controller\MelisReactApiOtherConfigController::class,
            'MelisReactApi\Controller\MelisReactApiPlatformScheme' => \MelisReactApi\Controller\MelisReactApiPlatformSchemeController::class,
            'MelisReactApi\Controller\MelisReactApiPlatformSchemeReact' => \MelisReactApi\Controller\MelisReactApiPlatformSchemeReactController::class,
            'MelisReactApi\Controller\MelisReactApiCmsSites' => \MelisReactApi\Controller\MelisReactApiCmsSitesController::class,
            'MelisReactApi\Controller\MelisReactApiGdpr'     => \MelisReactApi\Controller\MelisReactApiGdprController::class,
            'MelisReactApi\Controller\MelisReactApiEmails'   => \MelisReactApi\Controller\MelisReactApiEmailsController::class,
            'MelisReactApi\Controller\MelisReactApiPlatform' => \MelisReactApi\Controller\MelisReactApiPlatformController::class,
            'MelisReactApi\Controller\MelisReactApiLanguage' => \MelisReactApi\Controller\MelisReactApiLanguageController::class,
            'MelisReactApi\Controller\MelisReactApiLog'      => \MelisReactApi\Controller\MelisReactApiLogController::class,
            'MelisReactApi\Controller\MelisReactApiAnnouncement' => \MelisReactApi\Controller\MelisReactApiAnnouncementController::class,
            'MelisReactApi\Controller\MelisReactApiSiteRedirect' => \MelisReactApi\Controller\MelisReactApiSiteRedirectController::class,
            'MelisReactApi\Controller\MelisReactApiTemplate'     => \MelisReactApi\Controller\MelisReactApiTemplateController::class,
            'MelisReactApi\Controller\MelisReactApiCmsLanguage'   => \MelisReactApi\Controller\MelisReactApiCmsLanguageController::class,
            'MelisReactApi\Controller\MelisReactApiCmsPlatformId' => \MelisReactApi\Controller\MelisReactApiCmsPlatformIdController::class,
            'MelisReactApi\Controller\MelisReactApiCmsStyle'      => \MelisReactApi\Controller\MelisReactApiCmsStyleController::class,
            'MelisReactApi\Controller\MelisReactApiAuth'           => \MelisReactApi\Controller\MelisReactApiAuthController::class,
            'MelisReactApi\Controller\MelisReactApiCmsMiniTemplate'   => \MelisReactApi\Controller\MelisReactApiCmsMiniTemplateController::class,
            'MelisReactApi\Controller\MelisReactApiCmsSlider'      => \MelisReactApi\Controller\MelisReactApiCmsSliderController::class,
        ],
    ],
    // La déclaration des capacités d'outils vit dans un fichier SÉPARÉ par module
    // (`<module>/config/react.capabilities.php`, clé mergée `melisReactToolCapabilities`),
    // pas ici — cf. MelisReactApi\Service\Capabilities. Annonces → melis-core/config/react.capabilities.php.
];
