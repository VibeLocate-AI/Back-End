<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | VibeLocate AI Service
    |--------------------------------------------------------------------------
    */

    'vibe_ai' => [
        'url' => env('VIBELOCATE_AI_URL', 'http://127.0.0.1:8001'),

        // Optional labels for the Admin AI Health screen.
        // These are NOT API keys and can be left null until you know
        // the actual model names used by the separate AI service.
        'model' => env('VIBELOCATE_AI_MODEL'),
        'fallback_model' => env('VIBELOCATE_AI_FALLBACK_MODEL'),
    ],

];
