<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradingCategory;
use App\Models\StudentProfile;
use App\Policies\CoursePolicy;
use App\Policies\CourseSectionPolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\GradingStructurePolicy;
use App\Policies\StudentProfilePolicy;
use App\Services\Google\ClassroomClient;
use App\Services\Google\FakeClassroomClient;
use App\Services\Google\GoogleClassroomClient;
use App\Services\Google\GoogleServiceAccount;
use App\Support\PdfArabic;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Until the school finishes the Workspace setup the app talks to an
        // in-memory stand-in, so every screen works and nothing calls out.
        $this->app->singleton(ClassroomClient::class, fn () => config('google.classroom.enabled')
            ? new GoogleClassroomClient(new GoogleServiceAccount)
            : new FakeClassroomClient);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(CourseSection::class, CourseSectionPolicy::class);
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(StudentProfile::class, StudentProfilePolicy::class);
        Gate::policy(GradingCategory::class, GradingStructurePolicy::class);

        // One password policy for every place a password is set. Breach checking
        // is skipped outside production so tests and local work stay offline.
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // @ar('...') shapes Arabic for the dompdf-generated PDFs.
        Blade::directive('ar', fn (string $expression) => "<?php echo e(".PdfArabic::class."::shape({$expression})); ?>");
    }
}
