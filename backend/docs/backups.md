# النسخ الاحتياطي

كل ليلة تُؤخذ نسخة واحدة تضم **قاعدة البيانات + الملفات المرفوعة** في ملف مضغوط
واحد، وتُحذف النسخ الأقدم من ثلاثين يوماً.

## التشغيل يدوياً

```bash
cd backend && php artisan backup:run
```

يطبع اسم الأرشيف وحجمه وعدد الجداول والملفات والزمن المستغرق.

## التشغيل تلقائياً كل ليلة

الجدولة معرَّفة في `routes/console.php` عند الساعة **01:30** (تُغيَّر بـ `BACKUP_TIME`).
لكن Laravel لا يشغّل نفسه — يحتاج نبضة كل دقيقة من نظام التشغيل.

### Windows (XAMPP)

سجّل المهمة مرة واحدة من **موجّه أوامر بصلاحيات مسؤول**:

```
schtasks /create /tn "VIS Scheduler" /tr "C:\Users\pc\Documents\Amircan student system\run-scheduler.bat" /sc minute /mo 1
```

الملف `run-scheduler.bat` في جذر المشروع يستدعي `php artisan schedule:run` ويكتب
مخرجاته في `backend/storage/logs/scheduler.log`. في 1439 دقيقة من اليوم لا يحدث
شيء؛ في الدقيقة المستحقة تُؤخذ النسخة.

للتحقق من أن المهمة مسجّلة:

```
schtasks /query /tn "VIS Scheduler"
```

ولإزالتها:

```
schtasks /delete /tn "VIS Scheduler" /f
```

> ⚠️ **الجهاز يجب أن يكون مشغّلاً وMySQL يعمل** في وقت النسخة. إن كنت تطفئ
> الجهاز ليلاً، اضبط `BACKUP_TIME` على وقت يكون فيه شغّالاً.

### Linux (عند الرفع أونلاين)

سطر واحد في crontab:

```cron
* * * * * cd /var/www/vis/backend && php artisan schedule:run >> /dev/null 2>&1
```

## أين تُحفظ

`storage/app/private/backups` افتراضياً، ويُغيَّر بـ `BACKUP_PATH`.

> **انقلها خارج القرص.** أرشيف بجانب قاعدة البيانات ينجو من خطأ بشري، لا من عطل
> القرص. اجعل `BACKUP_PATH` مجلداً على قرص آخر أو مجلداً يُزامَن مع سحابة.

## ما بداخل الأرشيف

```
vis-2026-08-22-211220.zip
└── vis-2026-08-22-211220/
    ├── database.sql        ← كل الجداول والبيانات
    └── files/              ← مرفقات الفصل الإلكتروني وشعار الكشف
```

## الاستعادة

انسخ الأرشيف، فُكّه، ثم:

```bash
# 1. أنشئ قاعدة فارغة (أو استعمل القائمة — الـ dump يحذف الجداول ويعيد بناءها)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS vis_school CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. استورد
mysql -u root vis_school < vis-2026-08-22-211220/database.sql

# 3. أعد الملفات إلى مكانها
cp -r vis-2026-08-22-211220/files/* backend/storage/
```

على Windows بمسارات XAMPP:

```
C:\xampp\mysql\bin\mysql.exe -u root vis_school < database.sql
```

## هل النسخة صالحة فعلاً؟

الأمر لا يكتفي بكتابة الملف: يعيد فتحه بعد الكتابة ويتأكد أن الـ dump بداخله
يحمل الجداول المتوقّعة. أي تشغيل ينتج ملفاً لا يُقرأ **يُبلّغ عن فشل** ويحذف
الملف الناقص بدل تركه يوهمك بالأمان.

ومع ذلك — **جرّب الاستعادة بنفسك مرة كل فصل دراسي**. نسخة لم تُستعَد ليست نسخة.
استعدها في قاعدة باسم آخر (`vis_restore_test` مثلاً) وقارن الأعداد، ثم احذفها.

## المتابعة

- كل نسخة ناجحة تُكتب في السجل: `Backup written`
- كل فشل يُكتب: `Backup failed` مع سبب العطل
- الجدولة تكتب سطراً في `storage/logs/scheduler.log`

راجع السجل أسبوعياً، أو اربطه بتنبيه إن أضفت تتبّع أخطاء لاحقاً.

## الإعدادات

| المتغير | الافتراضي | المعنى |
|---|---|---|
| `BACKUP_PATH` | `storage/app/private/backups` | مكان الأرشيفات |
| `BACKUP_KEEP_DAYS` | 30 | كم يوماً تُحفظ قبل الحذف |
| `BACKUP_TIME` | `01:30` | موعد النسخة الليلية |
| `BACKUP_MYSQLDUMP` | مسار XAMPP | مسار mysqldump؛ إن لم يوجد يُستعمل بديل بـ PHP |
