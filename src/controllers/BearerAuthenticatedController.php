<?php

namespace skeeks\cms\mcp\controllers;

use skeeks\cms\base\Controller;
use skeeks\cms\mcp\McpComponent;
use skeeks\cms\oauth2\Oauth2ServerComponent;
use Yii;
use yii\helpers\Url;
use yii\web\UnauthorizedHttpException;

abstract class BearerAuthenticatedController extends Controller
{
    public $enableCsrfValidation = false;

    protected function authenticateBearer()
    {
        $header = (string)Yii::$app->request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->challenge();
            throw new UnauthorizedHttpException('Bearer token is required.');
        }

        try {
            $token = $this->oauth()->validateAccessToken(trim($matches[1]), $this->resourceUri());
        } catch (\Exception $e) {
            $this->challenge();
            throw $e;
        }

        // Bearer authentication is stateless. login() would start a PHP
        // session and serialize concurrent API requests behind its lock.
        Yii::$app->user->setIdentity($token->cmsUser);

        return $token;
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
