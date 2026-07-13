<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsTree;
use skeeks\cms\models\CmsTreeType;
use yii\base\Exception;

class CmsTreeService extends AbstractCmsService
{
    public function treeList(array $arguments): array
    {
        $query = CmsTree::find();
        foreach (['cms_site_id', 'pid', 'tree_type_id', 'active'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $query->andWhere([$key => $arguments[$key]]);
            }
        }
        return $this->page($query->orderBy(['level' => SORT_ASC, 'priority' => SORT_ASC]), $arguments, [$this, 'treeData']);
    }

    public function treeGet(array $arguments): array
    {
        return $this->treeData($this->find(CmsTree::class, $arguments), true);
    }

    public function treeResolve(array $arguments): array
    {
        if (!empty($arguments['id'])) {
            return $this->treeGet($arguments);
        }
        $site = $this->findSite($arguments);
        $tree = null;
        if (!empty($arguments['path'])) {
            $tree = $site->rootCmsTree;
            foreach (array_values(array_filter(explode('/', trim($arguments['path'], '/')))) as $code) {
                $tree = CmsTree::find()->andWhere(['pid' => $tree->id, 'code' => $code])->one();
                if (!$tree) {
                    break;
                }
            }
        } elseif (!empty($arguments['code'])) {
            $tree = CmsTree::find()->andWhere(['cms_site_id' => $site->id, 'code' => $arguments['code']])->one();
        }
        if (!$tree) {
            throw new Exception('cms_tree not found.');
        }
        return $this->treeData($tree, true);
    }

    public function treeCreate(array $arguments): array
    {
        $parent = CmsTree::findOne((int)($arguments['parent_id'] ?? 0));
        if (!$parent) {
            throw new Exception('Parent cms_tree not found.');
        }
        $model = new CmsTree();
        $this->apply($model, $arguments, $this->writableAttributes());
        $model->active = $this->publishedValue($arguments, 'N');
        $transaction = CmsTree::getDb()->beginTransaction();
        try {
            if (!$model->appendTo($parent)->save()) {
                throw new Exception($this->modelErrors($model));
            }
            $this->saveProperties($model, $arguments);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $model->refresh();
        return $this->treeData($model, true);
    }

    public function treeUpdate(array $arguments): array
    {
        $model = $this->find(CmsTree::class, $arguments);
        $this->apply($model, $arguments, $this->writableAttributes());
        if (array_key_exists('publish', $arguments)) {
            $model->active = $this->publishedValue($arguments, $model->active);
        }
        if (!$model->save()) {
            throw new Exception($this->modelErrors($model));
        }
        $this->saveProperties($model, $arguments);
        $model->refresh();
        return $this->treeData($model, true);
    }

    public function treeValidate(array $arguments): array
    {
        $model = !empty($arguments['id']) ? $this->find(CmsTree::class, $arguments) : new CmsTree();
        $this->apply($model, $arguments, $this->writableAttributes());
        $valid = $model->validate();
        $propertyErrors = $this->validateProperties($model, $arguments);
        return [
            'valid' => $valid && !$propertyErrors,
            'errors' => $model->errors,
            'property_errors' => $propertyErrors,
            'tree_type_id' => $model->tree_type_id,
        ];
    }

    public function treeTypeList(array $arguments): array
    {
        return $this->page(CmsTreeType::find()->orderBy(['priority' => SORT_ASC]), $arguments);
    }

    public function treeTypeGet(array $arguments): array
    {
        $model = $this->find(CmsTreeType::class, $arguments);
        return [
            'type' => $this->recordData($model),
            'properties' => array_map([$this, 'propertyData'], $model->cmsTreeTypeProperties),
        ];
    }

    public function treeTypePropertyList(array $arguments): array
    {
        $type = CmsTreeType::findOne((int)$arguments['tree_type_id']);
        if (!$type) {
            throw new Exception('cms_tree_type not found.');
        }
        return ['items' => array_map([$this, 'propertyData'], $type->cmsTreeTypeProperties)];
    }

    public function treeData(CmsTree $tree, bool $details = false): array
    {
        $data = array_merge($tree->toArray(), [
            'url' => $tree->absoluteUrl,
            'published' => $tree->active === 'Y',
        ]);
        if ($details) {
            $data['properties'] = $this->relatedValues($tree);
            $data['property_definitions'] = array_map([$this, 'propertyData'], $tree->relatedProperties);
        }
        return $data;
    }

    protected function writableAttributes(): array
    {
        return [
            'name', 'code', 'tree_type_id', 'description_short', 'description_full',
            'description_short_type', 'description_full_type', 'seo_h1', 'meta_title',
            'meta_description', 'meta_keywords', 'priority', 'active', 'is_index',
            'view_file', 'image_id', 'image_full_id',
        ];
    }
}
