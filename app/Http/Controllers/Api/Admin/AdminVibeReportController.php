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
            ->leftJoin('neighborhood_translations as nt', function ($join) use ($language) {
                $join->on('nt.neighborhood_id', '=', 'n.id')
                    ->where('nt.language_code', '=', $language);
            })
            ->leftJoin('property_locations as pl', 'pl.neighborhood_id', '=', 'n.id')
            ->leftJoin('properties as p', function ($join) {
                $join->on('p.id', '=', 'pl.property_id')
                    ->whereNull('p.deleted_at');
            })
            ->select(
                'n.id',
                'n.community_id',
                DB::raw('COALESCE(nt.name, n.name) as area_name'),
                DB::raw('COUNT(DISTINCT p.id) as total_properties'),
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
                        AND pl.latitude IS NOT NULL
                        AND pl.longitude IS NOT NULL
                        THEN p.id
                    END) as geocoded_properties
                ")
            )
            ->groupBy(
                'n.id',
                'n.community_id',
                'n.name',
                'nt.name'
            )
            ->orderByDesc('approved_properties')
            ->orderBy('area_name')
            ->get();

        $formattedAreas = $areas->map(function ($area) {
            $total = (int) $area->total_properties;
            $approved = (int) $area->approved_properties;
            $pending = (int) $area->pending_properties;
            $rejected = (int) $area->rejected_properties;
            $geocoded = (int) $area->geocoded_properties;

            $coverage = $approved > 0
                ? round(($geocoded / $approved) * 100, 2)
                : 0;

            if ($approved === 0) {
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
                    'total' => $total,
                    'approved' => $approved,
                    'pending' => $pending,
                    'rejected' => $rejected,
                    'geocoded' => $geocoded,
                ],
                'coverage_percentage' => $coverage,
                'status' => $status,
                'pois' => null,
            ];
        });

        $totalAreas = $formattedAreas->count();
        $coveredAreas = $formattedAreas->where('status', 'covered')->count();
        $partialAreas = $formattedAreas->where('status', 'partial')->count();
        $noDataAreas = $formattedAreas->where('status', 'no_data')->count();

        $overallCoverage = $totalAreas > 0
            ? round(($coveredAreas / $totalAreas) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_areas' => $totalAreas,
                    'covered_areas' => $coveredAreas,
                    'partial_areas' => $partialAreas,
                    'no_data_areas' => $noDataAreas,
                    'coverage_percentage' => $overallCoverage,
                ],
                'areas' => $formattedAreas->values(),
            ],
        ]);
    }
}