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

    public function siteData(CmsSite $site): array
    {
        return array_merge($site->toArray(), [
            'url' => $site->url,
            'root_tree_id' => $site->rootCmsTree ? (int)$site->rootCmsTree->id : null,
        ]);
    }

    public function themeData(CmsTheme $theme): array
    {
        $config = (array)$theme->config;
        $object = $theme->objectTheme;
        if ($object && $object->configFormModel) {
            $config = array_merge($object->configFormModelData, $config);
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
