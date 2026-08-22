<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use App\Services\GradingSchemePresets;
use App\Services\GradingStructureImportConfirmService;
use App\Services\GradingStructureImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradingStructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tiers = GradeTier::query()
            ->with([
                'categories' => fn ($query) => $query->orderBy('display_order'),
                'categories.items' => fn ($query) => $query->orderBy('display_order'),
                'courseSections:id,grade_tier_id,class_name,section_code,academic_year',
            ])
            ->orderBy('min_grade')
            ->get();

        return response()->json(['data' => $tiers]);
    }

    public function importPreview(Request $request, GradingStructureImportService $importer): JsonResponse
    {
        abort_unless($request->user()->can('import', GradingCategory::class), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $path = $request->file('file')->getRealPath();

        return response()->json(['data' => $importer->preview($path)]);
    }

    public function importConfirm(Request $request, GradingStructureImportConfirmService $confirmer): JsonResponse
    {
        abort_unless($request->user()->can('import', GradingCategory::class), 403);

        $request->validate([
            'structure' => ['required', 'array', 'min:1'],
            'structure.*.tier_name' => ['required', 'string'],
            'structure.*.min_grade' => ['required', 'integer', 'min:1'],
            'structure.*.max_grade' => ['required', 'integer', 'gte:structure.*.min_grade'],
            'structure.*.categories' => ['required', 'array', 'min:1'],
            'structure.*.categories.*.name' => ['required', 'string'],
            'structure.*.categories.*.weight_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'structure.*.categories.*.items' => ['required', 'array', 'min:1'],
            'structure.*.categories.*.items.*.name' => ['required', 'string'],
            'structure.*.categories.*.items.*.max_score' => ['required', 'numeric', 'min:0'],
            'structure.*.categories.*.items.*.is_total_field' => ['required', 'boolean'],
        ]);

        $tiers = $confirmer->confirm($request->input('structure'));

        return response()->json(['data' => $tiers], 201);
    }

    /**
     * The school's own grading sheet, ready to build in one click. Presets
     * already in place are flagged so the page can say so instead of failing.
     */
    public function presets(Request $request, GradingSchemePresets $presets): JsonResponse
    {
        return response()->json([
            'data' => collect($presets->all())->map(fn (array $preset) => [
                'key' => $preset['key'],
                'name' => $preset['name'],
                'label' => $preset['label'],
                'min_grade' => $preset['min_grade'],
                'max_grade' => $preset['max_grade'],
                'categories' => collect($preset['categories'])->map(fn (array $category) => [
                    'name' => $category['name'],
                    'weight_percentage' => $category['weight_percentage'],
                    'items' => count($category['items']),
                ]),
                'conflict' => GradeTier::overlapping($preset['min_grade'], $preset['max_grade'])?->name,
            ]),
        ]);
    }

    public function applyPreset(Request $request, GradingSchemePresets $presets): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $request->validate(['key' => ['required', 'string']]);

        $preset = $presets->find($request->string('key')->toString());

        abort_if($preset === null, 404, 'المخطط الجاهز غير موجود.');

        $this->guardOverlap($preset['min_grade'], $preset['max_grade']);

        abort_if(
            GradeTier::where('name', $preset['name'])->exists(),
            422,
            "يوجد مخطط بالاسم «{$preset['name']}» بالفعل.",
        );

        return response()->json(['data' => $presets->apply($preset)], 201);
    }

    public function storeTier(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:grade_tiers,name'],
            'min_grade' => ['required', 'integer', 'min:1', 'max:12'],
            'max_grade' => ['required', 'integer', 'min:1', 'max:12', 'gte:min_grade'],
        ]);

        $this->guardOverlap($data['min_grade'], $data['max_grade']);

        // Built switched off: a scheme governs nothing until the admin says so.
        $tier = GradeTier::create($data + ['is_active' => false]);

        return response()->json(['data' => $tier->load('categories.items')], 201);
    }

    public function updateTier(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('grade_tiers', 'name')->ignore($gradeTier)],
            'min_grade' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'max_grade' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $min = $data['min_grade'] ?? $gradeTier->min_grade;
        $max = $data['max_grade'] ?? $gradeTier->max_grade;

        abort_if($max < $min, 422, 'الصف الأخير لا يمكن أن يكون أصغر من الصف الأول.');

        if ($min !== $gradeTier->min_grade || $max !== $gradeTier->max_grade) {
            $this->guardOverlap($min, $max, $gradeTier->id);
        }

        $gradeTier->update($data);

        return response()->json(['data' => $gradeTier->fresh('categories.items')]);
    }

    /**
     * Point a scheme at the classes it governs. A class carries exactly one
     * scheme, so any class picked here is taken off whichever scheme held it.
     */
    public function assignSections(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'course_section_ids' => ['present', 'array'],
            'course_section_ids.*' => ['integer', 'exists:course_sections,id'],
        ]);

        $keep = $data['course_section_ids'];

        DB::transaction(function () use ($gradeTier, $keep): void {
            CourseSection::query()
                ->where('grade_tier_id', $gradeTier->id)
                ->whereIntegerNotInRaw('id', $keep ?: [0])
                ->update(['grade_tier_id' => null]);

            CourseSection::query()
                ->whereIntegerInRaw('id', $keep ?: [0])
                ->update(['grade_tier_id' => $gradeTier->id]);
        });

        return response()->json(['data' => $gradeTier->fresh('courseSections')]);
    }

    public function activateTier(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $total = $gradeTier->categories()->where('is_active', true)->sum('weight_percentage');

        abort_if(
            abs($total - 100) > 0.001,
            422,
            "لا يمكن تفعيل المخطط ومجموع أوزان فئاته {$total}%. يجب أن يكون 100% بالضبط.",
        );

        abort_if(
            $gradeTier->categories()->where('is_active', true)->doesntExist(),
            422,
            'أضف فئات وبنوداً للمخطط قبل تفعيله.',
        );

        $gradeTier->update(['is_active' => true]);

        return response()->json(['data' => $gradeTier->fresh('categories.items')]);
    }

    /**
     * What switching a scheme off would destroy, so the admin sees the real cost
     * before agreeing to it.
     */
    public function deactivationImpact(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        return response()->json([
            'data' => [
                'scheme' => $gradeTier->name,
                'scores' => $gradeTier->scoreQuery()->count(),
                'students' => $gradeTier->scoreQuery()->distinct()->count('student_profile_id'),
                'classes' => $gradeTier->courseSections()
                    ->get()
                    ->map(fn (CourseSection $section) => $section->class_name ?: $section->section_code)
                    ->values(),
            ],
        ]);
    }

    /**
     * Switching a scheme off wipes every mark entered under it — the marks only
     * mean anything against the split that produced them. The caller has to say
     * so explicitly; there is no undo.
     */
    public function deactivateTier(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $request->validate(['confirm' => ['required', 'accepted']]);

        $deleted = DB::transaction(function () use ($gradeTier) {
            $count = $gradeTier->scoreQuery()->count();
            $gradeTier->scoreQuery()->delete();
            $gradeTier->update(['is_active' => false]);

            return $count;
        });

        return response()->json([
            'data' => $gradeTier->fresh('categories.items'),
            'deleted_scores' => $deleted,
        ]);
    }

    public function destroyTier(Request $request, GradeTier $gradeTier): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        abort_if(
            $gradeTier->hasRecordedScores(),
            422,
            'لا يمكن حذف هذا المخطط لأن هناك درجات مُدخلة عليه. عدّله بدل حذفه.',
        );

        $gradeTier->delete();

        return response()->json(status: 204);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'grade_tier_id' => ['required', 'integer', 'exists:grade_tiers,id'],
            'name' => ['required', 'string', 'max:255'],
            'weight_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $existing = GradingCategory::query()
            ->where('grade_tier_id', $data['grade_tier_id'])
            ->where('is_active', true)
            ->sum('weight_percentage');

        abort_if(
            $existing + $data['weight_percentage'] > 100.0001,
            422,
            'مجموع أوزان الفئات في هذا المخطط لا يمكن أن يتجاوز 100%.',
        );

        $category = GradingCategory::create($data + [
            'display_order' => GradingCategory::where('grade_tier_id', $data['grade_tier_id'])->count() + 1,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $category->load('items'),
            'warning' => $this->weightWarning($category->grade_tier_id),
        ], 201);
    }

    public function destroyCategory(Request $request, GradingCategory $gradingCategory): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $hasScores = GradingItem::query()
            ->where('grading_category_id', $gradingCategory->id)
            ->whereHas('scores')
            ->exists();

        // Deleting would take the recorded marks with it, so retire the category
        // instead: it drops out of the weighting but the history survives.
        if ($hasScores) {
            $gradingCategory->update(['is_active' => false]);

            return response()->json([
                'data' => $gradingCategory->fresh('items'),
                'message' => 'الفئة تحتوي على درجات مُدخلة، لذلك تم تعطيلها بدل حذفها.',
            ]);
        }

        $gradingCategory->items()->delete();
        $gradingCategory->delete();

        return response()->json(status: 204);
    }

    private function guardOverlap(int $minGrade, int $maxGrade, ?int $ignoreId = null): void
    {
        $clash = GradeTier::overlapping($minGrade, $maxGrade, $ignoreId);

        abort_if(
            $clash !== null,
            422,
            "نطاق الصفوف يتقاطع مع المخطط «{$clash?->name}» (G{$clash?->min_grade}-G{$clash?->max_grade}). كل صف يتبع مخططاً واحداً فقط.",
        );
    }

    public function updateCategory(Request $request, GradingCategory $gradingCategory): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'weight_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('weight_percentage', $data)) {
            $otherWeights = GradingCategory::query()
                ->where('grade_tier_id', $gradingCategory->grade_tier_id)
                ->where('is_active', true)
                ->where('id', '!=', $gradingCategory->id)
                ->sum('weight_percentage');

            abort_if(
                $otherWeights + $data['weight_percentage'] > 100.0001,
                422,
                'مجموع أوزان الفئات في هذا المخطط لا يمكن أن يتجاوز 100%.',
            );
        }

        $gradingCategory->update($data);

        return response()->json([
            'data' => $gradingCategory->fresh('items'),
            'warning' => $this->weightWarning($gradingCategory->grade_tier_id),
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'grading_category_id' => ['required', 'integer', 'exists:grading_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'max_score' => ['required', 'numeric', 'min:0'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_total_field' => ['sometimes', 'boolean'],
        ]);

        $item = GradingItem::create($data);

        return response()->json(['data' => $item], 201);
    }

    public function updateItem(Request $request, GradingItem $gradingItem): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'max_score' => ['sometimes', 'numeric', 'min:0'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_total_field' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $gradingItem->update($data);

        return response()->json(['data' => $gradingItem]);
    }

    public function destroyItem(Request $request, GradingItem $gradingItem): JsonResponse
    {
        abort_unless($request->user()->can('manage', GradingCategory::class), 403);

        if ($gradingItem->scores()->exists()) {
            $gradingItem->update(['is_active' => false]);

            return response()->json(['data' => $gradingItem->fresh(), 'message' => 'Item has recorded scores; deactivated instead of deleted.']);
        }

        $gradingItem->delete();

        return response()->json(status: 204);
    }

    private function weightWarning(int $gradeTierId): ?string
    {
        $total = GradingCategory::query()
            ->where('grade_tier_id', $gradeTierId)
            ->where('is_active', true)
            ->sum('weight_percentage');

        if (abs($total - 100.0) < 0.001) {
            return null;
        }

        return $total > 100
            ? "مجموع أوزان الفئات {$total}% — أي أكثر من 100%."
            : "مجموع أوزان الفئات {$total}% — ما زال أقل من 100%.";
    }
}
