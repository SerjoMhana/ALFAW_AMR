@php
    use App\Services\ReportCardTemplate;

    // Shares the designer's template with the quarter sheet.
    $t = ReportCardTemplate::get();
    $logo = ReportCardTemplate::logoDataUri();
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: {{ $t['text_color'] }}; font-size: {{ max(8, $t['font_size'] - 1.5) }}px; margin: 28px 36px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .school-logo { text-align: center; margin-bottom: 6px; }
        .school-logo img { width: {{ max(50, $t['logo_width'] - 25) }}px; }
        .report-title { text-align: center; font-size: {{ $t['font_size'] + 4 }}px; font-weight: bold; margin-bottom: 2px; color: {{ $t['accent_color'] }}; }
        .report-date { text-align: center; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid {{ $t['border_color'] }}; padding: 4px 6px; vertical-align: top; }
        .info td { padding: 5px 6px; }
        .info .label { font-weight: bold; }
        .letter { border: 1px solid #444; padding: 8px; margin: 10px 0; font-size: 9.5px; }
        .subjects th { background: {{ $t['header_bg'] }}; text-align: center; }
        .subjects td.num { text-align: center; width: 90px; }
        .subjects td.subject-name { font-weight: bold; }
        .gpa-row td { font-weight: bold; background: {{ $t['label_bg'] }}; }
        .legend { font-size: 8.5px; padding: 5px 6px; }
        .note { font-size: 9px; margin-top: 8px; }
        .signatures { margin-top: 26px; width: 100%; border: none; }
        .signatures td { border: none; border-top: 1px solid #444; width: 40%; text-align: center; padding-top: 5px; font-weight: bold; }
        .signatures td.gap { border: none; width: 20%; }
        .section-gap { margin-top: 10px; }
    </style>
</head>
<body>
@foreach ($reports as $report)
    @php
        $student = $report['student_profile'];
        $classSection = $report['class_section'];
        $columns = $semester === 1 ? ['Quarter 1', 'Quarter 2'] : ['Semester 1', 'Semester 2'];
    @endphp
    <div class="page">
        @if ($logo)
            <div class="school-logo"><img src="{{ $logo }}" alt=""></div>
        @endif
        <div class="report-title">@ar(str_replace('{semester}', (string) $semester, $t['semester_title']))</div>
        <div class="report-date">Date: {{ now()->format('Y-m-d') }}</div>

        <table class="info">
            <tr>
                <td><span class="label">Student:</span> @ar($student->full_name ?: $student->user?->name)</td>
                <td><span class="label">@ar($t['label_student_number'])</span> {{ $student->admission_no ?: $student->student_number }}</td>
                <td><span class="label">Total Days Absent:</span></td>
            </tr>
            <tr>
                <td><span class="label">@ar($t['label_grade'])</span> @ar($classSection->class_name ?: $classSection->section_code)</td>
                <td colspan="2"><span class="label">Teacher:</span> {{ $classSection->teacher?->name }}</td>
            </tr>
            <tr>
                <td><span class="label">@ar($t['label_school'])</span> @ar($t['school_name'])</td>
                <td colspan="2"><span class="label">@ar($t['label_principal'])</span></td>
            </tr>
            <tr>
                <td><span class="label">Address:</span></td>
                <td colspan="2"><span class="label">Telephone:</span></td>
            </tr>
        </table>

        @if (trim($reportMessage ?? '') !== '')
            <div class="letter">{!! nl2br(e($reportMessage)) !!}</div>
        @endif

        <table class="subjects section-gap">
            <thead>
                <tr>
                    <th style="text-align:left">Subjects</th>
                    <th>{{ $columns[0] }}</th>
                    <th>{{ $columns[1] }}</th>
                    <th>Final Grade</th>
                    @if ($semester === 2)
                        <th>Credits Earned</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($report['subjects'] as $subject)
                    <tr>
                        <td class="subject-name">@ar($subject['name'])</td>
                        <td class="num">{{ $subject['columns'][0] }}</td>
                        <td class="num">{{ $subject['columns'][1] }}</td>
                        <td class="num">{{ $subject['final'] }} ({{ $subject['letter'] }})</td>
                        @if ($semester === 2)
                            <td class="num">{{ $subject['credit_earned'] }}</td>
                        @endif
                    </tr>
                @endforeach
                <tr class="gpa-row">
                    <td>GPA</td>
                    <td colspan="{{ $semester === 2 ? 4 : 3 }}" style="text-align:center">{{ $report['gpa'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>

        <table class="section-gap">
            <tr>
                <td class="legend">
                    <strong>GPA:</strong>
                    4.0 = A+ &nbsp; 4 = A &nbsp; 3.7 = A- &nbsp; 3.3 = B+ &nbsp; 3 = B &nbsp; 2.7 = B- &nbsp; 2.3 = C+ &nbsp;
                    2.0 = C &nbsp; 1.7 = C- &nbsp; 1.3 = D+ &nbsp; 1.0 = D &nbsp; 0.7 = D- &nbsp; 0.0 = F
                </td>
            </tr>
        </table>

        <p class="note">To Parents/Guardians and Students: This copy of the progress report card should be retained for reference.</p>

        <table class="signatures">
            <tr>
                <td>Teacher's Signature</td>
                <td class="gap"></td>
                <td>@ar($t['signature_label'])</td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
