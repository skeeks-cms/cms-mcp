<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\base\Component as CmsComponent;
use skeeks\cms\models\CmsComponentSettings;
use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsUser;
use skeeks\yii2\config\ConfigBehavior;
use yii\base\Component;
use yii\base\Exception;

class CmsComponentSettingsService extends AbstractCmsService
{
    public function settingsList(array $arguments): array
    {
        $components = [];
        foreach (\Yii::$app->getComponents(true) as $id => $config) {
            try {
                $component = \Yii::$app->get($id);
            } catch (\Throwable $e) {
                continue;
            }
            if ($component instanceof CmsComponent || ($component instanceof Component && $component->getBehavior(ConfigBehavior::class))) {
                $components[] = [
                    'id' => $id,
                    'class' => get_class($component),
                    'name' => $this->componentName($component, $id),
                ];
            }
        }
        $query = CmsComponentSettings::find()->andFilterWhere(['cms_site_id' => $arguments['cms_site_id'] ?? null]);
        return ['components' => $components, 'settings' => $this->page($query, $arguments)];
    }

    public function settingsGet(array $arguments): array
    {
        $componentName = $arguments['component'];
        try {
            $componentName = get_class($this->resolveComponent($componentName));
        } catch (\Throwable $e) {
        }
        $query = CmsComponentSettings::find()->andWhere(['component' => $componentName]);
        if (array_key_exists('cms_site_id', $arguments)) {
            $query->andWhere(['cms_site_id' => $arguments['cms_site_id']]);
        }
        if (array_key_exists('user_id', $arguments)) {
            $query->andWhere(['user_id' => $arguments['user_id']]);
        }
        return ['items' => array_map([$this, 'recordData'], $query->all())];
    }

    public function settingsEffective(array $arguments): array
    {
        $component = $this->resolveComponent($arguments['component']);
        if ($component instanceof CmsComponent) {
            if (!empty($arguments['cms_site_id'])) {
                $component->cmsSite = CmsSite::findOne((int)$arguments['cms_site_id']);
            }
            if (!empty($arguments['user_id'])) {
                $component->cmsUser = CmsUser::findOne((int)$arguments['user_id']);
            }
            $component->refresh();
            $values = [];
            foreach ($component->attributes() as $attribute) {
                $values[$attribute] = $component->getAttribute($attribute);
            }
            return ['id' => $arguments['component'], 'class' => get_class($component), 'settings' => $values];
        }

        $values = [];
        foreach ((new \ReflectionObject($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $values[$property->getName()] = $property->getValue($component);
            }
        }
        return ['id' => $arguments['component'], 'class' => get_class($component), 'settings' => $values];
    }

    public function settingsUpdate(array $arguments): array
    {
        if (!\Yii::$app->user->id) {
            throw new Exception('OAuth CMS user is required.');
        }

        $component = $this->resolveComponent($arguments['component']);
        if (!$component instanceof CmsComponent) {
            throw new Exception('Only SkeekS CMS components support settings updates.');
        }

        $site = $this->findSite($arguments);
        $attributes = (array)($arguments['attributes'] ?? []);
        if (!$attributes) {
            throw new Exception('attributes must contain at least one setting.');
        }
        if (strlen(json_encode($attributes)) > 1048576) {
            throw new Exception('Component settings payload is too large.');
        }

        $component->cmsSite = $site;
        $component->refresh();

        $allowed = $component->safeAttributes();
        $unknown = array_values(array_diff(array_keys($attributes), $allowed));
        if ($unknown) {
            throw new Exception('Unknown or read-only component settings: '.implode(', ', $unknown));
        }

        $component->setAttributes($attributes);
        $component->setOverride(CmsComponent::OVERRIDE_SITE);
        if (!$component->save(true, array_keys($attributes))) {
            throw new Exception('Component settings validation failed: '.$this->errorsArray($component->errors));
        }
        $component->refresh();

        $values = [];
        foreach ($component->safeAttributes() as $attribute) {
            $values[$attribute] = $component->getAttribute($attribute);
        }

        return [
            'id' => $arguments['component'],
            'class' => get_class($component),
            'cms_site_id' => (int)$site->id,
            'changed_settings' => array_keys($attributes),
            'effective_settings' => $values,
        ];
    }

    protected function resolveComponent(string $id)
    {
        if (\Yii::$app->has($id)) {
            return \Yii::$app->get($id);
        }
        foreach (\Yii::$app->getComponents(true) as $componentId => $config) {
            try {
                $component = \Yii::$app->get($componentId);
            } catch (\Throwable $e) {
                continue;
            }
            if (get_class($component) === $id) {
                return $component;
            }
        }
        throw new Exception('Component not found: '.$id);
    }

    protected function componentName($component, string $fallback): string
    {
        try {
            if (isset($component->descriptor) && $component->descriptor->name) {
                return (string)$component->descriptor->name;
            }
        } catch (\Throwable $e) {
        }
        return $fallback;
    }
}
