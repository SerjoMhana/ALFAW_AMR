<?php

namespace Tests\Concerns;

use App\Models\DiscountRule;
use App\Models\FeeCategory;
use App\Models\FeeTemplate;
use App\Models\ParentGuardian;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

trait CreatesFinanceFixtures
{
    protected string $year = '2026-2027';

    protected function financeUser(string $email, array $permissions = [], string $type = 'staff'): User
    {
        $user = User::create([
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => Hash::make('password'),
            'user_type' => $type,
            'is_active' => true,
        ]);

        if ($permissions !== []) {
            $ids = collect($permissions)->map(
                fn (string $name) => Permission::firstOrCreate(['name' => $name], ['label' => $name])->id,
            );
            $user->permissions()->sync($ids);
        }

        return $user;
    }

    protected function admin(string $email = 'admin@example.com'): User
    {
        return $this->financeUser($email, [], 'admin');
    }

    protected function student(string $name, string $number, ?string $admissionDate = null): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'full_name' => $name,
            'grade_level' => 'G10',
            'academic_year' => $this->year,
            'admission_date' => $admissionDate,
            'status' => 'active',
        ]);
    }

    /**
     * A guardian with children attached in the given order.
     *
     * @param  array<int, StudentProfile>  $children
     */
    protected function guardianFor(array $children, string $suffix = '1'): ParentGuardian
    {
        $guardian = ParentGuardian::create([
            'parent_admission_no' => 'P-'.$suffix,
            'first_name' => 'Guardian',
            'full_name' => 'Guardian '.$suffix,
            'relation' => 'father',
            'mobile' => '091000000'.$suffix,
        ]);

        foreach ($children as $child) {
            $guardian->students()->attach($child->id, ['relation' => 'father', 'is_primary' => true]);
        }

        return $guardian->fresh();
    }

    protected function category(string $name): FeeCategory
    {
        return FeeCategory::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    /**
     * @param  array<int, string>|string|null  $gradeLevels  null means every grade
     */
    protected function feeTemplate(
        string $name,
        string $category,
        float $amount,
        array|string|null $gradeLevels = 'G10',
        ?string $dueDate = '2099-09-01',
    ): FeeTemplate {
        return FeeTemplate::create([
            'name' => $name,
            'fee_category_id' => $this->category($category)->id,
            'amount' => $amount,
            'academic_year' => $this->year,
            'grade_levels' => $gradeLevels === null ? null : (array) $gradeLevels,
            'due_date' => $dueDate,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
    }

    protected function paymentMethod(string $name = 'نقداً'): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['name' => $name],
            ['is_active' => true, 'display_order' => 1],
        );
    }

    /**
     * @param  array<string, float>  $tiers  sibling ordinal => percentage
     */
    protected function siblingRule(array $tiers, array $categories = ['tuition']): DiscountRule
    {
        return DiscountRule::create([
            'name' => 'خصم الإخوة',
            'type' => DiscountRule::TYPE_SIBLING,
            'applies_to_categories' => $categories,
            'sibling_tiers' => $tiers,
            'is_active' => true,
        ]);
    }

    /**
     * The guard caches its user for the life of the test app, so it must be
     * reset whenever a test switches between users.
     */
    protected function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
