<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\IdentityReviewLog;
use App\Models\IdentityVerification;
use App\Services\FaceMatch\Contracts\FaceMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class IdentityVerificationController extends Controller
{
    public function __construct(private readonly FaceMatcher $matcher)
    {
    }

    /**
     * 查询当前考生针对某场考试的核验状态（进入考试前展示）。
     */
    public function show(Request $request, ExamPaper $examPaper)
    {
        $verification = IdentityVerification::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->latest('id')
            ->first();

        $hasAdmitted = IdentityVerification::admitted()
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->exists();

        $data = $verification?->toSafeArray();
        if ($data && $hasAdmitted) {
            $data['is_admitted'] = true;
        }

        $attempts = IdentityVerification::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->count();

        return response()->json([
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'identity_check_enabled' => (bool) $examPaper->identity_check_enabled,
            ],
            'verification' => $data,
            'attempts' => $attempts,
            'max_attempts' => (int) config('identity.max_attempts'),
            'retention_notice' => '核验材料仅用于本次考试，考试结束后 '.config('identity.retention_days').' 天自动删除。',
        ]);
    }

    /**
     * 学生上传证件照 + 摄像头照片，系统自动比对并给出三态结果。
     */
    public function store(Request $request, ExamPaper $examPaper)
    {
        if ($examPaper->status != 1) {
            return response()->json(['message' => '该考试未开放'], 404);
        }

        $attempts = IdentityVerification::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->count();

        if ($attempts >= (int) config('identity.max_attempts')) {
            return response()->json([
                'message' => '核验提交次数已达上限（'.config('identity.max_attempts').' 次），请联系监考老师处理。',
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'id_card_photo' => 'required|file|mimes:'.implode(',', config('identity.allowed_mimes'))
                .'|max:'.config('identity.max_size_kb'),
            'live_photo' => 'required|file|mimes:'.implode(',', config('identity.allowed_mimes'))
                .'|max:'.config('identity.max_size_kb'),
            'id_card_name' => 'required|string|max:50',
            'id_card_no' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z]{6,30}$/'],
        ], [
            'id_card_photo.required' => '请上传证件照片',
            'live_photo.required' => '请拍摄摄像头人脸照片',
            'id_card_no.regex' => '证件号码格式不正确',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 已有进行中的考试记录，或核验已放行，则不允许重复提交
        $inProgressRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', ExamRecord::STATUS_IN_PROGRESS)
            ->exists();

        if ($inProgressRecord) {
            return response()->json(['message' => '您已在考试中，无需再次核验'], 409);
        }

        $latest = IdentityVerification::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->orderByDesc('attempt_number')
            ->first();

        $admitted = IdentityVerification::admitted()
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->exists();

        if ($admitted) {
            $latestAdmitted = IdentityVerification::admitted()
                ->where('user_id', $request->user()->id)
                ->where('exam_paper_id', $examPaper->id)
                ->latest('id')
                ->first();

            return response()->json([
                'message' => '核验已通过，可直接进入考试',
                'verification' => $latestAdmitted->toSafeArray(),
            ], 409);
        }

        // 疑似且尚未处理：先不要让学生覆盖材料，等监考老师结论
        if ($latest && $latest->status === IdentityVerification::STATUS_SUSPECTED
            && $latest->review_result === null) {
            return response()->json([
                'message' => '上一次核验结果为"疑似"，正在等待监考老师人工确认，请勿重复提交。',
                'verification' => $latest->toSafeArray(),
            ], 409);
        }

        $disk = Storage::disk('local');
        $dir = 'identity-verifications/'.$examPaper->id.'/'.$request->user()->id;
        $idCardPath = null;
        $livePath = null;

        try {
            $verification = DB::transaction(function () use ($request, $examPaper, $disk, $dir, $attempts, &$idCardPath, &$livePath) {
                $idCardPath = $request->file('id_card_photo')->store($dir, 'local');
                $livePath = $request->file('live_photo')->store($dir, 'local');

                if (!$idCardPath || !$livePath) {
                    throw new \RuntimeException('核验材料保存失败');
                }

                $result = $this->matcher->compare($request->file('live_photo'), $request->file('id_card_photo'));

                $status = $result['passed']
                    ? IdentityVerification::STATUS_PASSED
                    : ($result['suspected']
                        ? IdentityVerification::STATUS_SUSPECTED
                        : IdentityVerification::STATUS_FAILED);

                $verification = IdentityVerification::create([
                    'user_id' => $request->user()->id,
                    'exam_paper_id' => $examPaper->id,
                    'id_card_no' => $request->input('id_card_no'),
                    'id_card_name' => $request->input('id_card_name'),
                    'id_card_path' => $idCardPath,
                    'live_photo_path' => $livePath,
                    'match_score' => $result['score'],
                    'match_detail' => $result['raw'],
                    'status' => $status,
                    'attempt_number' => $attempts + 1,
                    'expires_at' => now()->addDays((int) config('identity.retention_pending_days')),
                ]);

                IdentityReviewLog::create([
                    'identity_verification_id' => $verification->id,
                    'operator_id' => $request->user()->id,
                    'action' => IdentityReviewLog::ACTION_SUBMIT,
                    'detail' => 'machine_status='.$status.';score='.$result['score'],
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ]);

                return $verification;
            });
        } catch (\Throwable $e) {
            // 落库失败时尽量清理已写入的文件，避免残留
            foreach ([$idCardPath ?? null, $livePath ?? null] as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
            throw $e;
        }

        return response()->json([
            'message' => match ($verification->status) {
                IdentityVerification::STATUS_PASSED => '核验通过，可以进入考试',
                IdentityVerification::STATUS_SUSPECTED => '核验结果为疑似，已提交监考老师人工确认',
                default => '核验未通过，请确认是本人后重新上传',
            },
            'verification' => $verification->toSafeArray(),
        ], 201);
    }
}
