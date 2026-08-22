<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\DiscountRule;
use App\Models\FeeCategory;
use App\Models\FeeTemplate;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Everything the admin defines and changes: the kinds of charge, the charges
 * themselves, and the discount rules.
 */
class FeeSetupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $academicYear = $request->query('academic_year');

        return response()->json([
            'data' => [
                'methods' => PaymentMethod::orderBy('display_order')->orderBy('name')->get(),
                'categories' => FeeCategory::orderBy('name')->get(),
                'templates' => FeeTemplate::query()
                    ->with('category')
                    ->when($academicYear, fn ($query) => $query->where('academic_year', $academicYear))
                    ->orderBy('academic_year')
                    ->orderBy('name')
                    ->get(),
                'rules' => DiscountRule::orderBy('name')->get(),
            ],
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:fee_categories,name'],
        ]);

        return response()->json([
            'data' => FeeCategory::create(['name' => $validated['name'], 'is_active' => true]),
        ], 201);
    }

    public function updateCategory(Request $request, FeeCategory $feeCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('fee_categories', 'name')->ignore($feeCategory)],
            'is_active' => ['boolean'],
        ]);

        $feeCategory->update($validated);

        return response()->json(['data' => $feeCategory->fresh()]);
    }

    public function destroyCategory(FeeCategory $feeCategory): JsonResponse
    {
        abort_if(
            $feeCategory->templates()->exists(),
            422,
            'لا يمكن حذف فئة مستخدمة في قوالب رسوم. احذف القوالب أولاً أو عطّل الفئة.',
        );

        $feeCategory->delete();

        return response()->json(status: 204);
    }

    public function storeMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:payment_methods,name'],
        ]);

        return response()->json([
            'data' => PaymentMethod::create([
                'name' => $validated['name'],
                'is_active' => true,
                'display_order' => (int) PaymentMethod::max('display_order') + 1,
            ]),
        ], 201);
    }

    public function updateMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('payment_methods', 'name')->ignore($paymentMethod)],
            'is_active' => ['boolean'],
        ]);

        $paymentMethod->update($validated);

        return response()->json(['data' => $paymentMethod->fresh()]);
    }

    public function destroyMethod(PaymentMethod $paymentMethod): JsonResponse
    {
        // Receipts store the method name, so removing it never rewrites history.
        $paymentMethod->delete();

        return response()->json(status: 204);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        return response()->json([
            'data' => FeeTemplate::create($this->templateRules($request))->load('category'),
        ], 201);
    }

    public function updateTemplate(Request $request, FeeTemplate $feeTemplate): JsonResponse
    {
        $feeTemplate->update($this->templateRules($request));

        return response()->json(['data' => $feeTemplate->fresh('category')]);
    }

    public function destroyTemplate(FeeTemplate $feeTemplate): JsonResponse
    {
        // Charges already raised keep their own snapshot, so removing the
        // template never disturbs a student's balance.
        $feeTemplate->delete();

        return response()->json(status: 204);
    }

    public function storeRule(Request $request): JsonResponse
    {
        return response()->json([
            'data' => DiscountRule::create($this->ruleRules($request)),
        ], 201);
    }

    public function updateRule(Request $request, DiscountRule $discountRule): JsonResponse
    {
        $discountRule->update($this->ruleRules($request));

        return response()->json(['data' => $discountRule->fresh()]);
    }

    public function destroyRule(DiscountRule $discountRule): JsonResponse
    {
        $discountRule->delete();

        return response()->json(status: 204);
    }

    private function templateRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fee_category_id' => ['required', 'integer', 'exists:fee_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'academic_year' => ['required', 'string', 'max:20'],
            // Several grades can share one price; an empty list means all grades.
            'grade_levels' => ['nullable', 'array'],
            'grade_levels.*' => ['string', 'max:20'],
            'due_date' => ['nullable', 'date'],
            'is_mandatory' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);
    }

    private function ruleRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DiscountRule::TYPES)],
            // Category names, matched against the snapshot stored on a charge.
            'applies_to_categories' => ['nullable', 'array'],
            'applies_to_categories.*' => ['string', 'max:100'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'sibling_tiers' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);
    }
}
