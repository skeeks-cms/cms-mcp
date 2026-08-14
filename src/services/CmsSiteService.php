<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsTheme;
use yii\base\Exception;

class CmsSiteService extends AbstractCmsService
{
    public function siteList(array $arguments): array
    {
        return $this->page(CmsSite::find(), $arguments, [$this, 'siteData']);
    }

    public function siteGet(array $arguments): array
    {
        return $this->siteData($this->find(CmsSite::class, $arguments));
    }

    public function siteContext(array $arguments): array
    {
        $site = $this->findSite($arguments);
        $theme = CmsTheme::find()->andWhere(['cms_site_id' => $site->id, 'is_active' => 1])->one();
        return [
            'site' => $this->siteData($site),
            'root_tree' => $site->rootCmsTree ? $this->treeData($site->rootCmsTree) : null,
            'active_theme' => $theme ? $this->themeData($theme) : null,
        ];
    }

    public function themeList(array $arguments): array
    {
        $query = CmsTheme::find()->andFilterWhere(['cms_site_id' => $arguments['cms_site_id'] ?? null]);
        return $this->page($query, $arguments, [$this, 'themeData']);
    }

    public function themeGet(array $arguments): array
    {
        return $this->themeData($this->find(CmsTheme::class, $arguments));
    }

    public function themeActive(array $arguments): array
    {
        $site = $this->findSite($arguments);
        $theme = CmsTheme::find()->andWhere(['cms_site_id' => $site->id, 'is_active' => 1])->one();
        if (!$theme) {
            throw new Exception('Active theme not found.');
        }
        return $this->themeData($theme);
    }

    public function themeUpdate(array $arguments): array
    {
        if (!\Yii::$app->user->id) {
            throw new Exception('OAuth CMS user is required.');
        }

        $theme = $this->find(CmsTheme::class, $arguments);
        $config = (array)($arguments['config'] ?? []);
        if (!$config) {
            throw new Exception('config must contain at least one setting.');
        }
        if (strlen(json_encode($config)) > 1048576) {
            throw new Exception('Theme config payload is too large.');
        }

        $object = $theme->objectTheme;
        if (!$object || !$object->configFormModel) {
            throw new Exception('Theme does not expose editable configuration.');
        }

        $model = $object->configFormModel;
        $allowed = $model->safeAttributes();
        $unknown = array_values(array_diff(array_keys($config), $allowed));
        if ($unknown) {
            throw new Exception('Unknown or read-only theme settings: '.implode(', ', $unknown));
        }

        $model->setAttributes($config);
        if (!$model->validate(array_keys($config))) {
            throw new Exception('Theme config validation failed: '.$this->errorsArray($model->errors));
        }

        $theme->config = array_merge((array)$theme->config, $model->getAttributes(array_keys($config)));
        $theme->updated_by = \Yii::$app->user->id;
        if (!$theme->save()) {
            throw new Exception('Theme update failed: '.$this->modelErrors($theme));
        }
        $theme->refresh();

        return [
            'theme' => [
                'id' => (int)$theme->id,
                'cms_site_id' => (int)$theme->cms_site_id,
                'code' => $theme->code,
                'is_active' => (bool)$theme->is_active,
                'updated_at' => (int)$theme->updated_at,
            ],
            'changed_settings' => array_keys($config),
            'changed_config' => $model->getAttributes(array_keys($config)),
        ];
    }

    public function siteData(CmsSite $site): array
    {
        return array_merge($site->toArray(), [
            'url' => $site->url,
            'root_tree_id' => $site->rootCmsTree ? (int)$site->rootCmsTree->id : null,
            'logo' => $this->fileData($site->image),
            'favicon' => $this->fileData($site->favicon),
            'main_domain' => $site->cmsSiteMainDomain ? [
                'id' => (int)$site->cmsSiteMainDomain->id,
                'domain' => $site->cmsSiteMainDomain->domain,
                'is_https' => (bool)$site->cmsSiteMainDomain->is_https,
                'url' => $site->cmsSiteMainDomain->url,
            ] : null,
        ]);
    }

    protected function fileData($file): ?array
    {
        if (!$file) {
            return null;
        }
        return [
            'id' => (int)$file->id,
            'name' => $file->name,
            'src' => $file->src,
            'absolute_src' => $file->absoluteSrc,
        ];
    }

    public function themeData(CmsTheme $theme): array
    {
        $config = (array)$theme->config;
        $object = $theme->objectTheme;
        if ($object) {
            $model = $object->configFormModel;
            if ($model) {
                $config = $model->getAttributes($model->safeAttributes());
            }
        }
        return array_merge($theme->toArray(), [
            'theme_name' => $theme->themeName,
            'theme_description' => $theme->themeDescription,
            'effective_config' => $config,
        ]);
    }

    protected function treeData($tree): array
    {
        return array_merge($tree->toArray(), [
            'url' => $tree->absoluteUrl,
            'published' => $tree->active === 'Y',
        ]);
    }
}
