<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class EnsureIdentitySchema extends Command
{
    protected $signature = 'identity:ensure-schema';

    protected $description = '幂等创建证件人脸核验所需的表与字段（兼容已有数据库卷，避免重跑初始化 SQL）';

    public function handle(): int
    {
        $db = Schema::getConnection();

        if (!Schema::hasTable('identity_verifications')) {
            $db->statement("CREATE TABLE identity_verifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL COMMENT '考生ID',
                exam_paper_id BIGINT UNSIGNED NOT NULL COMMENT '试卷ID（材料仅限本次考试使用）',
                exam_record_id BIGINT UNSIGNED NULL COMMENT '关联的考试记录ID',
                id_card_no_encrypted TEXT NULL COMMENT '证件号码(AES加密存储)',
                id_card_name VARCHAR(50) NULL COMMENT '证件姓名',
                id_card_path VARCHAR(255) NULL COMMENT '证件照私有存储路径(非公开目录)',
                live_photo_path VARCHAR(255) NULL COMMENT '摄像头人脸照私有存储路径',
                match_score DECIMAL(5,2) NULL COMMENT '人脸比对得分0-100',
                match_detail VARCHAR(500) NULL COMMENT '比对引擎与原始指标',
                status ENUM('passed','suspected','failed') NOT NULL COMMENT '机器判定',
                attempt_number INT NOT NULL DEFAULT 1 COMMENT '第几次提交',
                reviewed_by BIGINT UNSIGNED NULL COMMENT '人工复核监考老师ID',
                reviewed_at TIMESTAMP NULL COMMENT '人工复核时间',
                review_result ENUM('approved','rejected') NULL COMMENT '人工复核结果',
                review_remark VARCHAR(500) NULL COMMENT '复核备注',
                expires_at TIMESTAMP NULL COMMENT '保留期到期时间',
                purged_at TIMESTAMP NULL COMMENT '材料清理(匿名化)时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_iv_user_paper (user_id, exam_paper_id),
                INDEX idx_iv_status_review (status, review_result),
                INDEX idx_iv_expires (expires_at, purged_at),
                INDEX idx_iv_record (exam_record_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='考试证件与人脸核验记录表'");
        }

        if (!Schema::hasTable('identity_review_logs')) {
            $db->statement("CREATE TABLE identity_review_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                identity_verification_id BIGINT UNSIGNED NOT NULL COMMENT '核验记录ID',
                operator_id BIGINT UNSIGNED NULL COMMENT '操作人ID(系统清理时为空)',
                action ENUM('submit','view_id_card','view_live_photo','approve','reject','purge') NOT NULL COMMENT '操作类型',
                detail VARCHAR(1000) NULL COMMENT '操作详情/备注',
                ip VARCHAR(45) NULL COMMENT '操作IP',
                user_agent VARCHAR(500) NULL COMMENT 'UA',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_irl_verification (identity_verification_id),
                INDEX idx_irl_operator (operator_id),
                INDEX idx_irl_action (action),
                INDEX idx_irl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='核验材料查看与人工复核审计日志表'");
        }

        if (Schema::hasTable('exam_papers') && !Schema::hasColumn('exam_papers', 'identity_check_enabled')) {
            $db->statement("ALTER TABLE exam_papers
                ADD COLUMN identity_check_enabled TINYINT(1) DEFAULT 1
                COMMENT '进入考试是否要求证件+人脸核验: 1-是 0-否' AFTER type");
        }

        $this->info('identity schema ready.');

        return self::SUCCESS;
    }
}
