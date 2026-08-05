<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;
use MelisReactApi\Service\Capabilities;

/**
 * API JSON pour le backoffice React.
 *
 * Toutes les actions retournent directement un objet Response avec
 * Content-Type: application/json pour éviter que le système de vues
 * Laminas/Melis essaie de rendre un layout autour de la réponse.
 *
 * Routes :
 *   GET /melis/react-api/me      → utilisateur courant
 *   GET /melis/react-api/menu    → arbre de navigation complet (dynamique, droits-filtré)
 */
class MelisReactApiController extends MelisAbstractActionController
{
    // ─── /melis/react-api/me ─────────────────────────────────────────────────

    public function meAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Free the PHP session lock BEFORE the (potentially slow) capabilities computation so this
        // read-only endpoint doesn't serialise every other request sharing the session cookie
        // (see releaseSessionLock()).
        $this->releaseSessionLock();

        $user = $this->getAuthStorage();

        // Current user's profile picture (usr_image blob) as a data URI, like the legacy header.
        // + capacités d'outils effectives (droits avancés React) ; admin ⇒ tout permis.
        $picture      = null;
        $isAdmin      = false;
        $capabilities = [];
        try {
            $sm       = $this->getServiceManager();
            $auth     = $sm->get('MelisCoreAuth');
            $identity = $auth->getIdentity();
            if (!empty($identity->usr_image)) {
                $picture = 'data:image/jpeg;base64,' . base64_encode($identity->usr_image);
            }
            $isAdmin      = !empty($identity->usr_admin);
            if ($isAdmin) {
                // Admin ⇒ tout permis (aucun droit à résoudre).
                $capabilities = Capabilities::allowedForUser($sm->get('config'), null);
            } else {
                // Sert les capacités depuis le cache de droits résolu (même choke point que le menu) ;
                // fallback sur le calcul live si le cache est indisponible.
                $resolved = null;
                try { $resolved = $sm->get('MelisCoreRights')->getResolvedRights(); } catch (\Throwable) {}
                $capabilities = (is_array($resolved) && isset($resolved['caps']) && is_array($resolved['caps']))
                    ? $resolved['caps']
                    : Capabilities::allowedForUser($sm->get('config'), $auth->getAuthRights());
            }
        } catch (\Throwable) {
        }

        return $this->jsonResponse([
            'success' => true,
            'data'    => [
                'id'           => (int) ($user->usr_id ?? 0),
                'name'         => trim(($user->usr_firstname ?? '') . ' ' . ($user->usr_lastname ?? '')),
                'login'        => $user->usr_login ?? '',
                'email'        => $user->usr_email ?? '',
                'picture'      => $picture,
                'isAdmin'      => $isAdmin,
                'capabilities' => (object) $capabilities,
            ],
        ]);
    }

    // ─── /melis/react-api/rights/capabilities ────────────────────────────────

    /**
     * Capacités d'outils DÉCLARÉES par les modules (carte melisKey → arbre caps) — alimente
     * l'éditeur de droits (cases List/Create/Edit/Delete + onglets). Réservé aux gestionnaires
     * de droits. Les `label` d'onglets (clés `tr_...`) sont traduits dans la locale courante ici
     * (via MelisCoreTranslation) — le module déclare des CLÉS, jamais du texte en dur.
     */
    public function rightsCapabilitiesAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }
        try {
            $declared = Capabilities::declared($this->getServiceManager()->get('config'));
            $transSvc = null;
            try { $transSvc = $this->getServiceManager()->get('MelisCoreTranslation'); } catch (\Throwable) {}
            // getMessage() defaults to 'en_EN' when no locale is passed — read the current
            // session locale explicitly (same source langsAction() uses) so tab labels follow
            // the admin's actual back-office language instead of always resolving to English.
            $localeContainer = new \Laminas\Session\Container('meliscore');
            $currentLocale   = (string) ($localeContainer['melis-lang-locale'] ?? 'en_EN');
            $out = [];
            foreach ($declared as $key => $val) {
                $out[$key] = $this->translateCapLabels($val, $transSvc, $currentLocale);
            }
            return $this->jsonResponse(['success' => true, 'data' => (object) $out]);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Traduit récursivement les `label` (clés `tr_...`) d'un nœud de capacités ; laisse les listes plates intactes. */
    private function translateCapLabels($node, $transSvc, $locale = 'en_EN')
    {
        // Scalaire, ou liste plate d'actions (indices séquentiels) → rien à traduire.
        if (!is_array($node) || array_values($node) === $node) {
            return $node;
        }
        if (isset($node['label']) && is_string($node['label']) && str_starts_with($node['label'], 'tr_') && $transSvc) {
            try { $t = (string) $transSvc->getMessage($node['label'], $locale); if ($t !== '') { $node['label'] = $t; } } catch (\Throwable) {}
        }
        // Actions labellisées `{key,label}` (nouveau) : traduire leur `label` tr_. Les actions en
        // chaîne simple (rétro-compat) restent intactes (label dérivé côté React via i18n générique).
        if (isset($node['actions']) && is_array($node['actions'])) {
            $node['actions'] = array_map(function ($a) use ($transSvc, $locale) {
                if (is_array($a) && isset($a['label']) && is_string($a['label']) && str_starts_with($a['label'], 'tr_') && $transSvc) {
                    try { $t = (string) $transSvc->getMessage($a['label'], $locale); if ($t !== '') { $a['label'] = $t; } } catch (\Throwable) {}
                }
                return $a;
            }, $node['actions']);
        }
        if (isset($node['tabs']) && is_array($node['tabs'])) {
            $node['tabs'] = array_map(
                fn ($tab) => is_string($tab) ? $tab : $this->translateCapLabels($tab, $transSvc, $locale),
                $node['tabs']
            );
        }
        return $node;
    }

    // ─── /melis/react-api/langs ──────────────────────────────────────────────

    /**
     * Back-office languages + the current session locale, for the React language switcher.
     * Changing language is done via the existing /melis/change-language?langId=X (sets the
     * session locale + the user's usr_lang_id + clears the config cache); the React app then
     * reloads so the whole platform (menu, tools, iframes) follows the new locale.
     */
    public function langsAction(): HttpResponse
    {
        // Endpoint PUBLIC (pas de garde d'auth) : le SPA charge les langues au boot, y compris sur
        // l'écran de LOGIN (sélecteur de langue) — avant authentification. Renvoyer 401 ici polluait
        // la console de 401 au chargement du login (ticket 0010874). La liste des langues du BO n'est
        // pas sensible ; hors session on retombe simplement sur la locale par défaut.
        $container     = new \Laminas\Session\Container('meliscore');
        $currentLocale = (string) ($container['melis-lang-locale'] ?? 'en_EN');
        $currentId     = (int) ($container['melis-lang-id'] ?? 0);

        // Lecture seule : on LIBÈRE le verrou d'écriture de session AVANT la requête + la réponse.
        // Sinon /langs (appelé au boot en même temps que /me, /menu…) sérialise sur le verrou de
        // session PHP → réponse lente/racée pendant le boot → le sélecteur de langue peut manquer.
        $this->releaseSessionLock();

        $langs = [];
        try {
            $rows = $this->getServiceManager()->get('MelisCoreTableLang')->fetchAll()->toArray();
            foreach ($rows as $r) {
                $locale = (string) ($r['lang_locale'] ?? '');
                if ($locale === '') {
                    continue;
                }
                $langs[] = [
                    'id'     => (int) ($r['lang_id'] ?? 0),
                    'locale' => $locale,
                    'short'  => explode('_', $locale)[0],
                    'label'  => (string) ($r['lang_name'] ?? $locale),
                ];
            }
        } catch (\Throwable) {
        }

        return $this->jsonResponse([
            'success' => true,
            'data'    => [
                'current' => ['id' => $currentId, 'locale' => $currentLocale],
                'langs'   => $langs,
            ],
        ]);
    }

    // ─── /melis/react-api/assets ─────────────────────────────────────────────

    /**
     * Returns the CSS/JS asset URLs and inline JS globals the React app needs
     * to bootstrap Melis tool iframes — identical to what layoutCore.phtml loads.
     */
    public function assetsAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        try {
            $assets = \MelisReactOverride\Service\PlatformAssetsService::build($this->getServiceManager());
            return $this->jsonResponse(['success' => true, 'data' => $assets]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => array_map(
                    fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''),
                    array_slice($e->getTrace(), 0, 8)
                ),
            ], 500);
        }
    }

    // ─── /melis/react-api/react-modules ──────────────────────────────────────

    /**
     * Lists the ACTIVE Melis modules that ship a React brick.
     *
     * A module contributes a brick by placing `public/ui-react/brick.manifest.json`
     * in its package. Only modules currently loaded (in melis.module.load.php /
     * component loaders) are enumerated, so a brick is exposed *if and only if* its
     * module is active — that is what makes the React UI modular.
     *
     * Each entry: { id, module, route, label, forwardKey, melisKey, bundleUrl }.
     * The React shell loads each bundleUrl at runtime and registers its route +
     * forwardKey→route mapping (so the dynamic backend menu links to the React page).
     */
    public function reactModulesAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        try {
            $modulesSrv = new \MelisAssetManager\Service\MelisModulesService();
            $active     = $modulesSrv->getMelisActiveModules();

            $bricks = [];
            foreach ($active as $module) {
                if (!is_string($module) || $module === '') {
                    continue;
                }
                $path = $modulesSrv->getModulePath($module, true);
                if (!$path) {
                    continue;
                }
                $manifestFile = rtrim($path, '/\\') . '/public/ui-react/brick.manifest.json';
                if (!is_file($manifestFile)) {
                    continue;
                }
                $manifest = json_decode((string) file_get_contents($manifestFile), true);
                if (!is_array($manifest)) {
                    continue;
                }
                // A module may ship a SINGLE brick (manifest = one object) or MULTIPLE bricks
                // (manifest with a `bricks` array — one tool per entry, all built into the same
                // bundle which self-registers each by id). Normalise to a list of defs.
                $defs = (isset($manifest['bricks']) && is_array($manifest['bricks']))
                    ? $manifest['bricks']
                    : [$manifest];

                foreach ($defs as $def) {
                    if (!is_array($def)) {
                        continue;
                    }
                    $entry = $def['entry'] ?? $manifest['entry'] ?? 'brick.js';
                    // Cache-bust: the bundle filename is not content-hashed, so append the
                    // built file's mtime — each rebuild yields a new URL and the browser
                    // never serves a stale brick.
                    $bundleFile = rtrim($path, '/\\') . '/public/ui-react/' . $entry;
                    $ver        = is_file($bundleFile) ? (string) filemtime($bundleFile) : '0';
                    $bricks[] = [
                        'id'         => $def['id'] ?? strtolower($module),
                        'module'     => $module,
                        'route'      => $def['route'] ?? null,
                        'label'      => $def['label'] ?? $module,
                        'forwardKey' => $def['forwardKey'] ?? null,
                        'melisKey'   => $def['melisKey'] ?? null,
                        // Opt-in to the host sub-tab pattern (native "User Management" look): the
                        // host collapses /[section]/[tool]/:id to ONE tool tab + renders SubTabBar
                        // for opened records. Off by default (a brick keeps a top tab per entity).
                        'subTabs'    => (bool) ($def['subTabs'] ?? false),
                        // Opt-in to persistent mounting: the host keeps the brick MOUNTED (CSS-hidden)
                        // once visited, so switching tool tabs never refetches its data nor reloads its
                        // legacy iframe. The host scopes the brick's router context to its own route and
                        // passes it an `active` prop (see Shell.tsx BrickHost); a brick may still freeze
                        // its own route-reading on `active` (see CmsStylePage).
                        'persistent' => (bool) ($def['persistent'] ?? false),
                        'bundleUrl'  => '/' . $module . '/ui-react/' . $entry . '?v=' . $ver,
                    ];
                }
            }

            return $this->jsonResponse(['success' => true, 'data' => $bricks]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }

    // ─── /melis/react-api/dashboard/bubbles ──────────────────────────────────

    /**
     * Counts for the dashboard's top "bubble" widgets — the React equivalent of
     * MelisCore's legacy dashboard bubble plugins (News / Updates / Notifications /
     * Messages). Each count reuses the same source as its plugin and degrades to 0
     * (never a 404) when the underlying module/service is absent, so the row always
     * renders. All four bubbles are MelisCore-native.
     */
    public function dashboardBubblesAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Read-only endpoint: free the PHP session lock immediately so the dashboard's other cold
        // requests (stats / legacy-plugins / layout) don't serialise behind this one. Nothing below
        // writes to the session — the flash messenger is read-only here and the external News feed
        // now goes through a cross-session FILE cache, not the session (see externalCached()).
        $this->releaseSessionLock();

        $sm   = $this->getServiceManager();
        $data = [
            'news'          => ['count' => 0],
            'updates'       => ['count' => 0],
            'notifications' => ['count' => 0, 'items' => []],
            'messages'      => ['count' => 0],
        ];

        // Notifications — MelisCore flash messenger (always available). Titles/messages are
        // stored untranslated (translation keys) and re-translated here at read time, same as
        // the legacy MelisFlashMessengerController::getflashMessageAction().
        try {
            $notifs = json_decode((string) $sm->get('MelisCoreFlashMessenger')->getFlashMessengerMessages(), true);
            if (is_array($notifs)) {
                $translator = $sm->get('translator');
                $data['notifications']['count'] = count($notifs);
                foreach ($notifs as $notif) {
                    $data['notifications']['items'][] = [
                        'title'      => $translator->translate($notif['title'] ?? ''),
                        'message'    => $translator->translate($notif['message'] ?? ''),
                        'date_trans' => $notif['date_trans'] ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        // Messages — melis-messenger if installed, else 0 (unique conversation senders).
        try {
            if ($sm->has('MelisMessengerService')) {
                $userId = (int) ($this->getAuthStorage()->usr_id ?? 0);
                if ($userId) {
                    $messages = $sm->get('MelisMessengerService')->getNewMessage($userId);
                    $senders  = [];
                    foreach ((array) $messages as $m) {
                        $sid = $m['msgr_msg_cont_sender_id'] ?? null;
                        if ($sid !== null && !in_array($sid, $senders, true)) {
                            $senders[] = $sid;
                        }
                    }
                    $data['messages']['count'] = count($senders);
                }
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        // News — external marketplace feed via cURL (short timeout), like the plugin. Cached across
        // sessions (10 min) so it doesn't reblock on every login: without this the feed was refetched
        // on the FIRST dashboard open of each session (~0.7s+), on the critical path of the top bar.
        try {
            $newsCount = $this->externalCached('dashboard-news-count', 600, static function () use ($sm) {
                $cfg     = $sm->get('MelisCoreConfig')->getItem('/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section');
                $newsCfg = $cfg['interface']['MelisCoreDashboardBubbleNewsMelisPlugin']['datas'] ?? null;
                if (empty($newsCfg['url'])) {
                    return 0;
                }
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $newsCfg['url']);
                if (isset($newsCfg['filter'])) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $newsCfg['filter']);
                }
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($curl, CURLOPT_TIMEOUT, 5);
                curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
                $raw = curl_exec($curl);
                $curl_ok = ($raw !== false);
                curl_close($curl);
                // A failed transfer → null so externalCached serves the last known value / retries,
                // instead of caching a spurious 0.
                if (!$curl_ok) {
                    return null;
                }
                $resp = json_decode((string) $raw, true);
                return (!empty($resp['response']) && is_array($resp['response'])) ? count($resp['response']) : 0;
            });
            if ($newsCount !== null) {
                $data['news']['count'] = (int) $newsCount;
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        // Updates — count not yet wired (legacy always returned a placeholder 0 here). The former
        // MelisMarketPlace `isMarketplaceAccessible` dispatch was a blocking cURL to marketplace
        // (up to 5s) whose result was DISCARDED — removed so it no longer stalls the cold load.
        // $data['updates']['count'] stays 0 until a real update count is implemented.

        return $this->jsonResponse(['success' => true, 'data' => $data]);
    }

    // ─── /melis/react-api/dashboard/stats ────────────────────────────────────

    /**
     * KPI counts (users, sites, pages, languages) and recent login activity.
     * Each figure degrades to 0 / empty when the underlying module is absent.
     */
    public function dashboardStatsAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Read-only (DB counts only): free the session lock so this doesn't serialise with the
        // dashboard's other cold requests sharing the same session cookie.
        $this->releaseSessionLock();

        $defaults = [
            'kpis'     => ['users' => 0, 'sites' => 0, 'pages' => 0, 'languages' => 0],
            'activity' => [],
        ];

        $sm = $this->getServiceManager();

        // DB adapter — if unavailable, return safe defaults immediately.
        $db = null;
        try { $db = $sm->get('Laminas\Db\Adapter\AdapterInterface'); } catch (\Throwable) {}
        if (!$db) {
            return $this->jsonResponse(['success' => true, 'data' => $defaults]);
        }

        $countSql = static function (\Laminas\Db\Adapter\AdapterInterface $db, string $table): int {
            try {
                $rows = iterator_to_array($db->query("SELECT COUNT(*) AS c FROM `{$table}`", []));
                return (int) (($rows[0] ?? [])['c'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        };

        $users     = $countSql($db, 'melis_core_user');
        $languages = $countSql($db, 'melis_core_lang');

        // Sites + pages counts (MelisCms only — table may not exist).
        $sites = 0;
        $pages = 0;
        try {
            if ($sm->has('MelisEngineTablePageSaved')) {
                $all = $sm->get('MelisEngineTablePageSaved')->fetchAll();
                foreach ((array) ($all ? $all->toArray() : []) as $p) {
                    if (($p['page_type'] ?? '') === 'SITE') { $sites++; }
                    if (($p['page_type'] ?? '') === 'PAGE') { $pages++; }
                }
            }
        } catch (\Throwable) {}

        // Recent activity — last N users to log in.
        $activity = [];
        try {
            $results = $sm->get('MelisCoreTableUser')->getLastLoggedInUsers(8);
            foreach ((array) ($results ? $results->toArray() : []) as $u) {
                $name = trim(($u['usr_firstname'] ?? '') . ' ' . ($u['usr_lastname'] ?? ''));
                $activity[] = [
                    'id'        => (int) ($u['usr_id'] ?? 0),
                    'name'      => $name !== '' ? $name : ($u['usr_login'] ?? 'User'),
                    'loginDate' => $u['usr_last_login_date'] ?? null,
                ];
            }
        } catch (\Throwable) {}

        return $this->jsonResponse([
            'success' => true,
            'data'    => [
                'kpis'     => ['users' => $users, 'sites' => $sites, 'pages' => $pages, 'languages' => $languages],
                'activity' => $activity,
            ],
        ]);
    }

    // ─── /melis/react-api/dashboard/legacy-plugins ───────────────────────────

    /**
     * Returns the list of available legacy dashboard plugins (from Melis config)
     * so the React dashboard palette can display and load them as iframe widgets.
     *
     * Each entry: { pluginName, title, icon, section, w, h }
     */
    public function legacyDashboardPluginsAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Read-only endpoint: free the session lock so the dashboard's other cold requests don't
        // serialise behind it. The marketplace lookups below no longer write the session (they go
        // through the cross-session file cache, see externalCached()), so this is safe.
        $this->releaseSessionLock();

        $sm     = $this->getServiceManager();
        $config = null;
        try { $config = $sm->get('MelisCoreConfig'); } catch (\Throwable) {}

        if (!$config) {
            return $this->jsonResponse(['success' => true, 'data' => []]);
        }

        // Explicit path: Utilities plugins register directly under meliscore.interface.melis_dashboardplugin,
        // NOT under meliscore_leftmenu (app.toolstree.php's node is a different tree branch).
        $section     = $config->getItem('/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section');
        $pluginNames = array_keys($section['interface'] ?? []);

        $translationSvc = null;
        try { $translationSvc = $sm->get('MelisCoreTranslation'); } catch (\Throwable) {}

        // Native React widgets already cover these — skip the legacy tile to avoid a duplicate in the
        // palette (cf. ui-react/src/components/dashboard/widget-registry.tsx). Map: plugin → native
        // widget id. They are still RIGHTS-GATED below: a granted one is reported in `nativeWidgets`
        // so the React dashboard only shows/keeps its native widget when the user has the right
        // (ticket 0010740 — a rights-less user used to see the native "Recent activity" widget).
        $nativeReactWidgets = ['MelisCoreDashboardRecentUserActivityPlugin' => 'activity'];
        $nativeWidgets = [];

        // Section list + order: identical to DashboardPluginsController::organizedPluginsBySection —
        // marketplace categories when reachable, else the offline fallback declared in
        // config/app.interface.php (/meliscore/datas/fallBacksection), then the custom sections it
        // appends. A section outside this list — or missing — falls into "Others", same as legacy.
        // Same rights gate as legacy (DashboardPluginsController::getDashboardPluginsMenuAction) —
        // the right key is the plugin CLASS name, stored under <melis_dashboardplugin> in usr_rights.
        $rightsSvc = null;
        try { $rightsSvc = $sm->get('MelisCoreDashboardPluginsService'); } catch (\Throwable) {}

        // Marketplace section list — same source as MelisCorePluginsService::getPackagistCategories(),
        // but fetched raw through the cross-session file cache (TTL 1h) instead of the service, which
        // caches only in the PHP SESSION → a blocking HTTP call on the FIRST dashboard open of each
        // login session. The raw fetch also writes nothing to the session, so the lock stays freed.
        $knownSections = $this->externalCached('packagist-categories', 3600, static function () {
            $raw = @file_get_contents('http://marketplace.melisplatform.com/melis-packagist/get-package-group', true);
            if ($raw === false) return null; // transient failure → serve stale / fall back below
            $decoded = json_decode($raw);
            $out = [];
            if (!empty($decoded)) {
                foreach ($decoded as $val) {
                    if (($val->module_count ?? 0) > 0) { $out[] = $val->mp_group_name; }
                }
            }
            return $out;
        });
        if (empty($knownSections)) {
            try { $knownSections = (array) $config->getItem('/meliscore/datas/fallBacksection'); } catch (\Throwable) {}
        }
        $knownSections = array_values(array_unique(array_merge(
            $knownSections ?: ['MelisCore', 'MelisCms', 'MelisMarketing', 'MelisCommerce', 'MelisSites'],
            ['MelisCommerce', 'Others', 'CustomProjects'],
        )));

        // module → marketplace section, exactly like legacy getMelisPublicModules(null, true): the
        // $dashboardPlugin flag CamelCases the package name into the module name. Same cross-session
        // cache as above.
        $publicModules = $this->externalCached('public-modules-dashboard', 3600, static function () {
            $raw = @file_get_contents('http://marketplace.melisplatform.com/melis-packagist/get-package-list', true);
            if ($raw === false) return null;
            $decoded = json_decode($raw);
            $out = [];
            if (!empty($decoded)) {
                foreach ($decoded as $moduleInfo) {
                    $packageName = str_replace('melisplatform/', '', $moduleInfo->packageName);
                    // $dashboardPlugin=true transform: CamelCase each dash-part, then drop the dashes.
                    $packageName = str_replace('-', '', implode('-', array_map('ucfirst', explode('-', $packageName))));
                    $out[$packageName] = ['section' => $moduleInfo->packageSection];
                }
            }
            return $out;
        }) ?? [];

        $plugins = [];
        foreach ($pluginNames as $pluginName) {
            // Rights: user must be granted this plugin (or the _root wildcard). Mirrors
            // DashboardPluginsController.php:92. Applied to native-equivalent plugins too — same
            // "no rights service → allowed" semantics as before.
            if ($rightsSvc && !$rightsSvc->canAccess($pluginName)) continue;

            // Modules external to MelisCore register via a conf.type indirection pointing to their
            // own namespace (e.g. /meliscommerce/interface/...) — MelisCore's own plugins are
            // declared directly in place instead, so fall back to the node itself when there's no type.
            $node     = $section['interface'][$pluginName] ?? [];
            $typePath = $node['conf']['type'] ?? null;

            $pluginConfig = $node;
            if ($typePath) {
                try { $pluginConfig = $config->getItem($typePath); } catch (\Throwable) { $pluginConfig = null; }
            }

            $datas = $pluginConfig['datas'] ?? [];

            // Description shown by the legacy palette as the item's `title` attribute (native browser
            // tooltip, cf. dashboard-menu-content.phtml) — forwarded so React can show the same text.
            $description = (string) ($datas['description'] ?? '');
            if ($description !== '' && str_starts_with($description, 'tr_')) {
                $translated = '';
                if ($translationSvc) {
                    try { $translated = (string) $translationSvc->getMessage($description); } catch (\Throwable) {}
                }
                // Untranslated key → no tooltip at all rather than a raw `tr_…` string on screen.
                $description = (str_starts_with($translated, 'tr_') ? '' : $translated);
            }

            // Native React equivalent: reported via `nativeWidgets` (so the React dashboard shows/keeps
            // its native widget only when granted) — never emitted as a duplicate legacy tile. Its
            // description still travels, so the native palette item gets the same tooltip.
            if (isset($nativeReactWidgets[$pluginName])) {
                $nativeWidgets[$nativeReactWidgets[$pluginName]] = $description;
                continue;
            }

            if (empty($datas)) continue;

            // Bubbles (News/Updates/Notifications/Messages) and the drag-drop grid container are
            // dashboard plugins too, but not addable widgets — they're rendered elsewhere already.
            if (!empty($datas['skip_plugin_container'])) continue;

            $title = (string) ($datas['name'] ?? $pluginName);

            if (str_starts_with($title, 'tr_') && $translationSvc) {
                try {
                    $translated = (string) $translationSvc->getMessage($title);
                    if ($translated !== '') { $title = $translated; }
                } catch (\Throwable) {}
            }

            // Owning module — legacy groups plugins by it inside each section.
            $module = (string) ($pluginConfig['forward']['module'] ?? '');

            // Module label: same translation key legacy uses in dashboard-menu-content.phtml.
            $moduleLabel = $module;
            if ($module && $translationSvc) {
                try {
                    $translated = (string) $translationSvc->getMessage('tr_PluginSection_' . strtolower($module));
                    if ($translated !== '') { $moduleLabel = $translated; }
                } catch (\Throwable) {}
            }
            // No translation entry → humanise "MelisCmsPageHistoric" into "Melis Cms Page Historic".
            if ($moduleLabel === $module && $module !== '') {
                $moduleLabel = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $module));
            }

            // Section: the module's marketplace section wins (legacy rule), then the plugin's own
            // `section`, then the offline shortcut below.
            $sectionValue = (string) ($publicModules[$module]['section'] ?? '');
            if ($sectionValue === '') {
                $sectionValue = (string) ($datas['section'] ?? '');
            }
            if (!in_array($sectionValue, $knownSections, true)) {
                // A plugin declared directly under meliscore's own tree (no conf.type indirection to
                // another module's namespace) belongs to MelisCore itself — legacy resolves this via
                // the marketplace category lookup for the "meliscore" module, which isn't reachable
                // offline, so we shortcut it here instead of dumping every core plugin into "Others".
                $sectionValue = $typePath ? 'Others' : 'MelisCore';
            }

            $plugins[] = [
                'pluginName' => $pluginName,
                'title'      => $title ?: $pluginName,
                // Tooltip text of the palette item, exactly like the legacy plugin menu.
                'description' => $description,
                'icon'       => (string) ($datas['icon'] ?? ''),
                'module'      => $module,
                'moduleLabel' => $moduleLabel ?: $module,
                // Thumbnail screenshot shown in the legacy plugin picker (dashboard-menu-content.phtml) —
                // forwarded as-is so the React palette can show the same image instead of a generic icon.
                'thumbnail'  => (string) ($datas['thumbnail'] ?? ''),
                'section'    => $sectionValue,
                'w'          => (int) ($datas['width']  ?? 6),
                'h'          => (int) ($datas['height'] ?? 4),
            ];
        }

        // Emit in legacy order — sections first (marketplace/fallback order), modules next (first
        // appearance). The React palette keeps this order as-is when it builds its groups, so no
        // section ordering is duplicated client-side.
        $sectionRank = array_flip($knownSections);
        $moduleRank  = [];
        foreach ($plugins as $p) {
            if (!isset($moduleRank[$p['module']])) { $moduleRank[$p['module']] = count($moduleRank); }
        }
        usort($plugins, function (array $a, array $b) use ($sectionRank, $moduleRank) {
            $sa = $sectionRank[$a['section']] ?? PHP_INT_MAX;
            $sb = $sectionRank[$b['section']] ?? PHP_INT_MAX;
            if ($sa !== $sb) { return $sa <=> $sb; }
            return ($moduleRank[$a['module']] ?? 0) <=> ($moduleRank[$b['module']] ?? 0);
        });

        return $this->jsonResponse([
            'success' => true,
            'data' => $plugins,
            // Native React widgets (widget-registry) the current user is granted — the dashboard
            // gates its always-registered native widgets by this list.
            'nativeWidgets' => array_keys($nativeWidgets),
            // Descriptions of those native widgets, taken from the legacy plugin config they mirror,
            // so their palette item shows the same tooltip as every other plugin.
            'nativeWidgetDescriptions' => array_filter($nativeWidgets, static fn ($d) => $d !== ''),
        ]);
    }

    // ─── /melis/react-api/rights/dashboard-plugins ───────────────────────────

    /**
     * Authoritative list of dashboard plugins for the user-RIGHTS editor, in module-merge order
     * (MelisCore first, then MelisCms, then any other module — same order the legacy rights tree
     * uses). Reuses MelisCoreDashboardPluginsRightsService::getRightsValues, whose children are
     * built per module via getMelisKeyPaths(); we drop the per-user `selected` flag (React derives
     * the checked state from the usr_rights XML <melis_dashboardplugin> section).
     *
     * Each entry: { key, title }.
     */
    public function rightsDashboardPluginsAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $sm = $this->getServiceManager();
        $userId = (int) $this->params()->fromQuery('userId', 0);
        if (!$userId) {
            try { $userId = (int) ($sm->get('MelisCoreAuth')->getIdentity()->usr_id ?? 0); } catch (\Throwable) {}
        }

        $items = [];
        try {
            // alias defined in melis-core module.config.php → MelisCoreDashboardPluginsRightsService
            $rights = $sm->get('MelisCoreDashboardPluginsService')->getRightsValues($userId);

            // The dashboard section is MERGED across modules, so the rights data doesn't carry the
            // owning module. Resolve it from each plugin's controller-plugin class namespace (1st
            // segment). Dashboard plugins register under `controller_plugins`, NOT `controllers`.
            $appConfig = $sm->get('config');
            $cp = $appConfig['controller_plugins'] ?? [];
            $ctrls = array_merge(
                $cp['factories']  ?? [],
                $cp['invokables'] ?? [],
                $cp['aliases']    ?? []
            );

            foreach (($rights[0]['children'] ?? []) as $c) {
                if (empty($c['key'])) { continue; }
                $key    = (string) $c['key'];
                $class  = (string) ($ctrls[$key] ?? '');
                $module = $class ? explode('\\', ltrim($class, '\\'))[0] : '';
                $items[] = [
                    'key'    => $key,
                    'title'  => (string) ($c['title'] ?? $key),
                    'module' => $module,
                ];
            }
        } catch (\Throwable) {}

        return $this->jsonResponse(['success' => true, 'data' => $items]);
    }

    // ─── /melis/react-api/dashboard/layout ───────────────────────────────────

    /**
     * GET  → returns the saved dashboard plugins (JSON: {pluginName, pluginId, x, y, w, h}[]) from melis_core_dashboards.
     * POST → receives that same JSON, converts to the legacy XML schema, saves to melis_core_dashboards.
     *
     * Uses dashboard_id = 'id_meliscore_toolstree_section_dashboard' — the SAME record the classic
     * /melis dashboard reads/writes — so the React and legacy dashboards are ALWAYS consistent (one
     * record, not two). The React client keys items by the REAL PHP plugin name so the legacy render
     * (which does `$pluginManager->get($pluginName)`) keeps working. On save we MERGE: each plugin's
     * existing per-instance config (children stored in the XML by savePluginConfigToXml) is preserved
     * across a React position/remove change, so moving a tile never wipes the classic plugin's settings.
     */
    public function dashboardLayoutAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Layout load/save touches only the DB (melis_core_dashboards), never the session — free the
        // session lock so it doesn't serialise with the dashboard's other cold requests.
        $this->releaseSessionLock();

        $sm          = $this->getServiceManager();
        $dashboardId = 'id_meliscore_toolstree_section_dashboard';

        $userId = 0;
        try { $userId = (int) $this->getAuthStorage()->usr_id; } catch (\Throwable) {}

        $tbl = null;
        try { $tbl = $sm->get('MelisCoreDashboardsTable'); } catch (\Throwable) {}

        if (!$tbl) {
            return $this->jsonResponse(['success' => false, 'error' => 'Dashboard table unavailable']);
        }

        $request = $this->getRequest();

        // ── GET: load ────────────────────────────────────────────────────────
        if ($request->isGet()) {
            try {
                $row = $tbl->getDashboardPlugins($dashboardId, $userId)->current();
                if (empty($row->d_content)) {
                    return $this->jsonResponse(['success' => true, 'data' => null]);
                }
                $xml   = simplexml_load_string($row->d_content);
                $items = [];
                if ($xml && $xml->plugin) {
                    foreach ($xml->plugin as $p) {
                        $items[] = [
                            'pluginName' => (string) $p['plugin'],
                            'pluginId'   => (string) $p['plugin_id'],
                            'x' => (int) $p->{'x-axis'},
                            'y' => (int) $p->{'y-axis'},
                            'w' => (int) $p->{'width'},
                            'h' => (int) $p->{'height'},
                            // Hauteur d'affichage React d'une tuile redimensionnée à la main (lignes de
                            // 46px). Enfant à part, IGNORÉ par le dashboard classique (qui rend `height`
                            // en 80px). Absent ⇒ null ⇒ la grille React auto-ajuste la hauteur.
                            'reactH' => isset($p->{'react-height'}) ? (int) $p->{'react-height'} : null,
                        ];
                    }
                }
                return $this->jsonResponse(['success' => true, 'data' => $items]);
            } catch (\Throwable $e) {
                return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
        }

        // ── POST: save ───────────────────────────────────────────────────────
        if ($request->isPost()) {
            try {
                $body  = (string) $request->getContent();
                $items = json_decode($body, true);
                if (!is_array($items)) {
                    return $this->jsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
                }

                // ⚠️ FILET ANTI-EFFACEMENT (serveur) — un record VIDE n'est écrit QUE sur demande
                // explicite (`?allowEmpty=1`, posé par le seul « supprimer tous les plugins », qui
                // est confirmé par l'utilisateur). Tout autre payload vide est REFUSÉ sans toucher
                // à la base : c'est exactement ce qui est arrivé à un utilisateur dont le
                // `d_content` s'est retrouvé réduit à `<Plugins></Plugins>`. Les garde-fous côté
                // React (cf. `persist`) restent la 1ʳᵉ ligne de défense ; celui-ci est le dernier
                // rempart, valable quel que soit le bug client.
                if ($items === [] && $this->params()->fromQuery('allowEmpty', '') !== '1') {
                    return $this->jsonResponse([
                        'success' => false,
                        'error'   => 'Refusing to clear the dashboard without an explicit request',
                    ], 409);
                }

                // Config préservée : file d'attente des nœuds <plugin> existants par nom de plugin.
                // Ré-appariés dans l'ordre aux items entrants du même plugin → un déplacement/retrait
                // React ne perd pas les réglages stockés dans le XML (savePluginConfigToXml) côté
                // dashboard classique. On récupère aussi la ligne existante pour l'update (d_id).
                $existing         = $tbl->getDashboardPlugins($dashboardId, $userId)->current();
                $existingByPlugin = [];
                if ($existing && !empty($existing->d_content)) {
                    $prev = @simplexml_load_string($existing->d_content);
                    if ($prev && $prev->plugin) {
                        foreach ($prev->plugin as $p) {
                            $existingByPlugin[(string) $p['plugin']][] = $p;
                        }
                    }
                }
                // Enfants de position (réécrits) — tout le reste est de la config à préserver.
                // `react-height` en fait partie : c'est un état de disposition React (redimensionnement
                // manuel), réécrit depuis le payload à chaque save, pas une config à figer.
                $posKeys = ['x-axis' => 1, 'y-axis' => 1, 'width' => 1, 'height' => 1, 'react-height' => 1];

                $pluginXmlData = '';
                $seqByPlugin   = [];
                foreach ($items as $item) {
                    $pluginName = trim((string) ($item['pluginName'] ?? ''));
                    if ($pluginName === '') continue;
                    // `plugin_id` s'écrit à la CONVENTION LEGACY, pas avec l'id d'instance React
                    // (`legacy-<PluginName>`). Ce record est partagé avec le dashboard classique, qui
                    // dérive de cet attribut la classe CSS du conteneur (plugin-container.phtml) :
                    // `legacy-AverageOpportunityWonPlugin` donnait `.legacy--average-opportunity-won-plugin`,
                    // et tout JS de plugin scopé sur `.average-opportunity-won` cessait de matcher →
                    // widget rendu mais inerte côté legacy. Cf. addLegacyPluginIdClass() dans
                    // MelisReactOverride pour le pendant côté iframe React.
                    // Sans risque pour React : le client redérive ses ids du NOM de plugin et ignore
                    // `pluginId` (cf. recordsToLayout), et la config par plugin vit dans une AUTRE
                    // ligne (`react_dashboard_config`), indexée par nom de plugin.
                    $seq      = $seqByPlugin[$pluginName] = ($seqByPlugin[$pluginName] ?? 0) + 1;
                    $pluginId = $this->legacyPluginInstanceId($pluginName, $seq);
                    $x = (int) ($item['x'] ?? 0);
                    $y = (int) ($item['y'] ?? 0);
                    $w = (int) ($item['w'] ?? 1);
                    $h = (int) ($item['h'] ?? 1);
                    // Hauteur d'affichage React d'un redimensionnement MANUEL (lignes 46px). Écrite dans
                    // un enfant <react-height> que le dashboard classique ignore ; absente sinon (la
                    // grille React auto-ajuste). > 0 requis (0/null = pas de réglage manuel).
                    $reactH = isset($item['reactH']) ? (int) $item['reactH'] : 0;

                    $nameAttr = htmlspecialchars($pluginName, ENT_QUOTES | ENT_XML1);
                    $idAttr   = htmlspecialchars($pluginId, ENT_QUOTES | ENT_XML1);

                    // Config du prochain nœud existant de ce plugin (enfants hors position).
                    $config = '';
                    if (!empty($existingByPlugin[$pluginName])) {
                        $prevNode = array_shift($existingByPlugin[$pluginName]);
                        foreach ($prevNode->children() as $child) {
                            if (isset($posKeys[$child->getName()])) continue;
                            $config .= "\n\t" . $child->asXML();
                        }
                    }

                    $reactHXml = $reactH > 0 ? "\n\t<react-height><![CDATA[{$reactH}]]></react-height>" : '';

                    $pluginXmlData .= "\n<plugin plugin=\"{$nameAttr}\" plugin_id=\"{$idAttr}\">"
                        . "\n\t<x-axis><![CDATA[{$x}]]></x-axis>"
                        . "\n\t<y-axis><![CDATA[{$y}]]></y-axis>"
                        . "\n\t<height><![CDATA[{$h}]]></height>"
                        . "\n\t<width><![CDATA[{$w}]]></width>"
                        . $reactHXml
                        . $config
                        . "\n</plugin>";
                }
                $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Plugins>{$pluginXmlData}\n</Plugins>";

                $existingId = $existing ? ($existing->d_id ?? null) : null;
                $tbl->save(
                    ['d_dashboard_id' => $dashboardId, 'd_user_id' => $userId, 'd_content' => $xml],
                    $existingId
                );

                // Rejoue l'évènement legacy du save dashboard (comme
                // DashboardPluginsController::saveDashboardPluginsAction) pour que SES listeners
                // tournent aussi depuis le nouveau back-office — en particulier la purge du cache
                // dashboard de l'utilisateur (MelisCoreClearCacheListenerListener). Sans ça, le
                // dashboard CLASSIQUE (qui partage ce même record) continuerait de servir une version
                // CACHÉE montrant les plugins retirés : la suppression (ou « tout supprimer ») ne s'y
                // refléterait pas. Le listener est attaché à l'identifiant PARTAGÉ 'MelisCore' ; notre
                // contrôleur est dans le namespace MelisReactApi → on ajoute l'identifiant pour que
                // l'évènement l'atteigne. Le listener lit `success` + l'utilisateur en session.
                $em = $this->getEventManager();
                $em->addIdentifiers(['MelisCore']);
                $em->trigger('meliscore_save_dashboard_plugin_end', $this, ['success' => 1]);

                return $this->jsonResponse(['success' => true]);
            } catch (\Throwable $e) {
                return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()]);
            }
        }

        return $this->jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    /**
     * `plugin_id` d'instance à la convention du dashboard CLASSIQUE, pour le record partagé.
     *
     * Legacy fabrique cet id comme `<datas.plugin_id>_<timestamp>` (MelisCoreDashboardTemplatingPlugin),
     * et `plugin-container.phtml` en dérive la classe CSS du conteneur en coupant au 1ᵉʳ `_` :
     *     strtolower(explode('_', preg_replace('/(?<!^)([A-Z])/', '-$1', $id))[0])
     * D'où `AverageOpportunityWon_1748…` → `.average-opportunity-won`, la classe sur laquelle se
     * scope le JS des plugins. On reproduit exactement cette forme, avec un compteur d'instance
     * (stable et lisible) à la place du timestamp : plusieurs exemplaires d'un même plugin restent
     * distincts, et le suffixe est de toute façon coupé pour la classe.
     *
     * `datas.plugin_id` est déclaré par le plugin ; on le résout via le nœud sous lequel les
     * plugins dashboard s'enregistrent. À défaut, on retombe sur le nom du plugin (comportement
     * antérieur — jamais pire que l'existant).
     */
    private function legacyPluginInstanceId(string $pluginName, int $seq): string
    {
        static $declaredIds = [];

        if (!array_key_exists($pluginName, $declaredIds)) {
            $declared = null;
            try {
                $config = $this->getServiceManager()->get('MelisCoreConfig');
                $node   = $config->getItem(
                    '/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section'
                    . '/interface/' . $pluginName
                );

                // Deux formes d'enregistrement coexistent :
                //  • INLINE — les plugins de MelisCore déclarent `datas` directement sur le nœud de
                //    section (ex. MelisCoreDashboardAnnouncementPlugin → plugin_id 'AnnouncementPlugin').
                //  • INDIRECTE — les modules tiers n'y mettent qu'un `conf.type` pointant vers leur
                //    vraie déclaration (ex. `/nehsdashboardplugins/interface/AverageOpportunityWonPlugin`).
                // Ne traiter que la seconde ferait retomber les plugins MelisCore sur le nom de classe,
                // et leur classe CSS de conteneur (.announcement-plugin) changerait — régression pour
                // tout CSS/JS legacy qui la cible. On tente donc l'inline D'ABORD, puis l'indirection.
                $datas = is_array($node) ? ($node['datas'] ?? null) : null;
                if (!is_array($datas) || empty($datas['plugin_id'])) {
                    $type  = is_array($node) ? (string) ($node['conf']['type'] ?? '') : '';
                    $datas = $type !== '' ? $config->getItem('/' . trim($type, '/') . '/datas') : null;
                }
                if (is_array($datas) && !empty($datas['plugin_id']) && is_string($datas['plugin_id'])) {
                    $declared = $datas['plugin_id'];
                }
            } catch (\Throwable) {
                // config indisponible → repli sur le nom du plugin
            }

            $declaredIds[$pluginName] = $declared ?? $pluginName;
        }

        return $declaredIds[$pluginName] . '_' . $seq;
    }

    // ─── /melis/react-api/menu ───────────────────────────────────────────────

    public function menuAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        // Free the PHP session lock BEFORE building the menu tree (canAccess over every tool can take
        // seconds). Read-only endpoint → safe. Without this, a concurrent /users/:id edit fetch
        // serialises behind /menu and the form spins / bounces back to the list (see releaseSessionLock()).
        $this->releaseSessionLock();

        // `?full=1` returns the UNFILTERED tool tree — used ONLY by the user-rights editor, which
        // must show every tool to be able to grant it (not just the editor's own tools). Restricted
        // to users who can manage user rights.
        $full = $this->params()->fromQuery('full', '') === '1';
        if ($full && !$this->getServiceManager()->get('MelisCoreRights')->canAccess('meliscore_tool_user')) {
            return $this->jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        }

        try {
            return $this->buildMenuResponse($full);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => array_slice(array_map(
                    fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''),
                    $e->getTrace()
                ), 0, 5),
            ], 500);
        }
    }

    /** @var \MelisCore\Service\MelisCoreConfigService|null Config service, stashed for buildLevel2/3 melisKey resolution. */
    private $menuConfig = null;
    /** @var array melisKey => config path map, stashed for buildLevel2/3. */
    private array $menuMelisKeys = [];

    private function buildMenuResponse(bool $full = false): HttpResponse
    {
        $sm          = $this->getServiceManager();
        $melisConfig = $sm->get('MelisCoreConfig');
        // $full → permissive rights provider so the whole tool tree is returned (rights editor).
        $melisRights = $full
            ? new class { public function canAccess($key): bool { return true; } }
            : $sm->get('MelisCoreRights');
        // MelisCoreRightsService::getMelisKeyPaths() lists only the 7 hardcoded core sections.
        // Newly-installed module sections (e.g. melisai_toolstree_section) are not in that list,
        // so canAccess() always returns false for their tools, hiding the entire section.
        // We detect those "unknown" sections and use permissive rights for them so active
        // modules registered outside the core section list are visible.
        $knownSectionKeys = (!$full && method_exists($melisRights, 'getMelisKeyPaths'))
            ? $melisRights->getMelisKeyPaths()
            : null;
        $melisKeys   = $melisConfig->getMelisKeys();
        $this->menuConfig     = $melisConfig;
        $this->menuMelisKeys  = $melisKeys;
        $transSvc    = null;
        try { $transSvc = $sm->get('MelisCoreTranslation'); } catch (\Throwable) {}

        // Sections « hôtes de sidebar » : déclarées par un module (config mergée
        // `melisReactSidebarHostSections` = [ sectionKey => ['requires' => melisKey, 'module' => …] ]).
        // Une telle section reste VISIBLE dans le menu même si aucun de ses outils n'est accessible,
        // à condition que l'utilisateur ait `canAccess(requires)` — pour héberger un panneau React
        // (ex. l'arbre des pages CMS, seul chemin vers les pages autorisées). Cf. ticket 0010724.
        $sidebarHostSections = [];
        try { $sidebarHostSections = $sm->get('config')['melisReactSidebarHostSections'] ?? []; } catch (\Throwable) {}

        // Découverte dynamique de toutes les sections enregistrées dans le leftmenu.
        $leftMenuInterface = $melisConfig->getItem(
            'meliscore/interface/meliscore_leftmenu/interface',
            '',
            false
        );

        // Fallback via getMelisKeys si le path direct ne retourne rien
        if (empty($leftMenuInterface) || !is_array($leftMenuInterface)) {
            if (!empty($melisKeys['meliscore_leftmenu'])) {
                $leftMenuConfig    = $melisConfig->getItem($melisKeys['meliscore_leftmenu']);
                $leftMenuInterface = $leftMenuConfig['interface'] ?? [];
            }
        }

        if (empty($leftMenuInterface) || !is_array($leftMenuInterface)) {
            return $this->jsonResponse(['success' => true, 'data' => [], '_debug' => 'leftMenuInterface empty']);
        }

        // Éléments non-section à ignorer
        $skipKeys = [
            'meliscore_leftmenu_identity',
            'meliscore_leftmenu_dashboard',
            'meliscms_sitetree',
            'melisdashboardplugin_section',
            'meliscore_footer',
        ];

        // Apply top-level section ordering (uses interface_ordering['meliscore_leftmenu'])
        $leftMenuInterface = $this->applyOrdering(
            $leftMenuInterface,
            $melisConfig->getOrderInterfaceConfig('meliscore_leftmenu')
        );

        $menu = [];

        foreach ($leftMenuInterface as $sectionKey => $_) {
            if (in_array($sectionKey, $skipKeys, true)) {
                continue;
            }

            // NOTE: do NOT canAccess() the section here. A top section is a CONTAINER, never granted
            // by per-tool rights, so checking it would wrongly hide the whole section. Visibility is
            // decided by its children: empty $categories below → the section is pruned.

            if (empty($melisKeys[$sectionKey])) {
                continue;
            }

            $sectionConfig = $melisConfig->getItem($melisKeys[$sectionKey]);

            $sectionName = $sectionConfig['conf']['name'] ?? $sectionKey;
            $sectionIcon = $sectionConfig['conf']['icon'] ?? '';

            // For sections not registered in getMelisKeyPaths(), canAccess() always returns false.
            // Use permissive rights for those so newly-added module sections remain visible.
            $effectiveRights = ($knownSectionKeys !== null && !in_array($sectionKey, $knownSectionKeys, true))
                ? new class { public function canAccess(string $key): bool { return true; } }
                : $melisRights;

            // Sections with is_parent_tool:true are directly clickable (they open a specific
            // tool via target_meliskey) and have no nav children — emit as a leaf tool node.
            if (!empty($sectionConfig['conf']['is_parent_tool'])) {
                $targetMelisKey = $sectionConfig['conf']['target_meliskey'] ?? ($sectionConfig['conf']['melisKey'] ?? $sectionKey);
                // ENFORCE rights: an is_parent_tool section IS a directly-clickable tool, so gate it by
                // canAccess on its target tool — sinon il s'affiche pour tout le monde (ticket 0010726 :
                // Marketplace visible/ouvrable sans le droit). En $full (éditeur de droits) $melisRights
                // est permissif → l'outil reste listé dans l'arbre des droits.
                if (!$effectiveRights->canAccess($targetMelisKey)) {
                    continue;
                }
                // Resolve the target tool's forward so the React nav can route it correctly.
                $targetConfig = !empty($melisKeys[$targetMelisKey])
                    ? $melisConfig->getItem($melisKeys[$targetMelisKey])
                    : [];
                $displayName = $sectionName;
                if ($transSvc && str_starts_with((string)$displayName, 'tr_')) {
                    try { $t = (string)$transSvc->getMessage($displayName); if ($t !== '') $displayName = $t; } catch (\Throwable) {}
                }
                $menu[] = [
                    'key'         => $sectionKey,
                    'name'        => $displayName,
                    'icon'        => $sectionConfig['conf']['icon'] ?? ($sectionConfig['conf']['tab_icon'] ?? ''),
                    'melisKey'    => $targetMelisKey,
                    'isTool'      => true,
                    'forward'     => $targetConfig['forward'] ?? [],
                    'hasNavChild' => false,
                    'children'    => [],
                ];
                continue;
            }

            if (empty($sectionConfig['interface'])) {
                continue;
            }

            $orderedInterface = $this->applyOrdering(
                $sectionConfig['interface'],
                $melisConfig->getOrderInterfaceConfig($sectionKey)
            );

            $categories = $this->buildLevel2($orderedInterface, $effectiveRights);

            if (empty($categories)) {
                // Aucun outil accessible sous cette section. Normalement on l'élague — SAUF si un module
                // l'a déclarée « hôte de sidebar » ET que l'utilisateur a le droit gating : on la garde
                // alors comme conteneur nu (sans enfant, sans forward) portant `sidebarModule`, pour que
                // l'hôte React y accroche son panneau (arbre des pages). `melisKey => null` évite que la
                // section vide soit prise pour une feuille-zone (iframe) côté front. Jamais en $full
                // (l'arbre de droits est déjà permissif, la section a ses enfants). Cf. ticket 0010724.
                $host = $full ? null : ($sidebarHostSections[$sectionKey] ?? null);
                if (is_array($host) && !empty($host['requires']) && $melisRights->canAccess($host['requires'])) {
                    $menu[] = [
                        'key'              => $sectionKey,
                        'name'             => $sectionName,
                        'icon'             => $sectionIcon,
                        'melisKey'         => null,
                        'isTool'           => false,
                        'forward'          => [],
                        'hasNavChild'      => true,
                        'sidebarModule'    => (string) ($host['module'] ?? ''),
                        'configChildCount' => count($orderedInterface),
                        'children'         => [],
                    ];
                }
                continue;
            }

            $menu[] = [
                'key'         => $sectionKey,
                'name'        => $sectionName,
                'icon'        => $sectionIcon,
                'melisKey'    => $sectionConfig['conf']['melisKey'] ?? $sectionKey,
                'isTool'      => !empty($sectionConfig['forward']),
                'forward'     => $sectionConfig['forward'] ?? [],
                'hasNavChild' => true,
                // UNFILTERED child count (before rights filtering). The React nav collapses a single-tool
                // wrapper (e.g. "SLIDER" → "Slider") but must NOT collapse a multi-tool category that the
                // current user only has 1 tool granted in (e.g. "Administration") — it relies on this.
                'configChildCount' => count($orderedInterface),
                'children'    => $categories,
            ];
        }

        // Outils « rights-only » (visibles UNIQUEMENT dans l'éditeur de droits, pas dans le menu de
        // gauche) : nœuds synthétiques déclarés par les modules via la config mergée `melisReactRightsTools`
        // = [ sectionKey => [ ['melisKey','name','icon'] ] ]. Sert aux outils qui ne s'ouvrent PAS par une
        // clé de menu (ex. l'éditeur de page CMS, ouvert via l'arbre du site) mais dont on veut exposer les
        // capacités (onglets/boutons) dans Users→Droits. Injectés seulement quand $full (arbre de droits).
        if ($full) {
            $rightsTools = $sm->get('config')['melisReactRightsTools'] ?? [];
            if (is_array($rightsTools) && $rightsTools) {
                foreach ($menu as &$section) {
                    $extra = $rightsTools[$section['key']] ?? null;
                    if (!is_array($extra)) { continue; }
                    $prepend = [];
                    foreach ($extra as $node) {
                        if (empty($node['melisKey'])) { continue; }
                        $name = (string) ($node['name'] ?? $node['melisKey']);
                        if ($transSvc && str_starts_with($name, 'tr_')) {
                            try { $t = (string) $transSvc->getMessage($name); if ($t !== '') { $name = $t; } } catch (\Throwable) {}
                        }
                        $prepend[] = [
                            'key'         => $node['melisKey'],
                            'name'        => $name,
                            'icon'        => $node['icon'] ?? '',
                            'melisKey'    => $node['melisKey'],
                            'isTool'      => true,
                            'forward'     => [],
                            'hasNavChild' => false,
                            'children'    => [],
                        ];
                    }
                    // En TÊTE de la section (demandé) : les nœuds rights-only passent avant les outils.
                    $section['children'] = array_merge($prepend, $section['children'] ?? []);
                    $section['hasNavChild'] = true;
                }
                unset($section);
            }
        }

        return $this->jsonResponse(['success' => true, 'data' => $menu]);
    }

    // ─── Helpers de traversée ────────────────────────────────────────────────

    private function buildLevel2(array $interface, $melisRights): array
    {
        $categories = [];

        foreach ($interface as $catKey => $catConfig) {
            // Only gate a category by canAccess when it is itself a LEAF tool — a forward AND no
            // children. A container category isn't granted by per-tool rights: its visibility comes
            // from its tools (buildLevel3 + the empty-prune below).
            // A forward alone does NOT make a category a tool. Some modules wrap their tool in a
            // leftmenu node that carries a forward of its own — one that merely RENDERS the menu
            // entry, not the tool (e.g. MelisCalendar's `meliscalendar_leftnemu` →
            // Calendar::render-calendar-leftmenu, wrapping the real `meliscalendar_tool`). The rights
            // editor lists the child tool, so the wrapper's melisKey is never grantable, and gating on
            // it hid the tool from every non-admin no matter what was granted. Such a wrapper is left
            // to its children; if none is granted, `tools` comes back empty and the !$hasNavChild
            // branch below still rights-gates (and prunes) it.
            // Check rights on the resolved melisKey, NOT the leftmenu array key: the rights XML
            // stores grants by melisKey, and the two differ for some modules (e.g. MelisSmallBusiness'
            // leftmenu key `melisSB_tool_userrole` vs melisKey `melissb_tool_userrole`) — using the
            // array key would wrongly hide the tool for everyone, admin included.
            $catAccessKey = $catConfig['conf']['melisKey'] ?? $catKey;
            if (!empty($catConfig['forward']) && empty($catConfig['interface'])
                && !$melisRights->canAccess($catAccessKey)) {
                continue;
            }
            if (!($catConfig['conf']['left_menu_display'] ?? true)) {
                continue;
            }

            // Unwrap a redundant single wrapper level: some modules declare the same group twice
            // (e.g. MelisPhpinfo: "Dev Tools" section → "Dev Tools" node → melis_phpinfo_tool). The legacy
            // left menu NEEDS both levels (a single-tool category's own header is suppressed, so the inner
            // node provides the visible "Dev Tools" group label), but the React tree only needs one —
            // otherwise the rights editor shows a doubled "Dev Tools > Dev Tools" and the nav carries an
            // extra, separately-rights-gated dead level. Collapse a forward-less inner wrapper that is
            // redundant with this category — same melisKey OR same display name — so downstream
            // (buildLevel3 / configChildCount / flatten) sees the real tools directly under it.
            if (!empty($catConfig['interface']) && count($catConfig['interface']) === 1) {
                $onlyKey   = array_key_first($catConfig['interface']);
                $onlyNode  = $catConfig['interface'][$onlyKey];
                $catName   = $catConfig['conf']['name'] ?? null;
                $sameKey   = ($onlyNode['conf']['melisKey'] ?? $onlyKey) === $catAccessKey;
                $sameName  = !empty($onlyNode['conf']['name']) && $catName !== null
                    && $onlyNode['conf']['name'] === $catName;
                if (($sameKey || $sameName) && empty($onlyNode['forward']) && !empty($onlyNode['interface'])) {
                    $catConfig['interface'] = $onlyNode['interface'];
                }
            }

            $catName     = $catConfig['conf']['name'] ?? $catKey;
            $catIcon     = $catConfig['conf']['icon'] ?? 'fa-folder';
            $hasNavChild = false;
            $tools       = [];

            if (!empty($catConfig['interface'])) {
                $tools       = $this->buildLevel3($catConfig['interface'], $melisRights);
                $hasNavChild = !empty($tools);
            }

            // Single-REAL-tool section, reached via the NORMAL buildLevel3 path (as opposed to the
            // conf.type-adoption fallback below): e.g. MelisCmsProspects declares 2 tools
            // (Prospects + Themes) under one section, but Themes' resolved config lacks a sub-item
            // icon, which buildLevel3 requires to count a nested entry as navigable — so it's
            // silently pruned and only Prospects survives, leaving a redundant "Prospect > Prospect"
            // double row. Flatten ONLY when this is a STRUCTURAL single-tool wrapper, not a
            // rights-based reduction (e.g. an "Administration" section where a restricted user is
            // granted just 1 of several tools must keep its group header — an admin would see more).
            // Distinguish the two by re-resolving with PERMISSIVE rights: if that also yields at most
            // one tool, no one — admin included — could ever see a second child, so it's safe to
            // promote the lone tool to a flat top-level row.
            // conf.no_collapse forces the category to stay an expandable group even with a single
            // tool (e.g. "Dev Tools" → "Melis Phpinfo"), so more tools can be added under it later.
            //
            // ⚠ ...and ONLY when that lone tool is itself a LEAF. This branch rebuilds the category as
            // a leaf node with `children => []`, so flattening a lone tool that has its OWN nav
            // children DELETES them (and, since a container wrapper has no `forward`, leaves a dead
            // unclickable row). That is the "Tool creation & Design" shape: the category holds one
            // wrapper (`meliscore_tool_tools`) which holds the real tools (MelisToolCreator,
            // MelisTemplatingPluginCreator, MelisDashboardPluginCreator). When the lone tool has
            // children, fall through to the normal category emit and let the FRONT-END unwrap the
            // chain: `collapseSingleTool()` (useNavMenu) promotes a level-2 single-child wrapper
            // while keeping deeper group headers, so a genuinely single-tool chain renders correctly
            // and a multi-tool wrapper keeps its group header.
            $noCollapse = !empty($catConfig['conf']['no_collapse']);
            if (!$noCollapse && $hasNavChild && count($tools) === 1 && empty($tools[0]['children']) && empty($catConfig['forward'])) {
                static $permissiveRights = null;
                $permissiveRights ??= new class { public function canAccess($key): bool { return true; } };
                $potentialTools = $this->buildLevel3($catConfig['interface'], $permissiveRights);
                if (count($potentialTools) <= 1) {
                    $onlyTool = $tools[0];
                    // Le wrapper est APLATI (il disparaît, on promeut le tool en ligne de nav) → afficher le
                    // nom du TOOL résolu, pas celui du wrapper. Le wrapper peut porter un `conf.name` destiné
                    // à l'arbre de DROITS legacy (ex. MelisCron's `meliscron_conf` = "Melis Cron", label du
                    // nœud parent dans getToolsKeys) — mais dans la nav aplatie c'est le tool qu'on ouvre, donc
                    // son nom ("Scheduled Tasks") est le bon. Sans wrapper name, retombait déjà sur le tool ;
                    // on le rend systématique pour ne pas laisser fuiter le label du wrapper dans la nav.
                    $flatName = $onlyTool['name'];
                    $flatIcon = $catConfig['conf']['icon'] ?? $onlyTool['icon'];
                    $categories[] = [
                        'key'         => $catKey,
                        'name'        => $flatName,
                        'icon'        => $flatIcon,
                        'melisKey'    => $onlyTool['melisKey'] ?? $catConfig['conf']['melisKey'] ?? $catKey,
                        'isTool'      => true,
                        'forward'     => $onlyTool['forward'] ?? [],
                        'hasNavChild' => false,
                        'configChildCount' => count($catConfig['interface'] ?? []),
                        'children'    => [],
                    ];
                    continue;
                }
            }

            // Fallback for the MelisCommerce leftmenu pattern: categories are declared via a `conf.type`
            // link whose target carries only conf (name/icon/melisKey). getItem() merges that conf but
            // DROPS the node's original `forward`, so the resolved category ends up with no navigable
            // children AND no forward — the prune below would then discard it (and, once all its
            // siblings are gone, the entire section). When the melisKey resolves to a real tool that
            // still carries a forward, adopt it so the category surfaces as a clickable leaf tool.
            $toolCfg = null;
            if (!$hasNavChild && empty($catConfig['forward'])) {
                $mk = $catConfig['conf']['melisKey'] ?? null;
                if ($mk && !empty($this->menuMelisKeys[$mk]) && $this->menuConfig) {
                    $toolCfg = $this->menuConfig->getItem($this->menuMelisKeys[$mk]);
                    if (!empty($toolCfg['forward'])) {
                        $catConfig['forward'] = $toolCfg['forward'];
                    }
                }
            }

            if (!$hasNavChild && !empty($catConfig['forward'])) {
                // Rights gate for a TYPE-REFERENCED leaf tool (its forward was adopted just above from the
                // linked tool's config). buildLevel3 already rights-checks a nested tool, but a category
                // that resolves — via `conf.type` — to a single leaf tool skips buildLevel3's gate (it has
                // no navigable children), so without this an UNGRANTED tool (e.g. melisagent_tool /
                // melis_mcp_inspector_tool, or an ungranted MelisCommerce tool) would still surface in the
                // menu. $catAccessKey is the resolved melisKey, so admins/granted users are unaffected.
                if (!$melisRights->canAccess($catAccessKey)) {
                    continue;
                }
                // Single-tool leftmenu-wrapper pattern (categories/products/clients/orders/coupons/…):
                // promote the resolved tool directly to a FLAT top-level row instead of nesting it one
                // level deeper under a group with a single child — that used to render as a redundant
                // "Produits > Produits" double row. CategoryGroup already renders a top-level
                // isTool+!hasNavChild node as a plain ToolRow, same as standalone tools (e.g. Comptes).
                $categories[] = [
                    'key'         => $catKey,
                    'name'        => $catName,
                    'icon'        => $catIcon,
                    'melisKey'    => $catConfig['conf']['melisKey'] ?? $catKey,
                    'isTool'      => true,
                    'forward'     => $catConfig['forward'],
                    'hasNavChild' => false,
                    'configChildCount' => count($catConfig['interface'] ?? []),
                    'children'    => [],
                ];
                continue;
            }

            if (!$hasNavChild && empty($catConfig['forward'])) {
                continue;
            }

            $categories[] = [
                'key'         => $catKey,
                'name'        => $catName,
                'icon'        => $catIcon,
                'melisKey'    => $catConfig['conf']['melisKey'] ?? $catKey,
                'isTool'      => !empty($catConfig['forward']),
                'forward'     => $catConfig['forward'] ?? [],
                'hasNavChild' => $hasNavChild,
                // Unfiltered tool count for this category (see section node note): the nav must keep the
                // "Administration" header even when only 1 of its 4 tools is granted to the user.
                'configChildCount' => count($catConfig['interface'] ?? []),
                'noCollapse'  => $noCollapse,
                'children'    => $tools,
            ];
        }

        return $categories;
    }

    private function buildLevel3(array $interface, $melisRights): array
    {
        $tools = [];

        foreach ($interface as $toolKey => $toolConfig) {
            // Rights are keyed by melisKey, which can differ from the leftmenu array key
            // (case/prefix mismatch for some modules — see buildLevel2 note). Use the melisKey.
            $toolAccessKey = $toolConfig['conf']['melisKey'] ?? $toolKey;
            // Wrapper-node trap, one level deeper than buildLevel2's: a pure CONTAINER node — it has
            // children but no forward of its own — is never granted directly. The rights editor only
            // writes `isTool` nodes, and a container is not one (buildRightsXml never emits its id), so
            // its melisKey is ungrantable and gating on its own canAccess() hid the whole group from
            // every non-admin no matter what was ticked. That is the "Tool creation & Design" shape:
            // the "Tools" wrapper `meliscore_tool_tools` (no forward) holds MelisToolCreator /
            // MelisTemplatingPluginCreator / MelisDashboardPluginCreator — granting those three does
            // NOT put the wrapper in the allow-set, so admins saw the section and granted users did not.
            // A container's visibility comes from its children: the sub-tool loop below rights-gates each
            // child, and the empty-leaf prune (no forward + no navigable child) discards the wrapper when
            // none survives — so nothing leaks. Only gate a genuine LEAF (a forward of its own).
            $isContainer = empty($toolConfig['forward']) && !empty($toolConfig['interface']);
            if (!$isContainer && !$melisRights->canAccess($toolAccessKey)) {
                continue;
            }
            if (!($toolConfig['conf']['left_menu_display'] ?? true)) {
                continue;
            }

            $toolIcon    = $toolConfig['conf']['icon'] ?? '';
            $subTools    = [];
            $hasNavChild = false;

            if (!empty($toolConfig['interface'])) {
                foreach ($toolConfig['interface'] as $subKey => $subConfig) {
                    if (empty($subConfig['conf']['icon'])) {
                        continue;
                    }
                    $subAccessKey = $subConfig['conf']['melisKey'] ?? $subKey;
                    if (!$melisRights->canAccess($subAccessKey)) {
                        continue;
                    }
                    $subTools[] = [
                        'key'         => $subKey,
                        'name'        => $subConfig['conf']['name'] ?? $subKey,
                        'icon'        => $subConfig['conf']['icon'],
                        'melisKey'    => $subConfig['conf']['melisKey'] ?? $subKey,
                        'isTool'      => !empty($subConfig['forward']),
                        'forward'     => $subConfig['forward'] ?? [],
                        'hasNavChild' => false,
                        'children'    => [],
                    ];
                    $hasNavChild = true;
                }
            }

            // Skip a non-navigable leaf: no forward (nothing to open) and no sub-tools. Some modules
            // register placeholder leftmenu entries (e.g. MelisSmallBusiness' "Media library", opened
            // from within the media field — not the left menu) — don't surface a dead, unclickable item.
            if (empty($toolConfig['forward']) && !$hasNavChild) {
                continue;
            }

            $tools[] = [
                'key'         => $toolKey,
                'name'        => $toolConfig['conf']['name'] ?? $toolKey,
                'icon'        => $toolIcon ?: 'fa-cube',
                'melisKey'    => $toolConfig['conf']['melisKey'] ?? $toolKey,
                'isTool'      => !empty($toolConfig['forward']),
                'forward'     => $toolConfig['forward'] ?? [],
                'hasNavChild' => $hasNavChild,
                'configChildCount' => count($toolConfig['interface'] ?? []),
                'children'    => $subTools,
            ];
        }

        return $tools;
    }

    private function applyOrdering(array $interface, array $orderConfig): array
    {
        if (empty($orderConfig)) {
            return $interface;
        }

        $ordered   = [];
        $remaining = $interface;

        foreach ($orderConfig as $ordKey => $ordVal) {
            // Support both list format (int key, string value) and
            // associative format (string key, array value).
            $targetKey = is_int($ordKey) ? $ordVal : $ordKey;
            if (isset($remaining[$targetKey])) {
                $ordered[$targetKey] = $remaining[$targetKey];
                unset($remaining[$targetKey]);
            }
        }

        return array_merge($ordered, $remaining);
    }

    // ─── Helpers HTTP/Auth ───────────────────────────────────────────────────

    /**
     * Retourne une réponse JSON pure, sans passer par le système de vues Laminas.
     * Évite que Melis essaie de rendre un layout PHP autour de la réponse.
     */
    private function jsonResponse(array $data, int $status = 200): HttpResponse
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaders([
            'Content-Type'                => 'application/json; charset=utf-8',
            'X-Content-Type-Options'      => 'nosniff',
        ]);
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function isAuthenticated(): bool
    {
        return $this->getServiceManager()->get('MelisCoreAuth')->hasIdentity();
    }

    private function getAuthStorage(): object
    {
        return $this->getServiceManager()->get('MelisCoreAuth')->getStorage()->read();
    }

    /**
     * Release the PHP session lock early for a READ-ONLY endpoint.
     *
     * PHP holds an EXCLUSIVE lock on the session file from session_start() (done in bootstrap) until
     * the script ends. Slow read-only endpoints — notably /me and /menu, which build the rights /
     * capabilities / menu tree over every tool and can take several SECONDS — would otherwise
     * serialise every other request sharing the same session cookie. In practice a user-edit fetch
     * (GET /users/:id, ~10ms of work) blocks for seconds behind an in-flight /menu or /me, the form
     * spins, and if the wait is interrupted the page bounces back to the list. Closing the session
     * here frees the lock while $_SESSION stays readable in-memory (identity/rights reads still work);
     * only session WRITES after this point would be lost, which these endpoints never do.
     */
    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    /**
     * Cross-session cache for expensive GLOBAL external data (marketplace lists, news feed).
     *
     * The legacy services (MelisCorePluginsService::getPackagistCategories / getMelisPublicModules,
     * the News bubble plugin) cache these only in the PHP SESSION, so the FIRST dashboard request of
     * every new login session refetches them over HTTP (each `file_get_contents`/cURL blocks ~0.7s+)
     * — the dashboard's cold-load latency. This caches the decoded payload to a file SHARED by all
     * sessions, with a TTL, so the network cost is paid at most once per TTL for the whole platform
     * (and never again inside a request that has already released its session lock).
     *
     * The producer returns `null` to signal a TRANSIENT failure (source unreachable): the result is
     * not cached and, when available, the last cached value is served stale rather than a hole.
     *
     * @param callable():mixed $producer
     * @return mixed the produced/cached value, or null on a first-ever failure
     */
    private function externalCached(string $key, int $ttlSeconds, callable $producer)
    {
        $dir  = sys_get_temp_dir() . '/melis-react-api-cache';
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.json';

        $readCache = static function () use ($file) {
            if (!is_file($file)) return null;
            $decoded = json_decode((string) @file_get_contents($file), true);
            return (is_array($decoded) && array_key_exists('v', $decoded)) ? $decoded : null;
        };

        $cached = $readCache();
        if ($cached !== null && (time() - (int) filemtime($file)) < $ttlSeconds) {
            return $cached['v'];
        }

        $value = $producer();
        if ($value !== null) {
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            @file_put_contents($file, json_encode(['v' => $value]), LOCK_EX);
            return $value;
        }

        // Transient failure: serve the last known value (even if stale) rather than a hole.
        return $cached !== null ? $cached['v'] : null;
    }
}
