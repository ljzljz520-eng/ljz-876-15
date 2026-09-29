<?php

return [
    // 人脸比对驱动：local=内置GD演示实现（仅用于演示/开发环境）
    // 生产环境请实现 App\Services\FaceMatch\Contracts\FaceMatcher 并切换为云厂商人脸比对驱动
    'driver' => env('IDENTITY_FACE_DRIVER', 'local'),

    // 机器判定阈值（0-100）：>= pass 为通过；>= suspect 为疑似；其余为失败
    'threshold_pass' => (int) env('IDENTITY_PASS_THRESHOLD', 80),
    'threshold_suspect' => (int) env('IDENTITY_SUSPECT_THRESHOLD', 55),

    // 核验材料保留期：考试结束（交卷）后保留的自然日数，到期自动清理
    'retention_days' => (int) env('IDENTITY_RETENTION_DAYS', 7),

    // 未交卷时核验记录的初始保留天数（防止记录永久存在）
    'retention_pending_days' => (int) env('IDENTITY_PENDING_RETENTION_DAYS', 30),

    // 同一考生同一场考试最多允许提交核验的次数
    'max_attempts' => (int) env('IDENTITY_MAX_ATTEMPTS', 5),

    // 允许上传的证件/人脸图片
    'allowed_mimes' => ['jpeg', 'jpg', 'png'],
    'max_size_kb' => 5120,
];
