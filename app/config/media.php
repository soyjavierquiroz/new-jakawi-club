<?php

return [
    'disk' => env('MEDIA_DISK', 'minio-media'),
    'bucket' => env('MEDIA_AWS_BUCKET', 'jakawi-media'),
    'imgproxy_url' => rtrim((string) env('IMGPROXY_URL', 'https://img.jakawi.com'), '/'),
    'imgproxy_key' => env('IMGPROXY_KEY'),
    'imgproxy_salt' => env('IMGPROXY_SALT'),
    'max_bytes' => 10 * 1024 * 1024,
    'max_pixels' => 24_000_000,
    'presets' => [
        'thumbnail' => ['width' => 480, 'height' => 270, 'quality' => 80, 'format' => 'webp'],
        'card' => ['width' => 960, 'height' => 540, 'quality' => 82, 'format' => 'webp'],
        'social' => ['width' => 1200, 'height' => 630, 'quality' => 85, 'format' => 'jpg'],
    ],
];
