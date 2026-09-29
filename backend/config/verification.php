<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 考前身份核验配置
    |--------------------------------------------------------------------------
    | 人脸比对相似度阈值（0 ~ 1）：
    |   >= pass_threshold       通过(passed)
    |   >= suspicious_threshold 疑似(suspicious)，需监考老师人工确认
    |   其余                    失败(failed)
    */
    'pass_threshold' => (float) env('FACE_PASS_THRESHOLD', 0.85),

    'suspicious_threshold' => (float) env('FACE_SUSPICIOUS_THRESHOLD', 0.60),

    /*
    |--------------------------------------------------------------------------
    | 核验材料保留期（隐私合规）
    |--------------------------------------------------------------------------
    | retention_hours     : 考试结束（交卷）后材料保留小时数，到期自动清理
    | max_retention_hours : 未交卷兜底保留小时数（自核验提交起算），到期强制清理
    */
    'retention_hours' => (int) env('VERIFICATION_RETENTION_HOURS', 24),

    'max_retention_hours' => (int) env('VERIFICATION_MAX_RETENTION_HOURS', 72),

    // 上传图片大小上限（KB）
    'max_upload_kb' => (int) env('VERIFICATION_MAX_UPLOAD_KB', 5120),
];
