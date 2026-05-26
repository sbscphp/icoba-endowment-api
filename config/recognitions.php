<?php

return [
    'backfill_chunk_size' => (int) env('RECOGNITION_BACKFILL_CHUNK_SIZE', 500),
    'backfill_dispatch_email' => (bool) env('RECOGNITION_BACKFILL_DISPATCH_EMAIL', true),
];
