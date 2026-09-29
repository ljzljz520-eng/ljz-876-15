<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamVerification;
use App\Services\FaceCompareService;
use App\Services\VerificationPurgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function __construct(
        protected FaceCompareService $faceCompare,
        protected VerificationPurgeService $purgeService
    ) {
    }

    /**
     * 学生提交考前身份核验（证件照 + 摄像头人脸抓拍）。
     */
    public function store(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();

        $maxKb = (int) config('verification.max_upload_kb', 5120);
        $validator = Validator::make($request->all(), [
            'id_card_image' => "required|image|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
            'face_image' => "required|image|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
        ], [
            'id_card_image.required' => '请上传证件照片',
            'id_card_image.image' => '证件照片必须是图片文件',
            'id_card_image.max' => '证件照片不能超过 ' . ($maxKb / 1024) . 'MB',
            'face_image.required' => '请完成摄像头人脸抓拍',
            'face_image.image' => '人脸抓拍必须是图片文件',
            'face_image.max' => '人脸抓拍不能超过 ' . ($maxKb / 1024) . 'MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first() ?: '核验材料校验失败',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 已有有效通过的核验，无需重复核验
        $effective = ExamVerification::latestEffectiveFor($user->id, $examPaper->id);
        if ($effective) {
            return response()->json([
                'message' => '您已通过本场考试的身份核验，无需重复提交',
                'verification' => $this->serialize($effective),
            ], 409);
        }

        // 存在待人工确认的疑似单，不允许重复提交
        $pending = ExamVerification::where('user_id', $user->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', ExamVerification::STATUS_SUSPICIOUS)
            ->whereNull('review_status')
            ->exists();
        if ($pending) {
            return response()->json([
                'message' => '您的核验结果为疑似，正在等待监考老师人工确认，请勿重复提交',
            ], 409);
        }

        // 存入私有盘（不在公开目录，不提供直接 URL）
        $dir = $user->id . '/' . $examPaper->id;
        $idCardExt = $this->imageExtension($request->file('id_card_image'));
        $faceExt = $this->imageExtension($request->file('face_image'));
        $idCardPath = $request->file('id_card_image')
            ->storeAs($dir, 'id_card_' . Str::uuid() . '.' . $idCardExt, 'verifications');
        $facePath = $request->file('face_image')
            ->storeAs($dir, 'face_' . Str::uuid() . '.' . $faceExt, 'verifications');

        try {
            $absoluteIdCard = Storage::disk('verifications')->path($idCardPath);
            $absoluteFace = Storage::disk('verifications')->path($facePath);
            $score = $this->faceCompare->compare($absoluteIdCard, $absoluteFace);
        } catch (\Throwable $e) {
            Storage::disk('verifications')->delete([$idCardPath, $facePath]);
            Log::warning('人脸比对失败', ['error' => $e->getMessage()]);

            return response()->json(['message' => '图片解析失败，请重新拍摄并上传'], 422);
        }

        $status = $this->faceCompare->classify($score);

        $verification = ExamVerification::create([
            'user_id' => $user->id,
            'exam_paper_id' => $examPaper->id,
            'id_card_path' => $idCardPath,
            'face_image_path' => $facePath,
            'similarity_score' => $score,
            'status' => $status,
            // 兜底保留期：未交卷时也按最大保留期强制清理；交卷后会刷新为更短的保留期
            'purge_after' => now()->addHours((int) config('verification.max_retention_hours', 72)),
        ]);

        return response()->json([
            'message' => $this->statusMessage($verification),
            'verification' => $this->serialize($verification),
        ], 201);
    }

    /**
     * 当前学生在指定试卷下的最新核验状态。
     */
    public function status(Request $request, ExamPaper $examPaper)
    {
        $this->purgeExpiredQuietly();

        $verification = ExamVerification::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'verification' => $verification ? $this->serialize($verification) : null,
            'effective_passed' => $verification ? $verification->isEffective() : false,
        ]);
    }

    /**
     * 监考端：待人工确认的疑似核验列表。
     */
    public function pending(Request $request)
    {
        $this->authorizeProctor($request);
        $this->purgeExpiredQuietly();

        $verifications = ExamVerification::with(['user:id,username,real_name,email', 'examPaper:id,title'])
            ->where('status', ExamVerification::STATUS_SUSPICIOUS)
            ->whereNull('review_status')
            ->orderBy('id', 'desc')
            ->paginate((int) $request->input('per_page', 15));

        $verifications->getCollection()->transform(fn ($v) => $this->serializeForProctor($v));

        return response()->json(['verifications' => $verifications]);
    }

    /**
     * 监考端：核验记录（元数据）总览。不返回材料内容，仅状态审计信息。
     */
    public function history(Request $request)
    {
        $this->authorizeProctor($request);
        $this->purgeExpiredQuietly();

        $query = ExamVerification::with(['user:id,username,real_name,email', 'examPaper:id,title', 'reviewer:id,username'])
            ->orderBy('id', 'desc');

        if ($request->filled('exam_paper_id')) {
            $query->where('exam_paper_id', (int) $request->input('exam_paper_id'));
        }
        if ($request->filled('status') && array_key_exists($request->input('status'), ExamVerification::STATUSES)) {
            $query->where('status', $request->input('status'));
        }

        $verifications = $query->paginate((int) $request->input('per_page', 15));
        $verifications->getCollection()->transform(fn ($v) => $this->serializeForProctor($v));

        return response()->json(['verifications' => $verifications]);
    }

    /**
     * 监考端：人工确认疑似核验（通过 / 拒绝）。
     */
    public function review(Request $request, ExamVerification $verification)
    {
        $this->authorizeProctor($request);

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approved,rejected',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$verification->isPendingReview()) {
            return response()->json(['message' => '该记录不在待人工确认状态'], 409);
        }

        $verification->update([
            'review_status' => $request->input('action'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->input('note'),
        ]);

        return response()->json([
            'message' => $verification->review_status === ExamVerification::REVIEW_APPROVED
                ? '已人工确认通过，考生可进入考试'
                : '已拒绝，考生需重新进行身份核验',
            'verification' => $this->serializeForProctor($verification->fresh(['user:id,username,real_name,email', 'examPaper:id,title'])),
        ]);
    }

    /**
     * 监考端：查看核验材料图片（受控访问）。
     *
     * 隐私约束：
     *  - 仅监考老师/管理员可访问；
     *  - 仅"待人工确认的疑似单"在保留期内可查看（人工确认完成后即关闭访问）；
     *  - 材料清理后返回 410。
     */
    public function image(Request $request, ExamVerification $verification, string $type)
    {
        $this->authorizeProctor($request);

        if (!in_array($type, ['id_card', 'face'], true)) {
            return response()->json(['message' => '无效的材料类型'], 404);
        }

        if ($verification->isPurged()) {
            return response()->json(['message' => '核验材料已按保留期清理，无法查看'], 410);
        }

        if (!$verification->isPendingReview()) {
            return response()->json(['message' => '仅待人工确认的疑似记录可查看核验材料'], 403);
        }

        $path = $type === 'id_card' ? $verification->id_card_path : $verification->face_image_path;
        $disk = Storage::disk('verifications');

        if (!$path || !$disk->exists($path)) {
            return response()->json(['message' => '核验材料不存在或已清理'], 410);
        }

        return response()->file($disk->path($path), [
            'Content-Type' => $this->imageMimeType($path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function imageExtension($file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
    }

    protected function imageMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    protected function authorizeProctor(Request $request): void
    {
        $user = $request->user();
        if (!$user || (!$user->isTeacher() && !$user->isAdmin())) {
            abort(403, '仅监考老师或管理员可执行此操作');
        }
    }

    protected function purgeExpiredQuietly(): void
    {
        try {
            $this->purgeService->purgeExpired();
        } catch (\Throwable $e) {
            Log::warning('核验材料惰性清理失败', ['error' => $e->getMessage()]);
        }
    }

    protected function statusMessage(ExamVerification $verification): string
    {
        return match ($verification->status) {
            ExamVerification::STATUS_PASSED => '身份核验通过，可以进入考试',
            ExamVerification::STATUS_SUSPICIOUS => '核验结果为疑似，请等待监考老师人工确认',
            default => '身份核验未通过，请调整光线和姿态后重新核验',
        };
    }

    /**
     * 学生视角序列化（不含存储路径等内部信息）。
     */
    protected function serialize(ExamVerification $v): array
    {
        return [
            'id' => $v->id,
            'status' => $v->status,
            'status_label' => ExamVerification::STATUSES[$v->status] ?? $v->status,
            'review_status' => $v->review_status,
            'review_note' => $v->review_note,
            'similarity_score' => $v->similarity_score,
            'effective_passed' => $v->isEffective(),
            'purged' => $v->isPurged(),
            'created_at' => optional($v->created_at)->toDateTimeString(),
        ];
    }

    /**
     * 监考视角序列化（仅元数据；材料内容走受控图片接口）。
     */
    protected function serializeForProctor(ExamVerification $v): array
    {
        return $this->serialize($v) + [
            'user' => $v->user ? [
                'id' => $v->user->id,
                'username' => $v->user->username,
                'real_name' => $v->user->real_name,
                'email' => $v->user->email,
            ] : null,
            'exam_paper' => $v->examPaper ? [
                'id' => $v->examPaper->id,
                'title' => $v->examPaper->title,
            ] : null,
            'reviewer' => $v->reviewer ? ['id' => $v->reviewer->id, 'username' => $v->reviewer->username] : null,
            'reviewed_at' => optional($v->reviewed_at)->toDateTimeString(),
            'purge_after' => optional($v->purge_after)->toDateTimeString(),
            'materials_available' => !$v->isPurged() && $v->isPendingReview(),
        ];
    }
}
