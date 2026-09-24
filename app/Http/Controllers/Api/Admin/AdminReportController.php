<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminReportController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('reports as r')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'r.reporter_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'r.reported_user_id')
            ->leftJoin('properties as p', 'p.id', '=', 'r.property_id')
            ->select(
                'r.id',
                'r.type',
                'r.subject',
                'r.description',
                'r.status',
                'r.admin_notes',
                'r.reporter_id',
                DB::raw("CONCAT(reporter.first_name, ' ', reporter.last_name) as reporter_name"),
                'r.reported_user_id',
                DB::raw("CONCAT(reported.first_name, ' ', reported.last_name) as reported_user_name"),
                'r.property_id',
                'p.title as property_title',
                'r.reviewed_by',
                'r.reviewed_at',
                'r.created_at',
                'r.updated_at'
            );

        if ($request->filled('status')) {
            $query->where('r.status', $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('r.type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('r.subject', 'like', "%{$search}%")
                    ->orWhere('r.description', 'like', "%{$search}%")
                    ->orWhere('reporter.first_name', 'like', "%{$search}%")
                    ->orWhere('reporter.last_name', 'like', "%{$search}%")
                    ->orWhere('reported.first_name', 'like', "%{$search}%")
                    ->orWhere('reported.last_name', 'like', "%{$search}%")
                    ->orWhere('p.title', 'like', "%{$search}%");
            });
        }

        $reports = $query
            ->orderByDesc('r.created_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    public function show(int $id)
    {
        $report = DB::table('reports as r')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'r.reporter_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'r.reported_user_id')
            ->leftJoin('properties as p', 'p.id', '=', 'r.property_id')
            ->select(
                'r.*',
                DB::raw("CONCAT(reporter.first_name, ' ', reporter.last_name) as reporter_name"),
                'reporter.email as reporter_email',
                DB::raw("CONCAT(reported.first_name, ' ', reported.last_name) as reported_user_name"),
                'reported.email as reported_user_email',
                'p.title as property_title'
            )
            ->where('r.id', $id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,reviewing,resolved,rejected',
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $report = DB::table('reports')
            ->where('id', $id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found'
            ], 404);
        }

        $authUser = $request->attributes->get('auth_user');

        DB::table('reports')
            ->where('id', $id)
            ->update([
                'status' => $request->status,
                'admin_notes' => $request->admin_notes,
                'reviewed_by' => $authUser['id'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Report status updated successfully',
            'report_id' => $id,
            'status' => $request->status,
        ]);
    }
}