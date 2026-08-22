<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\ParentGuardian;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('permissions')
            ->when($request->filled('user_type'), fn ($query) => $query->where('user_type', $request->string('user_type')))
            ->latest()
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    public function directory(): JsonResponse
    {
        $teachers = User::query()
            ->where('user_type', 'teacher')
            ->withCount('courses')
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'email', 'phone', 'is_active']);

        $students = StudentProfile::query()
            ->with(['user:id,name,username,email,is_active', 'parents.user:id,username,is_active'])
            ->active()
            ->get();

        $sections = CourseSection::query()
            ->orderByDesc('academic_year')
            ->orderBy('class_name')
            ->get();

        $bySection = $sections
            ->map(fn (CourseSection $section) => $this->directorySection(
                $section,
                $students->where('section_id', $section->id)->values(),
            ))
            ->filter(fn (array $section) => count($section['students']) > 0)
            ->values();

        $unassigned = $students->whereNull('section_id')->values();
        if ($unassigned->isNotEmpty()) {
            $bySection->push($this->directorySection(null, $unassigned));
        }

        return response()->json([
            'data' => [
                'teachers' => $teachers,
                'sections' => $bySection->values(),
            ],
        ]);
    }

    private function directorySection(?CourseSection $section, $students): array
    {
        $parents = $students
            ->flatMap(fn (StudentProfile $student) => $student->parents->map(fn (ParentGuardian $parent) => [
                'id' => $parent->id,
                'user_id' => $parent->user_id,
                'full_name' => $parent->full_name,
                'username' => $parent->user?->username ?? $parent->parent_admission_no,
                'relation' => $parent->relation,
                'mobile' => $parent->mobile,
                'has_account' => (bool) $parent->user_id,
                'children' => [$student->full_name ?: $student->user?->name],
            ]))
            ->groupBy('id')
            ->map(function ($rows) {
                $first = $rows->first();
                $first['children'] = $rows->pluck('children')->flatten()->filter()->unique()->values()->all();

                return $first;
            })
            ->values();

        return [
            'id' => $section?->id,
            'class_name' => $section?->class_name ?? 'بدون فصل',
            'section_code' => $section?->section_code,
            'academic_year' => $section?->academic_year,
            'students' => $students->map(fn (StudentProfile $student) => [
                'profile_id' => $student->id,
                'user_id' => $student->user_id,
                'name' => $student->full_name ?: $student->user?->name,
                'username' => $student->user?->username ?? $student->admission_no,
                'email' => $student->user?->email,
                'admission_no' => $student->admission_no,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'mobile' => $student->mobile,
                'nationality' => $student->nationality,
                'academic_year' => $student->academic_year,
                'parent_name' => $student->parent_full_name ?: $student->guardian_name,
                'parent_mobile' => $student->parent_mobile_phone ?: $student->guardian_phone,
            ])->values()->all(),
            'parents' => $parents->all(),
        ];
    }

    public function updateParentCredentials(Request $request, ParentGuardian $parent): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($parent->user_id)],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $username = $validated['username'] ?? $parent->user?->username ?? $parent->parent_admission_no;

        $user = $parent->user;
        if ($user) {
            $user->update([
                'username' => $username,
                'password' => Hash::make($validated['password']),
            ]);
        } else {
            $user = User::create([
                'name' => $parent->full_name ?: $username,
                'username' => $username,
                'email' => strtolower($username).'@parents.school.test',
                'password' => Hash::make($validated['password']),
                'user_type' => 'parent',
                'is_active' => true,
            ]);
            $parent->update(['user_id' => $user->id]);
        }

        return response()->json([
            'data' => $user,
        ]);
    }

    public function teachers(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('user_type', 'teacher')
                ->with('courses.classSection:id,class_name,section_code,academic_year')
                ->latest()
                ->get(),
        ]);
    }

    public function storeTeacher(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $courseIds = collect($validated['course_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
        unset($validated['course_ids']);

        $validated['user_type'] = 'teacher';
        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        $teacher = DB::transaction(function () use ($validated, $courseIds) {
            $teacher = User::create($validated);

            if ($courseIds->isNotEmpty()) {
                Course::whereIn('id', $courseIds)->update(['teacher_id' => $teacher->id]);
            }

            return $teacher;
        });

        return response()->json([
            'data' => $teacher->load(['permissions', 'courses.classSection']),
        ], 201);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['course_ids']);
        $validated['password'] = Hash::make($validated['password']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        return response()->json([
            'data' => User::create($validated)->load('permissions'),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user->load('permissions'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('password', $validated) && $validated['password']) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'data' => $user->load('permissions'),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if(
            $user->id === $request->user()->id,
            422,
            'لا يمكنك حذف حسابك الخاص.',
        );

        // A class may stand without a teacher, so removing one leaves their
        // classes unassigned rather than deleting them. The marks are untouched:
        // they hang off the class and the subject, not off whoever taught it.
        $orphaned = CourseSection::where('teacher_id', $user->id)->count();

        $user->delete();

        if ($orphaned > 0) {
            return response()->json([
                'message' => "تم حذف المعلم. {$orphaned} من الفصول أصبحت بلا معلم — أسندها لمعلم آخر من صفحة الفصول.",
                'unassigned_classes' => $orphaned,
            ]);
        }

        return response()->json(status: 204);
    }
}
