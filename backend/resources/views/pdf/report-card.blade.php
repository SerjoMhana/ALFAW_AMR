<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 12px; }
        h1, h2 { margin: 0 0 8px; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #1f5eff; padding-bottom: 12px; margin-bottom: 18px; }
        .section { margin-bottom: 18px; page-break-inside: avoid; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; }
        th, td { border: 1px solid #d8e0ed; padding: 6px; text-align: left; }
        th { background: #eef3fb; }
        .summary { display: table; width: 100%; margin-top: 8px; }
        .summary div { display: table-cell; padding-right: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="summary">
            <div><strong>Student:</strong> @ar($student_profile->full_name ?: $student_profile->user?->name)</div>
            <div><strong>No:</strong> {{ $student_profile->student_number }}</div>
            <div><strong>Grade:</strong> {{ $student_profile->grade_level }}</div>
        </div>
    </div>

    @foreach ($terms as $term)
        <div class="section">
            <h2>{{ $term['term'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Teacher</th>
                        <th>Final Grade</th>
                        <th>Applied Weight</th>
                        <th>Missing Scores</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($term['courses'] as $course)
                        <tr>
                            <td>{{ $course['course']->code }} - @ar($course['course']->name)</td>
                            <td>@ar($course['teacher']?->name ?: 'غير مسند')</td>
                            <td>{{ $course['report']['final_grade'] }}</td>
                            <td>{{ $course['report']['total_applied_weight'] }}%</td>
                            <td>{{ implode(', ', $course['report']['missing_scores']) ?: 'None' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($term['gpa'])
                <p><strong>Term GPA:</strong> {{ $term['gpa']['gpa'] ?? 'N/A' }}</p>
            @endif
        </div>
    @endforeach
</body>
</html>
