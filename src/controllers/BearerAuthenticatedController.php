<?php

namespace skeeks\cms\mcp\controllers;

use skeeks\cms\base\Controller;
use skeeks\cms\mcp\McpComponent;
use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\oauth2\Oauth2ServerComponent;
use Yii;
use yii\helpers\Url;
use yii\web\UnauthorizedHttpException;

abstract class BearerAuthenticatedController extends Controller
{
    public $enableCsrfValidation = false;
    protected $apiRequestId;

    public function runAction($id, $params = [])
    {
        $this->apiRequestId = ApiLogService::requestId();
        Yii::$app->response->headers->set('X-Request-ID', $this->apiRequestId);
        $context = ApiLogService::requestContext('rest', $this->apiRequestId);
        $context['action'] = (string)$id;
        $previousContext = ApiLogService::pushContext($context);
        $startedAt = ApiLogService::startedAt();
        ApiLogService::attachResponseLogging(Yii::$app->response, ApiLogService::CATEGORY_REST, $context, $startedAt);
        ApiLogService::info('request.start', [], ApiLogService::CATEGORY_REST, true);

        try {
            $result = parent::runAction($id, $params);
            ApiLogService::info('request.finish', [
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'status_code' => Yii::$app->response->statusCode,
                'result' => ApiLogService::summarizeResult($result),
            ], ApiLogService::CATEGORY_REST, true);
            return $result;
        } catch (\Throwable $e) {
            ApiLogService::error('request.error', array_merge([
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'status_code' => Yii::$app->response->statusCode,
            ], ApiLogService::exception($e)), ApiLogService::CATEGORY_REST, true);
            throw $e;
        } finally {
            ApiLogService::restoreContext($previousContext);
        }
    }

    protected function authenticateBearer()
    {
        $startedAt = ApiLogService::startedAt();
        ApiLogService::info('auth.start', [], ApiLogService::CATEGORY_REST, true);
        $header = (string)Yii::$app->request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->challenge();
            ApiLogService::warning('auth.error', [
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'reason' => 'missing_bearer',
            ], ApiLogService::CATEGORY_REST, true);
            throw new UnauthorizedHttpException('Bearer token is required.');
        }

        try {
            $token = $this->oauth()->validateAccessToken(trim($matches[1]), $this->resourceUri());
        } catch (\Exception $e) {
            $this->challenge();
            ApiLogService::warning('auth.error', array_merge([
                'duration_ms' => ApiLogService::durationMs($startedAt),
            ], ApiLogService::exception($e)), ApiLogService::CATEGORY_REST, true);
            throw $e;
        }

        // Bearer authentication is stateless. login() would start a PHP
        // session and serialize concurrent API requests behind its lock.
        Yii::$app->user->setIdentity($token->cmsUser);
        ApiLogService::info('auth.finish', [
            'duration_ms' => ApiLogService::durationMs($startedAt),
            'user_id' => Yii::$app->user->id,
        ], ApiLogService::CATEGORY_REST);

        return $token;
    }

    protected function apiRequestId(): string
    {
        return (string)$this->apiRequestId;
    }

    protected function challenge(): void
    {
        $metadata = Url::to(['/cms/oauth/protected-resource', 'resource' => $this->resourceUri()], true);
        Yii::$app->response->headers->set('WWW-Authenticate', 'Bearer resource_metadata="'.$metadata.'"');
    }

    protected function resourceUri(): string
    {
        return rtrim(Url::to($this->mcp()->resourceRoute, true), '/');
    }

    protected function mcp(): McpComponent
    {
        return Yii::$app->get('cmsMcp');
    }

    protected function oauth(): Oauth2ServerComponent
    {
        return Yii::$app->get($this->mcp()->oauth2Component);
    }
}
