<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContractor;

class CmsContractorService extends AbstractCmsService
{
    public function contractorList(array $a): array { $q = CmsContractor::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } $this->applyFilters($q, CmsContractor::class, $a, ['cms_site_id', 'type', 'inn']); $this->applySearch($q, CmsContractor::class, $a, ['name', 'full_name', 'first_name', 'last_name', 'inn']); return $this->page($q->orderBy(['id' => SORT_DESC]), $a); }
    public function contractorGet(array $a): array { return $this->withRelations($this->findAllowed(CmsContractor::class, $a), ['banks', 'companies', 'users']); }
    public function contractorCreate(array $a): array { $m = new CmsContractor(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    /**
     * Visibility is not the right to change: requisites are shared data of every
     * client and deal they belong to (CrmAccess::canEditContractor(), as the screen).
     */
    public function contractorUpdate(array $a): array
    {
        $m = $this->findAllowed(CmsContractor::class, $a);
        if (class_exists(\skeeks\cms\crm\CrmAccess::class) && method_exists(\skeeks\cms\crm\CrmAccess::class, 'canEditContractor')
            && !\skeeks\cms\crm\CrmAccess::instance()->canEditContractor((int)\Yii::$app->user->id, $m)) {
            throw new \InvalidArgumentException('Менять реквизиты можно с доступом «Изменение» или «Полный» ко всем клиентам, к которым они привязаны.');
        }
        return $this->mutate($m, $a);
    }
    protected function mutate(CmsContractor $m, array $a): array { if (empty($a['cms_site_id'])) { $a['cms_site_id'] = $this->findSite($a)->id; } $this->applyWritable($m, $a, $m->safeAttributes()); return $this->withRelations($this->save($m, 'Contractor validation failed'), ['banks', 'companies', 'users']); }
}
