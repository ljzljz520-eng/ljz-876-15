<?php

namespace App\Services;

use App\Models\ExamVerification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 核验材料清理服务。
 *
 * 隐私合规要求：核验材料（证件照、人脸抓拍）仅用于本次考试，
 * 超过保留期后物理删除文件，数据库仅保留状态/分数等审计元数据。
 */
class VerificationPurgeService
{
    /**
     * 清理所有到期的核验材料，返回清理条数。
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        ExamVerification::whereNull('purged_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($verifications) use (&$purged) {
                foreach ($verifications as $verification) {
                    $this->purgeOne($verification);
                    $purged++;
                }
            });

        return $purged;
    }

    /**
     * 清理单条记录的核验材料。
     */
    public function purgeOne(ExamVerification $verification): void
    {
        $disk = Storage::disk('verifications');

        foreach (['id_card_path', 'face_image_path'] as $field) {
            $path = $verification->{$field};
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
            }
        }

        $verification->forceFill([
            'id_card_path' => null,
            'face_image_path' => null,
            'purged_at' => now(),
        ])->save();

        Log::info('核验材料已按保留期清理', [
            'verification_id' => $verification->id,
            'user_id' => $verification->user_id,
            'exam_paper_id' => $verification->exam_paper_id,
        ]);
    }

    /**
     * 交卷后调用：将该考生该试卷的未清理材料保留期刷新为"考试结束后 + 保留小时数"。
     */
    public function schedulePurgeAfterExamFinished(int $userId, int $examPaperId): void
    {
        $retentionHours = (int) config('verification.retention_hours', 24);

        ExamVerification::where('user_id', $userId)
            ->where('exam_paper_id', $examPaperId)
            ->whereNull('purged_at')
            ->update(['purge_after' => now()->addHours($retentionHours)]);
    }
}
