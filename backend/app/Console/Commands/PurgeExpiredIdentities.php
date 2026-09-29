<?php

namespace App\Console\Commands;

use App\Models\ExamRecord;
use App\Models\IdentityReviewLog;
use App\Models\IdentityVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredIdentities extends Command
{
    protected $signature = 'identity:purge-expired {--dry-run : 只统计不执行清理}';

    protected $description = '清理超过保留期的考试证件与人脸核验材料（删除文件并匿名化记录）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $retentionDays = (int) config('identity.retention_days', 7);

        // 1) 兜底：已交卷的考试，按"交卷时间 + 保留天数"校正到期时间
        //    （正常在 submit 时已顺延，此处防止漏算）
        $backfillQuery = IdentityVerification::whereNull('identity_verifications.purged_at')
            ->join('exam_records', function ($join) {
                $join->on('exam_records.id', '=', 'identity_verifications.exam_record_id')
                    ->whereNotNull('exam_records.end_time');
            })
            ->select('identity_verifications.id', 'exam_records.end_time');

        $backfillCount = 0;
        foreach ($backfillQuery->cursor() as $row) {
            $due = \Carbon\Carbon::parse($row->end_time)->addDays($retentionDays);
            DB::table('identity_verifications')
                ->where('id', $row->id)
                ->where(function ($q) use ($due) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '<', $due);
                })
                ->update(['expires_at' => $due, 'updated_at' => now()]);
            $backfillCount++;
        }

        if ($backfillCount > 0) {
            $this->info("已按交卷时间校正 {$backfillCount} 条记录的保留期到期时间。");
        }

        // 2) 清理到期记录
        $expired = IdentityVerification::whereNull('purged_at')
            ->where('expires_at', '<=', now())
            ->get();

        $this->info('到期核验记录数：'.$expired->count());

        $disk = Storage::disk('local');
        $purgedFiles = 0;
        $purgedRecords = 0;

        foreach ($expired as $verification) {
            // 安全兜底：关联考试仍在进行中则跳过（不应发生，防止误删）
            $inProgress = ExamRecord::where('user_id', $verification->user_id)
                ->where('exam_paper_id', $verification->exam_paper_id)
                ->where('status', ExamRecord::STATUS_IN_PROGRESS)
                ->exists();

            if ($inProgress) {
                $this->line("跳过 #{$verification->id}：存在进行中的考试");
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] #{$verification->id} user={$verification->user_id} paper={$verification->exam_paper_id} expires={$verification->expires_at}");
                continue;
            }

            $deletedThisRecord = 0;
            foreach ([$verification->id_card_path, $verification->live_photo_path] as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                    $purgedFiles++;
                    $deletedThisRecord++;
                }
            }

            // 匿名化：清除证件号密文、姓名、文件路径，仅保留最小审计骨架
            $verification->update([
                'id_card_no_encrypted' => null,
                'id_card_name' => null,
                'id_card_path' => null,
                'live_photo_path' => null,
                'match_detail' => null,
                'purged_at' => now(),
            ]);

            IdentityReviewLog::create([
                'identity_verification_id' => $verification->id,
                'operator_id' => null,
                'action' => IdentityReviewLog::ACTION_PURGE,
                'detail' => 'files_deleted='.$deletedThisRecord,
                'ip' => null,
                'user_agent' => null,
            ]);

            $purgedRecords++;
            $this->line("已清理 #{$verification->id}");
        }

        if (!$dryRun) {
            $this->info("完成，删除文件 {$purgedFiles} 个，匿名化记录 {$purgedRecords} 条。");
        }

        return self::SUCCESS;
    }
}
