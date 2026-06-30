<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;

/**
 * JSON API for the "MCP Server" tab of the MelisAI Admin tool (melisKey melisai_mcp_server).
 *
 * Ported from AdminController::renderMcpExpositionAction / renderMcpDatabaseAction / saveMcpExposedTools
 * / saveSensitiveTables — same logic, clean JSON envelope.
 *
 * Routes (all under /melis/react-api/):
 *   GET  /ai-mcp-server/data          → dataAction        (tools + db tables in one call)
 *   POST /ai-mcp-server/save-tools    → saveToolsAction   (sync exposed tool list)
 *   POST /ai-mcp-server/save-sensitive → saveSensitiveAction (sync sensitive table list)
 */
class MelisReactApiAiMcpServerController extends MelisAbstractActionController
{
    private const MELIS_KEY = 'melisai_mcp_server';

    // ─── GET /ai-mcp-server/data ──────────────────────────────────────────────────

    public function dataAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $config               = $this->getServiceManager()->get('config');
            $functionDeclarations = $config['plugins']['melisaiengine']['datas']['function_declarations'] ?? [];

            $exposedTable = $this->getServiceManager()->get('MelisAIMcpExposedToolTable');
            $exposed      = $exposedTable->getExposedToolNames();   // ['toolName' => row]

            // Tools list — sorted alphabetically within each group
            $tools = [];
            foreach ($functionDeclarations as $decl) {
                $name = (string) ($decl['name'] ?? '');
                if ($name === '') continue;
                $tools[] = [
                    'name'        => $name,
                    'description' => (string) ($decl['description'] ?? ''),
                    'mcp'         => !empty($decl['mcp']),
                    'exposed'     => isset($exposed[$name]),
                ];
            }
            usort($tools, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

            // DB tables grouped by module, with sensitive flags
            $db  = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
            $sql = "SELECT t.TABLE_NAME AS table_name,
                        (SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k
                         WHERE k.TABLE_SCHEMA = t.TABLE_SCHEMA
                           AND k.TABLE_NAME   = t.TABLE_NAME
                           AND k.CONSTRAINT_NAME = 'PRIMARY'
                         ORDER BY k.ORDINAL_POSITION LIMIT 1) AS pk
                    FROM information_schema.TABLES t
                    WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
                    ORDER BY t.TABLE_NAME";

            $tableRows = iterator_to_array($db->query($sql, []));

            $sensitiveTableSvc = $this->getServiceManager()->get('MelisAIMcpSensitiveTableTable');
            $flagged           = $sensitiveTableSvc->getFlaggedTableNames();  // ['tableName' => true]

            $groups = [];
            foreach ($tableRows as $row) {
                $tableName = (string) $row['table_name'];
                $module    = $this->moduleFromTableName($tableName);

                if (!isset($groups[$module])) {
                    $groups[$module] = ['name' => $module, 'tables' => [], 'sensitiveCount' => 0];
                }

                $isSensitive = isset($flagged[$tableName]);
                $groups[$module]['tables'][] = [
                    'name'      => $tableName,
                    'pk'        => (string) ($row['pk'] ?? ''),
                    'sensitive' => $isSensitive,
                ];
                if ($isSensitive) {
                    $groups[$module]['sensitiveCount']++;
                }
            }
            ksort($groups);

            return $this->jsonResponse(['success' => true, 'data' => [
                'tools'     => $tools,
                'dbModules' => array_values($groups),
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── POST /ai-mcp-server/save-tools ──────────────────────────────────────────

    public function saveToolsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $body  = json_decode($this->getRequest()->getContent(), true) ?? [];
            $tools = $body['tools'] ?? [];
            if (!is_array($tools)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
            }

            $count = $this->syncExposedTools($tools);
            return $this->jsonResponse(['success' => true, 'data' => ['count' => $count]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── POST /ai-mcp-server/save-sensitive ──────────────────────────────────────

    public function saveSensitiveAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $body   = json_decode($this->getRequest()->getContent(), true) ?? [];
            $tables = $body['tables'] ?? [];
            if (!is_array($tables)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
            }

            $count = $this->syncSensitiveTables($tables);
            return $this->jsonResponse(['success' => true, 'data' => ['count' => $count]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── private helpers ──────────────────────────────────────────────────────────

    private function syncExposedTools(array $toolNames): int
    {
        $config               = $this->getServiceManager()->get('config');
        $functionDeclarations = $config['plugins']['melisaiengine']['datas']['function_declarations'] ?? [];

        $valid = [];
        foreach ($functionDeclarations as $decl) {
            if (!empty($decl['name'])) {
                $valid[(string) $decl['name']] = true;
            }
        }

        $exposedTable = $this->getServiceManager()->get('MelisAIMcpExposedToolTable');

        $submitted = [];
        foreach ($toolNames as $n) {
            $n = (string) $n;
            if (isset($valid[$n])) {
                $submitted[$n] = true;
            }
        }

        $existing = [];
        foreach ($exposedTable->fetchAll()->toArray() as $row) {
            $existing[(string) $row['met_tool_name']] = $row;
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $this->getConnectedUserId();

        foreach ($existing as $name => $row) {
            if (!isset($submitted[$name])) {
                $exposedTable->deleteById($row['met_id']);
            }
        }

        foreach (array_keys($submitted) as $name) {
            if (isset($existing[$name])) {
                continue;
            }
            $exposedTable->save([
                'met_tool_name'     => $name,
                'met_exposed_by'    => $userId,
                'met_date_added'    => $now,
                'met_date_modified' => $now,
            ]);
        }

        return count($submitted);
    }

    private function syncSensitiveTables(array $tableNames): int
    {
        $valid = [];
        $db    = $this->getServiceManager()->get('Laminas\Db\Adapter\AdapterInterface');
        $rows  = iterator_to_array($db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
            []
        ));
        foreach ($rows as $r) {
            $t         = (string) $r['TABLE_NAME'];
            $valid[$t] = $this->moduleFromTableName($t);
        }

        $sensitiveTable = $this->getServiceManager()->get('MelisAIMcpSensitiveTableTable');

        $submitted = [];
        foreach ($tableNames as $t) {
            $t = (string) $t;
            if (isset($valid[$t])) {
                $submitted[$t] = true;
            }
        }

        $existing = [];
        foreach ($sensitiveTable->fetchAll()->toArray() as $row) {
            $existing[(string) $row['mst_table_name']] = $row;
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $this->getConnectedUserId();

        foreach ($existing as $t => $row) {
            if (!isset($submitted[$t])) {
                $sensitiveTable->deleteById($row['mst_id']);
            }
        }

        foreach (array_keys($submitted) as $t) {
            if (isset($existing[$t])) {
                continue;
            }
            $sensitiveTable->save([
                'mst_table_name'    => $t,
                'mst_module'        => $valid[$t],
                'mst_flagged_by'    => $userId,
                'mst_date_added'    => $now,
                'mst_date_modified' => $now,
            ]);
        }

        return count($submitted);
    }

    private function moduleFromTableName(string $table): string
    {
        $parts = explode('_', $table);
        if (count($parts) < 2 || $parts[0] !== 'melis') {
            return 'other';
        }
        $map = [
            'core'       => 'melis-core',
            'cms'        => 'melis-cms',
            'ai'         => 'melis-ai',
            'newsletter' => 'melis-newsletter',
            'sb'         => 'melis-small-business',
            'docupl'     => 'melis-document-upload',
            'site'       => 'melis-site',
            'plugins'    => 'melis-plugins',
            'ecom'       => 'melis-commerce',
        ];
        return $map[$parts[1]] ?? ('melis-' . $parts[1]);
    }

    private function getConnectedUserId(): ?int
    {
        try {
            $identity = $this->getServiceManager()->get('MelisCoreAuth')->getIdentity();
            if ($identity && isset($identity->usr_id)) {
                return (int) $identity->usr_id;
            }
        } catch (\Throwable) {}
        return null;
    }

    private function isAuthenticated(): bool
    {
        return (bool) $this->getServiceManager()->get('MelisCoreAuth')->hasIdentity();
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
