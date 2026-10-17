<?php

declare(strict_types=1);

namespace App\Support;

use App\Logging\RedactSecrets;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry / GlitchTip scrubbing (MVP-SPEC A.6a): drop query string and fragment from request URLs and breadcrumb
 * URLs, replace secret path segments, remove c/t/token/code/pin/signature keys from request data. Combined with
 * send_default_pii=false.
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = RedactSecrets::scrubString(self::stripQuery($request['url']));
        }
        unset($request['query_string'], $request['cookies']);

        if (isset($request['data']) && is_array($request['data'])) {
            $request['data'] = RedactSecrets::scrub($request['data']);
        }

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $name) {
                if (in_array(strtolower((string) $name), ['cookie', 'authorization', 'referer', 'x-xsrf-token', 'x-csrf-token'], true)) {
                    unset($request['headers'][$name]);
                }
            }
        }

        $event->setRequest($request);

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $metadata = $breadcrumb->getMetadata();

        foreach (['url', 'to', 'from'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $metadata[$key] = RedactSecrets::scrubString(self::stripQuery($metadata[$key]));
            }
        }

        $message = $breadcrumb->getMessage();

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $message !== null ? RedactSecrets::scrubString($message) : null,
            RedactSecrets::scrub($metadata),
            $breadcrumb->getTimestamp(),
        );
    }

    public static function stripQuery(string $url): string
    {
        return (string) preg_replace('/[?#].*$/s', '', $url);
    }
}
