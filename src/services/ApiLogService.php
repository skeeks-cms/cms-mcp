<?php

namespace skeeks\cms\mcp\services;

use Yii;
use yii\web\HttpException;

class ApiLogService
{
    const CATEGORY_REST = 'skeeks.cms.api.rest';
    const CATEGORY_MCP = 'skeeks.cms.api.mcp';
    const CATEGORY_TASK = 'skeeks.cms.api.task';

    private static $context = [];

    public static function startedAt(): float
    {
        return microtime(true);
    }

    public static function durationMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }

    public static function requestId(): string
    {
        $candidate = '';
        if (Yii::$app && Yii::$app->has('request', true)) {
            $candidate = (string)Yii::$app->request->headers->get('X-Request-ID', '');
        }
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $candidate)) {
            return $candidate;
        }

        try {
            return bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    public static function requestContext(string $transport, string $requestId): array
    {
        $context = [
            'transport' => $transport,
            'request_id' => $requestId,
        ];
        if (Yii::$app && Yii::$app->has('request', true)) {
            $request = Yii::$app->request;
            $context['http_method'] = (string)$request->method;
            $context['path'] = (string)$request->pathInfo;
            $length = $request->headers->get('Content-Length');
            if ($length !== null && is_numeric($length)) {
                $context['content_length'] = (int)$length;
            }
        }
        return $context;
    }

    public static function attachResponseLogging($response, string $category, array $context, float $requestStartedAt): void
    {
        $response->on(\yii\web\Response::EVENT_AFTER_PREPARE, function ($event) use ($category, $context, $requestStartedAt) {
            $content = $event->sender->content;
            self::info('response.prepared', array_merge($context, [
                'duration_ms' => self::durationMs($requestStartedAt),
                'status_code' => $event->sender->statusCode,
                'response_bytes' => is_string($content) ? strlen($content) : null,
            ]), $category, true);
        });
        $response->on(\yii\web\Response::EVENT_AFTER_SEND, function ($event) use ($category, $context, $requestStartedAt) {
            $content = $event->sender->content;
            self::info('response.sent', array_merge($context, [
                'duration_ms' => self::durationMs($requestStartedAt),
                'status_code' => $event->sender->statusCode,
                'response_bytes' => is_string($content) ? strlen($content) : null,
            ]), $category, true);
        });
    }

    public static function pushContext(array $context): array
    {
        $previous = self::$context;
        self::$context = array_merge(self::$context, $context);
        return $previous;
    }

    public static function restoreContext(array $context): void
    {
        self::$context = $context;
    }

    public static function context(): array
    {
        return self::$context;
    }

    public static function info(string $event, array $context, string $category, bool $flush = false): void
    {
        self::write('info', $event, $context, $category, $flush);
    }

    public static function warning(string $event, array $context, string $category, bool $flush = false): void
    {
        self::write('warning', $event, $context, $category, $flush);
    }

    public static function error(string $event, array $context, string $category, bool $flush = false): void
    {
        self::write('error', $event, $context, $category, $flush);
    }

    public static function summarizeArguments(array $arguments): array
    {
        $summary = ['keys' => array_values(array_map('strval', array_keys($arguments)))];
        $safeScalars = [
            'id', 'limit', 'offset', 'sort_by', 'sort_direction', 'executor_id',
            'created_by', 'updated_by', 'cms_project_id', 'cms_company_id',
            'cms_user_id', 'parent_cms_task_id', 'date_from', 'date_to',
            'group_by', 'metric', 'confirm', 'publish',
        ];
        foreach ($safeScalars as $name) {
            if (array_key_exists($name, $arguments) && (is_scalar($arguments[$name]) || $arguments[$name] === null)) {
                $summary[$name] = $arguments[$name];
            }
        }
        foreach (['named_filters', 'status'] as $name) {
            if (array_key_exists($name, $arguments)) {
                $values = is_array($arguments[$name]) ? $arguments[$name] : [$arguments[$name]];
                $summary[$name] = array_slice(array_values(array_filter($values, 'is_scalar')), 0, 20);
            }
        }
        if (array_key_exists('q', $arguments)) {
            $summary['q_length'] = function_exists('mb_strlen')
                ? mb_strlen((string)$arguments['q'])
                : strlen((string)$arguments['q']);
        }
        foreach (['filters', 'attributes'] as $name) {
            if (!empty($arguments[$name]) && is_array($arguments[$name])) {
                $summary[$name.'_keys'] = array_values(array_map('strval', array_keys($arguments[$name])));
            }
        }
        foreach ($arguments as $name => $value) {
            if (preg_match('/token|secret|password|authorization|cookie/i', (string)$name)) {
                $summary['redacted_keys'][] = (string)$name;
            } elseif (is_array($value) && !array_key_exists($name, $summary) && !in_array($name, ['filters', 'attributes'], true)) {
                $summary[$name.'_count'] = count($value);
            }
        }
        return $summary;
    }

    public static function summarizeResult($result): array
    {
        if (!is_array($result)) {
            return ['type' => gettype($result)];
        }
        $summary = ['keys' => array_values(array_map('strval', array_keys($result)))];
        if (isset($result['items']) && is_array($result['items'])) {
            $summary['items_count'] = count($result['items']);
        }
        foreach (['total', 'limit', 'offset', 'updated', 'created', 'success', 'requires_confirmation'] as $name) {
            if (array_key_exists($name, $result) && (is_scalar($result[$name]) || $result[$name] === null)) {
                $summary[$name] = $result[$name];
            }
        }
        return $summary;
    }

    public static function exception(\Throwable $exception): array
    {
        return [
            'exception' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'message' => self::redactText($exception->getMessage()),
        ];
    }

    public static function exceptionStatusCode(\Throwable $exception, int $fallback = 500): int
    {
        if ($exception instanceof HttpException) {
            return (int)$exception->statusCode;
        }
        if (Yii::$app && Yii::$app->has('response', true) && Yii::$app->response->statusCode >= 400) {
            return (int)Yii::$app->response->statusCode;
        }
        return $fallback;
    }

    private static function write(string $level, string $event, array $context, string $category, bool $flush): void
    {
        $payload = array_merge(self::$context, $context);
        $payload['event'] = $event;
        $payload['logged_at'] = gmdate('c');
        if (!array_key_exists('user_id', $payload) && Yii::$app && Yii::$app->has('user', true)) {
            $payload['user_id'] = Yii::$app->user->getId(false);
        }
        $payload['memory_mb'] = round(memory_get_usage(true) / 1048576, 2);
        $payload['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);

        $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($message === false) {
            $message = json_encode(['event' => $event, 'json_error' => json_last_error_msg()]);
        }

        if ($level === 'error') {
            Yii::error($message, $category);
        } elseif ($level === 'warning') {
            Yii::warning($message, $category);
        } else {
            Yii::info($message, $category);
        }
        if ($flush) {
            Yii::getLogger()->flush(false);
        }
    }

    private static function redactText(string $message): string
    {
        $message = preg_replace(
            '/(authorization|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|cookie)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $message
        );
        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000).'…';
        }
        return $message;
    }
}
