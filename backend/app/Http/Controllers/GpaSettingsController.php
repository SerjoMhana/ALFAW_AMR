<?php

namespace App\Http\Controllers;

use App\Models\CreditLegendRow;
use App\Models\GpaScaleBand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GpaSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'scale' => GpaScaleBand::orderBy('display_order')->get(),
                'legend' => CreditLegendRow::orderBy('classes_per_week')->get(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scale' => ['required', 'array', 'min:1'],
            'scale.*.letter' => ['required', 'string', 'max:5'],
            'scale.*.min_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'scale.*.max_score' => ['required', 'numeric', 'min:0', 'max:100', 'gte:scale.*.min_score'],
            'scale.*.points' => ['required', 'numeric', 'min:0', 'max:10'],
            'legend' => ['required', 'array', 'min:1'],
            'legend.*.classes_per_week' => ['required', 'integer', 'min:1', 'max:40', 'distinct'],
            'legend.*.credits' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        DB::transaction(function () use ($validated): void {
            GpaScaleBand::query()->delete();
            foreach (array_values($validated['scale']) as $order => $band) {
                GpaScaleBand::create([
                    'letter' => $band['letter'],
                    'min_score' => $band['min_score'],
                    'max_score' => $band['max_score'],
                    'points' => $band['points'],
                    'display_order' => $order + 1,
                ]);
            }

            CreditLegendRow::query()->delete();
            foreach ($validated['legend'] as $row) {
                CreditLegendRow::create([
                    'classes_per_week' => $row['classes_per_week'],
                    'credits' => $row['credits'],
                ]);
            }
        });

        return $this->index();
    }
}
