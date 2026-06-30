<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

/**
 * API REST pour l'onglet "Usage" de l'outil Admin de MelisAI (melisKey melisai_usage).
 *
 * Couche API (shared) du back-office React ; l'UI native est livrée par la BRIQUE du
 * module MelisAI (gating modulaire). Calqué sur les contrôleurs natifs (gabarit full-React).
 *
 * Cet onglet est un TABLEAU DE BORD (graphes), pas un CRUD : le contrôleur ne fournit que le
 * "bootstrap" (liste des sociétés + modèles + modèle actif) pour peupler les sélecteurs.
 * Les DONNÉES DES GRAPHES sont réutilisées telles quelles depuis les endpoints legacy JSON
 * (`/melis/MelisAI/Admin/{getModelsByCompany,updateAllCharts,updateChart}`), qui s'appuient
 * déjà sur MelisAIUsageService — pas de duplication de la logique de mise en forme.
 *
 * Route :
 *   GET /melis/react-api/ai-usage/bootstrap → { companies, models, activeModelId, activeCompanyId }
 */
class MelisReactApiAiUsageController extends MelisAbstractActionController
{
    /** melisKey de l'outil — utilisé par le garde de droits (cf. denyUnlessAccess). */
    private const MELIS_KEY = 'melisai_usage';

    // ─── GET /ai-usage/bootstrap ─────────────────────────────────────────────────

    public function bootstrapAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            // $db->query() yields ArrayObject rows (ArrayAccess) — don't type-hint as array.
            $companies = array_map(
                static fn($r): array => ['id' => (int) $r['macp_id'], 'name' => (string) $r['macp_company_name']],
                iterator_to_array($db->query(
                    'SELECT macp_id, macp_company_name FROM melis_ai_companies ORDER BY macp_company_name',
                    []
                ))
            );

            $models = array_map(
                static fn($r): array => [
                    'id'        => (int) $r['mam_id'],
                    'name'      => (string) $r['mam_name'],
                    'companyId' => (int) $r['mam_macp_id'],
                    'status'    => (int) $r['mam_status'],
                ],
                iterator_to_array($db->query(
                    'SELECT mam_id, mam_name, mam_macp_id, mam_status FROM melis_ai_models ORDER BY mam_name',
                    []
                ))
            );

            // Active model = first with status=1 (mirrors AdminController::renderToolUsageAction).
            $active = null;
            foreach ($models as $m) {
                if ($m['status'] === 1) { $active = $m; break; }
            }

            return $this->jsonResponse(['success' => true, 'data' => [
                'companies'       => $companies,
                'models'          => $models,
                'activeModelId'   => $active['id'] ?? null,
                'activeCompanyId' => $active['companyId'] ?? null,
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /ai-usage/charts?modelId= ───────────────────────────────────────────
    // Les 4 graphes d'un modèle (défaut période 1 MONTH). Remplace l'appel legacy
    // /melis/MelisAI/Admin/updateAllCharts → l'onglet ne dépend plus du contrôleur jQuery.

    public function chartsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $modelId = (int) $this->params()->fromQuery('modelId', 0);
            if (!$modelId) { return $this->jsonResponse(['success' => false, 'error' => 'modelId required'], 400); }
            $data = [];
            foreach (['total-queries', 'total-queries-per-instance', 'total-tokens', 'total-tokens-per-instance'] as $type) {
                $data[$type] = $this->buildChart($type, '1 MONTH', $modelId);
            }
            return $this->jsonResponse(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /ai-usage/chart?type=&period=&modelId= ──────────────────────────────
    // Un seul graphe (changement de période). Remplace /melis/MelisAI/Admin/updateChart.

    public function chartAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $modelId = (int) $this->params()->fromQuery('modelId', 0);
            $type    = (string) $this->params()->fromQuery('type', '');
            $period  = (string) $this->params()->fromQuery('period', '1 MONTH');
            if (!$modelId || $type === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'type and modelId required'], 400);
            }
            return $this->jsonResponse(['success' => true, 'data' => $this->buildChart($type, $period, $modelId)]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Met en forme un graphe d'usage. Porté de MelisAI\Controller\AdminController::getChart
     * (même service métier MelisAIUsageService — la logique reste côté Melis, l'API ne fait que
     * la mise en forme JSON). Retourne { labels, datasets:[{label,data:[…]}], maxValue }.
     */
    private function buildChart(string $chartType, string $period, int $modelId): array
    {
        $usageService = $this->getServiceManager()->get('MelisAIUsageService');
        $translator   = $this->getServiceManager()->get('translator');
        $datasets = [];
        $labels   = [];
        $maxValue = 0;

        switch ($chartType) {
            case 'total-tokens':
            case 'total-queries':
                $total = ($chartType === 'total-tokens')
                    ? $usageService->getTotalTokens($modelId, $period, false)->toArray()
                    : $usageService->getTotalQueries($modelId, $period, false)->toArray();
                if ($total) {
                    $minDate = null;
                    $chartData = [];
                    foreach ($total as $d) {
                        $minDate  = empty($minDate) && $period === 'ALL' ? $d['period'] : $minDate;
                        $maxValue = $d['total'] > $maxValue ? $d['total'] : $maxValue;
                        $chartData[] = ['period' => $d['period'], 'total' => $d['total']];
                    }
                    $labels = $this->generateLabels($period, $minDate);
                    $points = [];
                    foreach ($labels as $date) {
                        $k = array_search($date, array_column($chartData, 'period'));
                        $points[] = $k !== false ? (int) $chartData[$k]['total'] : 0;
                    }
                    $datasets[] = ['label' => $translator->translate('tr_melisai_usage_all'), 'data' => $points];
                } else {
                    $labels = $this->generateLabels($period);
                }
                break;

            case 'total-tokens-per-instance':
            case 'total-queries-per-instance':
                $total = ($chartType === 'total-tokens-per-instance')
                    ? $usageService->getTotalTokens($modelId, $period, true)->toArray()
                    : $usageService->getTotalQueries($modelId, $period, true)->toArray();
                if ($total) {
                    $minDate   = null;
                    $byInstance = [];
                    foreach ($total as $d) {
                        $minDate  = empty($minDate) && $period === 'ALL' ? $d['period'] : $minDate;
                        $maxValue = $d['total'] > $maxValue ? $d['total'] : $maxValue;
                        $byInstance[$d['mai_instance_id']][] = ['period' => $d['period'], 'data' => $d['total']];
                    }
                    $labels = $this->generateLabels($period, $minDate);
                    foreach ($byInstance as $instanceId => $cData) {
                        $points = [];
                        foreach ($labels as $date) {
                            $k = array_search($date, array_column($cData, 'period'));
                            $points[] = $k !== false ? (int) $cData[$k]['data'] : 0;
                        }
                        $datasets[] = ['label' => (string) $instanceId, 'data' => $points];
                    }
                } else {
                    $labels = $this->generateLabels($period);
                }
                break;

            default:
                throw new \InvalidArgumentException('Unknown chart type: ' . $chartType);
        }

        return ['labels' => $labels, 'datasets' => $datasets, 'maxValue' => $maxValue];
    }

    /** Porté de AdminController::generateLabels — étiquettes de dates selon la période. */
    private function generateLabels(string $duration, $minDate = null): array
    {
        $labels = [];
        switch ($duration) {
            case '1 DAY':
            case '7 DAY':
            case '1 MONTH':
                $days = $duration === '1 DAY' ? 1 : ($duration === '7 DAY' ? 7 : 30);
                for ($i = $days - 1; $i >= 0; $i--) {
                    $labels[] = (new \DateTime())->modify("-$i days")->format('Y-m-d');
                }
                break;
            case '3 MONTH':
            case '6 MONTH':
            case '1 YEAR':
                $months = ['3 MONTH' => 3, '6 MONTH' => 6, '1 YEAR' => 12][$duration];
                for ($i = $months - 1; $i >= 0; $i--) {
                    $labels[] = (new \DateTime())->modify("-$i month")->format('Y-m');
                }
                break;
            case 'ALL':
                $minDate     = $minDate ?? date('Y');
                $startYear   = (int) date('Y', strtotime((string) $minDate)) - 3;
                $currentYear = (int) date('Y');
                for ($y = $startYear; $y <= $currentYear; $y++) {
                    $labels[] = $y;
                }
                break;
        }
        return $labels;
    }

    // ─── helpers (copiés du gabarit, cf. MelisReactApiSiteRedirectController) ──────

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
