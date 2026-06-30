<?php

namespace MelisReactApi\Controller;

use Laminas\Http\PhpEnvironment\Response as HttpResponse;
use Laminas\Session\Container;
use MelisCore\Controller\MelisAbstractActionController;
use MelisAIEngine\Service\MelisAIEngineAgentService;

/**
 * General-purpose React API for the MelisAIEngine chat container.
 * Replaces MelisAIChatViewHelper (which returned rendered HTML) with JSON endpoints
 * consumed by the React AiChatContainer component.
 *
 * Endpoints:
 *   GET  /melis/react-api/ai-engine/init        → validate + return finalInstanceId + config
 *   POST /melis/react-api/ai-engine/run          → start / advance an agent turn
 *   POST /melis/react-api/ai-engine/continue     → resume after needs_continuation=true
 *   POST /melis/react-api/ai-engine/restart      → clear session, return new finalInstanceId
 */
class MelisReactApiAiEngineController extends MelisAbstractActionController
{
    // ── Init ─────────────────────────────────────────────────────────────────────

    /**
     * GET /ai-engine/init
     *
     * Replicates MelisAIChatViewHelper validation logic in JSON form.
     *
     * Query params:
     *   maiInstanceId  string  (required) instance identifier, optionally appended with |objectId
     *   agentId        int     (optional) override the instance's default agent
     *   needExitParam  bool    (optional, default 1) check for exit parameters
     *   exitParamArr   JSON    (optional) override exit params
     *   clearSession   bool    (optional, default 1) clear any existing session
     *   renderMode     string  (optional, default 'melis') for lang resolution
     *
     * Returns:
     *   { success, data: { label, finalInstanceId, agentId, isFileUploadActivated,
     *                       hasExitParameter, errorType?, errorMessage? } }
     */
    public function initAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAuthenticated()) { return $deny; }

        $q             = $this->getRequest()->getQuery();
        $maiInstanceId = trim((string) $q->get('maiInstanceId', ''));
        $agentId       = (int) $q->get('agentId', 0);
        $needExitParam = (bool) (int) $q->get('needExitParam', 1);
        $exitParamArr  = json_decode((string) $q->get('exitParamArr', '[]'), true) ?? [];
        $clearSession  = (bool) (int) $q->get('clearSession', 1);
        $renderMode    = trim((string) $q->get('renderMode', 'melis'));

        if (!$maiInstanceId) {
            return $this->jsonResponse(['success' => false, 'error' => 'maiInstanceId is required'], 400);
        }

        try {
            $engineService = $this->getServiceManager()->get('MelisAIEngineService');
        } catch (\Throwable) {
            return $this->jsonResponse(['success' => false, 'error' => 'MelisAIEngine module is not active'], 503);
        }

        // ── Parse maiInstanceId — optional |objectId suffix ─────────────────────
        $objectId = '';
        if (str_contains($maiInstanceId, '|')) {
            [$maiInstanceId, $objectId] = explode('|', $maiInstanceId, 2);
        }

        // ── Validate instance ───────────────────────────────────────────────────
        $instanceData = $engineService->getActiveInstance($maiInstanceId)->toArray();
        if (!$instanceData) {
            return $this->jsonResponse(['success' => true, 'data' => [
                'errorType'    => 'instance',
                'errorMessage' => 'AI instance is not active or does not exist.',
            ]]);
        }

        // ── Resolve agent ───────────────────────────────────────────────────────
        if (!$agentId) {
            $agentId = (int) current($instanceData)['mai_maa_id'];
        }

        $activeAgent = $engineService->getAgent($agentId, true)->current();
        if (!$activeAgent) {
            return $this->jsonResponse(['success' => true, 'data' => [
                'errorType'    => 'agent',
                'errorMessage' => 'The AI agent is not active.',
            ]]);
        }

        // ── Resolve model ───────────────────────────────────────────────────────
        $companyName  = '';
        $modelId      = 0;
        $isActiveModel = true;

        if (!empty($activeAgent->mam_id)) {
            $companyName   = $activeAgent->macp_company_name;
            $modelId       = $activeAgent->mam_id;
            $isActiveModel = (bool) $activeAgent->mam_status;
        } else {
            $defaultModel = $engineService->getDefaultModel()->current();
            if ($defaultModel) {
                $companyName   = $defaultModel->macp_company_name;
                $modelId       = $defaultModel->mam_id;
                $isActiveModel = (bool) $defaultModel->mam_status;
            } else {
                $isActiveModel = false;
            }
        }

        if (!$isActiveModel) {
            return $this->jsonResponse(['success' => true, 'data' => [
                'errorType'    => 'model',
                'errorMessage' => 'The AI model is not active.',
            ]]);
        }

        // ── Check company module ────────────────────────────────────────────────
        $aiModel = $engineService->getActiveModelClass($companyName, $modelId, $agentId);
        if (!$aiModel) {
            return $this->jsonResponse(['success' => true, 'data' => [
                'errorType'    => 'companyModule',
                'errorMessage' => 'The AI company module is not active.',
            ]]);
        }

        // ── Build finalInstanceId ───────────────────────────────────────────────
        $auth         = $this->getServiceManager()->get('MelisCoreAuth');
        $userId       = (int) $auth->getStorage()->read()->usr_id;
        $instanceIdPk = (int) current($instanceData)['mai_id'];
        $finalInstanceId = $agentId . '_' . $instanceIdPk . '_' . $maiInstanceId
            . ($objectId ? '_' . $objectId : '')
            . '_' . $userId;

        // ── Clear session if requested ──────────────────────────────────────────
        if ($clearSession) {
            try {
                $agentService = $this->getServiceManager()->build(
                    MelisAIEngineAgentService::class,
                    ['agentId' => $agentId, 'finalInstanceId' => $finalInstanceId]
                );
                $agentService->clearSession();
            } catch (\Throwable) {}
        }

        // ── Instance label (locale-aware) ───────────────────────────────────────
        $langId        = $this->getCurrentLangId($renderMode);
        $instanceLabel = '';
        foreach ($instanceData as $trans) {
            if (!empty($trans['mait_label'])) {
                $instanceLabel = $trans['mait_label'];
                if ($trans['mait_lang_id'] == $langId) break;
            }
        }

        // ── File upload setting ─────────────────────────────────────────────────
        $isFileUploadActivated = (bool) $engineService->getAgentUserFileUploadSetting($agentId);

        // ── Media Library availability (mirrors formbox sbIsEnabled) ─────────────
        // The "Media Library" button + MoxieManager are only available when
        // MelisSmallBusiness is active (it ships moxman + the media connector).
        $isMediaLibraryActivated = in_array(
            'MelisSmallBusiness',
            $this->getServiceManager()->get('MelisAssetManagerModulesService')->getActiveModules()
        );

        // ── Exit param check ────────────────────────────────────────────────────
        $hasExitParameter = false;
        if ($needExitParam) {
            $exitParams = $engineService->getExitParams($agentId, true)->current();
            $hasExitParameter = !empty($exitParams) || !empty($exitParamArr);
        }

        return $this->jsonResponse(['success' => true, 'data' => [
            'label'                  => $instanceLabel,
            'finalInstanceId'        => $finalInstanceId,
            'agentId'                => $agentId,
            'isFileUploadActivated'  => $isFileUploadActivated,
            'isMediaLibraryActivated'=> $isMediaLibraryActivated,
            'hasExitParameter'       => $hasExitParameter,
        ]]);
    }

    // ── Run ──────────────────────────────────────────────────────────────────────

    /**
     * POST /ai-engine/run
     *
     * Starts a new agent turn or advances an existing conversation.
     *
     * Body (JSON):
     *   agentId          int     (required)
     *   instanceId       string  (required) — the finalInstanceId from /init
     *   userText         string  (optional) user's chat message
     *   userInput        string  (optional) alias for userText
     *   debugMode        bool
     *   exitParamArr     object  override exit params
     *   extraEntryParams object  extra key→value context
     *
     * Returns { success, data: <RunResult> }
     */
    public function runAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAuthenticated()) { return $deny; }

        $request     = $this->getRequest();
        $contentType = $request->getHeader('Content-Type');
        $isMultipart = $contentType && str_contains((string) $contentType->getFieldValue(), 'multipart/form-data');

        if ($isMultipart) {
            // FormData request — carries binary files + optional media library JSON strings
            $body  = $request->getPost()->toArray();
            $files = $request->getFiles()->toArray();
        } else {
            // JSON request — text-only (no files)
            $body  = json_decode($request->getContent(), true) ?? [];
            $files = [];
        }

        $agentId          = (int) ($body['agentId'] ?? 0);
        $instanceId       = trim((string) ($body['instanceId'] ?? ''));
        $userText         = trim((string) ($body['user_chat'] ?? $body['userText'] ?? $body['userInput'] ?? ''));
        $debugMode        = !empty($body['debugMode']);
        // Accept exitParamArr as a pre-decoded array (JSON body) or a JSON string (multipart)
        $rawExit      = $body['exitParamArr'] ?? null;
        $exitParamArr = is_array($rawExit) ? $rawExit : (isset($rawExit) ? (json_decode((string) $rawExit, true) ?? []) : []);

        $extraEntryParams = $body['extra_entry_param'] ?? $body['extraEntryParams'] ?? [];
        $entryParamForm   = $body['entry_param_form'] ?? $body['entryParamForm'] ?? [];

        // Resolve relative custom_files URL paths (e.g. /MelisDemoCms/img/news/blog-grid7.jpg) to
        // absolute filesystem paths. MelisDocUpload processes custom_files with filesize() /
        // mime_content_type() / copy(), which all require a real filesystem path — a URL fails with
        // "stat failed". Mirrors the legacy MelisAICommunityExtensions\NewsController resolution:
        //   1. try DOCUMENT_ROOT . $path
        //   2. else map the first URL segment (module/site name) to its vendor public/ folder.
        if (is_array($extraEntryParams) && !empty($extraEntryParams['custom_files'])) {
            $cfRaw  = $extraEntryParams['custom_files'];
            $cfList = is_array($cfRaw) ? $cfRaw : [$cfRaw];

            $modulesSrv = $this->getServiceManager()->get('MelisAssetManagerModulesService');
            $docRoot    = $_SERVER['DOCUMENT_ROOT'] ?? '';

            $resolved = [];
            foreach ($cfList as $path) {
                $path = (string) $path;
                if ($path === '') {
                    continue;
                }
                // Already an absolute filesystem path or remote URL — keep as is.
                if (str_starts_with($path, 'http') || is_file($path)) {
                    $resolved[] = $path;
                    continue;
                }
                // 1. DOCUMENT_ROOT + path (physically deployed public asset).
                if ($docRoot && is_file($docRoot . $path)) {
                    $resolved[] = $docRoot . $path;
                    continue;
                }
                // 2. Map first segment (module/site name) → vendor module public/ folder.
                $segments   = explode('/', ltrim($path, '/'));
                $moduleName = array_shift($segments);
                $modulePath = $moduleName ? $modulesSrv->getModulePath($moduleName) : '';
                if ($modulePath) {
                    $candidate = rtrim($modulePath, '/') . '/public/' . implode('/', $segments);
                    if (is_file($candidate)) {
                        $resolved[] = $candidate;
                        continue;
                    }
                }
                // Unresolved — drop it rather than passing a URL that would fail downstream.
            }

            $extraEntryParams['custom_files'] = $resolved;
        }

        if (!$agentId || !$instanceId) {
            return $this->jsonResponse(['success' => false, 'error' => 'agentId and instanceId are required'], 400);
        }

        // When files are uploaded without any text (file-only message), the legacy
        // Laminas form in validateInput() may fail NotEmpty on user_chat because the
        // form's auto-generated input inherits required=true from merged module configs.
        // Mirror legacy tool.js behaviour: allow file-only by providing a minimal
        // non-empty fallback ( , non-breaking space) that passes StringTrim +
        // NotEmpty validators but reads as empty to the AI model.
        $hasFiles = !empty($files) || !empty($body['userMediaFiles']);
        if ($userText === '' && $hasFiles) {
            $userText = "\xc2\xa0"; // UTF-8 U+00A0 non-breaking space
        }

        try {
            $agentService = $this->getServiceManager()->build(
                MelisAIEngineAgentService::class,
                ['agentId' => $agentId, 'finalInstanceId' => $instanceId]
            );

            // Mirror legacy AIController::runAgentAction() exactly:
            //   runAgent($postValues, $files)
            // where $postValues includes user_chat + userMediaFiles[] (JSON strings)
            // and   $files is the raw Laminas getFiles() array (keyed by user_file_upload).
            // The service itself handles:
            //   - validateInput() checking $files['user_file_upload'] + $postData['userMediaFiles']
            //   - merging both sources into $allFiles before processFiles()
            // Do NOT pre-merge here — it destroys the structure validateInput() expects.
            $postData = [
                'agentId'        => $agentId,
                'instanceId'     => $instanceId,
                'debugMode'      => $debugMode ? '1' : '0',
                'user_chat'      => $userText,
                'exitParamArr'   => json_encode($exitParamArr),
                'userMediaFiles' => $body['userMediaFiles'] ?? [],
            ];

            // Extra entry params → extra_entry_param[key]
            if (is_array($extraEntryParams)) {
                foreach ($extraEntryParams as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $item) {
                            $postData['extra_entry_param'][$key][] = $item;
                        }
                    } else {
                        $postData['extra_entry_param'][$key] = $val;
                    }
                }
            }

            // Entry param form values → entry_param_form[key] (mirrors legacy runAgent form fields)
            if (is_array($entryParamForm) && !empty($entryParamForm)) {
                foreach ($entryParamForm as $key => $val) {
                    $postData['entry_param_form'][$key] = (string) $val;
                }
            }

            // $files already holds getFiles()->toArray() for multipart, [] for JSON
            $result               = $agentService->runAgent($postData, $files);
            $result['instanceId'] = $instanceId;

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ── Continue ─────────────────────────────────────────────────────────────────

    /**
     * POST /ai-engine/continue
     *
     * Body (JSON): { agentId, instanceId, sessionId, continuationContext }
     * Returns:     same shape as runAction
     */
    public function continueAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAuthenticated()) { return $deny; }

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

            $result               = $agentService->continueConversation($sessionId, $continuationContext);
            $result['instanceId'] = $instanceId;

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ── Validate ─────────────────────────────────────────────────────────────────

    /**
     * POST /ai-engine/validate
     *
     * Accepts the AI's latest answer: advances the agent to its exit-param step and
     * returns the resulting payload (incl. exitParams), which the client uses to fire
     * the exit callback. Equivalent to the legacy #validate-answer-btn AJAX →
     * POST /melis/MelisAIEngine/AI/validateAnswer.
     *
     * Body (JSON): { agentId, instanceId, debugMode?, exitParamArr? }
     * Returns:     same shape as runAction
     */
    public function validateAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAuthenticated()) { return $deny; }

        $body       = json_decode($this->getRequest()->getContent(), true) ?? [];
        $agentId    = (int) ($body['agentId'] ?? 0);
        $instanceId = trim((string) ($body['instanceId'] ?? ''));
        $debugMode  = !empty($body['debugMode']);

        // Accept exitParamArr as a pre-decoded array (JSON body) or a JSON string.
        $rawExit      = $body['exitParamArr'] ?? null;
        $exitParamArr = is_array($rawExit) ? $rawExit : (isset($rawExit) ? (json_decode((string) $rawExit, true) ?? []) : []);

        if (!$agentId || !$instanceId) {
            return $this->jsonResponse(['success' => false, 'error' => 'agentId and instanceId are required'], 400);
        }

        try {
            $agentService = $this->getServiceManager()->build(
                MelisAIEngineAgentService::class,
                ['agentId' => $agentId, 'finalInstanceId' => $instanceId]
            );

            // validateAnswer() reads agentId/instanceId/debugMode/exitParamArr from $postData
            // (it forwards them to runAgent() for the exit-param step).
            $postData = [
                'agentId'      => $agentId,
                'instanceId'   => $instanceId,
                'debugMode'    => $debugMode ? '1' : '0',
                'exitParamArr' => json_encode($exitParamArr),
            ];

            $result               = $agentService->validateAnswer($postData);
            $result['instanceId'] = $instanceId;

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ── Restart ──────────────────────────────────────────────────────────────────

    /**
     * POST /ai-engine/restart
     *
     * Clears the agent session and returns a refreshed finalInstanceId.
     * Equivalent to the legacy restart-chat-btn → POST /melis/MelisAIEngine/AI/restartAgent.
     *
     * Body (JSON): { maiInstanceId, agentId, instanceId, ... }
     * Returns:     { success, data: InitResult } (new finalInstanceId = same formula)
     */
    public function restartAction(): HttpResponse
    {
        if ($deny = $this->denyUnlessAuthenticated()) { return $deny; }

        $body          = json_decode($this->getRequest()->getContent(), true) ?? [];
        $agentId       = (int) ($body['agentId'] ?? 0);
        $instanceId    = trim((string) ($body['instanceId'] ?? ''));

        if (!$agentId || !$instanceId) {
            return $this->jsonResponse(['success' => false, 'error' => 'agentId and instanceId are required'], 400);
        }

        try {
            $agentService = $this->getServiceManager()->build(
                MelisAIEngineAgentService::class,
                ['agentId' => $agentId, 'finalInstanceId' => $instanceId]
            );
            $agentService->clearSession();

            // Return the same finalInstanceId (session cleared, ready for re-use)
            return $this->jsonResponse(['success' => true, 'data' => [
                'finalInstanceId' => $instanceId,
                'agentId'         => $agentId,
            ]]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function getCurrentLangId(string $renderMode = 'melis'): int
    {
        try {
            if ($renderMode === 'melis') {
                $container = new Container('meliscore');
                return (int) ($container['melis-lang-id'] ?? 1);
            }
            $container = new Container('melisplugins');
            return (int) ($container['melis-plugins-lang-id'] ?? 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    private function isAuthenticated(): bool
    {
        return $this->getServiceManager()->get('MelisCoreAuth')->hasIdentity();
    }

    private function denyUnlessAuthenticated(): ?HttpResponse
    {
        if (!$this->isAuthenticated()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Unauthenticated'], 401);
        }
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
