<?php

return [
    'security'=>[
        'max_upload_bytes'=>(int)env('FARAST_MAX_UPLOAD_BYTES',2*1024*1024*1024),
        'max_tool_input_bytes'=>(int)env('FARAST_MAX_TOOL_INPUT_BYTES',50*1024*1024),
    ],
    'observability'=>['enabled'=>filter_var(env('FARAST_OBSERVABILITY_ENABLED',true),FILTER_VALIDATE_BOOLEAN)],
    'evaluation'=>['max_regression'=>(float)env('FARAST_EVALUATION_MAX_REGRESSION',0.05)],
];