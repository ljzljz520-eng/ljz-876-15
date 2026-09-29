<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\IdentityVerification;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        // 准入闸门：试卷要求核验时，必须存在"机器通过"或"疑似经人工确认通过"的记录
        $verification = null;
        if ($examPaper->identity_check_enabled) {
            $admittedVerification = IdentityVerification::admitted()
                ->where('user_id', $request->user()->id)
                ->where('exam_paper_id', $examPaper->id)
                ->latest('id')
                ->first();

            if (!$admittedVerification) {
                $latest = IdentityVerification::where('user_id', $request->user()->id)
                    ->where('exam_paper_id', $examPaper->id)
                    ->whereNull('purged_at')
                    ->latest('id')
                    ->first();

                $reason = !$latest
                    ? '进入考试前请先完成证件与人脸核验'
                    : match (true) {
                        $latest->status === IdentityVerification::STATUS_SUSPECTED
                            && $latest->review_result === null
                            => '核验结果为疑似，正在等待监考老师人工确认',
                        $latest->review_result === IdentityVerification::REVIEW_REJECTED
                            => '核验未通过监考老师人工确认，无法进入考试',
                        default => '人脸核验未通过，请重新核验',
                    };

                return response()->json([
                    'message' => $reason,
                    'identity_required' => true,
                    'verification' => $latest?->toSafeArray(),
                ], 403);
            }

            $verification = $admittedVerification;
        }

        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        // 核验记录关联本次考试（材料即"本次考试专用"的凭证）
        if ($verification) {
            $verification->update(['exam_record_id' => $record->id]);
        }

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
                'identity_check_enabled' => (bool) $examPaper->identity_check_enabled,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        // 防止绕过开考闸门直接拉题：试卷要求核验时必须存在一条有效放行记录
        if ($examPaper->identity_check_enabled) {
            $admitted = IdentityVerification::admitted()
                ->where('user_id', $request->user()->id)
                ->where('exam_paper_id', $examPaper->id)
                ->exists();

            if (!$admitted) {
                return response()->json([
                    'message' => '请先完成证件与人脸核验',
                    'identity_required' => true,
                ], 403);
            }
        }

        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
                'identity_check_enabled' => (bool) $examPaper->identity_check_enabled,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $totalScore = 0;
        $questionMap = $examPaper->questions->keyBy('id');

        foreach ($request->answers as $answerData) {
            $question = $questionMap->get($answerData['question_id']);
            if (!$question) {
                continue;
            }

            $isCorrect = $this->checkAnswer($question, $answerData['answer']);
            $score = $isCorrect ? $question->pivot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answerData['answer'],
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $totalScore += $score;
        }

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => 'graded',
        ]);

        // 交卷后启动保留期计时：材料保留至"考试结束 + 保留天数"，到期自动清理
        $retentionDays = (int) config('identity.retention_days', 7);
        IdentityVerification::where(function ($q) use ($record) {
            $q->where('exam_record_id', $record->id)
                ->orWhere(function ($q2) use ($record) {
                    $q2->where('user_id', $record->user_id)
                        ->where('exam_paper_id', $record->exam_paper_id);
                });
        })
            ->whereNull('purged_at')
            ->update(['expires_at' => $record->end_time->copy()->addDays($retentionDays)]);

        return response()->json([
            'message' => '提交成功',
            'score' => $totalScore,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper.questions', 'answers.question']);

        return response()->json([
            'record' => $record,
        ]);
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
