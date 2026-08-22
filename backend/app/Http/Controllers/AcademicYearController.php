<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\AcademicYearPurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AcademicYear::query()->orderByDesc('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/', 'unique:academic_years,name'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // The very first year has to be the active one, otherwise the school
        // would be left with no year to work in.
        $shouldActivate = $data['is_active'] ?? true;
        $shouldActivate = $shouldActivate || AcademicYear::query()->active()->doesntExist();

        $year = AcademicYear::create([
            'name' => $data['name'],
            'is_active' => false,
        ]);

        if ($shouldActivate) {
            $year->activate();
        }

        return response()->json(['data' => $year->refresh()], 201);
    }

    /**
     * Switch the school over to another year. Exactly one year is ever active,
     * which is what the rest of the app reads to decide the default year.
     */
    public function activate(AcademicYear $academicYear): JsonResponse
    {
        $academicYear->activate();

        return $this->index();
    }

    public function show(AcademicYear $academicYear): JsonResponse
    {
        return response()->json(['data' => $academicYear]);
    }

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/', Rule::unique('academic_years', 'name')->ignore($academicYear)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Activation goes through the model so this route cannot leave a second
        // year active behind the admin's back.
        $activate = array_key_exists('is_active', $data) && $data['is_active'];

        abort_if(
            array_key_exists('is_active', $data) && ! $data['is_active'] && $academicYear->is_active,
            422,
            'لا يمكن إلغاء تفعيل السنة الحالية. فعّل سنة أخرى بدلاً من ذلك.',
        );

        unset($data['is_active']);

        if ($data !== []) {
            $academicYear->update($data);
        }

        if ($activate) {
            $academicYear->activate();
        }

        return response()->json(['data' => $academicYear->refresh()]);
    }

    /**
     * What deleting this year would take with it, so the warning the admin
     * reads carries real numbers rather than a general caution.
     */
    public function impact(AcademicYear $academicYear, AcademicYearPurgeService $purge): JsonResponse
    {
        return response()->json(['data' => $purge->summary($academicYear)]);
    }

    /**
     * Deletes the year and everything recorded under it.
     *
     * Removing the row on its own would leave the year's classes, marks and
     * receipts behind with nothing to belong to, so the two go together.
     */
    public function destroy(Request $request, AcademicYear $academicYear, AcademicYearPurgeService $purge): JsonResponse
    {
        abort_if(
            $academicYear->is_active,
            422,
            'لا يمكن حذف السنة الحالية. فعّل سنة أخرى أولاً ثم احذف هذه.',
        );

        abort_if(
            AcademicYear::count() <= 1,
            422,
            'لا يمكن حذف السنة الوحيدة في المنظومة.',
        );

        $result = $purge->purge($academicYear, $request->user());

        return response()->json([
            'data' => $result,
            'message' => $result['total'] > 0
                ? "حُذفت السنة الدراسية و{$result['total']} سجلاً مرتبطاً بها."
                : 'حُذفت السنة الدراسية، ولم تكن مرتبطة بأي بيانات.',
        ]);
    }
}
