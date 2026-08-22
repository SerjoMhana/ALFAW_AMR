<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $permissions = collect([
            ['name' => 'users.view', 'label' => 'View users'],
            ['name' => 'users.manage', 'label' => 'Manage users'],
            ['name' => 'students.view', 'label' => 'View students'],
            ['name' => 'students.manage', 'label' => 'Manage students'],
            ['name' => 'view_students', 'label' => 'View students'],
            ['name' => 'create_students', 'label' => 'Create students'],
            ['name' => 'edit_students', 'label' => 'Edit students'],
            ['name' => 'archive_students', 'label' => 'Archive students'],
            ['name' => 'restore_students', 'label' => 'Restore students'],
            ['name' => 'delete_students', 'label' => 'Delete students permanently'],
            ['name' => 'import_students', 'label' => 'Import students'],
            ['name' => 'view_student_archive', 'label' => 'View student archive'],
            ['name' => 'teachers.view', 'label' => 'View teachers'],
            ['name' => 'teachers.manage', 'label' => 'Manage teachers'],
            ['name' => 'courses.view', 'label' => 'View courses'],
            ['name' => 'courses.manage', 'label' => 'Manage courses'],
            ['name' => 'sections.view', 'label' => 'View sections'],
            ['name' => 'sections.manage', 'label' => 'Manage sections'],
            ['name' => 'enrollments.manage', 'label' => 'Manage enrollments'],
            ['name' => 'attendance.view', 'label' => 'View attendance'],
            ['name' => 'attendance.manage', 'label' => 'Manage attendance'],
            ['name' => 'settings.view', 'label' => 'View settings'],
            ['name' => 'settings.manage', 'label' => 'Manage settings'],
            ['name' => 'staff.permissions.manage', 'label' => 'Manage staff permissions'],
            ['name' => 'view_grades', 'label' => 'View grades'],
            ['name' => 'enter_grades', 'label' => 'Enter grades'],
            ['name' => 'edit_grades', 'label' => 'Edit grades'],
            ['name' => 'admin_manage_grades', 'label' => 'Manage grades for any section'],
            ['name' => 'manage_grading_structure', 'label' => 'Manage grading structure'],
            ['name' => 'import_grading_structure', 'label' => 'Import grading structure'],
            ['name' => 'view_grade_audit_logs', 'label' => 'View grade audit logs'],
            ['name' => 'finance.view', 'label' => 'View finance'],
            ['name' => 'finance.fees.manage', 'label' => 'Manage fees, plans and discount rules'],
            ['name' => 'finance.discounts.request', 'label' => 'Request a discount'],
            ['name' => 'finance.discounts.approve', 'label' => 'Approve a discount'],
            ['name' => 'finance.payments.record', 'label' => 'Record payments'],
            ['name' => 'finance.payments.void', 'label' => 'Void a receipt'],
            ['name' => 'finance.reports.view', 'label' => 'View financial reports'],
            ['name' => 'finance.advances.manage', 'label' => 'Issue advances and record what they were spent on'],
            ['name' => 'finance.advances.settle', 'label' => 'Settle and reopen an advance'],
            ['name' => 'classroom.view', 'label' => 'Read every class stream'],
            ['name' => 'classroom.post', 'label' => 'Post to a class stream'],
            ['name' => 'classroom.manage', 'label' => 'Moderate any class stream'],
            ['name' => 'calendar.manage', 'label' => 'Manage the school calendar'],
        ])->mapWithKeys(fn (array $permission) => [
            $permission['name'] => Permission::updateOrCreate(
                ['name' => $permission['name']],
                ['label' => $permission['label']],
            ),
        ]);

        /*
         * The administrator's password comes from the environment, and a strong
         * one is generated and printed when it does not.
         *
         * A fixed 'password' seeded into every install is the first thing an
         * attacker tries, and the school would never know to change it.
         */
        $plain = env('SEED_ADMIN_PASSWORD') ?: Str::password(16);
        $password = Hash::make($plain);

        User::updateOrCreate(['email' => 'admin@school.test'], [
            'name' => 'System Admin',
            'password' => $password,
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        // Sample staff, teacher and student accounts exist for local work only.
        // They are never created in production, where they would simply be four
        // more ways in.
        if (! app()->isProduction() && env('SEED_SAMPLE_USERS', false)) {
            $staff = User::updateOrCreate(['email' => 'staff@school.test'], [
                'name' => 'Staff User',
                'password' => $password,
                'user_type' => 'staff',
                'is_active' => true,
            ]);

            $staff->permissions()->sync([
                $permissions['users.view']->id,
                $permissions['students.view']->id,
                $permissions['view_students']->id,
                $permissions['courses.view']->id,
                $permissions['settings.view']->id,
            ]);

            foreach (['teacher', 'student'] as $type) {
                User::updateOrCreate(['email' => "{$type}@school.test"], [
                    'name' => ucfirst($type).' User',
                    'password' => $password,
                    'user_type' => $type,
                    'is_active' => true,
                ]);
            }
        }

        // Exactly one year is ever active. Seeding them all as active — which
        // this used to do — leaves the settings page showing three current
        // years and no way to delete any of them.
        foreach (['2024-2025', '2025-2026', '2026-2027'] as $year) {
            AcademicYear::firstOrCreate(['name' => $year], ['is_active' => false]);
        }

        if (AcademicYear::query()->active()->doesntExist()) {
            AcademicYear::query()->orderByDesc('name')->first()?->activate();
        }

        $this->call(GradingStructureSeeder::class);

        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn('Administrator: admin@school.test');
            $this->command?->warn("Password (shown once): {$plain}");
            $this->command?->warn('Sign in and change it, or set SEED_ADMIN_PASSWORD before seeding.');
        }
    }
}
