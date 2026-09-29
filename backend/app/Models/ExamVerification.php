<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'id_card_path',
        'face_image_path',
        'similarity_score',
        'status',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'purge_after',
        'purged_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'similarity_score' => 'float',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
    ];

    // 系统自动判定状态
    public const STATUS_PASSED = 'passed';         // 通过
    public const STATUS_SUSPICIOUS = 'suspicious'; // 疑似（需人工确认）
    public const STATUS_FAILED = 'failed';         // 失败

    public const STATUSES = [
        self::STATUS_PASSED => '通过',
        self::STATUS_SUSPICIOUS => '疑似',
        self::STATUS_FAILED => '失败',
    ];

    // 人工复核结果（仅疑似单）
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * 核验是否最终有效（自动通过，或疑似经人工确认通过）。
     */
    public function isEffective(): bool
    {
        if ($this->status === self::STATUS_PASSED) {
            return true;
        }

        return $this->status === self::STATUS_SUSPICIOUS
            && $this->review_status === self::REVIEW_APPROVED;
    }

    /**
     * 是否待人工确认。
     */
    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_SUSPICIOUS && $this->review_status === null;
    }

    /**
     * 核验材料是否已被清理。
     */
    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * 查询某考生在某试卷下最新一条有效通过的核验记录。
     */
    public static function latestEffectiveFor(int $userId, int $examPaperId): ?self
    {
        return static::where('user_id', $userId)
            ->where('exam_paper_id', $examPaperId)
            ->where(function ($query) {
                $query->where('status', self::STATUS_PASSED)
                    ->orWhere(function ($q) {
                        $q->where('status', self::STATUS_SUSPICIOUS)
                            ->where('review_status', self::REVIEW_APPROVED);
                    });
            })
            ->orderBy('id', 'desc')
            ->first();
    }
}
