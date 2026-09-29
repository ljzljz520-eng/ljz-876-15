<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdentityReviewLog;
use Illuminate\Http\Request;

class IdentityAuditController extends Controller
{
    /**
     * 核验审计日志：谁、在什么时候、查看/处理了哪份核验材料。
     * 仅管理员可查，用于合规追溯，日志本身不包含任何图片与证件号。
     */
    public function index(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = IdentityReviewLog::with([
            'verification:id,user_id,exam_paper_id,status,review_result,purged_at',
            'operator:id,username,real_name,role',
        ]);

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($operatorId = $request->input('operator_id')) {
            $query->where('operator_id', $operatorId);
        }

        if ($verificationId = $request->input('verification_id')) {
            $query->where('identity_verification_id', $verificationId);
        }

        $logs = $query->orderByDesc('id')
            ->paginate($perPage = $request->input('per_page', 30));

        return response()->json(['logs' => $logs]);
    }
}
