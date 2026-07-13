<?php

namespace skeeks\cms\mcp\controllers;

use skeeks\cms\base\Controller;
use skeeks\cms\mcp\McpComponent;
use skeeks\cms\oauth2\Oauth2ServerComponent;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class McpController extends Controller
{
    const JSONRPC_VERSION = '2.0';
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['post']]]];
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
        try {
            switch ($method) {
                case 'initialize':
                    return $this->result($id, [
                        'protocolVersion' => $this->mcp()->protocolVersion,
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => $this->mcp()->serverName, 'version' => $this->mcp()->serverVersion],
                    ]);
                case 'notifications/initialized':
                    return $id === null ? null : $this->result($id, []);
                case 'ping':
                    return $this->result($id, []);
                case 'tools/list':
                    return $this->result($id, ['tools' => $this->mcp()->getToolSchemas()]);
                case 'tools/call':
                    return $this->callTool($id, $params, $accessToken);
                default:
                    return $this->error($id, -32601, 'Method not found: '.$method);
            }
        } catch (\Throwable $e) {
            return $this->error($id, -32000, $e->getMessage(), YII_DEBUG ? ['type' => get_class($e)] : null);
        }
    }

    protected function callTool($id, array $params, $accessToken): array
    {
        $tool = $this->mcp()->getTool((string)($params['name'] ?? ''));
        if ($tool->getRequiredScope() && !$accessToken->hasScope($tool->getRequiredScope())) {
            return $this->error($id, -32003, 'Missing OAuth scope: '.$tool->getRequiredScope());
        }
        if (!$this->mcp()->canExecuteTool($tool)) {
            return $this->error($id, -32003, 'The authorized CMS user has no permission to execute this tool.');
        }
        try {
            $data = $tool->execute((array)($params['arguments'] ?? []));
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => Json::encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (\Throwable $e) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'structuredContent' => ['success' => false, 'error' => $e->getMessage()],
                'isError' => true,
            ]);
        }
    }

    protected function authenticateBearer()
    {
        $header = (string)Yii::$app->request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->challenge();
            throw new UnauthorizedHttpException('Bearer token is required.');
        }
        $resourceUri = rtrim(Url::to($this->mcp()->resourceRoute, true), '/');
        try {
            $token = $this->oauth()->validateAccessToken(trim($matches[1]), $resourceUri);
        } catch (\Exception $e) {
            $this->challenge();
            throw $e;
        }
        Yii::$app->user->login($token->cmsUser, 0);
        return $token;
    }

    protected function challenge(): void
    {
        $resource = rtrim(Url::to($this->mcp()->resourceRoute, true), '/');
        $metadata = Url::to(['/cms/oauth/protected-resource', 'resource' => $resource], true);
        Yii::$app->response->headers->set('WWW-Authenticate', 'Bearer resource_metadata="'.$metadata.'"');
    }

    protected function payload(): array
    {
        $payload = Yii::$app->request->bodyParams;
        if (!$payload && Yii::$app->request->rawBody) { $payload = Json::decode(Yii::$app->request->rawBody); }
        return (array)$payload;
    }
    protected function result($id, array $result): array { return ['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'result' => $result]; }
    protected function error($id, int $code, string $message, array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) { $error['data'] = $data; }
        return ['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'error' => $error];
    }
    protected function isList(array $value): bool { return $value !== [] && array_keys($value) === range(0, count($value) - 1); }
    protected function mcp(): McpComponent { return Yii::$app->get('cmsMcp'); }
    protected function oauth(): Oauth2ServerComponent { return Yii::$app->get($this->mcp()->oauth2Component); }
}
