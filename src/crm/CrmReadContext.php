<?php

namespace skeeks\cms\mcp\crm;

use yii\base\BaseObject;

/**
 * Parameters of one read of a client's history or overview, passed to the
 * history sources, log describers and overview contributors of packages.
 */
class CrmReadContext extends BaseObject
{
    /** @var CrmRecord|null the client whose history is read */
    public $record;
    /**
     * @var array[] activity feeds that make the history: the record's own feed and,
     * with include_related, the feeds of its leads and deals (CrmRecord::relatedFeedOwners())
     */
    public $feedOwners = [];
    /** @var bool the leads and deals of a company or client person join the history */
    public $includeRelated = false;
    /** @var string[]|null history types to return, null for all */
    public $types;
    /** @var int|null */
    public $dateFrom;
    /** @var int|null */
    public $dateTo;
    /** @var int how many newest entries a source returns (offset + limit of the page) */
    public $fetchLimit = 50;
    /**
     * @var bool emails, phones and addresses in full. False by default: the
     * caller may pass data to an external model, so contacts are masked
     * (PersonalData) unless explicitly asked for.
     */
    public $includeContacts = false;
    /** @var int length of a summary text */
    public $summaryLength = 500;

    public function wants(string $type): bool
    {
        return $this->types === null || in_array($type, $this->types, true);
    }

    /** @return array{0: int|null, 1: int|null} */
    public function period(): array
    {
        return [$this->dateFrom, $this->dateTo];
    }

    /** Text for an AI client: contacts masked unless included, length limited. */
    public function text(?string $text, ?int $length = null): string
    {
        $text = PersonalData::redactLinks((string)$text, self::personalLinkPatterns());
        if (!$this->includeContacts) {
            $text = PersonalData::redactText($text);
        }
        return PersonalData::cut($text, $length ?? $this->summaryLength);
    }

    /** @return string[] patterns of personal links (McpComponent extension personalLinkPatterns) */
    public static function personalLinkPatterns(): array
    {
        static $patterns;
        if ($patterns === null) {
            $patterns = \Yii::$app->has('cmsMcp') ? array_values(\Yii::$app->cmsMcp->extensions('personalLinkPatterns')) : [];
        }
        return $patterns;
    }

    public function email(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }
        return $this->includeContacts ? $email : PersonalData::maskEmail($email);
    }

    public function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        return $this->includeContacts ? $phone : PersonalData::maskPhone($phone);
    }
}
