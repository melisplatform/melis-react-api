<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

/**
 * API REST pour l'onglet "Platform AI" de l'outil Admin de MelisAI (melisKey melisai_platform_ai).
 *
 * Couche API (shared) du back-office React ; l'UI native est livrée par la BRIQUE du module MelisAI.
 * Cet onglet est un FORMULAIRE de configuration (clés API par plateforme + réglages d'upload), pas un
 * CRUD. Conformément à l'approche "A" (le formulaire de sauvegarde est trop couplé pour être réécrit) :
 *  - cet endpoint ne fournit que les DONNÉES de rendu (jamais les clés en clair, seulement "clé existe"),
 *  - la SAUVEGARDE et la suppression de clé réutilisent les endpoints legacy
 *    (`/melis/MelisAI/Admin/save`, `/melis/MelisAI/Admin/removeKey`) — le chiffrement/save reste côté Melis.
 *
 * Route : GET /melis/react-api/ai-platform/data
 *   → { platforms, model|null, models, keyExistsMap, sameKeyExists }
 */
class MelisReactApiAiPlatformController extends MelisAbstractActionController
{
    private const MELIS_KEY = 'melisai_platform_ai';

    public function dataAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            $platforms = array_map(
                static fn($r): array => ['id' => (int) $r['plf_id'], 'name' => (string) $r['plf_name']],
                iterator_to_array($db->query('SELECT plf_id, plf_name FROM melis_core_platform ORDER BY plf_id', []))
            );

            // AI companies (providers): drive the model dropdown — selecting a company filters its models.
            $companies = array_map(
                static fn($r): array => ['id' => (int) $r['macp_id'], 'name' => (string) $r['macp_company_name']],
                iterator_to_array($db->query('SELECT macp_id, macp_company_name FROM melis_ai_companies ORDER BY macp_company_name', []))
            );

            // Active model: prefer mam_is_default=1, fall back to mam_status=1, then first available.
            $rows = iterator_to_array($db->query(
                'SELECT * FROM melis_ai_models ORDER BY mam_is_default DESC, mam_status DESC, mam_id LIMIT 1', []
            ));
            $default = $rows[0] ?? null;

            // Saved-key existence per company → { companyId: { platformId: true } } for non-empty keys.
            // Keyed by company because the company is selectable in the form: switching company must
            // reflect that company's own keys, not the default company's.
            $keysByCompany = [];
            foreach (iterator_to_array($db->query(
                'SELECT mapk_macp_id, mapk_platform_id, mapk_keys FROM melis_ai_platform_keys', []
            )) as $pk) {
                if (trim((string) $pk['mapk_keys']) === '') { continue; }
                $keysByCompany[(int) $pk['mapk_macp_id']][(int) $pk['mapk_platform_id']] = true;
            }
            // JSON-encode each company's map as an object even when it has a single entry.
            $keysByCompanyOut = [];
            foreach ($keysByCompany as $cid => $map) { $keysByCompanyOut[$cid] = (object) $map; }

            // Mode in which each company's keys were last saved (mam_same_keys_platforms): 1=common, 0=per-platform.
            // Keys are stored per company but the raw rows don't record the mode, so we read it from the
            // company's representative model (its default model first). The front shows the "key saved" hint
            // ONLY in the matching mode → a key saved in common mode is not advertised in per-platform mode.
            $keyModeByCompany = [];
            foreach (iterator_to_array($db->query(
                'SELECT mam_macp_id, mam_same_keys_platforms FROM melis_ai_models ORDER BY mam_is_default DESC, mam_status DESC, mam_id', []
            )) as $m) {
                $cid = (int) $m['mam_macp_id'];
                if (!array_key_exists($cid, $keyModeByCompany)) {
                    $keyModeByCompany[$cid] = (int) $m['mam_same_keys_platforms'];
                }
            }

            $data = [
                'platforms'        => $platforms,
                'companies'        => $companies,
                'model'            => null,
                'models'           => [],
                'keysByCompany'    => $keysByCompanyOut ? (object) $keysByCompanyOut : (object) [],
                'keyModeByCompany' => $keyModeByCompany ? (object) $keyModeByCompany : (object) [],
            ];

            if ($default) {
                $companyId = (int) $default['mam_macp_id'];

                // Every model with its FULL settings → selecting a model in the form applies its own
                // values (status, default flag, key mode, upload config), instead of keeping the
                // initially-loaded default model's values.
                $mapModel = static fn($r): array => [
                    'id'                => (int) $r['mam_id'],
                    'companyId'         => (int) $r['mam_macp_id'],
                    'name'              => (string) $r['mam_name'],
                    'status'            => (int) $r['mam_status'],
                    'isDefault'         => (int) $r['mam_is_default'],
                    'sameKeysPlatforms' => (int) $r['mam_same_keys_platforms'],
                    'fileUserActive'    => (int) $r['mam_file_user_active'],
                    'fileContextActive' => (int) $r['mam_file_context_active'],
                    'fileUploadMode'    => (string) ($r['mam_file_upload_mode'] ?? ''),
                    'internalUpload'    => (int) ($r['mam_internal_upload'] ?? 0),
                    'fileMaxSizeMb'     => (string) $r['mam_file_max_size_mb'],
                ];

                $data['models'] = array_map(
                    $mapModel,
                    iterator_to_array($db->query('SELECT * FROM melis_ai_models ORDER BY mam_name', []))
                );

                $data['model'] = $mapModel($default);
            }

            return $this->jsonResponse(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── helpers (gabarit) ────────────────────────────────────────────────────────

    private function isAuthenticated(): bool
    {
        return $this->getServiceManager()->get('MelisCoreAuth')->hasIdentity();
    }

    private function denyUnlessAccess(): ?HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }
        try {
            if (!$this->getServiceManager()->get('MelisCoreRights')->canAccess(self::MELIS_KEY)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
            }
        } catch (\Throwable) {}
        return null;
    }

    private function jsonResponse(array $data, int $status = 200): HttpResponse
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaders([
            'Content-Type'           => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function errorResponse(\Throwable $e, int $status = 500): HttpResponse
    {
        return $this->jsonResponse([
            'success' => false,
            'error'   => $e->getMessage(),
            'file'    => basename($e->getFile()) . ':' . $e->getLine(),
        ], $status);
    }
}
