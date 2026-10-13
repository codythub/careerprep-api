<?php

/*
|--------------------------------------------------------------------------
| CareerPrep CORS
|--------------------------------------------------------------------------
|
| The API uses bearer tokens only, so credentials are never allowed.
|
| FRONTEND_URL: comma-separated exact origins (scheme + host [+ port]).
|
| VERCEL_PREVIEW_PROJECT + VERCEL_PREVIEW_SCOPE: allow this project's Vercel
| preview URLs (https://<project>-<id>-<scope>.vercel.app). Intended for a
| staging API only, so they are ignored when APP_ENV=production.
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('FRONTEND_URL', '')),
)));

$previewProject = strtolower(trim((string) env('VERCEL_PREVIEW_PROJECT', '')));
$previewScope = strtolower(trim((string) env('VERCEL_PREVIEW_SCOPE', '')));

$allowedOriginPatterns = env('APP_ENV') !== 'production' && $previewProject !== '' && $previewScope !== ''
    ? ['#^https://'.preg_quote($previewProject, '#').'-[a-z0-9-]+-'.preg_quote($previewScope, '#').'\.vercel\.app$#']
    : [];

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => $allowedOriginPatterns,
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => false,
];
