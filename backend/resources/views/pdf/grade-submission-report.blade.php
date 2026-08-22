<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 10px; margin: 26px 30px; }
        .school-name { text-align: center; font-size: 17px; font-weight: bold; }
        .report-title { text-align: center; font-size: 12px; font-weight: bold; margin: 4px 0 2px; }
        .meta { text-align: center; color: #555; font-size: 9px; margin-bottom: 14px; }

        table.summary { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        table.summary td { border: 1px solid #444; padding: 7px 9px; text-align: center; width: 20%; }
        table.summary td .value { font-size: 16px; font-weight: bold; display: block; }
        table.summary td .label { font-size: 9px; color: #555; }

        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #444; }

        table.data { border-collapse: collapse; width: 100%; }
        table.data th, table.data td { border: 1px solid #666; padding: 5px 7px; }
        table.data th { background: #e5e7eb; text-align: left; font-size: 9px; }
        table.data td.center { text-align: center; }

        .teacher-row td { background: #f3f4f6; font-weight: bold; }
        .status-partial { color: #92400e; font-weight: bold; }
        .status-missing { color: #991b1b; font-weight: bold; }
        .ok { color: #166534; font-weight: bold; }
        .none { color: #555; font-style: italic; padding: 6px 0; }
        .footer { margin-top: 26px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
@php
    $statusLabels = [
        'submitted' => 'Submitted',
        'partial' => 'In progress (not submitted)',
        'not_started' => 'Not started',
    ];
    $pendingTeachers = collect($report['teachers'])->where('is_complete', false);
    $doneTeachers = collect($report['teachers'])->where('is_complete', true);
@endphp

<div class="school-name">Vision International School</div>
<div class="report-title">Grade Submission Status Report &mdash; {{ $title }}</div>
<div class="meta">Academic Year {{ $report['academic_year'] }} &nbsp;|&nbsp; Terms: {{ implode(', ', $report['terms']) }} &nbsp;|&nbsp; Generated {{ $generatedAt }}</div>

<table class="summary">
    <tr>
        <td><span class="value">{{ $report['summary']['total'] }}</span><span class="label">Total subject / term</span></td>
        <td><span class="value">{{ $report['summary']['submitted'] }}</span><span class="label">Submitted</span></td>
        <td><span class="value">{{ $report['summary']['partial'] }}</span><span class="label">In progress</span></td>
        <td><span class="value">{{ $report['summary']['not_started'] }}</span><span class="label">Not started</span></td>
        <td><span class="value">{{ $report['summary']['completion_percent'] }}%</span><span class="label">Completed</span></td>
    </tr>
</table>

<h2>Teachers with pending grades ({{ $pendingTeachers->count() }})</h2>

@if ($pendingTeachers->isEmpty())
    <p class="none">All teachers have submitted every subject for this period.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width: 26%">Teacher</th>
                <th style="width: 24%">Subject</th>
                <th style="width: 12%">Class</th>
                <th style="width: 14%">Term</th>
                <th style="width: 24%">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pendingTeachers as $teacher)
                <tr class="teacher-row">
                    <td>@ar($teacher['teacher_name'])</td>
                    <td colspan="4">
                        {{ $teacher['pending_count'] }} pending of {{ $teacher['total'] }}
                        &nbsp;&mdash;&nbsp; {{ $teacher['completion_percent'] }}% complete
                    </td>
                </tr>
                @foreach ($teacher['pending'] as $row)
                    <tr>
                        <td></td>
                        <td>@ar($row['subject_name']) <small>({{ $row['subject_code'] }})</small></td>
                        <td class="center">{{ $row['class_name'] }}</td>
                        <td class="center">{{ $row['term'] }}</td>
                        <td class="{{ $row['status'] === 'partial' ? 'status-partial' : 'status-missing' }}">
                            {{ $statusLabels[$row['status']] }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

<h2>Teachers who submitted everything ({{ $doneTeachers->count() }})</h2>

@if ($doneTeachers->isEmpty())
    <p class="none">No teacher has completed all their subjects yet.</p>
@else
    <table class="data">
        <thead>
            <tr>
                <th style="width: 40%">Teacher</th>
                <th style="width: 20%">Subjects submitted</th>
                <th style="width: 40%">Subjects</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doneTeachers as $teacher)
                <tr>
                    <td>@ar($teacher['teacher_name'])</td>
                    <td class="center ok">{{ $teacher['submitted_count'] }} / {{ $teacher['total'] }}</td>
                    <td>@ar(collect($teacher['submitted'])->map(fn ($row) => $row['subject_name'].' ('.$row['class_name'].')')->unique()->implode(', '))</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['unassigned']))
    <h2>Subjects with no teacher assigned ({{ count($report['unassigned']) }})</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 40%">Subject</th>
                <th style="width: 20%">Class</th>
                <th style="width: 20%">Term</th>
                <th style="width: 20%">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['unassigned'] as $row)
                <tr>
                    <td>@ar($row['subject_name']) <small>({{ $row['subject_code'] }})</small></td>
                    <td class="center">{{ $row['class_name'] }}</td>
                    <td class="center">{{ $row['term'] }}</td>
                    <td class="center status-missing">{{ $statusLabels[$row['status']] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    Generated by Vision International School Student Management System on {{ $generatedAt }}.
</div>
</body>
</html>
