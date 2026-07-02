<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

/**
 * JSON CRUD API for the "Instances" tab of the MelisAI Admin tool.
 *
 * Brick UI lives in melis-ai/ui-react/src/InstancesPage.tsx — this controller
 * is the shared API layer (melis-react-api), never coupled to the legacy jQuery tool.
 *
 * Routes (all under /melis/react-api/ai-instances/…):
 *   GET  /ai-instances/bootstrap   → agents + BO languages (form init)
 *   GET  /ai-instances/stats       → KPI counts
 *   GET  /ai-instances/list        → paginated list (search, status filter)
 *   POST /ai-instances/save        → create / update + translations
 *   DELETE /ai-instances/delete/:id
 *   GET  /ai-instances/:id         → single item + translations
 */
class MelisReactApiAiInstanceController extends MelisAbstractActionController
{
    private const MELIS_KEY = 'melisai_instances';

    /** Instance IDs that are hardcoded in the platform — mai_instance_id cannot be changed. */
    private const PROTECTED_IDS = ['agenttool', 'admintool', 'mainchatassistantgeneral'];

    // ─── GET /ai-instances/bootstrap ─────────────────────────────────────────────

    public function bootstrapAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            $agents = array_map(
                static fn($r): array => ['id' => (int) $r['maa_id'], 'name' => (string) $r['maa_name']],
                iterator_to_array($db->query(
                    'SELECT maa_id, maa_name FROM melis_ai_agents WHERE maa_status = 1 ORDER BY maa_name',
                    []
                ))
            );

            $langs = array_map(
                static fn($r): array => [
                    'id'     => (int) $r['lang_id'],
                    'locale' => (string) $r['lang_locale'],
                    'name'   => (string) $r['lang_name'],
                ],
                iterator_to_array($db->query(
                    'SELECT lang_id, lang_locale, lang_name FROM melis_core_lang ORDER BY lang_id',
                    []
                ))
            );

            return $this->jsonResponse(['success' => true, 'data' => [
                'agents' => $agents,
                'langs'  => $langs,
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /ai-instances/stats ──────────────────────────────────────────────────

    public function statsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db  = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $row = iterator_to_array($db->query(
                'SELECT COUNT(*) AS total,
                        SUM(mai_status = 1) AS active,
                        SUM(mai_status = 0) AS inactive
                 FROM melis_ai_instances',
                []
            ))[0];

            return $this->jsonResponse(['success' => true, 'data' => [
                'total'    => (int) $row['total'],
                'active'   => (int) $row['active'],
                'inactive' => (int) $row['inactive'],
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /ai-instances/list ───────────────────────────────────────────────────

    public function listAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db     = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $page   = max(1, (int) $this->params()->fromQuery('page', 1));
            $limit  = max(1, min(200, (int) $this->params()->fromQuery('limit', 50)));
            $search = trim((string) $this->params()->fromQuery('search', ''));
            $status = $this->params()->fromQuery('status', '');
            $offset = ($page - 1) * $limit;

            $where  = [];
            $params = [];

            if ($search !== '') {
                $where[]  = '(i.mai_name LIKE ? OR i.mai_instance_id LIKE ? OR a.maa_name LIKE ?)';
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($status !== '') {
                $where[]  = 'i.mai_status = ?';
                $params[] = (int) $status;
            }

            $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $baseSql = "FROM melis_ai_instances i
                        LEFT JOIN melis_ai_agents a ON a.maa_id = i.mai_maa_id
                        $whereStr";

            $total = (int) iterator_to_array($db->query(
                "SELECT COUNT(*) AS cnt $baseSql", $params
            ))[0]['cnt'];

            $rows = iterator_to_array($db->query(
                "SELECT i.mai_id, i.mai_maa_id, i.mai_instance_id, i.mai_name, i.mai_description,
                        i.mai_status, i.mai_last_user_id, i.mai_date_creation, a.maa_name
                 $baseSql ORDER BY i.mai_id DESC LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            ));

            return $this->jsonResponse(['success' => true, 'data' => [
                'items' => array_map(fn($r) => $this->formatInstance((array) $r), $rows),
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /ai-instances/:id ────────────────────────────────────────────────────

    public function getAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $id = (int) $this->params()->fromRoute('id', 0);
            if (!$id) {
                return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404);
            }
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            $rows = iterator_to_array($db->query(
                'SELECT i.*, a.maa_name
                 FROM melis_ai_instances i
                 LEFT JOIN melis_ai_agents a ON a.maa_id = i.mai_maa_id
                 WHERE i.mai_id = ?',
                [$id]
            ));
            if (!$rows) {
                return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404);
            }

            $item = $this->formatInstance((array) $rows[0]);

            // Attach translations for every BO language (empty string if no record yet)
            $langs = iterator_to_array($db->query(
                'SELECT lang_id, lang_locale, lang_name FROM melis_core_lang ORDER BY lang_id',
                []
            ));
            $translations = [];
            foreach ($langs as $lang) {
                $langId   = (int) $lang['lang_id'];
                $transRow = iterator_to_array($db->query(
                    'SELECT mait_id, mait_label FROM melis_ai_instance_trans
                     WHERE mait_mai_id = ? AND mait_lang_id = ?',
                    [$id, $langId]
                ));
                $t = $transRow[0] ?? null;
                $translations[] = [
                    'langId'   => $langId,
                    'locale'   => (string) $lang['lang_locale'],
                    'langName' => (string) $lang['lang_name'],
                    'transId'  => $t ? (int) $t['mait_id'] : null,
                    'label'    => $t ? (string) $t['mait_label'] : '',
                ];
            }
            $item['translations'] = $translations;

            return $this->jsonResponse(['success' => true, 'data' => $item]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── POST /ai-instances/save ──────────────────────────────────────────────────

    public function saveAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $body = json_decode($this->getRequest()->getContent(), true) ?? [];

            $id         = (int)    ($body['id']         ?? 0);
            $name       = trim((string) ($body['name']       ?? ''));
            $instanceId = trim((string) ($body['instanceId'] ?? ''));
            $status     = (int)    ($body['status']     ?? 0);
            $agentId    = isset($body['agentId']) && $body['agentId'] !== '' ? (int) $body['agentId'] : null;
            $transList  = (array)  ($body['translations'] ?? []);

            if ($name === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'Name is required'], 400);
            }
            if ($instanceId === '') {
                return $this->jsonResponse(['success' => false, 'error' => 'Instance ID is required'], 400);
            }

            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            // On edit, lock instanceId for protected records
            if ($id) {
                $existing = iterator_to_array($db->query(
                    'SELECT mai_instance_id FROM melis_ai_instances WHERE mai_id = ?', [$id]
                ));
                if ($existing && in_array((string) $existing[0]['mai_instance_id'], self::PROTECTED_IDS, true)) {
                    $instanceId = (string) $existing[0]['mai_instance_id'];
                }
            }

            // Uniqueness check on mai_instance_id (excluding current record on edit)
            $dup = iterator_to_array($db->query(
                'SELECT COUNT(*) AS cnt FROM melis_ai_instances WHERE mai_instance_id = ? AND mai_id <> ?',
                [$instanceId, $id]
            ));
            if ((int) ($dup[0]['cnt'] ?? 0) > 0) {
                return $this->jsonResponse(['success' => false, 'error' => 'Instance ID already exists'], 400);
            }

            $auth   = $this->getServiceManager()->get('MelisCoreAuth');
            $userId = (int) ($auth->getIdentity()->usr_id ?? 0);

            if ($id) {
                $db->query(
                    'UPDATE melis_ai_instances
                     SET mai_name=?, mai_instance_id=?, mai_status=?, mai_maa_id=?, mai_last_user_id=?
                     WHERE mai_id=?',
                    [$name, $instanceId, $status, $agentId, $userId, $id]
                );
                $newId = $id;
            } else {
                $db->query(
                    'INSERT INTO melis_ai_instances (mai_name, mai_instance_id, mai_status, mai_maa_id, mai_last_user_id)
                     VALUES (?, ?, ?, ?, ?)',
                    [$name, $instanceId, $status, $agentId, $userId]
                );
                $newId = (int) iterator_to_array($db->query('SELECT LAST_INSERT_ID() AS id', []))[0]['id'];
            }

            // Upsert translations
            foreach ($transList as $t) {
                $langId  = (int) ($t['langId']  ?? 0);
                $transId = (int) ($t['transId'] ?? 0);
                $label   = trim((string) ($t['label'] ?? ''));
                if (!$langId) { continue; }

                if ($transId) {
                    $db->query(
                        'UPDATE melis_ai_instance_trans SET mait_label=? WHERE mait_id=?',
                        [$label, $transId]
                    );
                } else {
                    $db->query(
                        'INSERT INTO melis_ai_instance_trans (mait_mai_id, mait_lang_id, mait_label)
                         VALUES (?, ?, ?)',
                        [$newId, $langId, $label]
                    );
                }
            }

            return $this->jsonResponse(['success' => true, 'data' => ['id' => $newId]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── DELETE /ai-instances/delete/:id ─────────────────────────────────────────

    public function deleteAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $id = (int) $this->params()->fromRoute('id', 0);
            if (!$id) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid ID'], 400);
            }
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $existing = iterator_to_array($db->query(
                'SELECT mai_instance_id FROM melis_ai_instances WHERE mai_id = ?', [$id]
            ));
            if ($existing && in_array((string) $existing[0]['mai_instance_id'], self::PROTECTED_IDS, true)) {
                return $this->jsonResponse(['success' => false, 'error' => 'This instance is protected and cannot be deleted'], 400);
            }
            // Translations cascade on DB level (FK); delete translations first if no cascade
            $db->query('DELETE FROM melis_ai_instance_trans WHERE mait_mai_id = ?', [$id]);
            $db->query('DELETE FROM melis_ai_instances WHERE mai_id = ?', [$id]);
            return $this->jsonResponse(['success' => true, 'data' => null]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── private helpers ─────────────────────────────────────────────────────────

    private function formatInstance(array $r): array
    {
        return [
            'id'           => (int)    $r['mai_id'],
            'agentId'      => $r['mai_maa_id'] !== null ? (int) $r['mai_maa_id'] : null,
            'agentName'    => (string) ($r['maa_name']      ?? ''),
            'instanceId'   => (string) $r['mai_instance_id'],
            'name'         => (string) $r['mai_name'],
            'description'  => (string) ($r['mai_description'] ?? ''),
            'status'       => (int)    $r['mai_status'],
            'lastUserId'   => (int)    ($r['mai_last_user_id'] ?? 0),
            'dateCreation' => (string) ($r['mai_date_creation'] ?? ''),
            'isProtected'  => in_array((string) $r['mai_instance_id'], self::PROTECTED_IDS, true),
        ];
    }

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
