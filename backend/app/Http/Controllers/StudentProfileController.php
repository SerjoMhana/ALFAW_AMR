<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStudentProfileRequest;
use App\Http\Requests\ImportStudentsRequest;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Models\AcademicYear;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\ParentGuardian;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\SimpleXlsxReader;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class StudentProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StudentProfile::with(['user', 'section.course', 'parents', 'enrollments.courseSection'])->latest();
        $archived = $request->query('archived', $request->route('archived', 'active'));

        if ($archived === 'only') {
            $query->where(fn ($inner) => $inner->where('status', 'archived')->orWhereNotNull('archived_at'));
        } elseif ($archived !== 'with') {
            $query->active();
        }

        // The school works one year at a time: the active year decides who is
        // listed and which grade they are shown in. The archive is deliberately
        // left out so a graduate stays reachable whatever year is active.
        $viewedYear = $request->filled('academic_year')
            ? $request->query('academic_year')
            : AcademicYear::currentName();

        if ($viewedYear && $archived !== 'only') {
            $query->inAcademicYear($viewedYear);
        }

        $query->when($request->filled('search'), function ($query) use ($request): void {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('student_number', 'like', $search)
                    ->orWhere('student_code', 'like', $search)
                    ->orWhere('admission_no', 'like', $search)
                    ->orWhere('national_id', 'like', $search)
                    ->orWhere('grade_level', 'like', $search)
                    ->orWhere('current_grade_level', 'like', $search)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', $search))
                    ->orWhereHas('section', fn ($sectionQuery) => $sectionQuery->where('section_code', 'like', $search));
            });
        });

        $query->when($request->filled('grade'), function ($query) use ($request): void {
            $grade = $request->query('grade');
            $query->where(fn ($inner) => $inner->where('grade_level', $grade)->orWhere('current_grade_level', $grade));
        });
        $query->when($request->filled('section_id'), fn ($query) => $query->where('section_id', $request->query('section_id')));
        $query->when($request->filled('gender'), fn ($query) => $query->where('gender', $request->query('gender')));
        $query->when($request->filled('nationality'), fn ($query) => $query->where('nationality', 'like', '%'.$request->query('nationality').'%'));
        $query->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')));

        if ($request->filled('per_page')) {
            $page = $query->paginate((int) $request->query('per_page', 15))
                ->through(fn (StudentProfile $student) => $this->asOfYear($student, $viewedYear));

            return response()->json($page->toArray() + ['academic_year' => $viewedYear]);
        }

        return response()->json([
            'data' => $query->get()->map(fn (StudentProfile $student) => $this->asOfYear($student, $viewedYear)),
            'academic_year' => $viewedYear,
        ]);
    }

    /**
     * Presents a student as they were in the year being viewed: a student now in
     * G2 reads as G1 while last year is active, and reads as G2 again the moment
     * the school switches back. Nothing is written — only the payload changes.
     */
    private function asOfYear(StudentProfile $student, ?string $academicYear): StudentProfile
    {
        if ($academicYear !== null) {
            $section = $student->sectionForYear($academicYear);

            if ($section) {
                $label = $section->class_name ?: $section->section_code;
                $student->grade_level = $label;
                $student->course = $label;
                $student->academic_year = $academicYear;
                $student->setRelation('section', $section);
            }
        }

        return $student->unsetRelation('enrollments');
    }

    public function store(StoreStudentProfileRequest $request): JsonResponse
    {
        $studentProfile = DB::transaction(function () use ($request) {
            $fullName = trim(implode(' ', array_filter([
                $request->input('first_name'),
                $request->input('middle_name'),
                $request->input('last_name'),
            ]))) ?: $request->input('user_name');
            $admissionNo = $request->input('admission_no') ?: $this->nextStudentNumber();
            $studentNumber = $request->input('student_number') ?: $admissionNo;
            $email = $request->input('user_email') ?: $this->generatedStudentEmail($admissionNo ?: $studentNumber);

            $userId = $request->integer('user_id') ?: User::create([
                'name' => $fullName,
                'username' => $admissionNo,
                'email' => $email,
                'password' => Hash::make($admissionNo.'123'),
                'user_type' => 'student',
                'is_active' => true,
            ])->id;

            $profileData = $request->safe()->only($this->profileFields());
            $profileData['full_name'] = $fullName;
            $profileData['student_number'] = $profileData['student_number'] ?? $studentNumber;
            $profileData['student_code'] = $profileData['student_code'] ?? $admissionNo;
            $profileData['admission_no'] = $profileData['admission_no'] ?? $admissionNo;
            $profileData['admission_date'] ??= now()->toDateString();
            $profileData['batch'] ??= trim(($request->input('course') ?: '').' '.($request->input('academic_year') ?: '')) ?: null;
            $profileData['grade_level'] ??= $this->normalizeGradeLevel($request->input('batch') ?: $request->input('course'));
            $profileData['current_grade_level'] ??= $this->gradeNumber($profileData['grade_level']);
            $profileData['grade_tier'] ??= $this->gradeTier($profileData['grade_level']);

            $studentProfile = StudentProfile::create([
                ...$profileData,
                'status' => 'active',
                'user_id' => $userId,
            ]);

            $parent = $this->parentForStudentRequest($request, $studentProfile);
            $studentProfile->parents()->syncWithoutDetaching([
                $parent->id => [
                    'relation' => $parent->relation,
                    'is_primary' => true,
                ],
            ]);

            collect($request->input('sibling_ids', []))
                ->filter()
                ->unique()
                ->each(fn ($siblingId) => $this->linkSiblings($studentProfile->id, (int) $siblingId));

            $this->syncEnrollment($studentProfile);

            return $studentProfile;
        });

        return response()->json([
            'data' => $studentProfile->load(['user', 'parents']),
        ], 201);
    }

    public function searchSiblings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'exclude_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.$search.'%';
        $students = StudentProfile::query()
            ->with('parents')
            ->active()
            ->when(isset($data['exclude_id']), fn ($query) => $query->whereKeyNot($data['exclude_id']))
            ->where(function ($query) use ($like): void {
                $query->where('full_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('admission_no', 'like', $like);
            })
            ->limit(10)
            ->get()
            ->map(function (StudentProfile $student): array {
                $parent = $student->parents->first();

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'admission_no' => $student->admission_no,
                    'course' => $student->course,
                    'batch' => $student->batch,
                    'parent_full_name' => $parent?->full_name,
                    'parent' => $parent ? [
                        'id' => $parent->id,
                        'first_name' => $parent->first_name,
                        'last_name' => $parent->last_name,
                        'relation' => $parent->relation,
                        'mobile' => $parent->mobile,
                    ] : null,
                ];
            });

        return response()->json(['data' => $students]);
    }

    public function storeSibling(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $data = $request->validate([
            'sibling_student_id' => ['required', 'integer', 'exists:student_profiles,id'],
        ]);
        $sibling = StudentProfile::query()->active()->findOrFail($data['sibling_student_id']);

        if ($studentProfile->is_archived) {
            throw ValidationException::withMessages(['student_id' => 'Student must be active.']);
        }

        $this->linkSiblings($studentProfile->id, $sibling->id);

        return response()->json([
            'message' => 'Sibling linked successfully.',
        ], 201);
    }

    public function show(StudentProfile $studentProfile): JsonResponse
    {
        return response()->json([
            'data' => $studentProfile->load(['user', 'parents', 'enrollments.courseSection.courses']),
        ]);
    }

    public function update(UpdateStudentProfileRequest $request, StudentProfile $studentProfile): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($studentProfile, $validated): void {
            $userData = [];
            if (array_key_exists('user_name', $validated)) {
                $userData['name'] = $validated['user_name'];
            }
            if (array_key_exists('user_email', $validated)) {
                request()->validate([
                    'user_email' => [
                        'email',
                        Rule::unique('users', 'email')->ignore($studentProfile->user_id),
                    ],
                ]);
                $userData['email'] = $validated['user_email'];
            }

            if ($userData !== []) {
                $studentProfile->user()->update($userData);
            }

            $studentProfile->update(collect($validated)->except(['user_name', 'user_email'])->all());

            $this->syncEnrollment($studentProfile->refresh());
        });

        return response()->json([
            'data' => $studentProfile->load('user'),
        ]);
    }

    public function destroy(StudentProfile $studentProfile): JsonResponse
    {
        DB::transaction(function () use ($studentProfile): void {
            $user = $studentProfile->user;
            $studentProfile->delete();
            $user?->delete();
        });

        return response()->json(status: 204);
    }

    public function archive(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $data = $request->validate([
            'archive_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $studentProfile->update([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_by' => $request->user()->id,
            'archive_reason' => $data['archive_reason'] ?? null,
            'previous_section_id' => $studentProfile->section_id
                ?: $studentProfile->enrollments()->latest()->value('course_section_id'),
        ]);
        $studentProfile->user()->update(['is_active' => false]);

        return response()->json([
            'data' => $studentProfile->load('user'),
        ]);
    }

    public function restore(StudentProfile $studentProfile): JsonResponse
    {
        $studentProfile->update([
            'status' => 'active',
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
            'section_id' => $studentProfile->previous_section_id ?: $studentProfile->section_id,
            'restored_at' => now(),
            'restored_by' => request()->user()->id,
        ]);
        $studentProfile->user()->update(['is_active' => true]);

        return response()->json([
            'data' => $studentProfile->load('user'),
        ]);
    }

    public function import(ImportStudentsRequest $request, SimpleXlsxReader $reader): JsonResponse
    {
        return $this->confirmImport($request, $reader);
    }

    public function previewImport(ImportStudentsRequest $request, SimpleXlsxReader $reader): JsonResponse
    {
        $records = $this->recordsFromImport($request, $reader);
        $existingByNumber = StudentProfile::query()
            ->whereIn('student_number', collect($records)->pluck('student_number')->filter()->all())
            ->orWhereIn('admission_no', collect($records)->pluck('admission_no')->filter()->all())
            ->orWhereIn('student_code', collect($records)->pluck('student_code')->filter()->all())
            ->get()
            ->keyBy('student_number');
        $existingNationalIds = StudentProfile::query()
            ->whereIn('national_id', collect($records)->pluck('national_id')->filter()->all())
            ->get()
            ->keyBy('national_id');

        $duplicates = [];
        $errors = [];
        $preview = [];

        foreach ($records as $index => $record) {
            $rowNumber = $index + 2;
            $rowErrors = [];

            if (! $record['student_number']) {
                $rowErrors[] = 'Admission No. is required.';
            }
            if (! $record['user_name']) {
                $rowErrors[] = 'Full Name is required.';
            }
            $existingByNationalId = $record['national_id'] ? $existingNationalIds->get($record['national_id']) : null;
            if ($existingByNationalId && $existingByNationalId->student_number !== $record['student_number']) {
                $rowErrors[] = 'National ID already exists.';
            }
            if ($existingByNumber->has($record['student_number'])) {
                $duplicates[] = $record;
            }
            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'messages' => $rowErrors];
            }

            if (count($preview) < 20) {
                $preview[] = $record;
            }
        }

        return response()->json([
            'total' => count($records),
            'new_count' => max(0, count($records) - count($duplicates) - count($errors)),
            'duplicate_count' => count($duplicates),
            'error_count' => count($errors),
            'preview' => $preview,
            'duplicates' => array_slice($duplicates, 0, 20),
            'errors' => $errors,
        ]);
    }

    public function confirmImport(ImportStudentsRequest $request, SimpleXlsxReader $reader): JsonResponse
    {
        $records = $this->recordsFromImport($request, $reader);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $defaultPasswordHash = Hash::make('password');

        foreach ($records as $index => $record) {
            if (($record['student_number'] ?? '') === '' || ($record['user_name'] ?? '') === '') {
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($record, $defaultPasswordHash, &$created, &$updated): void {
                    $studentProfile = StudentProfile::query()
                        ->where('student_number', $record['student_number'])
                        ->when($record['admission_no'], fn ($query) => $query->orWhere('admission_no', $record['admission_no']))
                        ->when($record['student_code'], fn ($query) => $query->orWhere('student_code', $record['student_code']))
                        ->first();
                    $email = $record['user_email'] ?: $this->generatedStudentEmail($record['student_number']);

                    if ($studentProfile) {
                        $studentProfile->user()->update([
                            'name' => $record['user_name'],
                            'email' => $email,
                            'is_active' => true,
                        ]);
                        $studentProfile->update(collect($record)->only($this->profileFields())->all() + [
                            'status' => 'active',
                            'archived_at' => null,
                            'archive_reason' => null,
                        ]);
                        $updated++;

                        return;
                    }

                    $user = User::create([
                        'name' => $record['user_name'],
                        'email' => $email,
                        'password' => $defaultPasswordHash,
                        'user_type' => 'student',
                        'is_active' => true,
                    ]);

                    StudentProfile::create(collect($record)->only($this->profileFields())->all() + [
                        'user_id' => $user->id,
                        'status' => 'active',
                    ]);
                    $created++;
                });
            } catch (\Throwable $exception) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    private function recordsFromImport(ImportStudentsRequest $request, SimpleXlsxReader $reader): array
    {
        $file = $request->file('file');
        $rows = strtolower($file->getClientOriginalExtension()) === 'csv'
            ? $this->csvRows($file->getRealPath())
            : $reader->rows($file->getRealPath());
        $headers = array_shift($rows) ?? [];

        return collect($rows)
            ->map(fn (array $row) => $this->normalizeImportRow($headers, $row))
            ->values()
            ->all();
    }

    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function normalizeImportRow(array $headers, array $row): array
    {
        $value = function (string $header) use ($headers, $row): ?string {
            $index = array_search($header, $headers, true);
            if ($index === false) {
                return null;
            }

            $cell = trim((string) ($row[$index] ?? ''));

            return $cell === '' || $cell === '-' || $cell === '/' ? null : $cell;
        };

        $batchParts = $this->parseBatch($value('Batch') ?? $value('Student category'));
        $studentNumber = $value('Admission No.') ?? $value('Course') ?? $value('Roll number');
        $fullName = $value('Full Name') ?? trim(implode(' ', array_filter([
            $value('First Name'),
            $value('Middle Name'),
            $value('Last Name'),
        ])));
        $gradeLevel = $batchParts['grade_level'] ?? $this->normalizeGradeLevel($value('Course'));
        $parentFullName = $value('Parents Full Name');

        $parentUsername = $value('Parents username')
            ?? $value('Parents mobile phone')
            ?? $value('Mobile')
            ?? $value('Phone');

        return [
            'user_name' => $fullName ?: $studentNumber,
            'user_email' => $value('E-mail'),
            'student_number' => $studentNumber,
            'student_code' => $studentNumber,
            'admission_no' => $studentNumber,
            'admission_date' => $this->excelDate($value('Admission Date')),
            'grade_level' => $gradeLevel,
            'current_grade_level' => $this->gradeNumber($gradeLevel),
            'grade_tier' => $this->gradeTier($gradeLevel),
            'student_category' => $value('Student category'),
            'batch' => $value('Batch'),
            'course' => $value('Course'),
            'academic_year' => $batchParts['academic_year'],
            'first_name' => $value('First Name'),
            'middle_name' => $value('Middle Name'),
            'last_name' => $value('Last Name'),
            'full_name' => $fullName,
            'arabic_name' => $value('Last Name'),
            'date_of_birth' => $this->excelDate($value('Date of Birth')),
            'gender' => $this->normalizeGender($value('Gender')),
            'blood_group' => $value('Blood group'),
            'mother_tongue' => $value('Mother Tongue'),
            'religion' => $value('Religion'),
            'country' => $value('Country'),
            'nationality' => $value('Nationality'),
            'nationality_ar' => $value('الجنسية بالعربي'),
            'national_id' => $value('الرقم الوطني'),
            'birth_place' => $value('Birth Place'),
            'address_line_1' => $value('Address Line 1'),
            'address_line_2' => $value('Address Line 2'),
            'city' => $value('City'),
            'state' => $value('State'),
            'pin_code' => $value('Pin code'),
            'phone' => $value('Phone'),
            'mobile' => $value('Mobile'),
            'roll_number' => $value('Roll number'),
            'biometric_id' => $value('Biometric ID'),
            'guardian_name' => $parentFullName,
            'guardian_phone' => $value('Parents mobile phone') ?? $value('Mobile') ?? $value('Phone'),
            'parent_first_name' => $value('Parents first name'),
            'parent_last_name' => $value('Parents last name'),
            'parent_full_name' => $parentFullName,
            'parent_relation' => $value('Parents relation'),
            'parent_nationality' => $value('Nationality'),
            'parent_username' => $parentUsername,
            'parent_date_of_birth' => $this->excelDate($value('Parents date of birth')),
            'parent_education' => $value('Parents education'),
            'parent_occupation' => $value('Parents occupation'),
            'parent_income' => $value('Parents income'),
            'parent_email' => $value('Parents email'),
            'parent_office_address_1' => $value('Parents office address 1'),
            'parent_office_address_2' => $value('Parents office address 2'),
            'parent_city' => $value('Parents city'),
            'parent_state' => $value('Parents state'),
            'parent_office_phone' => $value('Parents office phone'),
            'parent_mobile_phone' => $value('Parents mobile phone'),
            'second_parent_full_name' => null,
            'second_parent_relation' => null,
            'second_parent_nationality' => null,
            'second_parent_phone' => null,
            'second_parent_email' => null,
        ];
    }

    private function normalizeGradeLevel(?string $value): string
    {
        if ($value && preg_match('/Grade\s*0?(\d+)/i', $value, $matches)) {
            return 'G'.$matches[1];
        }

        if ($value && preg_match('/G\s*0?(\d+)/i', $value, $matches)) {
            return 'G'.$matches[1];
        }

        return $value ?: 'G1';
    }

    private function parseBatch(?string $value): array
    {
        if (! $value) {
            return [];
        }

        preg_match('/G\s*0?(\d+)/i', $value, $grade);
        preg_match('/-\s*([A-Z0-9]+)\s+/i', $value, $section);
        preg_match('/(\d{4}-\d{4})/', $value, $year);

        return [
            'grade_level' => isset($grade[1]) ? 'G'.$grade[1] : null,
            'section_name' => $section[1] ?? null,
            'academic_year' => $year[1] ?? null,
        ];
    }

    private function normalizeGender(?string $value): ?string
    {
        return match (strtolower((string) $value)) {
            'f' => 'female',
            'm' => 'male',
            default => $value,
        };
    }

    private function gradeNumber(?string $gradeLevel): ?int
    {
        if ($gradeLevel && preg_match('/(\d+)/', $gradeLevel, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function gradeTier(?string $gradeLevel): ?string
    {
        $grade = $this->gradeNumber($gradeLevel);
        if (! $grade) {
            return null;
        }

        if ($grade <= 4) {
            return 'G1-4';
        }

        return $grade <= 8 ? 'G5-8' : 'G9-12';
    }

    private function excelDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function generatedStudentEmail(string $studentNumber): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $studentNumber)).'@students.local';
    }

    private function nextStudentNumber(): string
    {
        $lastNumber = StudentProfile::query()
            ->where('admission_no', 'like', 'S%')
            ->lockForUpdate()
            ->pluck('admission_no')
            ->map(fn (?string $number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max();

        return 'S'.str_pad((string) (($lastNumber ?: 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function parentForStudentRequest(StoreStudentProfileRequest $request, StudentProfile $studentProfile): ParentGuardian
    {
        $siblingIds = collect($request->input('sibling_ids', []))->filter()->unique()->values();

        if ($request->boolean('use_sibling_parent') && $siblingIds->isNotEmpty()) {
            $siblingParent = ParentGuardian::query()
                ->whereHas('students', fn ($query) => $query->whereIn('student_profiles.id', $siblingIds))
                ->first();

            if ($siblingParent) {
                $this->copyParentSnapshotToStudent($studentProfile, $siblingParent);

                return $siblingParent;
            }
        }

        $parentData = $request->input('parent', []);
        $parentAdmissionNo = str_starts_with(strtoupper($studentProfile->admission_no), 'S')
            ? preg_replace('/^S/i', 'P', $studentProfile->admission_no)
            : 'P'.$studentProfile->admission_no;
        $fullName = trim(implode(' ', array_filter([
            $parentData['first_name'] ?? null,
            $parentData['last_name'] ?? null,
        ])));

        $parent = ParentGuardian::updateOrCreate(
            ['parent_admission_no' => $parentAdmissionNo],
            [
                'first_name' => $parentData['first_name'] ?? '',
                'last_name' => $parentData['last_name'] ?? null,
                'full_name' => $fullName ?: ($parentData['first_name'] ?? $parentAdmissionNo),
                'relation' => $parentData['relation'] ?? 'other',
                'mobile' => $parentData['mobile'] ?? null,
            ],
        );
        $this->copyParentSnapshotToStudent($studentProfile, $parent);

        return $parent;
    }

    private function copyParentSnapshotToStudent(StudentProfile $studentProfile, ParentGuardian $parent): void
    {
        $studentProfile->update([
            'guardian_name' => $studentProfile->guardian_name ?: $parent->full_name,
            'guardian_phone' => $studentProfile->guardian_phone ?: $parent->mobile,
            'parent_first_name' => $parent->first_name,
            'parent_last_name' => $parent->last_name,
            'parent_full_name' => $parent->full_name,
            'parent_relation' => $parent->relation,
            'parent_username' => $parent->parent_admission_no,
            'parent_mobile_phone' => $parent->mobile,
        ]);
    }

    private function linkSiblings(int $studentId, int $siblingId): void
    {
        if ($studentId === $siblingId) {
            throw ValidationException::withMessages(['sibling_ids' => 'Student cannot be linked as a sibling to itself.']);
        }

        $sibling = StudentProfile::query()->active()->find($siblingId);
        if (! $sibling) {
            throw ValidationException::withMessages(['sibling_ids' => 'Selected sibling must be active.']);
        }

        [$firstId, $secondId] = $studentId < $siblingId
            ? [$studentId, $siblingId]
            : [$siblingId, $studentId];

        DB::table('student_siblings')->updateOrInsert(
            [
                'student_id' => $firstId,
                'sibling_student_id' => $secondId,
            ],
            [
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function syncEnrollment(StudentProfile $studentProfile): void
    {
        $section = $studentProfile->section_id
            ? CourseSection::find($studentProfile->section_id)
            : CourseSection::query()
                ->when($studentProfile->academic_year, fn ($query) => $query->where('academic_year', $studentProfile->academic_year))
                ->where(function ($query) use ($studentProfile): void {
                    $query->where('class_name', $studentProfile->course)
                        ->orWhere('section_code', $studentProfile->course)
                        ->orWhere('class_name', $studentProfile->grade_level);
                })
                ->first();

        if (! $section) {
            return;
        }

        if ($studentProfile->section_id !== $section->id) {
            $studentProfile->forceFill(['section_id' => $section->id])->save();
        }

        Enrollment::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', 'active')
            ->where('course_section_id', '!=', $section->id)
            ->update(['status' => 'dropped']);

        Enrollment::query()->updateOrCreate(
            [
                'student_profile_id' => $studentProfile->id,
                'course_section_id' => $section->id,
            ],
            [
                'status' => 'active',
                'enrolled_at' => $studentProfile->admission_date ?? now()->toDateString(),
            ],
        );
    }

    private function profileFields(): array
    {
        return [
            'student_number',
            'student_code',
            'admission_no',
            'admission_date',
            'grade_level',
            'current_grade_level',
            'grade_tier',
            'student_category',
            'batch',
            'course',
            'section_id',
            'academic_year',
            'first_name',
            'middle_name',
            'last_name',
            'full_name',
            'arabic_name',
            'date_of_birth',
            'gender',
            'blood_group',
            'mother_tongue',
            'religion',
            'country',
            'nationality',
            'nationality_ar',
            'national_id',
            'birth_place',
            'address_line_1',
            'address_line_2',
            'city',
            'state',
            'pin_code',
            'phone',
            'mobile',
            'roll_number',
            'biometric_id',
            'guardian_name',
            'guardian_phone',
            'parent_first_name',
            'parent_last_name',
            'parent_full_name',
            'parent_relation',
            'parent_nationality',
            'parent_username',
            'parent_date_of_birth',
            'parent_education',
            'parent_occupation',
            'parent_income',
            'parent_email',
            'parent_office_address_1',
            'parent_office_address_2',
            'parent_city',
            'parent_state',
            'parent_office_phone',
            'parent_mobile_phone',
            'second_parent_full_name',
            'second_parent_relation',
            'second_parent_nationality',
            'second_parent_phone',
            'second_parent_email',
        ];
    }
}
