<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityReviewLog extends Model
{
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_VIEW_ID_CARD = 'view_id_card';
    public const ACTION_VIEW_LIVE_PHOTO = 'view_live_photo';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';
    public const ACTION_PURGE = 'purge';

    public const ACTIONS = [
        self::ACTION_SUBMIT => '学生提交核验',
        self::ACTION_VIEW_ID_CARD => '查看证件照',
        self::ACTION_VIEW_LIVE_PHOTO => '查看摄像头照片',
        self::ACTION_APPROVE => '人工确认通过',
        self::ACTION_REJECT => '人工确认拒绝',
        self::ACTION_PURGE => '保留期到期清理',
    ];

    protected $fillable = [
        'identity_verification_id',
        'operator_id',
        'action',
        'detail',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'identity_verification_id' => 'integer',
        'operator_id' => 'integer',
    ];

    public function verification()
    {
        return $this->belongsTo(IdentityVerification::class, 'identity_verification_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
