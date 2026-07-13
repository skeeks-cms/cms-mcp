<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsProject;

class CmsProjectService extends AbstractCmsService
{
    protected $writable = ['name', 'description', 'is_active', 'is_private', 'cms_company_id', 'cms_user_id', 'cms_image_id', 'managers', 'users'];
    public function projectList(array $a): array { $q = CmsProject::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } $this->applyFilters($q, CmsProject::class, $a, ['is_active', 'is_private', 'cms_company_id', 'cms_user_id']); $this->applySearch($q, CmsProject::class, $a, ['name', 'description']); return $this->page($q->orderBy(['id' => SORT_DESC]), $a); }
    public function projectGet(array $a): array { return $this->withRelations($this->findAllowed(CmsProject::class, $a), ['managers', 'users', 'cmsCompany', 'cmsUser']); }
    public function projectCreate(array $a): array { $m = new CmsProject(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    public function projectUpdate(array $a): array { return $this->mutate($this->findAllowed(CmsProject::class, $a), $a); }
    protected function mutate(CmsProject $m, array $a): array { $this->applyWritable($m, $a, $this->writable); return $this->withRelations($this->save($m, 'Project validation failed'), ['managers', 'users', 'cmsCompany', 'cmsUser']); }
}
