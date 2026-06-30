<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisAI\Controller\McpInspectorController;

/**
 * JSON API for the MCP Inspector tool — exposed in the React back-office
 * as a dedicated tool under the MelisAI section (melisKey melis_mcp_inspector_tool).
 *
 * Extends McpInspectorController (which holds all the process-management logic:
 * server discovery, npx/node launchers, port checks, log parsing). This controller
 * adds React-API-style JSON endpoints (success/data/error envelope, HttpResponse)
 * without duplicating any of that logic.
 *
 * Uses different action method names (apiLaunchAction, apiStatusAction) to avoid
 * the return-type clash with the parent's JsonModel-typed launchAction/statusAction.
 *
 * Routes (all under /melis/react-api/mcp-inspector/…):
 *   GET  /mcp-inspector/servers[/]  → serversAction
 *   POST /mcp-inspector/launch[/]   → apiLaunchAction
 *   GET  /mcp-inspector/status[/]   → apiStatusAction
 *   GET  /mcp-inspector/log[/]      → logAction
 */
class MelisReactApiMcpInspectorController extends McpInspectorController
{
    private const MELIS_KEY = 'melis_mcp_inspector_tool';

    // ─── GET /mcp-inspector/servers ──────────────────────────────────────────────

    public function serversAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            return $this->jsonResponse(['success' => true, 'data' => $this->getAvailableServers()]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── POST /mcp-inspector/launch ──────────────────────────────────────────────

    public function apiLaunchAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $body     = json_decode($this->getRequest()->getContent(), true) ?? [];
            $serverId = $body['server'] ?? $this->params()->fromQuery('server', '');

            $server = null;
            foreach ($this->getAvailableServers() as $s) {
                if ($s['id'] === $serverId) {
                    $server = $s;
                    break;
                }
            }

            if ($server === null) {
                return $this->jsonResponse(['success' => false, 'error' => 'Server not found'], 404);
            }

            $this->stopInspector();
            $this->startInspector($server['path']);
            $this->patchInspectorClientBundles();

            return $this->jsonResponse(['success' => true, 'data' => [
                'url'     => $this->getInspectorUrl(),
                'running' => $this->isInspectorRunning(),
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /mcp-inspector/status ───────────────────────────────────────────────

    public function apiStatusAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            // Re-apply the client-bundle guard on status polls — on a cold npm cache the
            // bundle may not exist yet when launch runs; the first status poll after the
            // inspector starts is the earliest safe moment to patch it (idempotent).
            $this->patchInspectorClientBundles();

            return $this->jsonResponse(['success' => true, 'data' => [
                'running' => $this->isInspectorRunning(),
                'url'     => $this->getInspectorUrl(),
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── GET /mcp-inspector/log ──────────────────────────────────────────────────

    public function logAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }
        try {
            $logPath = $this->getLogPath();
            if (!is_file($logPath)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Log file not found'], 404);
            }
            $log = (string) file_get_contents($logPath);
            // Strip ANSI escape sequences (colour codes from newer inspector versions on Linux)
            $log = (string) preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $log);
            return $this->jsonResponse(['success' => true, 'data' => ['log' => $log]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── private helpers ─────────────────────────────────────────────────────────

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
