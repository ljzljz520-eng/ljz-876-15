<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        /*
        | 身份核验材料专用盘：私有存储，不对外暴露 URL。
        | 仅能通过受控接口（人工确认场景、保留期内）读取，到期物理清理。
        */
        'verifications' => [
            'driver' => 'local',
            'root' => storage_path('app/verifications'),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
