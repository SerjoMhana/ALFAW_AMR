<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /*
         * One slip per person, two to a page, with a cut line between them —
         * the office prints a class, cuts, and hands each person their own.
         */
        @page { margin: 0; }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #172033;
            font-size: 11px;
            margin: 0;
        }

        .slip {
            height: 48%;
            padding: 26px 34px;
            position: relative;
        }

        .slip + .slip { border-top: 1px dashed #9ca3af; }

        .head { text-align: center; }
        .logo { max-height: 58px; margin-bottom: 6px; }
        .school { font-size: 16px; font-weight: bold; }
        .title { font-size: 11px; color: #555; margin-top: 2px; }

        .welcome {
            margin: 16px 0 12px;
            font-size: 12px;
            line-height: 1.9;
        }

        table.creds { border-collapse: collapse; width: 100%; margin-top: 4px; }

        table.creds td {
            border: 1px solid #666;
            padding: 9px 12px;
            font-size: 12px;
        }

        td.label {
            width: 30%;
            background: #f2f4f7;
            font-weight: bold;
        }

        td.value {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 14px;
            letter-spacing: 1px;
        }

        .note {
            margin-top: 12px;
            font-size: 9px;
            color: #666;
            line-height: 1.7;
        }

        .foot {
            position: absolute;
            bottom: 14px;
            inset-inline: 34px;
            font-size: 8px;
            color: #888;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
@php
    // Every string the sheet can print, in both languages. The caller decides
    // which by passing $locale, so an Arabic screen prints an Arabic slip.
    $t = [
        'ar' => [
            'title' => 'بيانات الدخول إلى نظام المدرسة',
            'welcome_teacher' => 'أهلاً بك أستاذ :name، نسعد بانضمامك إلى مدرستنا. هذه بيانات دخولك إلى المنظومة:',
            'welcome_student' => 'أهلاً بك :name، هذه بيانات دخولك إلى منظومة المدرسة:',
            'welcome_guardian' => 'أهلاً بك :name، هذه بيانات دخولك إلى بوابة أولياء الأمور لمتابعة ابنك:',
            'username' => 'اسم المستخدم',
            'password' => 'كلمة المرور',
            'reference' => 'رقم القبول',
            'note' => 'احتفظ بهذه الورقة في مكان آمن ولا تشاركها مع أحد. يمكنك تغيير كلمة المرور بعد أول دخول.',
            'printed' => 'تاريخ الطباعة',
        ],
        'en' => [
            'title' => 'School system sign-in details',
            'welcome_teacher' => 'Welcome :name. We are glad to have you with us. These are your sign-in details:',
            'welcome_student' => 'Welcome :name. These are your sign-in details for the school system:',
            'welcome_guardian' => 'Welcome :name. These are your sign-in details for the guardian portal:',
            'username' => 'Username',
            'password' => 'Password',
            'reference' => 'Admission no.',
            'note' => 'Keep this slip somewhere safe and share it with nobody. You can change the password after signing in.',
            'printed' => 'Printed',
        ],
    ][$locale] ?? [];

    $arabic = $locale === 'ar';
    // Arabic needs shaping for dompdf; English must not be touched by it.
    $say = fn (string $text) => $arabic ? \App\Support\PdfArabic::shape($text) : $text;
@endphp

@foreach ($slips as $slip)
    <div class="slip" @if ($arabic) dir="rtl" @endif>
        <div class="head">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @endif
            <div class="school">{{ $say($school) }}</div>
            <div class="title">{{ $say($t['title']) }}</div>
        </div>

        <p class="welcome">
            {{ $say(str_replace(':name', $slip['name'], $t['welcome_'.$slip['role']] ?? $t['welcome_student'])) }}
        </p>

        <table class="creds">
            @if (! empty($slip['reference']))
                <tr>
                    <td class="label">{{ $say($t['reference']) }}</td>
                    <td class="value" dir="ltr">{{ $slip['reference'] }}</td>
                </tr>
            @endif
            <tr>
                <td class="label">{{ $say($t['username']) }}</td>
                <td class="value" dir="ltr">{{ $slip['username'] }}</td>
            </tr>
            <tr>
                <td class="label">{{ $say($t['password']) }}</td>
                <td class="value" dir="ltr">{{ $slip['password'] }}</td>
            </tr>
        </table>

        <p class="note">{{ $say($t['note']) }}</p>

        <div class="foot">
            <span>{{ $say($t['printed']) }} {{ $generatedAt }}</span>
            <span>{{ $say($school) }}</span>
        </div>
    </div>
@endforeach
</body>
</html>
