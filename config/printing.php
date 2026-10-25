<?php

return [
    'enabled'                   => (bool) env('PRINTING_ENABLED', false),
    'job_ttl_seconds'           => 600,
    'heartbeat_timeout_seconds' => 60,
    'max_labels'                => 1000,
    'max_document_bytes'        => 20 * 1024 * 1024,
];
