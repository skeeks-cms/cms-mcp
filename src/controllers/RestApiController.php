<?php

namespace skeeks\cms\mcp\controllers;

use skeeks\cms\mcp\services\RestApiOpenApiService;
use Yii;
use yii\base\InvalidConfigException;
use yii\filters\VerbFilter;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RestApiController extends BearerAuthenticatedController
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['get'],
                    'tools' => ['get'],
                    'tools-index' => ['get'],
                    'tool-schema' => ['get'],
                    'context' => ['get'],
                    'openapi' => ['get'],
                    'execute' => ['post'],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->headers->set('X-Skeeks-Api-Version', (string)$this->mcp()->apiVersion);
        Yii::$app->response->headers->set('X-Skeeks-Server-Version', (string)$this->mcp()->serverVersion);
        return parent::beforeAction($action);
    }

    public function actionIndex(): array
    {
        $accessToken = $this->authenticateBearer();
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        $this->applyCatalogHeaders($catalog['tools_revision']);
        $baseUrl = rtrim(Url::to(['/cms/rest-api'], true), '/');

        return [
            'success' => true,
            'name' => $this->mcp()->serverName,
            'api_version' => $catalog['api_version'],
            'server_version' => $catalog['server_version'],
            'tools_revision' => $catalog['tools_revision'],
            'tools_count' => $catalog['tools_count'],
            'resource' => $this->resourceUri(),
            'endpoints' => [
                'tools' => $baseUrl.'/tools',
                'tools_index' => $baseUrl.'/tools/index',
                'tool_schema' => $baseUrl.'/tools/{tool_name}',
                'context' => $baseUrl.'/context',
                'openapi' => $baseUrl.'/openapi',
                'execute' => $baseUrl.'/tools/{tool_name}',
            ],
        ];
    }

    public function actionTools()
    {
        $accessToken = $this->authenticateBearer();
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        $etag = $this->applyCatalogHeaders($catalog['tools_revision']);
        if ($this->isNotModified($etag)) {
            return $this->notModified();
        }

        $tools = $this->filterTools($catalog['tools'], Yii::$app->request->getQueryParams());

        return [
            'success' => true,
            'api_version' => $catalog['api_version'],
            'server_version' => $catalog['server_version'],
            'tools_revision' => $catalog['tools_revision'],
            'tools_count' => $catalog['tools_count'],
            'returned_count' => count($tools),
            'tools' => $tools,
        ];
    }

    public function actionToolsIndex()
    {
        $accessToken = $this->authenticateBearer();
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        $etag = $this->applyCatalogHeaders($catalog['tools_revision']);
        if ($this->isNotModified($etag)) {
            return $this->notModified();
        }

        $tools = $this->filterTools($catalog['tools'], Yii::$app->request->getQueryParams());
        $index = [];
        foreach ($tools as $tool) {
            $index[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'required_scope' => $tool['requiredScope'] ?? null,
            ];
        }

        return [
            'success' => true,
            'api_version' => $catalog['api_version'],
            'server_version' => $catalog['server_version'],
            'tools_revision' => $catalog['tools_revision'],
            'tools_count' => $catalog['tools_count'],
            'returned_count' => count($index),
            'tools' => $index,
        ];
    }

    public function actionToolSchema(string $name)
    {
        $accessToken = $this->authenticateBearer();
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        $etag = $this->applyCatalogHeaders($catalog['tools_revision']);
        if ($this->isNotModified($etag)) {
            return $this->notModified();
        }

        foreach ($catalog['tools'] as $tool) {
            if ($tool['name'] === $name) {
                return [
                    'success' => true,
                    'api_version' => $catalog['api_version'],
                    'server_version' => $catalog['server_version'],
                    'tools_revision' => $catalog['tools_revision'],
                    'tool' => $tool,
                ];
            }
        }

        throw new NotFoundHttpException('Unknown or unauthorized MCP tool: '.$name);
    }

    public function actionContext(): array
    {
        $accessToken = $this->authenticateBearer();
        $arguments = Yii::$app->request->getQueryParams();

        return $this->execute('cms_site_context_get', $arguments, $accessToken);
    }

    public function actionOpenapi(): array
    {
        $accessToken = $this->authenticateBearer();
        $catalog = $this->mcp()->getAuthorizedToolsMetadata($accessToken);
        $this->applyCatalogHeaders($catalog['tools_revision']);
        $service = new RestApiOpenApiService(['mcp' => $this->mcp()]);

        return $service->build(
            Url::to(['/cms/rest-api'], true),
            $catalog['tools']
        );
    }

    public function actionExecute(string $name): array
    {
        $accessToken = $this->authenticateBearer();
        $arguments = Yii::$app->request->bodyParams;
        if (!$arguments && Yii::$app->request->rawBody) {
            $arguments = json_decode(Yii::$app->request->rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadRequestHttpException('Request body must contain valid JSON.');
            }
        }

        return $this->execute($name, (array)$arguments, $accessToken);
    }

    protected function execute(string $name, array $arguments, $accessToken): array
    {
        try {
            return [
                'success' => true,
                'tool' => $name,
                'data' => $this->mcp()->executeTool($name, $arguments, $accessToken, [
                    'transport' => 'rest',
                    'request_id' => $this->apiRequestId(),
                ]),
            ];
        } catch (InvalidConfigException $e) {
            throw new NotFoundHttpException($e->getMessage(), 0, $e);
        } catch (ForbiddenHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'tool' => $name,
                'error' => $e->getMessage(),
                'error_type' => YII_DEBUG ? get_class($e) : null,
            ];
        }
    }

    protected function resourceUri(): string
    {
        return rtrim(Url::to($this->mcp()->restResourceRoute, true), '/');
    }

    protected function applyCatalogHeaders(string $revision): string
    {
        $etag = '"tools-'.substr($revision, strlen('sha256:')).'"';
        $headers = Yii::$app->response->headers;
        $headers->set('ETag', $etag);
        $headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
        $headers->set('Vary', 'Authorization');
        $headers->set('X-Skeeks-Api-Version', (string)$this->mcp()->apiVersion);
        $headers->set('X-Skeeks-Server-Version', (string)$this->mcp()->serverVersion);
        $headers->set('X-Skeeks-Tools-Revision', $revision);

        return $etag;
    }

    protected function isNotModified(string $etag): bool
    {
        $header = (string)Yii::$app->request->headers->get('If-None-Match', '');
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }

    protected function notModified(): string
    {
        Yii::$app->response->statusCode = 304;
        Yii::$app->response->format = Response::FORMAT_RAW;

        return '';
    }

    protected function filterTools(array $tools, array $query): array
    {
        $prefix = trim((string)($query['prefix'] ?? ''));
        $search = trim((string)($query['q'] ?? ''));
        $names = $query['names'] ?? [];
        if (is_string($names)) {
            $names = preg_split('/\s*,\s*/', $names, -1, PREG_SPLIT_NO_EMPTY);
        }
        $names = array_fill_keys(array_map('strval', (array)$names), true);

        if ($prefix === '' && $search === '' && !$names) {
            return array_values($tools);
        }

        return array_values(array_filter($tools, static function (array $tool) use ($prefix, $search, $names): bool {
            $name = (string)($tool['name'] ?? '');
            if ($prefix !== '' && strpos($name, $prefix) !== 0) {
                return false;
            }
            if ($names && !isset($names[$name])) {
                return false;
            }
            if ($search !== '' && stripos($name.' '.(string)($tool['description'] ?? ''), $search) === false) {
                return false;
            }

            return true;
        }));
    }

}
