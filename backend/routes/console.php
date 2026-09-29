<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每日凌晨校正一次核验相关表结构（幂等，兼容已有数据库卷）
Schedule::command('identity:ensure-schema')
    ->dailyAt('03:05')
    ->withoutOverlapping();

// 每日凌晨 03:15 清理超过保留期的证件/人脸核验材料
Schedule::command('identity:purge-expired')
    ->dailyAt('03:15')
    ->withoutOverlapping();
