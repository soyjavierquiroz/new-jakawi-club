<?php
return [
    'privacy_ready' => (bool) env('ADS_PRIVACY_READY', false),
    'retention_days' => max(1, min(90, (int) env('ADS_RETENTION_DAYS', 30))),
    'meta' => ['enabled' => (bool) env('META_ADS_ENABLED', false), 'pixel_id' => env('META_PIXEL_ID'),
        'token' => env('META_CAPI_ACCESS_TOKEN'), 'version' => 'v26.0'],
    'tiktok' => ['enabled' => (bool) env('TIKTOK_ADS_ENABLED', false), 'pixel_id' => env('TIKTOK_PIXEL_ID'),
        'token' => env('TIKTOK_EVENTS_ACCESS_TOKEN')],
];
