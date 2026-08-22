@php
    use App\Services\ReportCardTemplate;

    // Every string, colour and toggle below is the school's own, editable from
    // Grade Management → Report card designer.
    $t = ReportCardTemplate::get();
    $logo = ReportCardTemplate::logoDataUri();

    // Headings may wrap onto two lines. Escaping first and only then turning
    // newlines into breaks keeps admin-entered text from becoming markup.
    $lines = fn (string $text) => nl2br(e($text));
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: {{ $t['text_color'] }}; font-size: {{ $t['font_size'] }}px; margin: 36px 48px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .school-logo { text-align: center; margin-bottom: 8px; }
        .school-logo img { width: {{ $t['logo_width'] }}px; }
        .school-name { text-align: center; font-size: {{ $t['font_size'] + 8 }}px; font-weight: bold; margin-bottom: 4px; color: {{ $t['accent_color'] }}; }
        .report-title { text-align: center; font-size: {{ $t['font_size'] + 2 }}px; font-weight: bold; margin-bottom: 24px; }
        table.info { border-collapse: collapse; width: 70%; margin-bottom: 20px; }
        table.info td { border: 1px solid {{ $t['border_color'] }}; padding: 5px 8px; }
        table.info td.label { font-weight: bold; width: 45%; background: {{ $t['label_bg'] }}; }
        table.marks { border-collapse: collapse; width: 100%; }
        table.marks th, table.marks td { border: 1px solid {{ $t['border_color'] }}; padding: 6px 8px; }
        table.marks th { background: {{ $t['header_bg'] }}; text-align: center; }
        table.marks td.num { text-align: center; width: 110px; }
        .signature { margin-top: 60px; }
        .signature .name { font-weight: bold; }
        .signature .role { color: #444; }
        .footer-note { margin-top: 28px; font-size: {{ max(8, $t['font_size'] - 2) }}px; color: #444; }
    </style>
</head>
<body>
@foreach ($reports as $report)
    <div class="page">
        @if ($logo)
            <div class="school-logo"><img src="{{ $logo }}" alt=""></div>
        @endif
        <div class="school-name">@ar($t['school_name'])</div>
        <div class="report-title">@ar($t['quarter_title']) &mdash; {{ $report['term'] }}</div>

        <table class="info">
            <tr>
                <td class="label">@ar($t['label_student_number'])</td>
                <td>{{ $report['student_profile']->admission_no ?: $report['student_profile']->student_number }}</td>
            </tr>
            <tr>
                <td class="label">@ar($t['label_student_name'])</td>
                <td>@ar($report['student_profile']->full_name ?: $report['student_profile']->user?->name)</td>
            </tr>
            <tr>
                <td class="label">@ar($t['label_grade'])</td>
                <td>@ar($report['class_section']->class_name ?: $report['class_section']->section_code)</td>
            </tr>
            @if ($t['show_report_date'])
                <tr>
                    <td class="label">@ar($t['label_date'])</td>
                    <td>{{ now()->format('m/d/Y') }}</td>
                </tr>
            @endif
        </table>

        <table class="marks">
            <thead>
                <tr>
                    <th style="text-align:left">{!! $lines($t['column_subject']) !!}</th>
                    <th>{!! $lines($t['column_marks']) !!}</th>
                    @if ($t['show_letter_grade'])
                        <th>{!! $lines($t['column_letter']) !!}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($report['subjects'] as $subject)
                    <tr>
                        <td>@ar($subject['name'])</td>
                        <td class="num">{{ $subject['grade'] }}</td>
                        @if ($t['show_letter_grade'])
                            <td class="num">{{ $subject['letter'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($t['show_signature'])
            <div class="signature">
                <div class="name">@ar($t['signature_name'])</div>
                <div class="role">@ar($t['signature_role'])</div>
            </div>
        @endif

        @if ($t['footer_note'])
            <div class="footer-note">@ar($t['footer_note'])</div>
        @endif
    </div>
@endforeach
</body>
</html>
