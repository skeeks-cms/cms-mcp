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
        return parent::beforeAction($action);
    }

    public function actionIndex(): array
    {
        $this->authenticateBearer();
        $baseUrl = rtrim(Url::to(['/cms/rest-api'], true), '/');

        return [
            'success' => true,
            'name' => $this->mcp()->serverName,
            'version' => $this->mcp()->serverVersion,
            'resource' => $this->resourceUri(),
            'endpoints' => [
                'tools' => $baseUrl.'/tools',
                'context' => $baseUrl.'/context',
                'openapi' => $baseUrl.'/openapi',
                'execute' => $baseUrl.'/tools/{tool_name}',
            ],
        ];
    }

    public function actionTools(): array
    {
        $accessToken = $this->authenticateBearer();

        return [
            'success' => true,
            'tools' => $this->mcp()->getAuthorizedToolSchemas($accessToken, true),
        ];
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
        $service = new RestApiOpenApiService(['mcp' => $this->mcp()]);

        return $service->build(
            Url::to(['/cms/rest-api'], true),
            $this->mcp()->getAuthorizedToolSchemas($accessToken, true)
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
                'data' => $this->mcp()->executeTool($name, $arguments, $accessToken),
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

}
