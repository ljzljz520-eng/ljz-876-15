<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IdentityVerification extends Model
{
    // 机器判定状态
    public const STATUS_PASSED = 'passed';
    public const STATUS_SUSPECTED = 'suspected';
    public const STATUS_FAILED = 'failed';

    // 人工复核结果（null 表示未复核）
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PASSED => '通过',
        self::STATUS_SUSPECTED => '疑似',
        self::STATUS_FAILED => '失败',
    ];

    public const REVIEW_RESULTS = [
        self::REVIEW_APPROVED => '人工确认通过',
        self::REVIEW_REJECTED => '人工确认拒绝',
    ];

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'exam_record_id',
        'id_card_no_encrypted',
        'id_card_name',
        'id_card_path',
        'live_photo_path',
        'match_score',
        'match_detail',
        'status',
        'attempt_number',
        'reviewed_by',
        'reviewed_at',
        'review_result',
        'review_remark',
        'expires_at',
        'purged_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'exam_record_id' => 'integer',
        'match_score' => 'decimal:2',
        'attempt_number' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'purged_at' => 'datetime',
    ];

    protected $hidden = [
        'id_card_no_encrypted', // 证件号加密存储，永不直接输出
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviewLogs()
    {
        return $this->hasMany(IdentityReviewLog::class, 'identity_verification_id');
    }

    /** 准入条件：机器通过（未被改判）或疑似经人工确认通过，且材料未过保留期 */
    public function scopeAdmitted($query)
    {
        return $query->whereNull('purged_at')->where(function ($q) {
            $q->where('status', self::STATUS_PASSED)
                ->whereNull('review_result')
                ->orWhere(function ($q2) {
                    $q2->where('status', self::STATUS_SUSPECTED)
                        ->where('review_result', self::REVIEW_APPROVED);
                });
        });
    }

    public function setIdCardNoAttribute(?string $value): void
    {
        $this->attributes['id_card_no_encrypted'] = $value !== null && $value !== ''
            ? Crypt::encryptString($value)
            : null;
    }

    /** 仅返回脱敏证件号（如 110101********1234） */
    public function getIdCardNoMaskedAttribute(): ?string
    {
        $raw = $this->id_card_no_encrypted
            ? Crypt::decryptString($this->id_card_no_encrypted)
            : null;

        if (!$raw) {
            return null;
        }

        $len = strlen($raw);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($raw, 0, 4).str_repeat('*', max($len - 8, 4)).substr($raw, -4);
    }

    /** 是否可以据此进入考试：机器通过，或疑似经人工确认通过 */
    public function getIsAdmittedAttribute(): bool
    {
        if ($this->purged_at) {
            return false;
        }

        if ($this->status === self::STATUS_PASSED && $this->review_result === null) {
            return true;
        }

        return $this->status === self::STATUS_SUSPECTED
            && $this->review_result === self::REVIEW_APPROVED;
    }

    /** 给前端/接口使用的安全数组（不含任何证件号密文与存储路径） */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'exam_paper_id' => $this->exam_paper_id,
            'exam_record_id' => $this->exam_record_id,
            'id_card_name' => $this->purged_at ? null : $this->id_card_name,
            'id_card_no_masked' => $this->id_card_no_masked,
            'match_score' => $this->match_score !== null ? (float) $this->match_score : null,
            'status' => $this->status,
            'attempt_number' => $this->attempt_number,
            'review_result' => $this->review_result,
            'review_remark' => $this->review_remark,
            'reviewed_at' => $this->reviewed_at?->toDateTimeString(),
            'is_admitted' => $this->is_admitted,
            'purged' => $this->purged_at !== null,
            'expires_at' => $this->expires_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
