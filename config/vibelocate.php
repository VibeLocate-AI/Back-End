<?php
return [
    'jwt_secret' => env('JWT_SECRET', 'vibelocate-local-secret-change-this-key-2026'),
    'access_expiry' => (int) env('JWT_ACCESS_EXPIRY', 900),
    'refresh_days' => (int) env('JWT_REFRESH_DAYS', 7),
    'remember_days' => (int) env('JWT_REMEMBER_DAYS', 30),
    'encryption_key' => env('APP_ENCRYPTION_KEY', 'change-this-local-encryption-key'),
];
