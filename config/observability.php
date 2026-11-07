<?php

return [
    // Keep environment access inside configuration so config:cache is authoritative.
    'enabled' => filter_var(env('FARAST_OBSERVABILITY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
];
