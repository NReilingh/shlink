<?php

declare(strict_types=1);

use function Shlinkio\Shlink\Common\env;

use const Shlinkio\Shlink\Core\DEFAULT_REDIRECT_CACHE_LIFETIME;
use const Shlinkio\Shlink\Core\DEFAULT_REDIRECT_STATUS_CODE;
use const Shlinkio\Shlink\Core\DEFAULT_SHORT_CODES_LENGTH;
use const Shlinkio\Shlink\Core\MIN_SHORT_CODES_LENGTH;

return (static function (): array {
    $webhooks = env('VISITS_WEBHOOKS');
    $shortCodesLength = (int) env('DEFAULT_SHORT_CODES_LENGTH', DEFAULT_SHORT_CODES_LENGTH);
    $shortCodesLength = $shortCodesLength < MIN_SHORT_CODES_LENGTH ? MIN_SHORT_CODES_LENGTH : $shortCodesLength;

    return [

        'url_shortener' => [
            'domain' => [
                'schema' => env('SHORT_DOMAIN_SCHEMA', 'http'),
                'hostname' => env('SHORT_DOMAIN_HOST', ''),
            ],
            'validate_url' => (bool) env('VALIDATE_URLS', false), // Deprecated
            'visits_webhooks' => $webhooks === null ? [] : explode(',', $webhooks),
            'default_short_codes_length' => $shortCodesLength,
            'auto_resolve_titles' => (bool) env('AUTO_RESOLVE_TITLES', false),
            'append_extra_path' => (bool) env('REDIRECT_APPEND_EXTRA_PATH', false),

            // TODO Move these two options to their own config namespace. Maybe "redirects".
            'redirect_status_code' => (int) env('REDIRECT_STATUS_CODE', DEFAULT_REDIRECT_STATUS_CODE),
            'redirect_cache_lifetime' => (int) env('REDIRECT_CACHE_LIFETIME', DEFAULT_REDIRECT_CACHE_LIFETIME),
        ],

    ];
})();
