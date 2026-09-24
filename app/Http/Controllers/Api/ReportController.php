<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !isset($authUser['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:user,property,fraud,inappropriate_content,other',
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'reported_user_id' => 'nullable|integer',
            'property_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (!empty($data['reported_user_id'])) {
            $exists = DB::table('users')
                ->where('id', $data['reported_user_id'])
                ->exists();

            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reported user not found'
                ], 404);
            }
        }

        if (!empty($data['property_id'])) {
            $exists = DB::table('properties')
                ->where('id', $data['property_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Property not found'
                ], 404);
            }
        }

        $reportId = DB::table('reports')->insertGetId([
            'reporter_id' => (int) $authUser['id'],
            'reported_user_id' => $data['reported_user_id'] ?? null,
            'property_id' => $data['property_id'] ?? null,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => 'pending',
            'admin_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted successfully',
            'report_id' => $reportId,
            'status' => 'pending',
        ], 201);
    }

    public function index(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');

        if (!$authUser || !isset($authUser['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $reports = DB::table('reports')
            ->where('reporter_id', (int) $authUser['id'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }
}