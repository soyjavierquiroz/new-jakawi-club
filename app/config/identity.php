<?php

return [
    'enabled' => (bool) env('COM_IDENTITY_ENABLED', false),
    'base_url' => env('COM_IDENTITY_BASE_URL', 'https://jakawi.com/internal/identity'),
    'key_id' => env('COM_IDENTITY_KEY_ID'),
    'secret' => env('COM_IDENTITY_SECRET'),
    'proof_secret' => env('COM_IDENTITY_EMAIL_PROOF_SECRET'),
];
