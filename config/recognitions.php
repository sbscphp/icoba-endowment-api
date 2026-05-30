<?php

return [
    'certificate_image_folder' => env('RECOGNITION_CERTIFICATE_IMAGE_FOLDER', 'certificates'),
    'certificate_image_preview_folder' => env('RECOGNITION_CERTIFICATE_PREVIEW_FOLDER', 'certificates/previews'),
    'backfill_chunk_size' => (int) env('RECOGNITION_BACKFILL_CHUNK_SIZE', 500),
    'backfill_dispatch_email' => filter_var(env('RECOGNITION_BACKFILL_DISPATCH_EMAIL', true), FILTER_VALIDATE_BOOL),
    'certificate_asset_fetch_timeout' => (int) env('RECOGNITION_CERTIFICATE_ASSET_FETCH_TIMEOUT', 30),
    'certificate_asset_fetch_attempts' => (int) env('RECOGNITION_CERTIFICATE_ASSET_FETCH_ATTEMPTS', 2),
    'certificate_asset_max_embed_bytes' => (int) env('RECOGNITION_CERTIFICATE_ASSET_MAX_EMBED_BYTES', 512_000),
];
