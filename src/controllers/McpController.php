<?php

namespace skeeks\cms\mcp\controllers;

use skeeks\cms\base\Controller;
use skeeks\cms\mcp\McpComponent;
use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\oauth2\Oauth2ServerComponent;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class McpController extends Controller
{
    const JSONRPC_VERSION = '2.0';
    public $enableCsrfValidation = false;
    protected $apiRequestId;

    public function behaviors()
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['post']]]];
    }

    public function runAction($id, $params = [])
    {
        $this->apiRequestId = ApiLogService::requestId();
        Yii::$app->response->headers->set('X-Request-ID', $this->apiRequestId);
        $context = ApiLogService::requestContext('mcp', $this->apiRequestId);
        $context['action'] = (string)$id;
        $previousContext = ApiLogService::pushContext($context);
        $startedAt = ApiLogService::startedAt();
        ApiLogService::attachResponseLogging(Yii::$app->response, ApiLogService::CATEGORY_MCP, $context, $startedAt);
        ApiLogService::info('request.start', [], ApiLogService::CATEGORY_MCP, true);

        try {
            $result = parent::runAction($id, $params);
            ApiLogService::info('request.finish', [
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'status_code' => Yii::$app->response->statusCode,
                'result' => ApiLogService::summarizeResult($result),
            ], ApiLogService::CATEGORY_MCP, true);
            return $result;
        } catch (\Throwable $e) {
            ApiLogService::error('request.error', array_merge([
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'status_code' => Yii::$app->response->statusCode,
            ], ApiLogService::exception($e)), ApiLogService::CATEGORY_MCP, true);
            throw $e;
        } finally {
            ApiLogService::restoreContext($previousContext);
        }
    }

    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $accessToken = $this->authenticateBearer();
        $payload = $this->payload();
        $response = $this->handle($payload, $accessToken);
        if ($response === null || $response === []) {
            Yii::$app->response->statusCode = 202;
            Yii::$app->response->format = Response::FORMAT_RAW;
            return '';
        }
        return $response;
    }

    protected function handle(array $request, $accessToken)
    {
        if ($this->isList($request)) {
            $responses = [];
            foreach ($request as $message) {
                if (is_array($message)) {
                    $response = $this->handleMessage($message, $accessToken);
                    if ($response !== null) { $responses[] = $response; }
                }
            }
            return $responses;
        }
        return $this->handleMessage($request, $accessToken);
    }

    protected function handleMessage(array $request, $accessToken)
    {
        $id = $request['id'] ?? null;
        $method = (string)($request['method'] ?? '');
        $params = (array)($request['params'] ?? []);
        $startedAt = ApiLogService::startedAt();
        $outcome = 'success';
        $errorContext = [];
        $rpcContext = [
            'rpc_method' => $method,
            'rpc_id_type' => gettype($id),
        ];
        if ($method === 'tools/call') {
            $rpcContext['tool'] = (string)($params['name'] ?? '');
        }
        ApiLogService::info('rpc.start', $rpcContext, ApiLogService::CATEGORY_MCP, true);
        try {
            switch ($method) {
                case 'initialize':
                    $catalog = $this->mcpCatalog($accessToken);
                    return $this->result($id, [
                        'protocolVersion' => $this->mcp()->protocolVersion,
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => $this->mcp()->serverName, 'version' => $this->mcp()->serverVersion],
                        'instructions' => 'Use tools/list as the authorized source of truth. Cache it by skeeks/toolsRevision until the revision changes.',
                        '_meta' => [
                            'skeeks/apiVersion' => $catalog['api_version'],
                            'skeeks/toolsRevision' => $catalog['tools_revision'],
                            'skeeks/toolsCount' => $catalog['tools_count'],
                        ],
                    ]);
                case 'notifications/initialized':
                    return $id === null ? null : $this->result($id, []);
                case 'ping':
                    return $this->result($id, []);
                case 'tools/list':
                    $catalog = $this->mcpCatalog($accessToken);
                    return $this->result($id, [
                        'tools' => $catalog['tools'],
                        '_meta' => [
                            'skeeks/apiVersion' => $catalog['api_version'],
                            'skeeks/toolsRevision' => $catalog['tools_revision'],
                            'skeeks/toolsCount' => $catalog['tools_count'],
                        ],
                    ]);
                case 'tools/call':
                    return $this->callTool($id, $params, $accessToken);
                default:
                    $outcome = 'method_not_found';
                    return $this->error($id, -32601, 'Method not found: '.$method);
            }
        } catch (\Throwable $e) {
            $outcome = 'error';
            $errorContext = ApiLogService::exception($e);
            return $this->error($id, -32000, $e->getMessage(), YII_DEBUG ? ['type' => get_class($e)] : null);
        } finally {
            $finishContext = array_merge($rpcContext, $errorContext, [
                'outcome' => $outcome,
                'duration_ms' => ApiLogService::durationMs($startedAt),
            ]);
            if ($outcome === 'error') {
                ApiLogService::error('rpc.error', $finishContext, ApiLogService::CATEGORY_MCP);
            } elseif ($outcome === 'method_not_found') {
                ApiLogService::warning('rpc.finish', $finishContext, ApiLogService::CATEGORY_MCP);
            } else {
                ApiLogService::info('rpc.finish', $finishContext, ApiLogService::CATEGORY_MCP);
            }
        }
    }

    protected function callTool($id, array $params, $accessToken): array
    {
        $tool = $this->mcp()->getTool((string)($params['name'] ?? ''));
        try {
            $data = $this->mcp()->execute(
                $tool,
                (array)($params['arguments'] ?? []),
                $accessToken,
                [
                    'transport' => 'mcp',
                    'request_id' => (string)$this->apiRequestId,
                ]
            );
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => Json::encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (ForbiddenHttpException $e) {
            return $this->error($id, -32003, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'structuredContent' => ['success' => false, 'error' => $e->getMessage()],
                'isError' => true,
            ]);
        }
    }

    protected function payload(): array
    {
        $payload = Yii::$app->request->bodyParams;
        if (!$payload && Yii::$app->request->rawBody) { $payload = Json::decode(Yii::$app->request->rawBody); }
        return (array)$payload;
    }

    protected function authenticateBearer()
    {
        $startedAt = ApiLogService::startedAt();
        ApiLogService::info('auth.start', [], ApiLogService::CATEGORY_MCP, true);
        $header = (string)Yii::$app->request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->challenge();
            ApiLogService::warning('auth.error', [
                'duration_ms' => ApiLogService::durationMs($startedAt),
                'reason' => 'missing_bearer',
            ], ApiLogService::CATEGORY_MCP, true);
            throw new UnauthorizedHttpException('Bearer token is required.');
        }
        $resourceUri = rtrim(Url::to($this->mcp()->resourceRoute, true), '/');
        try {
            $token = $this->oauth()->validateAccessToken(trim($matches[1]), $resourceUri);
        } catch (\Exception $e) {
            $this->challenge();
            ApiLogService::warning('auth.error', array_merge([
                'duration_ms' => ApiLogService::durationMs($startedAt),
            ], ApiLogService::exception($e)), ApiLogService::CATEGORY_MCP, true);
            throw $e;
        }
        Yii::$app->user->setIdentity($token->cmsUser);
        ApiLogService::info('auth.finish', [
            'duration_ms' => ApiLogService::durationMs($startedAt),
            'user_id' => Yii::$app->user->id,
        ], ApiLogService::CATEGORY_MCP);
        return $token;
    }

    protected function challenge(): void
    {
        $resource = rtrim(Url::to($this->mcp()->resourceRoute, true), '/');
        $metadata = Url::to(['/cms/oauth/protected-resource', 'resource' => $resource], true);
        Yii::$app->response->headers->set('WWW-Authenticate', 'Bearer resource_metadata="'.$metadata.'"');
    }

    protected function result($id, array $result): array { return ['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'result' => $result]; }
    protected function error($id, int $code, string $message, array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) { $error['data'] = $data; }
        return ['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'error' => $error];
    }
    protected function isList(array $value): bool { return $value !== [] && array_keys($value) === range(0, count($value) - 1); }
    protected function mcpCatalog($accessToken): array
    {
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        foreach ($catalog['tools'] as &$tool) {
            unset($tool['requiredScope']);
        }
        unset($tool);

        return $catalog;
    }
    protected function mcp(): McpComponent { return Yii::$app->get('cmsMcp'); }
    protected function oauth(): Oauth2ServerComponent { return Yii::$app->get($this->mcp()->oauth2Component); }
}
