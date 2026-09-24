<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVibeReportController extends Controller
{
    public function coverage(Request $request)
    {
        $language = $request->query('language', 'en');

        $areas = DB::table('neighborhoods as n')
            ->leftJoin(
                'neighborhood_translations as nt',
                function ($join) use ($language) {
                    $join->on('nt.neighborhood_id', '=', 'n.id')
                        ->where('nt.language_code', '=', $language);
                }
            )
            ->leftJoin(
                'property_locations as pl',
                'pl.neighborhood_id',
                '=',
                'n.id'
            )
            ->leftJoin(
                'properties as p',
                function ($join) {
                    $join->on('p.id', '=', 'pl.property_id')
                        ->whereNull('p.deleted_at');
                }
            )
            ->leftJoin(
                'vibe_reports as vr',
                'vr.property_id',
                '=',
                'p.id'
            )
            ->select(
                'n.id',
                'n.community_id',
                DB::raw(
                    'COALESCE(nt.name, n.name) as area_name'
                ),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'approved'
                        THEN p.id
                    END) as approved_properties
                "),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'pending'
                        THEN p.id
                    END) as pending_properties
                "),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'rejected'
                        THEN p.id
                    END) as rejected_properties
                "),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'approved'
                        AND vr.status = 'generated'
                        THEN p.id
                    END) as generated_reports
                "),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'approved'
                        AND vr.status = 'pending'
                        THEN p.id
                    END) as generating_reports
                "),

                DB::raw("
                    COUNT(DISTINCT CASE
                        WHEN p.moderation_status = 'approved'
                        AND vr.status = 'failed'
                        THEN p.id
                    END) as failed_reports
                "),

                DB::raw("
                    COALESCE(
                        SUM(
                            CASE
                                WHEN p.moderation_status = 'approved'
                                AND vr.status = 'generated'
                                THEN vr.poi_count
                                ELSE 0
                            END
                        ),
                        0
                    ) as poi_count
                "),

                DB::raw("
                    MAX(vr.last_refreshed_at)
                    as last_refreshed_at
                ")
            )
            ->groupBy(
                'n.id',
                'n.community_id',
                'n.name',
                'nt.name'
            )
            ->orderByDesc('generated_reports')
            ->orderBy('area_name')
            ->get();

        $formattedAreas = $areas->map(function ($area) {
            
            $approved = (int) $area->approved_properties;
            $generated = (int) $area->generated_reports;
            $pending = (int) $area->generating_reports;
            $failed = (int) $area->failed_reports;

            $coverage = $approved > 0
                ? round(($generated / $approved) * 100, 2)
                : 0;

            if ($approved === 0 || $generated === 0) {
                $status = 'no_data';
            } elseif ($coverage >= 100) {
                $status = 'covered';
            } else {
                $status = 'partial';
            }

            return [
                'neighborhood_id' => (int) $area->id,
                'community_id' => (int) $area->community_id,
                'area' => $area->area_name,

                'properties' => [
                    'approved' =>
                        (int) $area->approved_properties,
                    'pending' =>
                        (int) $area->pending_properties,
                    'rejected' =>
                        (int) $area->rejected_properties,
                ],

                'vibe_reports' => [
                    'generated' => $generated,
                    'pending' => $pending,
                    'failed' => $failed,
                    'missing' => max(
                        $approved - $generated - $pending - $failed,
                        0
                    ),
                ],

                'pois' => (int) $area->poi_count,

                'coverage_percentage' => $coverage,
                'status' => $status,

                'last_refreshed_at' =>
                    $area->last_refreshed_at,
            ];
        });

        $totalAreas = $formattedAreas->count();

        $coveredAreas = $formattedAreas
            ->where('status', 'covered')
            ->count();

        $partialAreas = $formattedAreas
            ->where('status', 'partial')
            ->count();

        $noDataAreas = $formattedAreas
            ->where('status', 'no_data')
            ->count();

        $totalApprovedProperties =
            $formattedAreas->sum(
                fn ($area) => $area['properties']['approved']
            );

        $totalGeneratedReports =
            $formattedAreas->sum(
                fn ($area) => $area['vibe_reports']['generated']
            );

        $totalPendingReports =
            $formattedAreas->sum(
                fn ($area) => $area['vibe_reports']['pending']
            );

        $totalFailedReports =
            $formattedAreas->sum(
                fn ($area) => $area['vibe_reports']['failed']
            );

        $totalPois =
            $formattedAreas->sum(
                fn ($area) => $area['pois']
            );

        $coveragePercentage =
            $totalApprovedProperties > 0
                ? round(
                    (
                        $totalGeneratedReports
                        / $totalApprovedProperties
                    ) * 100,
                    2
                )
                : 0;

        return response()->json([
            'success' => true,

            'data' => [
                'summary' => [
                    'total_areas' => $totalAreas,
                    'covered_areas' => $coveredAreas,
                    'partial_areas' => $partialAreas,
                    'no_data_areas' => $noDataAreas,

                    'approved_properties' =>
                        $totalApprovedProperties,

                    'generated_reports' =>
                        $totalGeneratedReports,

                    'pending_reports' =>
                        $totalPendingReports,

                    'failed_reports' =>
                        $totalFailedReports,

                    'missing_reports' => max(
                        $totalApprovedProperties
                        - $totalGeneratedReports
                        - $totalPendingReports
                        - $totalFailedReports,
                        0
                    ),

                    'total_pois' => $totalPois,

                    'coverage_percentage' =>
                        $coveragePercentage,
                ],

                'areas' => $formattedAreas->values(),
            ],
        ]);
    }
}