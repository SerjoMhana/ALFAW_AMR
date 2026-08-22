<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use App\Models\ParentGuardian;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CredentialSlipService;
use App\Services\ReportCardTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable sign-in slips.
 *
 * Each one carries a newly made password, because a stored one cannot be read
 * back. Issuing a slip therefore changes the account's password — which is why
 * this needs the permission to manage the person in question, not merely to
 * look at them.
 */
class CredentialSlipController extends Controller
{
    public function __construct(private readonly CredentialSlipService $slips) {}

    public function teacher(Request $request, User $user): Response
    {
        abort_unless($user->user_type === 'teacher', 404);

        return $this->render($request, collect([$this->slips->forTeacher($user)]), 'teacher-'.$user->id);
    }

    public function student(Request $request, StudentProfile $studentProfile): Response
    {
        return $this->render(
            $request,
            collect([$this->slips->forStudent($studentProfile)]),
            'student-'.($studentProfile->admission_no ?: $studentProfile->id),
        );
    }

    public function guardian(Request $request, ParentGuardian $parent): Response
    {
        return $this->render($request, collect([$this->slips->forGuardian($parent)]), 'guardian-'.$parent->id);
    }

    /**
     * Every student of one class, in one file.
     */
    public function classSheet(Request $request, CourseSection $courseSection): Response
    {
        $slips = $this->slips->forClass($courseSection);

        abort_if($slips->isEmpty(), 422, 'لا يوجد طلبة بحسابات في هذا الفصل.');

        return $this->render($request, $slips, 'class-'.($courseSection->class_name ?: $courseSection->id));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $slips
     */
    private function render(Request $request, Collection $slips, string $name): Response
    {
        // The sheet follows the screen it was asked from: an Arabic interface
        // prints an Arabic slip, an English one prints English.
        $locale = $request->query('locale') === 'en' ? 'en' : 'ar';
        $template = ReportCardTemplate::get();

        $pdf = Pdf::loadView('pdf.credential-slips', [
            'slips' => $slips,
            'locale' => $locale,
            'school' => $template['school_name'] ?? 'Vision International School',
            'logo' => ($template['show_logo'] ?? true) ? ReportCardTemplate::logoDataUri() : null,
            'generatedAt' => now()->format('Y-m-d'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("credentials-{$name}.pdf");
    }
}
