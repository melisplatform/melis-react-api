<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

/**
 * API REST native (React) pour l'outil « AI Agents » de MelisAI — table melis_ai_agents.
 * Listing + formulaire + scénario, façon gabarit Users de MelisReactApi. La sauvegarde /
 * suppression réutilisent le service métier MelisAIAgentService (logique + cascade FK) — pas de
 * réimplémentation. Les écritures de scénario passent par les endpoints legacy MelisAI/AgentProperties.
 * Le chat de l'agent (onglet « Run ») passe désormais par AiChatContainer + /react-api/ai-engine/*
 * (skill melis-ai-chat-container), donc plus d'actions chatInit/chatLaunch ici.
 *
 * Exposé sous le préfixe partagé /melis/react-api/agents/*. Contrôleur + routes vivent dans le module
 * d'infra MelisReactApi (couche REST partagée — pattern « origin », comme MelisReactApiAiUsage) ; les
 * services métier propres à l'IA (MelisAIAgentService, MelisAIEngineService, tables/services de scénario)
 * sont résolus au runtime via le ServiceManager, disponibles tant que le module MelisAI est actif.
 *
 * Garde de droits : ACCESS à l'outil melisKey `melisagent_tool`.
 */
class MelisReactApiAiAgentController extends MelisAbstractActionController
{
    private const MELIS_KEY = 'melisagent_tool';

    // ─── GET /agents ─────────────────────────────────────────────────────────
    public function listAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $page      = max(1, (int) $this->params()->fromQuery('page', 1));
            $limit     = min(9999, max(1, (int) $this->params()->fromQuery('limit', 25)));
            $search    = trim((string) ($this->params()->fromQuery('search', '') ?? ''));
            $rawStatus = $this->params()->fromQuery('status', '');
            $status    = ($rawStatus !== '' && $rawStatus !== null) ? (int) $rawStatus : null;
            $offset    = ($page - 1) * $limit;

            $db     = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $where  = [];
            $params = [];
            if ($status !== null) { $where[] = 'a.maa_status = ?'; $params[] = $status; }
            if ($search !== '') {
                $like    = '%' . $search . '%';
                $where[] = '(a.maa_name LIKE ? OR a.maa_description LIKE ? OR a.maa_agent_code LIKE ?)';
                $params  = array_merge($params, [$like, $like, $like]);
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countRow = iterator_to_array($db->query("SELECT COUNT(*) AS total FROM melis_ai_agents a $whereClause", $params));
            $total    = (int) ($countRow[0]['total'] ?? 0);

            $rows = $db->query(
                "SELECT a.maa_id, a.maa_name, a.maa_description, a.maa_agent_code, a.maa_status,
                        a.maa_mam_id, a.maa_agent_tools, a.maa_date_creation, a.maa_date_last_edition,
                        m.mam_name, m.mam_macp_id, c.macp_company_name
                 FROM melis_ai_agents a
                 LEFT JOIN melis_ai_models m ON m.mam_id = a.maa_mam_id
                 LEFT JOIN melis_ai_companies c ON c.macp_id = m.mam_macp_id
                 $whereClause ORDER BY a.maa_id DESC LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            );

            $items = [];
            foreach ($rows as $row) { $items[] = $this->formatAgent((array) $row); }

            return $this->jsonResponse(['success' => true, 'data' => ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /agents/stats ───────────────────────────────────────────────────
    public function statsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db  = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $row = iterator_to_array($db->query(
                'SELECT COUNT(*) AS total, SUM(maa_status = 1) AS active, SUM(maa_status = 0) AS inactive FROM melis_ai_agents', []
            ))[0] ?? [];
            return $this->jsonResponse(['success' => true, 'data' => [
                'total' => (int) ($row['total'] ?? 0), 'active' => (int) ($row['active'] ?? 0), 'inactive' => (int) ($row['inactive'] ?? 0),
            ]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    // ─── GET /agents/options (companies + models + function declarations) ─────
    public function optionsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $companies = [];
            foreach ($db->query('SELECT macp_id, macp_company_name FROM melis_ai_companies ORDER BY macp_company_name', []) as $r) {
                $companies[] = ['id' => (int) $r['macp_id'], 'name' => (string) $r['macp_company_name']];
            }
            $models = [];
            foreach ($db->query('SELECT mam_id, mam_macp_id, mam_name, mam_file_user_active, mam_file_context_active FROM melis_ai_models ORDER BY mam_name', []) as $r) {
                $models[] = [
                    'id' => (int) $r['mam_id'], 'companyId' => (int) $r['mam_macp_id'], 'name' => (string) $r['mam_name'],
                    'fileUserActive' => (int) $r['mam_file_user_active'], 'fileContextActive' => (int) $r['mam_file_context_active'],
                ];
            }
            // Function declarations (the agent's selectable AI tools) come from melisaiengine config.
            $config = $this->getServiceManager()->get('config');
            $decls  = $config['plugins']['melisaiengine']['datas']['function_declarations'] ?? [];
            $tools  = [];
            foreach ($decls as $tool) {
                $tools[] = ['name' => (string) ($tool['name'] ?? ''), 'description' => (string) ($tool['description'] ?? ''), 'mcp' => !empty($tool['mcp'])];
            }
            return $this->jsonResponse(['success' => true, 'data' => ['companies' => $companies, 'models' => $models, 'tools' => $tools]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    // ─── GET /agents/:id ─────────────────────────────────────────────────────
    public function getAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id <= 0) { return $this->jsonResponse(['success' => false, 'error' => 'Invalid ID'], 400); }
        try {
            $db  = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $row = iterator_to_array($db->query(
                'SELECT a.*, m.mam_macp_id FROM melis_ai_agents a LEFT JOIN melis_ai_models m ON m.mam_id = a.maa_mam_id WHERE a.maa_id = ?', [$id]
            ));
            if (!$row) { return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404); }
            return $this->jsonResponse(['success' => true, 'data' => $this->formatAgent((array) $row[0])]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    // ─── POST /agents/save ───────────────────────────────────────────────────
    public function saveAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $body = json_decode($this->getRequest()->getContent(), true) ?? [];
            $id   = isset($body['id']) && $body['id'] ? (int) $body['id'] : null;
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') { return $this->jsonResponse(['success' => false, 'error' => 'Le nom de l\'agent est obligatoire'], 400); }

            $tools = $body['tools'] ?? [];
            if (!is_array($tools)) { $tools = []; }
            $code = trim((string) ($body['code'] ?? ''));
            if ($code === '') { $code = $this->slugCode($name); }

            $data = [
                'maa_name'                => $name,
                'maa_description'         => trim((string) ($body['description'] ?? '')),
                'maa_agent_code'          => $code,
                'maa_mam_id'              => !empty($body['modelId']) ? (int) $body['modelId'] : null,
                'maa_status'              => (int) (bool) ($body['status'] ?? false),
                'maa_file_user_active'    => (int) (bool) ($body['fileUserActive'] ?? false),
                'maa_file_context_active' => (int) (bool) ($body['fileContextActive'] ?? false),
                'maa_db_can_create_table' => (int) (bool) ($body['dbCanCreateTable'] ?? false),
                'maa_agent_tools'         => json_encode(array_values($tools)),
                'maa_last_user_id'        => (int) ($this->currentUserId() ?? 0),
            ];
            $now = date('Y-m-d H:i:s');
            $data['maa_date_last_edition'] = $now;
            if (!$id) { $data['maa_date_creation'] = $now; }

            $agentService = $this->getServiceManager()->get('MelisAIAgentService');
            $res = $agentService->saveItem($data, $id);
            $newId = $id ?: (int) $res;

            // On create: seed the ENTRY/EXIT PARAMS scenario steps (parity with savePropertiesAction).
            if (!$id && $newId) { $this->seedEntryExitSteps($newId); }

            // DB Rights tab: sync this agent's per-table rights when the tab was opened (payload carries
            // `dbRights`). Absent key = tab never loaded → leave existing rights untouched (legacy parity).
            if (array_key_exists('dbRights', $body) && is_array($body['dbRights'])) {
                $this->syncDbRights($newId, $body['dbRights']);
            }

            return $this->jsonResponse(['success' => true, 'data' => ['id' => $newId]], $id ? 200 : 201);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    // ─── DELETE /agents/delete/:id ───────────────────────────────────────────
    public function deleteAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id <= 0) { return $this->jsonResponse(['success' => false, 'error' => 'Invalid ID'], 400); }
        try {
            $this->getServiceManager()->get('MelisAIAgentService')->deleteItem($id);
            return $this->jsonResponse(['success' => true, 'data' => null]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    // ═══ DB RIGHTS (per-agent database access; parity with AgentProperties render-db-rights) ═══

    /**
     * GET /agents/db-rights/:id — every live DB table grouped by module, merged with this agent's
     * stored per-table rights (read/write/delete_row/drop) + the admin's "sensitive" flags, plus the
     * DB-wide create-table flag (on the agent row). Mirrors AgentPropertiesController::getAgentDbRightsGrouped.
     */
    public function dbRightsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id <= 0) { return $this->jsonResponse(['success' => false, 'error' => 'Invalid ID'], 400); }
        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

            $agentRow = iterator_to_array($db->query('SELECT maa_db_can_create_table FROM melis_ai_agents WHERE maa_id = ?', [$id]));
            if (!$agentRow) { return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404); }
            $globalCreate = (bool) ((int) ($agentRow[0]['maa_db_can_create_table'] ?? 0));

            // Live tables + their primary key (information_schema).
            $tables = iterator_to_array($db->query(
                "SELECT t.TABLE_NAME AS table_name,
                        (SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
                          WHERE k.TABLE_SCHEMA = t.TABLE_SCHEMA AND k.TABLE_NAME = t.TABLE_NAME
                            AND k.CONSTRAINT_NAME = 'PRIMARY' ORDER BY k.ORDINAL_POSITION LIMIT 1) AS pk
                 FROM information_schema.TABLES t
                 WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
                 ORDER BY t.TABLE_NAME", []
            ));

            // This agent's stored rights, keyed by table name.
            $rightsByTable = [];
            foreach ($db->query('SELECT madr_table_name, madr_read, madr_write, madr_delete_row, madr_drop FROM melis_ai_agent_db_rights WHERE madr_maa_id = ?', [$id]) as $r) {
                $rightsByTable[(string) $r['madr_table_name']] = (array) $r;
            }

            // Sensitive set (admin MCP Database tool) — cosmetic highlight; never block on its absence.
            $sensitive = [];
            try { $sensitive = $this->getServiceManager()->get('MelisAIMcpSensitiveTableTable')->getFlaggedTableNames(); } catch (\Throwable) {}

            $groups = [];
            $activeTables = 0;
            foreach ($tables as $row) {
                $tableName = (string) $row['table_name'];
                $module    = $this->moduleFromTableName($tableName);
                $r         = $rightsByTable[$tableName] ?? null;
                $read   = $r ? (bool) $r['madr_read'] : false;
                $write  = $r ? (bool) $r['madr_write'] : false;
                $delRow = $r ? (bool) $r['madr_delete_row'] : false;
                $drop   = $r ? (bool) $r['madr_drop'] : false;
                if ($read || $write || $delRow || $drop) { $activeTables++; }
                if (!isset($groups[$module])) { $groups[$module] = ['name' => $module, 'tables' => []]; }
                $groups[$module]['tables'][] = [
                    'name' => $tableName, 'pk' => (string) ($row['pk'] ?? ''), 'module' => $module,
                    'sensitive' => isset($sensitive[$tableName]),
                    'read' => $read, 'write' => $write, 'delete_row' => $delRow, 'drop' => $drop,
                ];
            }
            ksort($groups);

            return $this->jsonResponse(['success' => true, 'data' => [
                'globalCreate' => $globalCreate,
                'totalTables'  => count($tables),
                'activeTables' => $activeTables,
                'modules'      => array_values($groups),
            ]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    /**
     * Sync an agent's per-table DB rights from a { table_name: {read,write,delete_row,drop} } map:
     * drop the agent's rows, re-insert the granted ones (validated against live tables, empty rows
     * skipped). Mirrors AgentPropertiesController::saveAgentDbRights.
     */
    private function syncDbRights(int $agentId, array $rights): void
    {
        if ($agentId <= 0) { return; }
        $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');

        $valid = [];
        foreach ($db->query("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'", []) as $r) {
            $valid[(string) $r['n']] = true;
        }

        $db->query('DELETE FROM melis_ai_agent_db_rights WHERE madr_maa_id = ?', [$agentId]);

        foreach ($rights as $tableName => $perm) {
            if (!isset($valid[$tableName]) || !is_array($perm)) { continue; }
            $read   = (int) (bool) ($perm['read'] ?? false);
            $write  = (int) (bool) ($perm['write'] ?? false);
            $delRow = (int) (bool) ($perm['delete_row'] ?? false);
            $drop   = (int) (bool) ($perm['drop'] ?? false);
            if (!($read || $write || $delRow || $drop)) { continue; }
            $db->query(
                'INSERT INTO melis_ai_agent_db_rights (madr_maa_id, madr_table_name, madr_read, madr_write, madr_delete_row, madr_drop) VALUES (?, ?, ?, ?, ?, ?)',
                [$agentId, (string) $tableName, $read, $write, $delRow, $drop]
            );
        }
    }

    /** Readable module name from a table-name prefix (parity with AgentPropertiesController). */
    private function moduleFromTableName(string $table): string
    {
        $parts = explode('_', $table);
        if (count($parts) < 2 || $parts[0] !== 'melis') { return 'other'; }
        $map = [
            'core' => 'melis-core', 'cms' => 'melis-cms', 'ai' => 'melis-ai', 'newsletter' => 'melis-newsletter',
            'sb' => 'melis-small-business', 'docupl' => 'melis-document-upload', 'site' => 'melis-site',
            'plugins' => 'melis-plugins', 'ecom' => 'melis-commerce',
        ];
        return $map[$parts[1]] ?? ('melis-' . $parts[1]);
    }

    // ═══ SCENARIO (read + introspection; writes reuse the legacy endpoints) ═══

    /** GET /agents/scenario/list?agentId= — ordered steps for an agent (native scenario list). */
    public function scenarioListAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $agentId = (int) $this->params()->fromQuery('agentId', 0);
            if ($agentId <= 0) { return $this->jsonResponse(['success' => false, 'error' => 'Invalid agentId'], 400); }
            $langId = $this->currentLangId();
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            // One row per step; pull a text/route preview from its data row in the current locale (fallback any).
            $rows = $db->query(
                "SELECT s.mas_id, s.mas_order, s.mas_type, s.mas_code, s.mas_mart_id,
                        (SELECT d.massd_text FROM melis_ai_scenario_steps_datas d
                          WHERE d.massd_mas_id = s.mas_id ORDER BY (d.massd_lang_id = ?) DESC, d.massd_id ASC LIMIT 1) AS text_data,
                        (SELECT d.massd_route FROM melis_ai_scenario_steps_datas d WHERE d.massd_mas_id = s.mas_id AND d.massd_route <> '' LIMIT 1) AS route_data,
                        (SELECT d.massd_url FROM melis_ai_scenario_steps_datas d WHERE d.massd_mas_id = s.mas_id AND d.massd_url <> '' LIMIT 1) AS url_data
                 FROM melis_ai_scenario_steps s
                 WHERE s.mas_maa_id = ? ORDER BY s.mas_order ASC, s.mas_id ASC",
                [$langId, $agentId]
            );
            $items = [];
            foreach ($rows as $r) {
                $r = (array) $r;
                $fixed = in_array($r['mas_type'], ['ENTRY PARAMS', 'EXIT PARAMS'], true);
                $items[] = [
                    'id'       => (int) $r['mas_id'],
                    'order'    => (int) $r['mas_order'],
                    'type'     => (string) $r['mas_type'],
                    'code'     => (string) $r['mas_code'],
                    'text'     => (string) ($r['text_data'] ?? ''),
                    'route'    => trim(((string) ($r['route_data'] ?? '')) . (($r['url_data'] ?? '') ? ', ' . $r['url_data'] : ''), ', '),
                    'fixed'    => $fixed, // ENTRY/EXIT can't be reordered or deleted
                ];
            }
            return $this->jsonResponse(['success' => true, 'data' => ['items' => $items]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    /** GET /agents/scenario/step/:id — full step detail (per-lang text/label, route/url, entry/exit). */
    public function scenarioStepAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $id = (int) $this->params()->fromRoute('id', 0);
            if ($id <= 0) { return $this->jsonResponse(['success' => false, 'error' => 'Invalid ID'], 400); }
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $stepRow = iterator_to_array($db->query('SELECT * FROM melis_ai_scenario_steps WHERE mas_id = ?', [$id]));
            if (!$stepRow) { return $this->jsonResponse(['success' => false, 'error' => 'Not found'], 404); }
            $s = (array) $stepRow[0];
            $trans = [];
            foreach ($db->query('SELECT massd_id, massd_lang_id, massd_text, massd_label, massd_route, massd_url FROM melis_ai_scenario_steps_datas WHERE massd_mas_id = ?', [$id]) as $d) {
                $d = (array) $d;
                $trans[(int) $d['massd_lang_id']] = [
                    'massdId' => (int) ($d['massd_id'] ?? 0),
                    'text' => (string) ($d['massd_text'] ?? ''), 'label' => (string) ($d['massd_label'] ?? ''),
                    'route' => (string) ($d['massd_route'] ?? ''), 'url' => (string) ($d['massd_url'] ?? ''),
                ];
            }
            $ee = iterator_to_array($db->query('SELECT * FROM melis_ai_scenario_steps_datas_entryexit WHERE masse_mas_id = ?', [$id]));
            $ee = $ee ? (array) $ee[0] : [];
            return $this->jsonResponse(['success' => true, 'data' => [
                'id' => (int) $s['mas_id'], 'agentId' => (int) $s['mas_maa_id'], 'order' => (int) $s['mas_order'],
                'type' => (string) $s['mas_type'], 'code' => (string) $s['mas_code'], 'responseTypeId' => (int) ($s['mas_mart_id'] ?? 0),
                'trans' => $trans,
                'entry' => [
                    'module'     => (string) ($ee['masse_entry_module_name'] ?? ''),
                    'formName'    => (string) ($ee['masse_entry_form_name'] ?? ''),
                    'formPath'    => (string) ($ee['masse_entry_form_path'] ?? ''),
                    'fields'      => $ee['masse_entry_form_fields_json'] ?? '',
                    'custom'      => $ee['masse_entry_custom_json'] ?? '',
                ],
                'exit' => [
                    'input1' => (string) ($ee['masse_exit_input_1'] ?? ''), 'input2' => (string) ($ee['masse_exit_input_2'] ?? ''),
                    'jsCallback' => (string) ($ee['masse_exit_js_callback'] ?? ''), 'urlCallback' => (string) ($ee['masse_exit_url_route_callback'] ?? ''),
                ],
            ]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    /** GET /agents/scenario/options — step types, languages, response types, modules with forms. */
    public function scenarioOptionsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $db = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $tr = $this->getServiceManager()->get('translator');
            $config = $this->getServiceManager()->get('config');

            $entryTypes = $config['plugins']['melisai']['datas']['entry_types'] ?? [];
            $types = [];
            foreach ($entryTypes as $key => $cfg) {
                $types[] = [
                    'value' => $key, 'desc' => $tr->translate($cfg['desc'] ?? $key),
                    'fixed' => in_array($key, ['ENTRY PARAMS', 'EXIT PARAMS'], true),
                    'hasText'  => isset($cfg['text_form_label']),
                    'textLabel' => isset($cfg['text_form_label']) ? $tr->translate($cfg['text_form_label']) : '',
                    'hasFiles' => isset($cfg['file_form_label']),
                    'filesLabel' => isset($cfg['file_form_label']) ? $tr->translate($cfg['file_form_label']) : '',
                ];
            }
            // Mirror getOrderedLanguagesByCurrentLocale(): a "General" (lang_id -1) bucket FIRST, then
            // each melis_core_lang. The legacy save validates one trans-form per entry here, so the
            // editor must post a trans row for every one of these (including General/-1).
            $languages = [['id' => -1, 'locale' => 'General', 'name' => 'General']];
            foreach ($db->query('SELECT lang_id, lang_locale, lang_name FROM melis_core_lang ORDER BY lang_id', []) as $l) {
                $l = (array) $l; $languages[] = ['id' => (int) $l['lang_id'], 'locale' => (string) $l['lang_locale'], 'name' => (string) $l['lang_name']];
            }
            $returnTypes = [];
            foreach ($db->query('SELECT * FROM melis_ai_return_types', []) as $rt) {
                $rt = (array) $rt; $idK = $rt['mart_id'] ?? null; $nameK = $rt['mart_name'] ?? $rt['mart_code'] ?? '';
                if ($idK !== null) { $returnTypes[] = ['id' => (int) $idK, 'name' => (string) $nameK]; }
            }
            $modules = [];
            foreach (($config['plugins'] ?? []) as $modKey => $plugin) {
                $hasForms = !empty($plugin['forms']);
                if (!$hasForms && !empty($plugin['tools'])) {
                    foreach ($plugin['tools'] as $tool) { if (!empty($tool['forms'])) { $hasForms = true; break; } }
                }
                if ($hasForms) { $modules[] = (string) $modKey; }
            }
            sort($modules);
            return $this->jsonResponse(['success' => true, 'data' => ['types' => $types, 'languages' => $languages, 'returnTypes' => $returnTypes, 'modules' => $modules]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    /** GET /agents/scenario/forms?module= — forms declared by a module (entry-param introspection). */
    public function scenarioFormsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $module = (string) $this->params()->fromQuery('module', '');
            $config = $this->getServiceManager()->get('config');
            $mod = isset($config['plugins'][$module]) ? $module : strtolower($module);
            $plugin = $config['plugins'][$mod] ?? [];
            $forms = [];
            foreach (($plugin['forms'] ?? []) as $formKey => $fc) {
                if (!empty($fc['attributes']['name'])) { $forms[] = ['value' => $fc['attributes']['name'], 'label' => preg_replace('/[-_]/', ' ', $formKey), 'formPath' => "plugins/$mod/forms/$formKey"]; }
            }
            foreach (($plugin['tools'] ?? []) as $toolKey => $tc) {
                foreach (($tc['forms'] ?? []) as $formKey => $fc) {
                    if (!empty($fc['attributes']['name'])) { $forms[] = ['value' => $fc['attributes']['name'], 'label' => preg_replace('/[-_]/', ' ', $formKey), 'formPath' => "plugins/$mod/tools/$toolKey/forms/$formKey"]; }
                }
            }
            return $this->jsonResponse(['success' => true, 'data' => ['forms' => $forms]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    /** GET /agents/scenario/elements?module=&form= — non-hidden fields of a module form (entry params). */
    public function scenarioElementsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $module = (string) $this->params()->fromQuery('module', '');
            $form   = (string) $this->params()->fromQuery('form', '');
            $config = $this->getServiceManager()->get('config');
            $tr     = $this->getServiceManager()->get('translator');
            $mod = isset($config['plugins'][$module]) ? $module : strtolower($module);
            $plugin = $config['plugins'][$mod] ?? [];
            $fields = [];
            $collect = function (array $elements) use (&$fields, $tr) {
                foreach ($elements as $element) {
                    $spec = $element['spec'] ?? [];
                    $type = $spec['type'] ?? ''; $name = $spec['name'] ?? ''; $label = $spec['options']['label'] ?? $name;
                    if (strcasecmp($type, 'Hidden') !== 0 && $name) { $fields[] = ['name' => $name, 'label' => $tr->translate($label)]; }
                }
            };
            if (isset($plugin['forms'][$form]['elements'])) { $collect($plugin['forms'][$form]['elements']); }
            foreach (($plugin['tools'] ?? []) as $tc) { if (isset($tc['forms'][$form]['elements'])) { $collect($tc['forms'][$form]['elements']); } }
            return $this->jsonResponse(['success' => true, 'data' => ['elements' => $fields]]);
        } catch (\Throwable $e) { return $this->errorResponse($e); }
    }

    private function currentLangId(): int
    {
        try {
            $c = new \Laminas\Session\Container('meliscore');
            return (int) ($c['melis-lang-id'] ?? 1) ?: 1;
        } catch (\Throwable) { return 1; }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────
    private function formatAgent(array $r): array
    {
        $toolsRaw = $r['maa_agent_tools'] ?? '';
        $tools    = $toolsRaw ? (json_decode((string) $toolsRaw, true) ?: []) : [];
        return [
            'id'                => (int)    $r['maa_id'],
            'name'              => (string) $r['maa_name'],
            'description'       => (string) ($r['maa_description'] ?? ''),
            'code'              => (string) ($r['maa_agent_code'] ?? ''),
            'status'            => (int)    $r['maa_status'],
            'modelId'           => isset($r['maa_mam_id']) ? (int) $r['maa_mam_id'] : 0,
            'modelName'         => (string) ($r['mam_name'] ?? ''),
            'companyId'         => isset($r['mam_macp_id']) ? (int) $r['mam_macp_id'] : 0,
            'companyName'       => (string) ($r['macp_company_name'] ?? ''),
            'fileUserActive'    => (int)    ($r['maa_file_user_active'] ?? 0),
            'fileContextActive' => (int)    ($r['maa_file_context_active'] ?? 0),
            'dbCanCreateTable'  => (int)    ($r['maa_db_can_create_table'] ?? 0),
            'tools'             => is_array($tools) ? $tools : [],
            'creationDate'      => $r['maa_date_creation'] ?? null,
            'lastEditionDate'   => $r['maa_date_last_edition'] ?? null,
        ];
    }

    private function slugCode(string $name): string
    {
        $base = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $base = trim($base, '_') ?: 'AGENT';
        return substr($base, 0, 20) . '_' . substr((string) time(), -5);
    }

    private function currentUserId(): ?int
    {
        try {
            $id = $this->getServiceManager()->get('MelisCoreAuth')->getIdentity()->usr_id ?? null;
            return $id ? (int) $id : null;
        } catch (\Throwable) { return null; }
    }

    /** Seed ENTRY/EXIT PARAMS steps for a freshly created agent (best-effort; agent row is source of truth). */
    private function seedEntryExitSteps(int $agentId): void
    {
        try {
            $entryExitTable = $this->getServiceManager()->get('MelisAIEngineScenarioStepsDataEntryExitTable');
            $stepService    = $this->getServiceManager()->get('MelisAIScenarioStepService');
            foreach (['ENTRY PARAMS', 'EXIT PARAMS'] as $step) {
                $stepId = $stepService->saveItem(['mas_maa_id' => $agentId, 'mas_type' => $step, 'mas_code' => $step]);
                $entryExitTable->save(['masse_mas_id' => $stepId]);
            }
        } catch (\Throwable) { /* non-blocking */ }
    }

    private function isAuthenticated(): bool
    {
        return $this->getServiceManager()->get('MelisCoreAuth')->hasIdentity();
    }

    private function denyUnlessAccess(): ?HttpResponse
    {
        if (!$this->isAuthenticated()) { return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401); }
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
        $response->getHeaders()->addHeaders(['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function errorResponse(\Throwable $e, int $status = 500): HttpResponse
    {
        return $this->jsonResponse(['success' => false, 'error' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()], $status);
    }
}
