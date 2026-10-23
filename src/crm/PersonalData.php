<?php

namespace skeeks\cms\mcp\crm;

/**
 * Contacts in data an AI client may pass to an external model: by default
 * the business part only (152-ФЗ). An email keeps its domain (the company),
 * a phone its last two digits; addresses and numbers inside free text are
 * replaced. The full values come only with include_contacts=true.
 */
class PersonalData
{
    private const EMAIL = '/[A-Z0-9._%+\-]+@([A-Z0-9\-]+\.)+[A-Z]{2,}/iu';
    // +7 (999) 123-45-67, 8 999 123 45 67, 89991234567: at least 10 digits with separators.
    private const PHONE = '/(?<![\w\d])(?:\+?\d[\s\-().]{0,3}){9,14}\d(?![\w\d])/u';

    /** "i***@skeeks.com" */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = mb_strrpos($email, '@');
        if ($at === false) {
            return '***';
        }
        return mb_substr($email, 0, 1).'***'.mb_substr($email, $at);
    }

    /** "***-**-67" */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        return $digits === '' ? '***' : '***-**-'.substr($digits, -2);
    }

    public static function redactText(string $text): string
    {
        $text = (string)preg_replace_callback(self::EMAIL, static function ($m) { return self::maskEmail($m[0]); }, $text);
        return (string)preg_replace_callback(self::PHONE, static function ($m) {
            $digits = preg_replace('/\D+/', '', $m[0]);
            // Dates, sums and numbers of documents rarely have 10–15 digits with phone separators.
            return strlen($digits) >= 10 && strlen($digits) <= 15 ? '[телефон]' : $m[0];
        }, $text);
    }

    /** What replaces a personal link of a client in texts. */
    public const PERSONAL_LINK = '[личная ссылка клиента]';

    /**
     * Personal links of a client (the page of a sending, an opt-out link)
     * replaced in any text, whatever include_contacts says: opening one would
     * count as the client's opening or opt-out. The packages that own such
     * pages add their path patterns to McpComponent extension
     * personalLinkPatterns.
     *
     * @param string[] $patterns regular expressions matched against each URL
     */
    public static function redactLinks(string $text, array $patterns): string
    {
        if (!$patterns || stripos($text, 'http') === false) {
            return $text;
        }
        return (string)preg_replace_callback('~https?://[^\s<>()\[\]"\']+~iu', static function ($m) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $m[0])) {
                    return self::PERSONAL_LINK;
                }
            }
            return $m[0];
        }, $text);
    }

    /** Plain text from HTML or text, spaces collapsed, cut to $length characters. */
    public static function plain(?string $value): string
    {
        $value = (string)$value;
        if ($value !== strip_tags($value)) {
            $value = preg_replace('~<(style|script|head|title)\b[^>]*>.*?</\1>~is', ' ', $value);
            $value = preg_replace('~<br\s*/?>|</p>|</div>|</li>|</tr>|</h\d>~i', "\n", (string)$value);
            $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        return trim((string)preg_replace("/\s*\n\s*(\n\s*)+/u", "\n\n", (string)$value));
    }

    public static function cut(string $text, int $length): string
    {
        $text = trim($text);
        return $length > 0 && mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)).'…' : $text;
    }
}
