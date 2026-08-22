# American Student System API

Base path: `/api`

Authentication uses Laravel Sanctum bearer tokens.

## Auth

- `POST /login`
- `GET /user`
- `POST /logout`

## Admin and Staff

- `GET|POST /users`
- `GET|POST /teachers`
- `GET|POST /student-profiles`
- `GET|POST /courses`
- `GET|POST /course-sections`
- `GET|POST /enrollments`
- `GET /permissions`
- `GET|PUT /staff-permissions`

Access is controlled by user type and permission middleware. Admin bypasses permissions.

## Teacher

- `GET /teacher/sections`
- `POST /assessments`
- `GET /assessments/{assessment}/gradebook`
- `PUT /assessments/{assessment}/scores`
- `GET /teacher/sections/{courseSection}/grade-summary`

Teachers can only access sections assigned to them.

## Student

- `GET /student/sections`
- `GET /student/dashboard`
- `GET /student/report-card`
- `GET /student/sections/{courseSection}/grade-report`
- `GET /student/gpa`

Students can only access their own active enrollments and read-only reports.

## Grade Reports

Grade reports return:

- `category_breakdown`
- `final_grade`
- `missing_categories`
- `total_applied_weight`
- assessment score details per category

## GPA

GPA applies to every grade, G1 through G12.

Each grade level carries its own GPA, computed only from the subjects of the
class the student sat in that year. Nothing is averaged or carried between
grades, and there is no cumulative figure across terms.

Pass `academic_year` to read the GPA of a grade the student has since left.

- Normal courses use a 4.0 scale.
- AP courses use a 5.0 scale.
- GPA is weighted by `credit_hours`.

## Audit

Bulk grade saves create records in `grade_audit_logs` with old score, new score, actor, student, and assessment.
