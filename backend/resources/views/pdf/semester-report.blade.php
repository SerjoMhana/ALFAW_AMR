@php
use App\Services\ReportCardTemplate;
$t=ReportCardTemplate::get();$logo=ReportCardTemplate::logoDataUri();$secondaryLogo=ReportCardTemplate::logoDataUri('secondary');$thirdLogo=ReportCardTemplate::logoDataUri('third');
@endphp
<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:38px 48px 78px}body{font-family:DejaVu Sans,sans-serif;color:{{ $t['text_color'] }};font-size:{{ max(8,$t['font_size']-1) }}px}body:before{content:"";position:fixed;top:-22px;right:-30px;bottom:-60px;left:-30px;border:1.5px solid {{ $t['border_color'] }}}
.page{page-break-after:always}.page:last-child{page-break-after:auto}.identity{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 7px}.identity td{border:none;text-align:center;vertical-align:top;width:33.33%;height:82px}.identity img.primary{width:{{ max(45,$t['logo_width']-10) }}px;max-height:60px}.identity img.secondary{width:{{ $t['secondary_logo_width'] }}px;max-height:60px}.identity img.third{width:{{ $t['third_logo_width'] }}px;max-height:60px}.logo-label{font-size:8px;font-weight:bold;margin-top:4px}
.report-title{text-align:center;font-size:12px;font-style:italic;margin-bottom:7px}.school-line{text-align:center;font-weight:bold;font-size:21px;margin:3px 0 15px}.school-meta{display:block;font-size:8px;font-weight:normal;color:#555;margin-top:4px}table{border-collapse:collapse;width:100%}
.info{width:100%;table-layout:fixed;margin-bottom:13px}.info td{border:none;padding:3px 7px}.label{font-weight:bold;font-size:9px;width:19%}.value{width:31%}.letter{border:1px solid {{ $t['border_color'] }};padding:7px;margin:7px 0;font-size:9px}
.subjects{border:1px solid {{ $t['border_color'] }}}.subjects th,.subjects td{border:1px solid {{ $t['border_color'] }};padding:5px 6px}.subjects th{background:{{ $t['header_bg'] }};text-align:center}.subjects td.num{text-align:center}.subjects td.subject-name{font-weight:normal}.subjects tbody tr:nth-child(even){background:#fbfcfd}.summary-row td,.status-row td,.gpa-row td{font-weight:bold;background:{{ $t['label_bg'] }}}
.grading-key{margin-top:9px;border:1px solid {{ $t['border_color'] }};font-size:8px;text-align:center}.grading-key-title{padding:4px;background:{{ $t['header_bg'] }};font-size:9px;font-weight:bold;border-bottom:1px solid {{ $t['border_color'] }}}.grading-key-text{padding:5px 7px;line-height:1.5}.remarks{margin-top:10px;font-size:8.5px;line-height:1.55}.remarks-title{font-weight:bold;margin-bottom:3px}.remarks-text{white-space:pre-wrap}
.signatures{position:fixed;bottom:8px;left:-12px;right:-12px;width:calc(100% + 24px);table-layout:fixed}.signatures td{border:none;width:33.33%;font-size:9px;font-weight:bold;text-align:center}.signature-space{height:35px}.signature-line{border-top:1px solid {{ $t['border_color'] }};padding-top:6px;margin:0 16px}.footer-note{position:fixed;bottom:62px;left:0;right:0;text-align:center;font-size:8px;color:#555}
</style></head><body>
@foreach($reports as $report)
@php
$student=$report['student_profile'];$classSection=$report['class_section'];$finalMode=($isFinal ?? false);
$columns=$finalMode?[$t['column_semester_1'],$t['column_semester_2']]:($semester===1?[$t['column_quarter_1'],$t['column_quarter_2']]:[$t['column_quarter_3'],$t['column_quarter_4']]);
$reportTitle=$finalMode?$t['final_title']:str_replace('{semester}',(string)$semester,$t['semester_title']);
$summary=$report['summary'] ?? ['maximum'=>count($report['subjects'])*100,'obtained'=>collect($report['subjects'])->sum('final'),'percentage'=>count($report['subjects'])?round(collect($report['subjects'])->avg('final'),1):0,'passed'=>count($report['subjects'])?collect($report['subjects'])->avg('final')>=60:false];
$guardian=data_get($student,'parent_full_name') ?: (data_get($student,'guardian_name') ?: '-');
@endphp
<div class="page">
@if($t['show_signature'])<table class="signatures"><tr><td><div class="signature-space"></div><div class="signature-line">@ar($t['signer_one'])</div></td><td><div class="signature-space"></div><div class="signature-line">@ar($t['signer_two'])</div></td><td><div class="signature-space"></div><div class="signature-line">@ar($t['signer_three'])</div></td></tr></table>@endif
@if($t['footer_note'])<div class="footer-note">@ar($t['footer_note'])</div>@endif
<table class="identity"><tr><td>@if($logo)<img class="primary" src="{{ $logo }}" alt="">@endif<div class="logo-label">@ar($t['logo_label'])</div></td><td>@if($secondaryLogo)<img class="secondary" src="{{ $secondaryLogo }}" alt="">@endif<div class="logo-label">@ar($t['secondary_logo_label'])</div></td><td>@if($thirdLogo)<img class="third" src="{{ $thirdLogo }}" alt="">@endif<div class="logo-label">@ar($t['third_logo_label'])</div></td></tr></table>
<div class="report-title">@ar($reportTitle) @if(data_get($classSection,'academic_year'))- {{ data_get($classSection,'academic_year') }}@endif</div><div class="school-line">@ar($t['school_name']) @if($t['school_address'])<span class="school-meta">@ar($t['school_address']) @if($t['school_phone']) - {{ $t['school_phone'] }}@endif</span>@endif</div>
<table class="info">
<tr><td class="label">@ar($t['label_student_name'])</td><td class="value">@ar($student->full_name ?: $student->user?->name)</td><td class="label">@ar($t['label_grade'])</td><td class="value">@ar($classSection->class_name ?: $classSection->section_code)</td></tr>
<tr>@if($t['show_roll_number'])<td class="label">@ar($t['label_roll_number'])</td><td class="value">{{ data_get($student,'roll_number') ?: '-' }}</td>@else<td colspan="2"></td>@endif @if($t['show_registration_number'])<td class="label">@ar($t['label_registration_number'])</td><td class="value">{{ $student->admission_no ?: $student->student_number }}</td>@else<td colspan="2"></td>@endif</tr>
@if($t['show_guardian'])<tr><td class="label">@ar($t['label_guardian'])</td><td class="value">@ar($guardian)</td><td class="label">@ar($t['label_teacher'])</td><td class="value">@ar(data_get($report,'class_section.teacher.name','-'))</td></tr>@endif
<tr>@if($t['show_report_date'])<td class="label">@ar($t['label_date'])</td><td class="value">{{ now()->format('Y-m-d') }}</td>@else<td colspan="2"></td>@endif @if($t['show_absence_total'])<td class="label">@ar($t['label_absence_total'])</td><td class="value">{{ $report['absence_total'] ?? 0 }}</td>@else<td colspan="2"></td>@endif</tr>
</table>
@if(trim($reportMessage ?? '')!=='')<div class="letter">{!! nl2br(e($reportMessage)) !!}</div>@endif
<table class="subjects"><thead><tr><th style="text-align:left">@ar($t['column_subject'])</th><th>@ar($columns[0])</th><th>@ar($columns[1])</th><th>@ar($t['column_final_grade'])</th><th>@ar($t['column_credit'])</th></tr></thead><tbody>
@foreach($report['subjects'] as $subject)<tr><td class="subject-name">@ar($subject['name'])</td><td class="num">{{ $subject['columns'][0] }}</td><td class="num">{{ $subject['columns'][1] }}</td><td class="num">{{ $subject['final'] }} ({{ $subject['letter'] }})</td><td class="num">{{ $subject['credit_hours'] }}</td></tr>@endforeach
@if($t['show_quarter_summary'])<tr class="summary-row"><td>@ar($t['label_grand_total'])</td><td colspan="4" style="text-align:center">{{ $summary['obtained'] }} / {{ $summary['maximum'] }} ({{ $summary['percentage'] }}%)</td></tr><tr class="status-row"><td>@ar($t['label_status'])</td><td colspan="4" style="text-align:center">@ar($summary['passed'] ? $t['label_pass'] : $t['label_fail'])</td></tr>@endif
@if($t['show_gpa'])<tr class="gpa-row"><td>@ar($t['label_gpa'])</td><td colspan="4" style="text-align:center">{{ $report['gpa'] ?? '-' }}</td></tr>@endif
</tbody></table>
@if($t['show_grading_key'] && trim($t['grading_key_text'])!=='')<div class="grading-key"><div class="grading-key-title">@ar($t['grading_key_title'])</div><div class="grading-key-text">@ar($t['grading_key_text'])</div></div>@endif
@if($t['show_teacher_remarks'])<div class="remarks"><div class="remarks-title">@ar($t['teacher_remarks_label'])</div><div class="remarks-text">@ar($t['teacher_remarks_text'])</div></div>@endif
</div>
@endforeach
</body></html>
