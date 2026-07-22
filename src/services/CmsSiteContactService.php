<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsSiteAddress;
use skeeks\cms\models\CmsSiteDomain;
use skeeks\cms\models\CmsSiteEmail;
use skeeks\cms\models\CmsSitePhone;
use skeeks\cms\models\CmsSiteSocial;
use yii\base\Exception;
use yii\db\ActiveRecord;

class CmsSiteContactService extends AbstractCmsService
{
    public function siteInfoGet(array $arguments): array
    {
        return $this->siteData($this->siteFromArguments($arguments));
    }

    public function siteUpdate(array $arguments): array
    {
        $site = $this->siteFromArguments($arguments);
        $this->applyWritable($site, $arguments, [
            'name', 'image_id', 'favicon_storage_file_id', 'work_time',
        ]);
        $this->save($site, 'cms_site validation failed');
        return $this->siteData($site);
    }

    public function phoneList(array $arguments): array { return $this->contactList(CmsSitePhone::class, $arguments); }
    public function phoneGet(array $arguments): array { return $this->contactGet(CmsSitePhone::class, $arguments); }
    public function phoneCreate(array $arguments): array { return $this->contactCreate(CmsSitePhone::class, $arguments); }
    public function phoneUpdate(array $arguments): array { return $this->contactUpdate(CmsSitePhone::class, $arguments); }

    public function emailList(array $arguments): array { return $this->contactList(CmsSiteEmail::class, $arguments); }
    public function emailGet(array $arguments): array { return $this->contactGet(CmsSiteEmail::class, $arguments); }
    public function emailCreate(array $arguments): array { return $this->contactCreate(CmsSiteEmail::class, $arguments); }
    public function emailUpdate(array $arguments): array { return $this->contactUpdate(CmsSiteEmail::class, $arguments); }

    public function addressList(array $arguments): array { return $this->contactList(CmsSiteAddress::class, $arguments); }
    public function addressGet(array $arguments): array { return $this->contactGet(CmsSiteAddress::class, $arguments); }
    public function addressCreate(array $arguments): array { return $this->contactCreate(CmsSiteAddress::class, $arguments); }
    public function addressUpdate(array $arguments): array { return $this->contactUpdate(CmsSiteAddress::class, $arguments); }

    public function socialList(array $arguments): array { return $this->contactList(CmsSiteSocial::class, $arguments); }
    public function socialGet(array $arguments): array { return $this->contactGet(CmsSiteSocial::class, $arguments); }
    public function socialCreate(array $arguments): array { return $this->contactCreate(CmsSiteSocial::class, $arguments); }
    public function socialUpdate(array $arguments): array { return $this->contactUpdate(CmsSiteSocial::class, $arguments); }

    public function socialTypeList(array $arguments): array
    {
        $items = [];
        foreach (CmsSiteSocial::getSocialTypes() as $code => $name) {
            $items[] = ['code' => (string)$code, 'name' => (string)$name];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    public function domainList(array $arguments): array { return $this->contactList(CmsSiteDomain::class, $arguments); }
    public function domainGet(array $arguments): array { return $this->contactGet(CmsSiteDomain::class, $arguments); }
    public function domainCreate(array $arguments): array { return $this->contactCreate(CmsSiteDomain::class, $arguments); }
    public function domainUpdate(array $arguments): array { return $this->contactUpdate(CmsSiteDomain::class, $arguments); }

    protected function contactList(string $class, array $arguments): array
    {
        $site = $this->findSite($arguments);
        $query = $class::find()->andWhere([$class::tableName().'.cms_site_id' => (int)$site->id]);
        $this->applyFilters($query, $class, $arguments, $this->filterAttributes($class));
        $this->applySearch($query, $class, $arguments, $this->searchAttributes($class));
        $order = $class === CmsSiteDomain::class
            ? ['is_main' => SORT_DESC, 'id' => SORT_ASC]
            : ['priority' => SORT_ASC, 'id' => SORT_ASC];
        return $this->page($query->orderBy($order), $arguments, function ($model) {
            return $this->contactData($model);
        });
    }

    protected function contactGet(string $class, array $arguments): array
    {
        return $this->contactData($this->findContact($class, $arguments));
    }

    protected function contactCreate(string $class, array $arguments): array
    {
        $site = $this->findSite($arguments);
        $model = new $class();
        $model->loadDefaultValues();
        $model->cms_site_id = (int)$site->id;
        $this->applyWritable($model, $arguments, $this->writableAttributes($class));
        $this->save($model, $this->modelLabel($class).' validation failed');
        return $this->contactData($model);
    }

    protected function contactUpdate(string $class, array $arguments): array
    {
        $model = $this->findContact($class, $arguments);
        $this->applyWritable($model, $arguments, $this->writableAttributes($class));
        $this->save($model, $this->modelLabel($class).' validation failed');
        return $this->contactData($model);
    }

    protected function findContact(string $class, array $arguments): ActiveRecord
    {
        $site = $this->findSite($arguments);
        $model = $class::find()->andWhere([
            $class::tableName().'.id' => (int)($arguments['id'] ?? 0),
            $class::tableName().'.cms_site_id' => (int)$site->id,
        ])->one();
        if (!$model) {
            throw new Exception($this->modelLabel($class).' not found on the selected site.');
        }
        return $model;
    }

    protected function siteFromArguments(array $arguments): CmsSite
    {
        $id = (int)($arguments['id'] ?? ($arguments['cms_site_id'] ?? 0));
        if (!$id) {
            return $this->findSite($arguments);
        }
        $site = $this->managerQuery(CmsSite::class)->andWhere([CmsSite::tableName().'.id' => $id])->one();
        if (!$site) {
            throw new Exception('cms_site not found or unavailable.');
        }
        return $site;
    }

    protected function siteData(CmsSite $site): array
    {
        return array_merge($this->recordData($site), [
            'url' => $site->url,
            'logo' => $this->fileData($site->image),
            'favicon' => $this->fileData($site->favicon),
        ]);
    }

    protected function contactData(ActiveRecord $model): array
    {
        $data = $this->recordData($model);
        if ($model instanceof CmsSiteAddress) {
            $data['image'] = $this->fileData($model->cmsImage);
            $data['coordinates'] = $model->coordinates;
            $data['effective_work_time'] = $model->workTime;
        } elseif ($model instanceof CmsSitePhone) {
            $data['only_number'] = $model->onlyNumber;
        } elseif ($model instanceof CmsSiteSocial) {
            $types = CmsSiteSocial::getSocialTypes();
            $data['social_type_name'] = $types[$model->social_type] ?? $model->social_type;
        } elseif ($model instanceof CmsSiteDomain) {
            $data['url'] = $model->url;
        }
        return $data;
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

    protected function writableAttributes(string $class): array
    {
        if ($class === CmsSitePhone::class || $class === CmsSiteEmail::class) {
            return ['value', 'name', 'priority'];
        }
        if ($class === CmsSiteAddress::class) {
            return ['value', 'name', 'latitude', 'longitude', 'work_time', 'cms_image_id', 'priority'];
        }
        if ($class === CmsSiteSocial::class) {
            return ['social_type', 'url', 'name', 'priority'];
        }
        if ($class === CmsSiteDomain::class) {
            return ['domain', 'is_main', 'is_https'];
        }
        return [];
    }

    protected function filterAttributes(string $class): array
    {
        if ($class === CmsSiteSocial::class) {
            return ['id', 'social_type'];
        }
        if ($class === CmsSiteDomain::class) {
            return ['id', 'domain', 'is_main', 'is_https'];
        }
        return ['id'];
    }

    protected function searchAttributes(string $class): array
    {
        if ($class === CmsSiteDomain::class) {
            return ['domain'];
        }
        if ($class === CmsSiteSocial::class) {
            return ['name', 'url', 'social_type'];
        }
        return ['name', 'value'];
    }

    protected function modelLabel(string $class): string
    {
        return [
            CmsSitePhone::class => 'cms_site_phone',
            CmsSiteEmail::class => 'cms_site_email',
            CmsSiteAddress::class => 'cms_site_address',
            CmsSiteSocial::class => 'cms_site_social',
            CmsSiteDomain::class => 'cms_site_domain',
        ][$class] ?? $class;
    }
}
