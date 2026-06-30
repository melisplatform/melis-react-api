<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use MelisCore\Controller\MelisAbstractActionController;
use MelisAIEngine\Service\MelisAIEngineAgentService;

/**
 * API REST for the "Chat Dev Tool" tab of the MelisAI Admin tool.
 *
 * Endpoints:
 *   GET  /melis/react-api/ai-chat-dev/agents       → active agents list
 *   POST /melis/react-api/ai-chat-dev/run           → start / advance an agent turn
 *   POST /melis/react-api/ai-chat-dev/continue      → resume after needs_continuation=true
 */
class MelisReactApiAiChatDevController extends MelisAbstractActionController
{
    private const MELIS_KEY = 'melisai_chat_dev_tool';

    /**
     * GET /ai-chat-dev/agents
     * Returns all active AI agents: [{ id, name }]
     */
    public function agentsAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        try {
            $agentTable = $this->getServiceManager()->get('MelisAIEngineAgentTable');
            $rows = $agentTable->getAllActiveAgents()->toArray();

            $agents = array_map(
                static fn($r): array => ['id' => (int) $r['maa_id'], 'name' => (string) $r['maa_name']],
                $rows
            );

            return $this->jsonResponse(['success' => true, 'data' => $agents]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * POST /ai-chat-dev/run
     * Starts a new agent turn (or continues from a previous instanceId).
     *
     * Body (JSON): { agentId, instanceId?, userText?, debugMode? }
     * Returns:     { success, data: { chatHist, needs_continuation, session_id,
     *                                  continuation_context, instanceId, currentStepType,
     *                                  scenarioFinished, finalAnswer, payload, errors } }
     */
    public function runAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        $body       = json_decode($this->getRequest()->getContent(), true) ?? [];
        $agentId    = (int) ($body['agentId'] ?? 0);
        $userText   = trim((string) ($body['userText'] ?? ''));
        $instanceId = trim((string) ($body['instanceId'] ?? ''));
        $debugMode  = isset($body['debugMode']) ? (bool) $body['debugMode'] : true;

        if (!$agentId) {
            return $this->jsonResponse(['success' => false, 'error' => 'agentId is required'], 400);
        }

        if (!$instanceId) {
            $instanceId = 'admintool_' . $agentId . '_' . time() . '_' . mt_rand(1000, 9999);
        }

        try {
            $agentService = $this->getServiceManager()->build(
                MelisAIEngineAgentService::class,
                ['agentId' => $agentId, 'finalInstanceId' => $instanceId]
            );

            $postData = [
                'agentId'    => $agentId,
                'instanceId' => $instanceId,
                'debugMode'  => $debugMode ? '1' : '0',
                'user_chat'  => $userText,
            ];

            $result = $agentService->runAgent($postData, []);

            // Strip the HTML interface string — not needed in the React API
            unset($result['stepHtmlInterface']);
            $result['instanceId'] = $instanceId;

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * POST /ai-chat-dev/continue
     * Resumes a turn that returned needs_continuation=true.
     *
     * Body (JSON): { agentId, instanceId, sessionId, continuationContext }
     * Returns:     same shape as runAction
     */
    public function continueAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAccess()) { return $deny; }

        $body                = json_decode($this->getRequest()->getContent(), true) ?? [];
        $agentId             = (int) ($body['agentId'] ?? 0);
        $instanceId          = trim((string) ($body['instanceId'] ?? ''));
        $sessionId           = trim((string) ($body['sessionId'] ?? ''));
        $continuationContext = $body['continuationContext'] ?? [];

        if (!$agentId || !$instanceId) {
            return $this->jsonResponse(['success' => false, 'error' => 'agentId and instanceId are required'], 400);
        }

        try {
            $agentService = $this->getServiceManager()->build(
                MelisAIEngineAgentService::class,
                ['agentId' => $agentId, 'finalInstanceId' => $instanceId]
            );

            $result = $agentService->continueConversation($sessionId, $continuationContext);

            unset($result['stepHtmlInterface']);
            $result['instanceId'] = $instanceId;

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ─── helpers (same pattern as MelisReactApiAiPlatformController) ─────────

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
