<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSite;
use yii\base\Component;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

abstract class AbstractCmsService extends Component
{
    protected $systemAttributes = [
        'id', 'created_by', 'updated_by', 'created_at', 'updated_at',
        'auth_key', 'password_hash', 'password_reset_token', 'access_token',
        'logged_at', 'last_activity_at', 'last_admin_activity_at',
    ];

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

    protected function findAllowed(string $class, array $arguments, callable $prepareQuery = null)
    {
        $id = (int)($arguments['id'] ?? 0);
        if (!$id) { throw new Exception('id is required.'); }
        $query = $this->managerQuery($class);
        if ($prepareQuery) { $prepareQuery($query); }
        $model = $query->andWhere([$class::tableName().'.id' => $id])->one();
        if (!$model) { throw new Exception($class.' #'.$id.' not found or unavailable.'); }
        return $model;
    }

    protected function managerQuery(string $class): ActiveQuery
    {
        $query = $class::find();
        if (method_exists($query, 'forManager')) {
            $query->forManager(\Yii::$app->user->identity);
        }
        return $query;
    }

    protected function applyFilters(ActiveQuery $query, string $class, array $arguments, array $allowed): void
    {
        $filters = array_merge((array)($arguments['filters'] ?? []), $arguments);
        foreach ($allowed as $attribute) {
            if (array_key_exists($attribute, $filters) && $filters[$attribute] !== '' && $filters[$attribute] !== null) {
                $query->andWhere([$class::tableName().'.'.$attribute => $filters[$attribute]]);
            }
        }
    }

    protected function applySearch(ActiveQuery $query, string $class, array $arguments, array $attributes): void
    {
        if (empty($arguments['q']) || !$attributes) { return; }
        $or = ['or'];
        foreach ($attributes as $attribute) {
            $or[] = ['like', $class::tableName().'.'.$attribute, (string)$arguments['q']];
        }
        $query->andWhere($or);
    }

    protected function applyDateRange(ActiveQuery $query, string $class, array $arguments, string $attribute): void
    {
        if (!empty($arguments['date_from'])) {
            $query->andWhere(['>=', $class::tableName().'.'.$attribute, $this->timestamp($arguments['date_from'])]);
        }
        if (!empty($arguments['date_to'])) {
            $query->andWhere(['<=', $class::tableName().'.'.$attribute, $this->timestamp($arguments['date_to'], true)]);
        }
    }

    protected function applyWritable(ActiveRecord $model, array $arguments, array $allowed): void
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        foreach (array_diff($allowed, $this->systemAttributes) as $attribute) {
            if (!array_key_exists($attribute, $source) || !$model->canSetProperty($attribute)) { continue; }
            $value = $source[$attribute];
            if (substr($attribute, -3) === '_at' && $value !== null && $value !== '' && !is_numeric($value)) {
                $value = $this->timestamp($value);
            }
            $model->{$attribute} = $value;
        }
    }

    protected function save(ActiveRecord $model, string $label): ActiveRecord
    {
        if (!\Yii::$app->user->id) { throw new Exception('OAuth CMS user is required.'); }
        if ($model->isNewRecord && $model->hasAttribute('created_by')) { $model->created_by = \Yii::$app->user->id; }
        if ($model->hasAttribute('updated_by')) { $model->updated_by = \Yii::$app->user->id; }
        if (!$model->save()) { throw new Exception($label.': '.$this->modelErrors($model)); }
        $model->refresh();
        return $model;
    }

    protected function timestamp($value, bool $endOfDay = false): int
    {
        if (is_numeric($value)) { return (int)$value; }
        $text = trim((string)$value);
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) { $text .= ' 23:59:59'; }
        $timestamp = strtotime($text);
        if (!$timestamp) { throw new Exception('Invalid date: '.$value); }
        return $timestamp;
    }

    protected function withRelations(ActiveRecord $model, array $relations, array $extra = []): array
    {
        $data = $this->recordData($model);
        foreach ($relations as $name) {
            $value = $model->{$name};
            $data[$name] = is_array($value)
                ? array_map([$this, 'recordData'], $value)
                : ($value ? $this->recordData($value) : null);
        }
        foreach ($extra as $name => $callback) { $data[$name] = $callback($model); }
        return $data;
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
