<?php

return [
    'registration_open' => (bool) env('JAKAWI_REGISTRATION_OPEN', false),
    'transactional_mail_ready' => (bool) env('JAKAWI_TRANSACTIONAL_MAIL_READY', false),
    'privacy_version' => '2026-10-28',
    'video_url' => env('JAKAWI_VIDEO_URL'),
    'video_poster' => env('JAKAWI_VIDEO_POSTER'),
    'event' => [
        'title' => env('JAKAWI_EVENT_TITLE'),
        'starts_at' => env('JAKAWI_EVENT_STARTS_AT'),
        'zoom_url' => env('JAKAWI_EVENT_ZOOM_URL'),
    ],
    'whatsapp_url' => env('JAKAWI_WHATSAPP_URL'),
];
