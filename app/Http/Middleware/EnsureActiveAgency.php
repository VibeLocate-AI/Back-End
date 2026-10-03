<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAgency
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->attributes->get('auth_user');

        if (!$user || empty($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $agentId = (int) $user['id'];

        $agency = DB::table('agency_agents as aa')
            ->join(
                'agencies as a',
                'a.id',
                '=',
                'aa.agency_id'
            )
            ->where(
                'aa.user_id',
                $agentId
            )
            ->select([
                'a.id',
                'a.status',
            ])
            ->first();

        if (!$agency) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Agent is not assigned to an agency',
            ], 403);
        }

        if ($agency->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Agency is not active yet',
                'agency_status' =>
                    $agency->status,
            ], 403);
        }

        /*
         * Store agency information so controllers
         * can reuse it without another query.
         */
        $request->attributes->set(
            'agent_agency_id',
            (int) $agency->id
        );

        return $next($request);
    }
}