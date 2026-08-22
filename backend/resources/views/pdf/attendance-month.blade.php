<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /*
         * A month on one landscape page: names down the side, one narrow column
         * per day. The blank form is the working document the supervisors carry,
         * so the cells are left big enough to write a mark in by hand.
         */
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 9px; margin: 18px 20px; }

        .school-name { text-align: center; font-size: 15px; font-weight: bold; }
        .sheet-title { text-align: center; font-size: 11px; font-weight: bold; margin: 3px 0 2px; }
        .meta { text-align: center; color: #555; font-size: 8px; margin-bottom: 10px; }

        table.grid { border-collapse: collapse; width: 100%; table-layout: fixed; }
        table.grid th, table.grid td { border: 1px solid #666; padding: 0; text-align: center; }

        th.no, td.no { width: 20px; }
        th.name, td.name { width: 150px; text-align: right; padding: 3px 5px; font-size: 9px; }
        th.adm, td.adm { width: 52px; font-size: 8px; }
        th.day, td.day { font-size: 8px; }
        td.day { height: 15px; }
        th.total, td.total { width: 22px; font-size: 8px; }

        thead th { background: #e5e7eb; font-weight: bold; padding: 3px 1px; }
        th.weekend, td.weekend { background: #f3f4f6; color: #9ca3af; }
        td.mark { font-size: 10px; }
        td.absent { color: #b91c1c; font-weight: bold; }

        .legend { margin-top: 10px; font-size: 8px; color: #333; }
        .legend span { margin-inline-end: 14px; }
        .signature { margin-top: 22px; font-size: 9px; }
        .signature td { padding-top: 20px; border-top: 1px solid #666; text-align: center; width: 33%; }
        .footer { margin-top: 8px; font-size: 8px; color: #666; text-align: center; }
    </style>
</head>
<body>
@php
    $days = collect($sheet['days']);
    $statusLabels = [
        'present' => 'حاضر',
        'absent' => 'غائب',
        'late' => 'متأخر',
        'excused' => 'بعذر',
    ];
@endphp

<div class="school-name">@ar($school ?: 'مدرسة ڤيجن الدولية')</div>
<div class="sheet-title">
    @ar('كشف الحضور والغياب الشهري') &mdash; @ar($sheet['section']['name'])
</div>
<div class="meta">
    {{ $sheet['month_label'] }}
    &nbsp;|&nbsp; @ar('السنة الدراسية') {{ $sheet['section']['academic_year'] }}
    &nbsp;|&nbsp; @ar($filled ? 'نسخة معبّأة من سجل المنظومة' : 'نسخة فارغة للتعبئة اليدوية')
</div>

<table class="grid">
    <thead>
        <tr>
            <th class="no">#</th>
            <th class="name">@ar('اسم الطالب')</th>
            <th class="adm">@ar('رقم القبول')</th>
            @foreach ($days as $day)
                <th class="day {{ $day['is_weekend'] ? 'weekend' : '' }}">{{ $day['day'] }}</th>
            @endforeach
            @if ($filled)
                <th class="total">@ar('ح')</th>
                <th class="total">@ar('غ')</th>
                <th class="total">@ar('ت')</th>
                <th class="total">@ar('م')</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse ($sheet['students'] as $student)
            <tr>
                <td class="no">{{ $student['no'] }}</td>
                <td class="name">@ar($student['name'])</td>
                <td class="adm">{{ $student['admission_no'] }}</td>
                @foreach ($days as $day)
                    @php $mark = $filled ? ($student['marks'][$day['date']] ?? null) : null; @endphp
                    <td class="day mark {{ $day['is_weekend'] ? 'weekend' : '' }} {{ $mark && $mark['status'] === 'absent' ? 'absent' : '' }}">
                        {{ $mark['mark'] ?? '' }}
                    </td>
                @endforeach
                @if ($filled)
                    <td class="total">{{ $student['totals']['present'] }}</td>
                    <td class="total">{{ $student['totals']['absent'] }}</td>
                    <td class="total">{{ $student['totals']['late'] }}</td>
                    <td class="total">{{ $student['totals']['excused'] }}</td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ 3 + $days->count() + ($filled ? 4 : 0) }}" style="padding: 10px;">
                    @ar('لا يوجد طلبة مسجلون في هذا الفصل.')
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="legend">
    @foreach ($sheet['legend'] as $status => $mark)
        <span><strong>{{ $mark }}</strong> = @ar($statusLabels[$status] ?? $status)</span>
    @endforeach
</div>

<table class="signature" style="width: 100%; border-collapse: collapse;">
    <tr>
        <td>@ar('المشرف')</td>
        <td>@ar('مسؤول المنظومة')</td>
        <td>@ar('مدير المدرسة')</td>
    </tr>
</table>

<div class="footer">@ar('تاريخ الطباعة') {{ $generatedAt }}</div>
</body>
</html>
