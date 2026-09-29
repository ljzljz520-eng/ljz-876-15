<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdentityReviewLog;
use App\Models\IdentityVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class ProctorController extends Controller
{
    private function ensureProctor(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return false;
        }

        return true;
    }

    /**
     * 待人工确认的疑似队列（只展示待处理的，避免后台成为长期浏览界面）。
     */
    public function index(Request $request)
    {
        if (!$this->ensureProctor($request)) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = IdentityVerification::with(['user:id,username,real_name', 'examPaper:id,title'])
            ->whereNull('purged_at');

        $status = $request->input('status', 'pending');

        if ($status === 'pending') {
            // 待处理：疑似且未复核
            $query->where('status', IdentityVerification::STATUS_SUSPECTED)
                ->whereNull('review_result');
        } elseif (in_array($status, [
            IdentityVerification::STATUS_PASSED,
            IdentityVerification::STATUS_SUSPECTED,
            IdentityVerification::STATUS_FAILED,
        ], true)) {
            // 非待处理队列仅展示近 30 天记录，材料定位是"本次考试专用"，不提供长期浏览
            $query->where('status', $status)
                ->where('created_at', '>=', now()->subDays(30));
        }

        if ($examPaperId = $request->input('exam_paper_id')) {
            $query->where('exam_paper_id', $examPaperId);
        }

        $verifications = $query->orderByDesc('id')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'verifications' => $verifications->setCollection(
                $verifications->getCollection()->map(fn ($v) => $this->toListItem($v))
            ),
        ]);
    }

    /**
     * 单条核验详情（含脱敏证件号，不含明文）。
     */
    public function show(Request $request, IdentityVerification $verification)
    {
        if (!$this->ensureProctor($request)) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $verification->load(['user:id,username,real_name,email', 'examPaper:id,title', 'reviewer:id,username,real_name']);

        return response()->json([
            'verification' => array_merge($verification->toSafeArray(), [
                'user' => $verification->user?->only(['id', 'username', 'real_name', 'email']),
                'exam_paper' => $verification->examPaper?->only(['id', 'title']),
                'reviewer' => $verification->reviewer?->only(['id', 'username', 'real_name']),
            ]),
            'media' => [
                // 前端通过带鉴权的专用接口加载图片，不暴露存储路径
                'id_card_url' => route('proctor.media', ['verification' => $verification->id, 'type' => 'id_card']),
                'live_photo_url' => route('proctor.media', ['verification' => $verification->id, 'type' => 'live']),
                'available' => $verification->purged_at === null,
            ],
        ]);
    }

    /**
     * 监考老师对疑似记录做人工确认：通过 / 拒绝。
     */
    public function review(Request $request, IdentityVerification $verification)
    {
        if (!$this->ensureProctor($request)) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'result' => 'required|in:'.IdentityVerification::REVIEW_APPROVED.','.IdentityVerification::REVIEW_REJECTED,
            'remark' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($verification->purged_at) {
            return response()->json(['message' => '核验材料已过保留期并被清理，无法操作'], 410);
        }

        if ($verification->status !== IdentityVerification::STATUS_SUSPECTED) {
            return response()->json(['message' => '仅疑似状态的核验需要人工确认'], 422);
        }

        if ($verification->review_result !== null) {
            return response()->json(['message' => '该记录已由其他监考老师处理'], 409);
        }

        $verification->update([
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_result' => $request->input('result'),
            'review_remark' => $request->input('remark'),
        ]);

        IdentityReviewLog::create([
            'identity_verification_id' => $verification->id,
            'operator_id' => $request->user()->id,
            'action' => $request->input('result') === IdentityVerification::REVIEW_APPROVED
                ? IdentityReviewLog::ACTION_APPROVE
                : IdentityReviewLog::ACTION_REJECT,
            'detail' => (string) $request->input('remark', ''),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json([
            'message' => $request->input('result') === IdentityVerification::REVIEW_APPROVED
                ? '已确认通过，学生可以进入考试'
                : '已确认拒绝，学生将无法进入考试',
            'verification' => $verification->toSafeArray(),
        ]);
    }

    /**
     * 受保护的核验图片查看接口：
     * - 仅监考/管理员可访问
     * - 材料过保留期后返回 410
     * - 每次查看都写审计日志，杜绝"在后台长期随意浏览"
     */
    public function media(Request $request, IdentityVerification $verification, string $type): Response
    {
        if (!$this->ensureProctor($request)) {
            return response()->json(['message' => '无权访问'], 403);
        }

        if (!in_array($type, ['id_card', 'live'], true)) {
            return response()->json(['message' => '资源不存在'], 404);
        }

        if ($verification->purged_at) {
            return response()->json(['message' => '核验材料已过保留期并被清理'], 410);
        }

        $path = $type === 'id_card' ? $verification->id_card_path : $verification->live_photo_path;
        $disk = Storage::disk('local');

        if (!$path || !$disk->exists($path)) {
            return response()->json(['message' => '核验材料不存在或已被清理'], 404);
        }

        IdentityReviewLog::create([
            'identity_verification_id' => $verification->id,
            'operator_id' => $request->user()->id,
            'action' => $type === 'id_card'
                ? IdentityReviewLog::ACTION_VIEW_ID_CARD
                : IdentityReviewLog::ACTION_VIEW_LIVE_PHOTO,
            'detail' => $path,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        // no-store：浏览器与中间代理不得缓存证件/人脸图片
        return $disk->response($path, null, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$type.'.'.pathinfo($path, PATHINFO_EXTENSION).'"',
        ]);
    }

    private function toListItem(IdentityVerification $v): array
    {
        return array_merge($v->toSafeArray(), [
            'student_name' => $v->user?->real_name ?: $v->user?->username,
            'exam_title' => $v->examPaper?->title,
        ]);
    }
}
