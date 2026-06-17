<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

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

        $user = $this->getAuthStorage();

        // Current user's profile picture (usr_image blob) as a data URI, like the legacy header.
        $picture = null;
        try {
            $identity = $this->getServiceManager()->get('MelisCoreAuth')->getIdentity();
            if (!empty($identity->usr_image)) {
                $picture = 'data:image/jpeg;base64,' . base64_encode($identity->usr_image);
            }
        } catch (\Throwable) {
        }

        return $this->jsonResponse([
            'success' => true,
            'data'    => [
                'id'      => (int) ($user->usr_id ?? 0),
                'name'    => trim(($user->usr_firstname ?? '') . ' ' . ($user->usr_lastname ?? '')),
                'login'   => $user->usr_login ?? '',
                'email'   => $user->usr_email ?? '',
                'picture' => $picture,
            ],
        ]);
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
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $container     = new \Laminas\Session\Container('meliscore');
        $currentLocale = (string) ($container['melis-lang-locale'] ?? 'en_EN');
        $currentId     = (int) ($container['melis-lang-id'] ?? 0);

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
                $entry = $manifest['entry'] ?? 'brick.js';
                // Cache-bust: the bundle filename is not content-hashed, so append the
                // built file's mtime — each rebuild yields a new URL and the browser
                // never serves a stale brick.
                $bundleFile = rtrim($path, '/\\') . '/public/ui-react/' . $entry;
                $ver        = is_file($bundleFile) ? (string) filemtime($bundleFile) : '0';
                $bricks[] = [
                    'id'         => $manifest['id'] ?? strtolower($module),
                    'module'     => $module,
                    'route'      => $manifest['route'] ?? null,
                    'label'      => $manifest['label'] ?? $module,
                    'forwardKey' => $manifest['forwardKey'] ?? null,
                    'melisKey'   => $manifest['melisKey'] ?? null,
                    'bundleUrl'  => '/' . $module . '/ui-react/' . $entry . '?v=' . $ver,
                ];
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

        $sm   = $this->getServiceManager();
        $data = [
            'news'          => ['count' => 0],
            'updates'       => ['count' => 0],
            'notifications' => ['count' => 0],
            'messages'      => ['count' => 0],
        ];

        // Notifications — MelisCore flash messenger (always available).
        try {
            $notifs = json_decode((string) $sm->get('MelisCoreFlashMessenger')->getFlashMessengerMessages(), true);
            if (is_array($notifs)) {
                $data['notifications']['count'] = count($notifs);
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

        // News — external marketplace feed via cURL (short timeout), like the plugin.
        try {
            $cfg     = $sm->get('MelisCoreConfig')->getItem('/meliscore/interface/melis_dashboardplugin/interface/melisdashboardplugin_section');
            $newsCfg = $cfg['interface']['MelisCoreDashboardBubbleNewsMelisPlugin']['datas'] ?? null;
            if (!empty($newsCfg['url'])) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $newsCfg['url']);
                if (isset($newsCfg['filter'])) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $newsCfg['filter']);
                }
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($curl, CURLOPT_TIMEOUT, 5);
                curl_setopt($curl, CURLOPT_NOSIGNAL, 1);
                $resp = json_decode((string) curl_exec($curl), true);
                curl_close($curl);
                if (!empty($resp['response']) && is_array($resp['response'])) {
                    $data['news']['count'] = count($resp['response']);
                }
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        // Updates — only meaningful when MelisMarketPlace is active; otherwise 0.
        try {
            $modules = $sm->get('MelisAssetManagerModulesService');
            if ($modules->isModuleLoaded('MelisMarketPlace')) {
                $res = $this->forward()->dispatch(
                    'MelisMarketPlace\Controller\MelisMarketPlace',
                    ['action' => 'isMarketplaceAccessible']
                )->getVariables();
                // Detailed update count needs the marketplace tool; expose availability as 0/at-least.
                if (!empty($res['isMarketplaceAccessible'])) {
                    $data['updates']['count'] = 0; // placeholder until marketplace count is wired
                }
            }
        } catch (\Throwable $e) { /* keep 0 */ }

        return $this->jsonResponse(['success' => true, 'data' => $data]);
    }

    // ─── /melis/react-api/menu ───────────────────────────────────────────────

    public function menuAction(): HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        try {
            return $this->buildMenuResponse();
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

    private function buildMenuResponse(): HttpResponse
    {
        $sm          = $this->getServiceManager();
        $melisConfig = $sm->get('MelisCoreConfig');
        $melisRights = $sm->get('MelisCoreRights');
        $melisKeys   = $melisConfig->getMelisKeys();

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

            if (!$melisRights->canAccess($sectionKey)) {
                continue;
            }

            if (empty($melisKeys[$sectionKey])) {
                continue;
            }

            $sectionConfig = $melisConfig->getItem($melisKeys[$sectionKey]);

            if (empty($sectionConfig['interface'])) {
                continue;
            }

            $sectionName = $sectionConfig['conf']['name'] ?? $sectionKey;
            $sectionIcon = $sectionConfig['conf']['icon'] ?? '';

            $orderedInterface = $this->applyOrdering(
                $sectionConfig['interface'],
                $melisConfig->getOrderInterfaceConfig($sectionKey)
            );

            $categories = $this->buildLevel2($orderedInterface, $melisRights);

            if (empty($categories)) {
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
                'children'    => $categories,
            ];
        }

        return $this->jsonResponse(['success' => true, 'data' => $menu]);
    }

    // ─── Helpers de traversée ────────────────────────────────────────────────

    private function buildLevel2(array $interface, $melisRights): array
    {
        $categories = [];

        foreach ($interface as $catKey => $catConfig) {
            if (!$melisRights->canAccess($catKey)) {
                continue;
            }
            if (!($catConfig['conf']['left_menu_display'] ?? true)) {
                continue;
            }

            $catName     = $catConfig['conf']['name'] ?? $catKey;
            $catIcon     = $catConfig['conf']['icon'] ?? 'fa-folder';
            $hasNavChild = false;
            $tools       = [];

            if (!empty($catConfig['interface'])) {
                $tools       = $this->buildLevel3($catConfig['interface'], $melisRights);
                $hasNavChild = !empty($tools);
            } elseif (!empty($catConfig['forward'])) {
                $tools = [[
                    'key'         => $catKey,
                    'name'        => $catName,
                    'icon'        => $catIcon,
                    'melisKey'    => $catConfig['conf']['melisKey'] ?? $catKey,
                    'isTool'      => true,
                    'forward'     => $catConfig['forward'],
                    'hasNavChild' => false,
                    'children'    => [],
                ]];
                $hasNavChild = true;
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
                'children'    => $tools,
            ];
        }

        return $categories;
    }

    private function buildLevel3(array $interface, $melisRights): array
    {
        $tools = [];

        foreach ($interface as $toolKey => $toolConfig) {
            if (!$melisRights->canAccess($toolKey)) {
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
                    if (!$melisRights->canAccess($subKey)) {
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

            $tools[] = [
                'key'         => $toolKey,
                'name'        => $toolConfig['conf']['name'] ?? $toolKey,
                'icon'        => $toolIcon ?: 'fa-cube',
                'melisKey'    => $toolConfig['conf']['melisKey'] ?? $toolKey,
                'isTool'      => !empty($toolConfig['forward']),
                'forward'     => $toolConfig['forward'] ?? [],
                'hasNavChild' => $hasNavChild,
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
}
