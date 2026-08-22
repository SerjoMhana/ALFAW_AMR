<?php

namespace App\Http\Controllers;

use App\Models\TermWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TermWindowController extends Controller
{
    /**
     * All four quarters for a year, whether or not a row exists yet.
     */
    public function index(Request $request): JsonResponse
    {
        $academicYear = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
        ])['academic_year'];

        $windows = TermWindow::query()
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->where('academic_year', $academicYear)
            ->get()
            ->keyBy('term');

        return response()->json([
            'data' => [
                'academic_year' => $academicYear,
                'terms' => collect(TermWindow::TERMS)->map(function (string $term) use ($windows, $academicYear) {
                    $window = $windows->get($term);

                    return [
                        'term' => $term,
                        'academic_year' => $academicYear,
                        'is_open' => (bool) $window?->is_open,
                        'opened_at' => $window?->opened_at?->toDateTimeString(),
                        'opened_by' => $window?->openedBy?->name,
                        'closed_at' => $window?->closed_at?->toDateTimeString(),
                        'closed_by' => $window?->closedBy?->name,
                    ];
                })->values(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'term' => ['required', Rule::in(TermWindow::TERMS)],
            'is_open' => ['required', 'boolean'],
        ]);

        $isOpen = $validated['is_open'];

        $window = TermWindow::updateOrCreate(
            [
                'academic_year' => $validated['academic_year'],
                'term' => $validated['term'],
            ],
            $isOpen
                ? ['is_open' => true, 'opened_at' => now(), 'opened_by' => $request->user()->id]
                : ['is_open' => false, 'closed_at' => now(), 'closed_by' => $request->user()->id],
        );

        return response()->json([
            'data' => $window->fresh()->load(['openedBy:id,name', 'closedBy:id,name']),
        ]);
    }
}
