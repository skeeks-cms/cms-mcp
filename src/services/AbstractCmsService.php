<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSite;
use yii\base\Component;
use yii\base\Exception;
use yii\db\ActiveRecord;

abstract class AbstractCmsService extends Component
{
    public function recordData($model): array
    {
        return $model instanceof ActiveRecord ? $model->toArray() : (array)$model;
    }

    public function propertyData($property): array
    {
        $data = $this->recordData($property);
        $data['label'] = $property->name ?? ($property->code ?? null);
        return $data;
    }

    protected function page($query, array $arguments, callable $serializer = null): array
    {
        $limit = max(1, min(100, (int)($arguments['limit'] ?? 50)));
        $offset = max(0, (int)($arguments['offset'] ?? 0));
        $total = (int)(clone $query)->count();
        $serializer = $serializer ?: [$this, 'recordData'];

        return [
            'items' => array_map($serializer, $query->limit($limit)->offset($offset)->all()),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    protected function find(string $class, array $arguments)
    {
        $id = (int)($arguments['id'] ?? 0);
        $model = $class::findOne($id);
        if (!$model) {
            throw new Exception($class.' #'.$id.' not found.');
        }
        return $model;
    }

    protected function findSite(array $arguments): CmsSite
    {
        $id = (int)($arguments['cms_site_id'] ?? 0);
        $site = $id
            ? CmsSite::findOne($id)
            : (\Yii::$app->skeeks->site ?: CmsSite::find()->andWhere(['is_default' => 1])->one());
        if (!$site) {
            throw new Exception('cms_site not found.');
        }
        return $site;
    }

    protected function apply(ActiveRecord $model, array $arguments, array $allowed): void
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        foreach ($allowed as $name) {
            if (array_key_exists($name, $source) && $model->canSetProperty($name)) {
                $model->{$name} = $source[$name];
            }
        }
        if (array_key_exists('image_ids', $source) && $model->canSetProperty('imageIds')) {
            $model->imageIds = $source['image_ids'];
        }
        if (array_key_exists('file_ids', $source) && $model->canSetProperty('fileIds')) {
            $model->fileIds = $source['file_ids'];
        }
    }

    protected function publishedValue(array $arguments, $default): string
    {
        return array_key_exists('publish', $arguments)
            ? ($arguments['publish'] ? 'Y' : 'N')
            : (string)$default;
    }

    protected function saveProperties($model, array $arguments): void
    {
        if (!array_key_exists('properties', $arguments)) {
            return;
        }
        $properties = $model->relatedPropertiesModel;
        $properties->setAttributes((array)$arguments['properties']);
        if (!$properties->save()) {
            throw new Exception('Property validation failed: '.$this->errorsArray($properties->errors));
        }
    }

    protected function validateProperties($model, array $arguments): array
    {
        if (!array_key_exists('properties', $arguments)) {
            return [];
        }
        $properties = $model->relatedPropertiesModel;
        $properties->setAttributes((array)$arguments['properties']);
        $properties->validate();
        return $properties->errors;
    }

    protected function relatedValues($model): array
    {
        $properties = $model->relatedPropertiesModel;
        $result = [];
        foreach ($properties->attributes() as $attribute) {
            $result[$attribute] = $properties->getAttribute($attribute);
        }
        return $result;
    }

    protected function modelErrors($model): string
    {
        return $this->errorsArray($model->errors);
    }

    protected function errorsArray(array $errors): string
    {
        $result = [];
        foreach ($errors as $attribute => $messages) {
            foreach ((array)$messages as $message) {
                $result[] = $attribute.': '.$message;
            }
        }
        return implode('; ', $result);
    }
}
