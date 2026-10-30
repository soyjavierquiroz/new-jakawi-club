<?php

return [
    'enabled' => (bool) env('JAKAWI_OIDC_ENABLED', false),
    'client_id' => env('OIDC_CLUB_CLIENT_ID'),
    'client_secret' => env('OIDC_CLUB_CLIENT_SECRET'),
];
