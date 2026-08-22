<?php

use App\Http\Controllers\AcademicLookupController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\ClassPostAttachmentController;
use App\Http\Controllers\ClassPostCommentController;
use App\Http\Controllers\ClassPostController;
use App\Http\Controllers\ClassReportCardController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseSectionController;
use App\Http\Controllers\CredentialSlipController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\Finance\CashAdvanceController;
use App\Http\Controllers\Finance\DiscountApprovalController;
use App\Http\Controllers\Finance\FeeSetupController;
use App\Http\Controllers\Finance\FinanceReportController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Finance\StudentAccountController;
use App\Http\Controllers\GPAController;
use App\Http\Controllers\GradeAuditLogController;
use App\Http\Controllers\GradeEntryController;
use App\Http\Controllers\GpaSettingsController;
use App\Http\Controllers\GradeReportController;
use App\Http\Controllers\GradeSubmissionReportController;
use App\Http\Controllers\GoogleClassroomController;
use App\Http\Controllers\GradingStructureController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReportCardPdfController;
use App\Http\Controllers\ReportCardPublicationController;
use App\Http\Controllers\ReportCardTemplateController;
use App\Http\Controllers\ReportSettingsController;
use App\Http\Controllers\TermWindowController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\StudentPromotionController;
use App\Http\Controllers\TeacherCourseController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/phase-one/admin-check', fn () => ['message' => 'admin ok'])
        ->middleware('role:admin');

    Route::get('/phase-one/staff-users-check', fn () => ['message' => 'staff users ok'])
        ->middleware('permission:users.view');

    Route::get('/phase-one/teacher-student-check', fn () => ['message' => 'teacher/student ok'])
        ->middleware('role:teacher,student');

    Route::get('/academic/lookups/users', [AcademicLookupController::class, 'users'])
        ->middleware('permission:users.view');

    /*
     * Printable sign-in slips. Issuing one sets a new password, so each route
     * asks for the permission to manage that kind of person, not to view them.
     */
    Route::get('/credential-slips/teachers/{user}', [CredentialSlipController::class, 'teacher'])
        ->middleware('permission:teachers.manage');
    Route::get('/credential-slips/students/{studentProfile}', [CredentialSlipController::class, 'student'])
        ->middleware('permission:students.manage');
    Route::get('/credential-slips/guardians/{parent}', [CredentialSlipController::class, 'guardian'])
        ->middleware('permission:students.manage');
    Route::get('/credential-slips/classes/{courseSection}', [CredentialSlipController::class, 'classSheet'])
        ->middleware('permission:students.manage');

    // What deleting a year would take with it, read before the warning is shown.
    Route::get('/academic-years/{academicYear}/impact', [AcademicYearController::class, 'impact'])
        ->middleware('permission:settings.manage');

    Route::put('/academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])
        ->middleware('permission:settings.manage');

    Route::apiResource('academic-years', AcademicYearController::class)
        ->middlewareFor(['index', 'show'], 'permission:settings.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:settings.manage');

    Route::get('/users/directory', [UserController::class, 'directory'])
        ->middleware('permission:users.view');
    Route::put('/parents/{parent}/credentials', [UserController::class, 'updateParentCredentials'])
        ->middleware('permission:users.manage');

    Route::apiResource('users', UserController::class)
        ->middlewareFor(['index', 'show'], 'permission:users.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:users.manage');

    Route::get('/teachers', [UserController::class, 'teachers'])
        ->middleware('permission:teachers.view');
    Route::post('/teachers', [UserController::class, 'storeTeacher'])
        ->middleware('permission:teachers.manage');
    Route::get('/subjects/catalogue', [TeacherCourseController::class, 'catalogue'])
        ->middleware('permission:teachers.view');
    Route::get('/teachers/{user}/courses', [TeacherCourseController::class, 'index'])
        ->middleware('permission:teachers.view');
    Route::put('/teachers/{user}/courses', [TeacherCourseController::class, 'sync'])
        ->middleware('permission:teachers.manage');
    Route::put('/teachers/{user}', [UserController::class, 'update'])
        ->middleware('permission:teachers.manage');
    Route::delete('/teachers/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:teachers.manage');

    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:staff.permissions.manage');
    Route::get('/staff-permissions', [PermissionController::class, 'staff'])
        ->middleware('permission:staff.permissions.manage');
    Route::put('/staff-permissions/{user}', [PermissionController::class, 'syncStaff'])
        ->middleware('permission:staff.permissions.manage');

    Route::get('/students/archived', [StudentProfileController::class, 'index'])
        ->defaults('archived', 'only')
        ->middleware('permission:view_student_archive');
    Route::post('/students/import/preview', [StudentProfileController::class, 'previewImport'])
        ->middleware('permission:import_students');
    Route::post('/students/import/confirm', [StudentProfileController::class, 'confirmImport'])
        ->middleware('permission:import_students');
    Route::get('/students', [StudentProfileController::class, 'index'])
        ->middleware('permission:view_students');
    Route::post('/students', [StudentProfileController::class, 'store'])
        ->middleware('permission:create_students');
    Route::get('/students/search-siblings', [StudentProfileController::class, 'searchSiblings'])
        ->middleware('permission:create_students');
    Route::get('/students/{studentProfile}', [StudentProfileController::class, 'show'])
        ->middleware('permission:view_students');
    Route::put('/students/{studentProfile}', [StudentProfileController::class, 'update'])
        ->middleware('permission:edit_students');
    Route::post('/students/{studentProfile}/siblings', [StudentProfileController::class, 'storeSibling'])
        ->middleware('permission:edit_students');
    Route::post('/students/{studentProfile}/archive', [StudentProfileController::class, 'archive'])
        ->middleware('permission:archive_students');
    Route::post('/students/{studentProfile}/restore', [StudentProfileController::class, 'restore'])
        ->middleware('permission:restore_students');
    Route::delete('/students/{studentProfile}', [StudentProfileController::class, 'destroy'])
        ->middleware('permission:delete_students');

    Route::get('/promotions/context', [StudentPromotionController::class, 'context'])
        ->middleware('permission:students.manage');
    Route::post('/promotions/preview', [StudentPromotionController::class, 'preview'])
        ->middleware('permission:students.manage');
    Route::post('/promotions/apply', [StudentPromotionController::class, 'apply'])
        ->middleware('permission:students.manage');

    Route::post('/student-profiles/import', [StudentProfileController::class, 'import'])
        ->middleware('permission:students.manage');
    Route::patch('/student-profiles/{studentProfile}/archive', [StudentProfileController::class, 'archive'])
        ->middleware('permission:students.manage');
    Route::patch('/student-profiles/{studentProfile}/restore', [StudentProfileController::class, 'restore'])
        ->middleware('permission:students.manage');
    Route::apiResource('student-profiles', StudentProfileController::class)
        ->middlewareFor(['index', 'show'], 'permission:students.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:students.manage');

    Route::post('/courses/bulk', [CourseController::class, 'bulkStore'])
        ->middleware('permission:courses.manage');

    // Reconnects subjects that have no class — declared before the resource so
    // it is not swallowed by courses/{course}.
    Route::post('/courses/assign-section', [CourseController::class, 'assignSection'])
        ->middleware('permission:courses.manage');

    // Clearing out subjects created by mistake, with a count of what goes first.
    Route::post('/courses/deletion-impact', [CourseController::class, 'deletionImpact'])
        ->middleware('permission:courses.manage');
    Route::post('/courses/delete-many', [CourseController::class, 'destroyMany'])
        ->middleware('permission:courses.manage');

    Route::apiResource('courses', CourseController::class)
        ->middlewareFor(['index', 'show'], 'permission:courses.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:courses.manage');

    Route::apiResource('course-sections', CourseSectionController::class)
        ->middlewareFor(['index', 'show'], 'permission:sections.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:sections.manage');

    Route::apiResource('enrollments', EnrollmentController::class)
        ->middlewareFor(['index', 'show'], 'permission:enrollments.manage')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:enrollments.manage');

    Route::get('/teacher/classes', [CourseSectionController::class, 'mine'])
        ->middleware('role:teacher');
    Route::get('/teacher/courses', [GradeEntryController::class, 'context'])
        ->middleware('role:teacher');

    /*
     * The school calendar. Everyone signed in may read it — that is the point
     * of publishing it — and only the office may write to it.
     */
    Route::get('/calendar-events', [CalendarEventController::class, 'index']);
    Route::post('/calendar-events', [CalendarEventController::class, 'store']);
    Route::put('/calendar-events/{calendarEvent}', [CalendarEventController::class, 'update']);
    Route::delete('/calendar-events/{calendarEvent}', [CalendarEventController::class, 'destroy']);

    /*
     * The in-house classroom: a wall of posts per subject.
     *
     * There is no permission gate on the group. Who may read a subject and who
     * may write to it depends on the subject — a teacher's own classes, a
     * student's own enrolment — so every route asks ClassroomAccess rather than
     * a flat permission that could not tell one class from another.
     */
    Route::prefix('classroom')->group(function (): void {
        Route::get('/courses', [ClassroomController::class, 'courses']);
        Route::get('/courses/{course}/stream', [ClassroomController::class, 'stream']);
        Route::post('/courses/{course}/seen', [ClassroomController::class, 'markSeen']);

        Route::post('/courses/{course}/posts', [ClassPostController::class, 'store']);
        Route::put('/posts/{post}', [ClassPostController::class, 'update']);
        Route::post('/posts/{post}/publish', [ClassPostController::class, 'publish']);
        Route::post('/posts/{post}/pin', [ClassPostController::class, 'pin']);
        Route::delete('/posts/{post}', [ClassPostController::class, 'destroy']);

        Route::post('/posts/{post}/attachments', [ClassPostAttachmentController::class, 'store']);
        Route::get('/attachments/{attachment}', [ClassPostAttachmentController::class, 'download']);
        Route::delete('/attachments/{attachment}', [ClassPostAttachmentController::class, 'destroy']);

        Route::get('/posts/{post}/comments', [ClassPostCommentController::class, 'index']);
        Route::post('/posts/{post}/comments', [ClassPostCommentController::class, 'store']);
        Route::delete('/comments/{comment}', [ClassPostCommentController::class, 'destroy']);
    });

    // Google Classroom: one course per subject, kept in step from here.
    Route::prefix('google-classroom')->middleware('permission:settings.manage')->group(function (): void {
        Route::get('/settings', [GoogleClassroomController::class, 'settings']);
        Route::get('/classes/{courseSection}', [GoogleClassroomController::class, 'status']);
        Route::post('/classes/{courseSection}/suggest-emails', [GoogleClassroomController::class, 'suggestEmails']);
        Route::put('/users/{user}/email', [GoogleClassroomController::class, 'linkEmail']);
        Route::put('/emails', [GoogleClassroomController::class, 'saveEmails']);
        Route::post('/courses/{course}', [GoogleClassroomController::class, 'createCourse']);
        Route::put('/courses/{course}', [GoogleClassroomController::class, 'renameCourse']);
        Route::post('/courses/{course}/teacher', [GoogleClassroomController::class, 'addTeacher']);
        Route::post('/courses/{course}/students', [GoogleClassroomController::class, 'addStudents']);
        Route::post('/courses/{course}/archive', [GoogleClassroomController::class, 'archiveCourse']);
    });

    // The report card's own wording and look, edited from the designer.
    Route::get('/report-card-template', [ReportCardTemplateController::class, 'show'])
        ->middleware('permission:settings.view');
    Route::put('/report-card-template', [ReportCardTemplateController::class, 'update'])
        ->middleware('permission:settings.manage');
    Route::post('/report-card-template/logo', [ReportCardTemplateController::class, 'uploadLogo'])
        ->middleware('permission:settings.manage');
    Route::delete('/report-card-template/logo', [ReportCardTemplateController::class, 'destroyLogo'])
        ->middleware('permission:settings.manage');
    Route::post('/report-card-template/reset', [ReportCardTemplateController::class, 'reset'])
        ->middleware('permission:settings.manage');

    Route::get('/report-settings', [ReportSettingsController::class, 'index'])
        ->middleware('permission:settings.view');
    Route::put('/report-settings', [ReportSettingsController::class, 'update'])
        ->middleware('permission:settings.manage');

    // The grading structure is the school's marking rulebook: only staff who
    // may manage it can read it either, so a student cannot enumerate it.
    Route::middleware('permission:manage_grading_structure')->group(function (): void {
        Route::get('/gpa-settings', [GpaSettingsController::class, 'index']);
        Route::put('/gpa-settings', [GpaSettingsController::class, 'update']);

        Route::get('/grading-structure', [GradingStructureController::class, 'index']);
        Route::post('/grading-structure/import/preview', [GradingStructureController::class, 'importPreview']);
        Route::post('/grading-structure/import/confirm', [GradingStructureController::class, 'importConfirm']);
        Route::get('/grading-structure/presets', [GradingStructureController::class, 'presets']);
        Route::post('/grading-structure/presets', [GradingStructureController::class, 'applyPreset']);
        Route::post('/grade-tiers', [GradingStructureController::class, 'storeTier']);
        Route::put('/grade-tiers/{gradeTier}', [GradingStructureController::class, 'updateTier']);
        Route::put('/grade-tiers/{gradeTier}/sections', [GradingStructureController::class, 'assignSections']);
        Route::post('/grade-tiers/{gradeTier}/activate', [GradingStructureController::class, 'activateTier']);
        Route::get('/grade-tiers/{gradeTier}/deactivation-impact', [GradingStructureController::class, 'deactivationImpact']);
        Route::post('/grade-tiers/{gradeTier}/deactivate', [GradingStructureController::class, 'deactivateTier']);
        Route::delete('/grade-tiers/{gradeTier}', [GradingStructureController::class, 'destroyTier']);
        Route::post('/grading-categories', [GradingStructureController::class, 'storeCategory']);
        Route::put('/grading-categories/{gradingCategory}', [GradingStructureController::class, 'updateCategory']);
        Route::delete('/grading-categories/{gradingCategory}', [GradingStructureController::class, 'destroyCategory']);
        Route::post('/grading-items', [GradingStructureController::class, 'storeItem']);
        Route::put('/grading-items/{gradingItem}', [GradingStructureController::class, 'updateItem']);
        Route::delete('/grading-items/{gradingItem}', [GradingStructureController::class, 'destroyItem']);
    });

    Route::get('/grade-entry/context', [GradeEntryController::class, 'context']);
    Route::get('/courses/{course}/grade-entry', [GradeEntryController::class, 'show']);
    Route::put('/courses/{course}/grade-entry', [GradeEntryController::class, 'bulkSave']);
    Route::post('/courses/{course}/grade-entry/submit', [GradeEntryController::class, 'submit']);
    Route::post('/courses/{course}/grade-entry/unlock', [GradeEntryController::class, 'unlock']);

    Route::get('/grade-audit-logs', [GradeAuditLogController::class, 'index']);

    /*
     * Finance. Note there is no update or delete route for a payment: a receipt
     * is immutable and a mistake is corrected by voiding it.
     */
    Route::prefix('finance')->group(function (): void {
        Route::get('/setup', [FeeSetupController::class, 'index'])
            ->middleware('permission:finance.view');
        Route::get('/students', [FinanceReportController::class, 'students'])
            ->middleware('permission:finance.view');
        Route::get('/classes', [FinanceReportController::class, 'classes'])
            ->middleware('permission:finance.view');

        Route::middleware('permission:finance.fees.manage')->group(function (): void {
            Route::post('/fee-categories', [FeeSetupController::class, 'storeCategory']);
            Route::put('/fee-categories/{feeCategory}', [FeeSetupController::class, 'updateCategory']);
            Route::delete('/fee-categories/{feeCategory}', [FeeSetupController::class, 'destroyCategory']);
            Route::post('/payment-methods', [FeeSetupController::class, 'storeMethod']);
            Route::put('/payment-methods/{paymentMethod}', [FeeSetupController::class, 'updateMethod']);
            Route::delete('/payment-methods/{paymentMethod}', [FeeSetupController::class, 'destroyMethod']);
            Route::post('/fee-templates', [FeeSetupController::class, 'storeTemplate']);
            Route::put('/fee-templates/{feeTemplate}', [FeeSetupController::class, 'updateTemplate']);
            Route::delete('/fee-templates/{feeTemplate}', [FeeSetupController::class, 'destroyTemplate']);
            Route::post('/discount-rules', [FeeSetupController::class, 'storeRule']);
            Route::put('/discount-rules/{discountRule}', [FeeSetupController::class, 'updateRule']);
            Route::delete('/discount-rules/{discountRule}', [FeeSetupController::class, 'destroyRule']);
        });

        Route::get('/students/{studentProfile}/account', [StudentAccountController::class, 'show'])
            ->middleware('permission:finance.view');
        Route::post('/students/{studentProfile}/assign-fees', [StudentAccountController::class, 'assignFees'])
            ->middleware('permission:finance.fees.manage');
        Route::post('/students/{studentProfile}/fees', [StudentAccountController::class, 'addFee'])
            ->middleware('permission:finance.fees.manage');

        Route::post('/fees/{studentFee}/discount-requests', [StudentAccountController::class, 'requestDiscount'])
            ->middleware('permission:finance.discounts.request');
        Route::get('/discount-requests', [DiscountApprovalController::class, 'index'])
            ->middleware('permission:finance.discounts.approve');
        Route::post('/discount-requests/{studentDiscount}/approve', [DiscountApprovalController::class, 'approve'])
            ->middleware('permission:finance.discounts.approve');
        Route::post('/discount-requests/{studentDiscount}/reject', [DiscountApprovalController::class, 'reject'])
            ->middleware('permission:finance.discounts.approve');

        Route::post('/students/{studentProfile}/payments', [PaymentController::class, 'store'])
            ->middleware('permission:finance.payments.record');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->middleware('permission:finance.view');
        Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])
            ->middleware('permission:finance.payments.void');

        /*
         * Cash advances. Spending an advance and signing it off are separate
         * rights on purpose: the person who buys is not the person who closes
         * the file on what was bought.
         */
        Route::get('/advances', [CashAdvanceController::class, 'index'])
            ->middleware('permission:finance.view');
        Route::get('/advances/{cashAdvance}', [CashAdvanceController::class, 'show'])
            ->middleware('permission:finance.view');

        Route::middleware('permission:finance.advances.manage')->group(function (): void {
            Route::post('/advances', [CashAdvanceController::class, 'store']);
            Route::put('/advances/{cashAdvance}', [CashAdvanceController::class, 'update']);
            Route::post('/advances/{cashAdvance}/expenses', [CashAdvanceController::class, 'addExpense']);
            Route::delete('/advance-expenses/{expense}', [CashAdvanceController::class, 'removeExpense']);
            Route::post('/advances/{cashAdvance}/cancel', [CashAdvanceController::class, 'cancel']);
        });

        Route::middleware('permission:finance.advances.settle')->group(function (): void {
            Route::post('/advances/{cashAdvance}/settle', [CashAdvanceController::class, 'settle']);
            Route::post('/advances/{cashAdvance}/reopen', [CashAdvanceController::class, 'reopen']);
        });

        Route::middleware('permission:finance.reports.view')->group(function (): void {
            Route::get('/reports/outstanding', [FinanceReportController::class, 'outstanding']);
            Route::get('/reports/aged', [FinanceReportController::class, 'aged']);
            Route::get('/reports/collections', [FinanceReportController::class, 'collections']);
        });
    });

    Route::get('/term-windows', [TermWindowController::class, 'index'])
        ->middleware('permission:admin_manage_grades');
    Route::put('/term-windows', [TermWindowController::class, 'update'])
        ->middleware('permission:admin_manage_grades');

    Route::get('/classes/{courseSection}/report-card-publications', [ReportCardPublicationController::class, 'index'])
        ->middleware('permission:admin_manage_grades');
    Route::post('/classes/{courseSection}/report-card-publications', [ReportCardPublicationController::class, 'store'])
        ->middleware('permission:admin_manage_grades');
    Route::delete('/classes/{courseSection}/report-card-publications', [ReportCardPublicationController::class, 'destroy'])
        ->middleware('permission:admin_manage_grades');

    Route::get('/reports/grade-submissions', [GradeSubmissionReportController::class, 'index'])
        ->middleware('permission:admin_manage_grades');
    Route::get('/reports/grade-submissions/pdf', [GradeSubmissionReportController::class, 'pdf'])
        ->middleware('permission:admin_manage_grades');

    Route::get('/student/courses/{course}/grade-report', [GradeReportController::class, 'studentReport'])
        ->middleware('role:student');
    Route::get('/student/courses', [GradeReportController::class, 'studentCourses'])
        ->middleware('role:student');
    Route::get('/teacher/courses/{course}/grade-summary', [GradeReportController::class, 'teacherCourseSummary'])
        ->middleware('role:teacher');
    Route::get(
        '/admin/student-profiles/{studentProfile}/courses/{course}/grade-report',
        [GradeReportController::class, 'adminReport'],
    );

    Route::get('/student/gpa', [GPAController::class, 'student'])
        ->middleware('role:student');
    Route::get('/student/dashboard', [StudentDashboardController::class, 'dashboard'])
        ->middleware('role:student');
    Route::get('/student/report-card', [StudentDashboardController::class, 'reportCard'])
        ->middleware('role:student');
    Route::get('/student/terms', [StudentDashboardController::class, 'terms'])
        ->middleware('role:student');

    Route::get('/student/report-cards', [StudentDashboardController::class, 'reportCards'])
        ->middleware('role:student');
    Route::get('/student/report-cards/{publication}/pdf', [StudentDashboardController::class, 'reportCardPdf'])
        ->middleware('role:student');

    Route::middleware('role:parent')->group(function (): void {
        Route::get('/parent/children', [ParentController::class, 'children']);
        Route::get('/parent/children/{studentProfile}/grades', [ParentController::class, 'childGrades']);
        Route::get('/parent/children/{studentProfile}/balance', [ParentController::class, 'childBalance']);
        Route::get('/parent/children/{studentProfile}/report-cards', [ParentController::class, 'childReportCards']);
        Route::get(
            '/parent/children/{studentProfile}/report-cards/{publication}/pdf',
            [ParentController::class, 'childReportCardPdf'],
        );
    });
    Route::get('/admin/student-profiles/{studentProfile}/gpa', [GPAController::class, 'admin']);

    Route::get('/course-sections/{courseSection}/attendance', [AttendanceController::class, 'section']);
    Route::put('/course-sections/{courseSection}/attendance', [AttendanceController::class, 'bulkSave']);
    Route::get('/course-sections/{courseSection}/attendance/month', [AttendanceController::class, 'month']);
    Route::get('/course-sections/{courseSection}/attendance/month.pdf', [AttendanceController::class, 'monthPdf']);
    Route::get('/student/attendance', [AttendanceController::class, 'student'])
        ->middleware('role:student');

    Route::get('/student/report-card/pdf', [ReportCardPdfController::class, 'student'])
        ->middleware('role:student');
    Route::get('/admin/student-profiles/{studentProfile}/report-card/pdf', [ReportCardPdfController::class, 'admin']);

    Route::get('/admin/classes/{courseSection}/report-cards/quarter/pdf', [ClassReportCardController::class, 'quarter']);
    Route::get('/admin/classes/{courseSection}/report-cards/semester/pdf', [ClassReportCardController::class, 'semester']);
});
