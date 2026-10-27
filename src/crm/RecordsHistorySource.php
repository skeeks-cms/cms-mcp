<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use yii\db\ActiveQuery;

/**
 * Records of the blocks of a client card in the history: tasks, deals and,
 * with skeeks/cms-shop, bills and payments. One entry per record at its
 * creation, with its current state; only records the employee sees
 * (forManager()). With include_related a company also gets the tasks of its
 * leads.
 */
class RecordsHistorySource implements HistorySourceInterface
{
    public const SHOP_BILL = 'skeeks\\cms\\shop\\models\\ShopBill';
    public const SHOP_PAYMENT = 'skeeks\\cms\\shop\\models\\ShopPayment';

    public function types(): array
    {
        $types = ['task', 'deal'];
        if (class_exists(self::SHOP_BILL)) {
            $types[] = 'bill';
            $types[] = 'payment';
        }
        return $types;
    }

    public function entries(CrmReadContext $context): array
    {
        $result = [];
        foreach ($this->queries($context) as $type => $query) {
            foreach ($query->orderBy([$query->modelClass::tableName().'.created_at' => SORT_DESC, $query->modelClass::tableName().'.id' => SORT_DESC])->limit($context->fetchLimit)->all() as $model) {
                $result[] = $this->{$type}($model, $context);
            }
        }
        return $result;
    }

    public function count(CrmReadContext $context): int
    {
        $count = 0;
        foreach ($this->queries($context) as $query) {
            $count += (int)$query->count();
        }
        return $count;
    }

    /** @return ActiveQuery[] type => query */
    protected function queries(CrmReadContext $context): array
    {
        $record = $context->record;
        $column = [CrmRecord::LEAD => 'cms_lead_id', CrmRecord::COMPANY => 'cms_company_id', CrmRecord::CLIENT => 'cms_user_id'][$record->type];
        $id = $record->getId();
        $queries = [];
        if ($context->wants('task')) {
            $q = $this->scoped(CmsTask::class);
            $t = CmsTask::tableName();
            $condition = [$t.'.'.$column => $id];
            if ($record->type === CrmRecord::COMPANY && $context->includeRelated) {
                $condition = ['or', $condition, [$t.'.cms_lead_id' => CmsLead::find()->select('id')->andWhere(['cms_company_id' => $id])]];
            }
            $queries['task'] = $q->andWhere($condition);
        }
        if ($record->type !== CrmRecord::LEAD) {
            if ($context->wants('deal')) {
                $queries['deal'] = $this->scoped(CmsDeal::class)->andWhere([CmsDeal::tableName().'.'.$column => $id]);
            }
            if (class_exists(self::SHOP_BILL) && $context->wants('bill')) {
                $queries['bill'] = $this->scoped(self::SHOP_BILL)->andWhere([(self::SHOP_BILL)::tableName().'.'.$column => $id]);
            }
            if (class_exists(self::SHOP_PAYMENT) && $context->wants('payment')) {
                $queries['payment'] = $this->scoped(self::SHOP_PAYMENT)->andWhere([(self::SHOP_PAYMENT)::tableName().'.'.$column => $id]);
            }
        }
        [$from, $to] = $context->period();
        foreach ($queries as $query) {
            $t = $query->modelClass::tableName();
            if ($from) {
                $query->andWhere(['>=', $t.'.created_at', $from]);
            }
            if ($to) {
                $query->andWhere(['<=', $t.'.created_at', $to]);
            }
        }
        return $queries;
    }

    protected function scoped(string $class): ActiveQuery
    {
        $query = $class::find();
        if (method_exists($query, 'forManager')) {
            $query->forManager(\Yii::$app->user->identity);
        }
        return $query;
    }

    protected function task(CmsTask $task, CrmReadContext $context): array
    {
        $executor = $task->executor_id ? CrmActor::user((int)$task->executor_id, $context) : null;
        $parts = [(string)$task->statusAsText];
        if ($executor && isset($executor['name'])) {
            $parts[] = 'исполнитель '.$executor['name'];
        }
        if ($task->plan_end_at) {
            $parts[] = 'срок '.self::day($task->plan_end_at);
        }
        $description = $context->text(PersonalData::plain($task->description), 300);
        return [
            'at' => (int)$task->created_at,
            'type' => 'task',
            'subtype' => (string)$task->status,
            'title' => 'Задача «'.$context->text((string)$task->name, 200).'»',
            'summary' => implode(', ', $parts).($description !== '' ? '. '.$description : ''),
            'actor' => CrmActor::user($task->created_by ? (int)$task->created_by : null, $context),
            'details' => array_filter([
                'status' => (string)$task->status,
                'executor' => $executor,
                'plan_end_at' => $task->plan_end_at ? (int)$task->plan_end_at : null,
                'done_at' => $task->executor_end_at ? (int)$task->executor_end_at : null,
            ], static function ($v) { return $v !== null; }),
            'ref' => ['tool' => 'cms_task_get', 'id' => (int)$task->id],
            'url' => CrmRecord::adminUrl('cms/admin-cms-task', (int)$task->id),
        ];
    }

    protected function deal(CmsDeal $deal, CrmReadContext $context): array
    {
        $active = $deal->is_active && (!$deal->end_at || (int)$deal->end_at > time());
        $parts = [$active ? 'активна' : 'не активна'];
        if ((float)$deal->amount) {
            $parts[] = self::money($deal->amount, (string)$deal->currency_code).($deal->is_periodic ? ' за период' : '');
        }
        if ($deal->start_at || $deal->end_at) {
            $parts[] = trim(($deal->start_at ? 'с '.self::day($deal->start_at) : '').($deal->end_at ? ' по '.self::day($deal->end_at) : ''));
        }
        return [
            'at' => (int)$deal->created_at,
            'type' => 'deal',
            'subtype' => $active ? 'active' : 'inactive',
            'title' => 'Услуга «'.$context->text((string)$deal->name, 200).'»',
            'summary' => implode(', ', $parts),
            'actor' => CrmActor::user($deal->created_by ? (int)$deal->created_by : null, $context),
            'details' => [
                'is_active' => $active,
                'amount' => (float)$deal->amount,
                'currency_code' => (string)$deal->currency_code,
                'is_periodic' => (bool)$deal->is_periodic,
                'start_at' => $deal->start_at ? (int)$deal->start_at : null,
                'end_at' => $deal->end_at ? (int)$deal->end_at : null,
            ],
            'ref' => ['tool' => 'cms_deal_get', 'id' => (int)$deal->id],
            'url' => CrmRecord::adminUrl('cms/admin-cms-deal', (int)$deal->id),
        ];
    }

    protected function bill($bill, CrmReadContext $context): array
    {
        $state = $bill->paid_at ? 'paid' : ($bill->closed_at ? 'closed' : 'unpaid');
        $parts = [self::money($bill->amount, (string)$bill->currency_code)];
        $parts[] = $bill->paid_at ? 'оплачен '.self::day($bill->paid_at) : ($bill->closed_at ? 'отменён' : 'не оплачен');
        if ($bill->description) {
            $parts[] = $context->text(PersonalData::plain($bill->description), 200);
        }
        return [
            'at' => (int)$bill->created_at,
            'type' => 'bill',
            'subtype' => $state,
            'title' => 'Счёт №'.(int)$bill->id,
            'summary' => implode(', ', $parts),
            'actor' => CrmActor::user($bill->created_by ? (int)$bill->created_by : null, $context),
            'details' => [
                'amount' => (float)$bill->amount,
                'currency_code' => (string)$bill->currency_code,
                'paid_at' => $bill->paid_at ? (int)$bill->paid_at : null,
                'closed_at' => $bill->closed_at ? (int)$bill->closed_at : null,
            ],
            'ref' => ['tool' => 'shop_bill_get', 'id' => (int)$bill->id],
            'url' => CrmRecord::adminUrl('cms/admin-cms-bill', (int)$bill->id),
        ];
    }

    protected function payment($payment, CrmReadContext $context): array
    {
        $in = (bool)$payment->is_debit;
        return [
            'at' => (int)$payment->created_at,
            'type' => 'payment',
            'subtype' => $in ? 'in' : 'out',
            'title' => ($in ? 'Поступление ' : 'Выплата ').self::money($payment->amount, (string)$payment->currency_code),
            'summary' => $context->text(PersonalData::plain($payment->comment), 200),
            'actor' => CrmActor::user($payment->created_by ? (int)$payment->created_by : null, $context),
            'details' => ['amount' => (float)$payment->amount, 'currency_code' => (string)$payment->currency_code, 'is_incoming' => $in],
            'ref' => ['tool' => 'shop_payment_get', 'id' => (int)$payment->id],
        ];
    }

    public static function day($time): string
    {
        return \Yii::$app->formatter->asDate((int)$time, 'php:d.m.Y');
    }

    public static function money($amount, string $currency): string
    {
        return number_format((float)$amount, ((float)$amount == (int)$amount) ? 0 : 2, ',', ' ').' '.($currency === 'RUB' || $currency === '' ? '₽' : $currency);
    }
}
