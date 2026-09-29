<?php

namespace App\Console\Commands;

use App\Services\VerificationPurgeService;
use Illuminate\Console\Command;

class PurgeVerificationMaterials extends Command
{
    protected $signature = 'verifications:purge';

    protected $description = '清理超过保留期的考前身份核验材料（证件照/人脸抓拍）';

    public function handle(VerificationPurgeService $purgeService): int
    {
        $count = $purgeService->purgeExpired();

        $this->info("已清理 {$count} 条到期核验材料。");

        return self::SUCCESS;
    }
}
