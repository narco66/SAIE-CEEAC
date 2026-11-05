<?php

/*
 * Liste CORS explicite (API-05). En production, frontend et API sont servis sous le même domaine (routage /api) :
 * aucune origine tierce n'est alors nécessaire.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept', 'Authorization', 'If-Match', 'If-None-Match', 'Idempotency-Key', 'X-Poll-Secret', 'X-Request-Id'],
    'exposed_headers' => ['ETag', 'X-Request-Id', 'Idempotent-Replayed'],
    'max_age' => 600,
    'supports_credentials' => true,
];
