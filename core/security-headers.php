<?php
declare(strict_types=1);

// Sent with every PHP response and by the cache fast path. Keep in sync with .htaccess and nginx.vhost.example.
return [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'SAMEORIGIN',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-src 'self' https://yandex.ru/map-widget/ https://yandex.com/map-widget/ https://www.google.com/maps/embed https://www.openstreetmap.org/export/embed.html; frame-ancestors 'self'",
];
