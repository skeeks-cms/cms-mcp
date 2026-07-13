<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsSiteAddress;
use skeeks\cms\models\CmsSiteEmail;
use skeeks\cms\models\CmsSitePhone;
use skeeks\cms\models\CmsSiteSocial;

class CmsSiteContactService extends AbstractCmsService
{
    public function siteUpdate(array $arguments): array
    {
        $site = $this->findAllowed(CmsSite::class, $arguments); $this->applyWritable($site, $arguments, ['name', 'image_id', 'favicon_storage_file_id', 'work_time']);
        return $this->recordData($this->save($site, 'Site validation failed'));
    }
    public function phoneList(array $a): array { return $this->contactList(CmsSitePhone::class, $a); }
    public function phoneGet(array $a): array { return $this->contactGet(CmsSitePhone::class, $a); }
    public function phoneCreate(array $a): array { return $this->contactCreate(CmsSitePhone::class, $a); }
    public function phoneUpdate(array $a): array { return $this->contactUpdate(CmsSitePhone::class, $a); }
    public function emailList(array $a): array { return $this->contactList(CmsSiteEmail::class, $a); }
    public function emailGet(array $a): array { return $this->contactGet(CmsSiteEmail::class, $a); }
    public function emailCreate(array $a): array { return $this->contactCreate(CmsSiteEmail::class, $a); }
    public function emailUpdate(array $a): array { return $this->contactUpdate(CmsSiteEmail::class, $a); }
    public function addressList(array $a): array { return $this->contactList(CmsSiteAddress::class, $a); }
    public function addressGet(array $a): array { return $this->contactGet(CmsSiteAddress::class, $a); }
    public function addressCreate(array $a): array { return $this->contactCreate(CmsSiteAddress::class, $a); }
    public function addressUpdate(array $a): array { return $this->contactUpdate(CmsSiteAddress::class, $a); }
    public function socialList(array $a): array { return $this->contactList(CmsSiteSocial::class, $a); }
    public function socialGet(array $a): array { return $this->contactGet(CmsSiteSocial::class, $a); }
    public function socialCreate(array $a): array { return $this->contactCreate(CmsSiteSocial::class, $a); }
    public function socialUpdate(array $a): array { return $this->contactUpdate(CmsSiteSocial::class, $a); }
    protected function contactList(string $class, array $a): array { $q = $class::find(); $this->applyFilters($q, $class, $a, ['cms_site_id']); return $this->page($q->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]), $a); }
    protected function contactGet(string $class, array $a): array { return $this->recordData($this->find($class, $a)); }
    protected function contactCreate(string $class, array $a): array { $m = new $class(); $m->loadDefaultValues(); if (empty($a['cms_site_id'])) { $a['cms_site_id'] = $this->findSite($a)->id; } $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Site contact validation failed')); }
    protected function contactUpdate(string $class, array $a): array { $m = $this->find($class, $a); $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Site contact validation failed')); }
}
