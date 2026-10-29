<?php

return [
    'home_landing_slug' => 'principal',
    'registration_open' => (bool) env('JAKAWI_REGISTRATION_OPEN', false),
    'transactional_mail_ready' => (bool) env('JAKAWI_TRANSACTIONAL_MAIL_READY', false),
    'privacy_version' => '2026-10-28',
    'video_url' => env('JAKAWI_VIDEO_URL'),
    'video_poster' => env('JAKAWI_VIDEO_POSTER'),
    'confirmation_video_url' => env('JAKAWI_CONFIRMATION_VIDEO_URL'),
    'whatsapp_group_url' => env('JAKAWI_WHATSAPP_GROUP_URL'),
    'event' => [
        'title' => env('JAKAWI_EVENT_TITLE'),
        'starts_at' => env('JAKAWI_EVENT_STARTS_AT'),
        'zoom_url' => env('JAKAWI_EVENT_ZOOM_URL'),
    ],
];
