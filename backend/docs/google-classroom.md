# ربط Google Classroom بالمنظومة

كل **مادة** في المنظومة تقابل **فصلاً واحداً** في Google Classroom. الفصل الدراسي نفسه (G12 مثلاً)
هو تجميع فقط ولا يقابله شيء في Google، لأن Google لا تعرف إلا الـ course.

الربط يتم مرة واحدة. الخطوات 1–8 في لوحات Google وملف الإعدادات، والخطوتان 9–10 داخل
صفحة **الإعدادات ← Google Classroom** في المنظومة.

> ⚠️ **ملف المفتاح (JSON) يفتح نطاق المدرسة بالكامل.** لا يوضع داخل مجلد المشروع ولا في
> `public/` ولا يُرسل في محادثة أو بريد. يوضع في مجلد خاص على الخادم، ويُكتب **مساره** فقط في
> `.env`.

---

## 1. نطاق المدرسة على Google Workspace

المنظومة لا تدير حسابات `@gmail.com` الشخصية. يلزم نطاق المدرسة (مثل `vis.edu.ly`) مُدَاراً عبر
**Google Workspace for Education** — مجاني للمدارس عبر <https://workspace.google.com/education>.
بعد تفعيل النطاق يُنشأ حساب لكل طالب وأستاذ عليه.

## 2. مشروع في Google Cloud

<https://console.cloud.google.com> ← **New Project** ← الاسم مثلاً `VIS Student System`.
المشروع وعاء للأذونات فقط، بلا تكلفة.

## 3. تفعيل واجهة Classroom

داخل المشروع: **APIs & Services → Library** ← ابحث عن **Google Classroom API** ← **Enable**.
بدون هذه الخطوة تُرفض كل الطلبات.

## 4. حساب خدمة (Service Account)

**IAM & Admin → Service Accounts → Create service account** ← الاسم `vis-classroom` ← **Done**.
افتحه بعد الإنشاء وانسخ **Unique ID (Client ID)** — رقم طويل تحتاجه في الخطوة 6.

## 5. مفتاح JSON

من حساب الخدمة: **Keys → Add key → Create new key → JSON**. سيُنزَّل ملف.

ضعه في مجلد خارج المشروع، مثلاً:

- Windows: `C:\vis-secrets\google-classroom.json`
- Linux: `/etc/vis/google-classroom.json` مع `chmod 600` وملكية حساب الخادم

## 6. التفويض على مستوى النطاق (Domain-wide delegation)

أولاً في Cloud Console: افتح حساب الخدمة وفعّل
**Enable Google Workspace domain-wide delegation**.

ثم في <https://admin.google.com>:
**Security → Access and data control → API controls → Domain-wide delegation → Add new**

- **Client ID**: الرقم المنسوخ في الخطوة 4
- **OAuth scopes** (مفصولة بفواصل):

```
https://www.googleapis.com/auth/classroom.courses
https://www.googleapis.com/auth/classroom.rosters
https://www.googleapis.com/auth/classroom.profile.emails
```

## 7. حساب النيابة (Impersonation)

حساب الخدمة لا يملك فصولاً في Classroom؛ لا بد أن ينوب عن شخص حقيقي على النطاق يكون مالك
الفصول، مثل `classroom@vis.edu.ly`. يجب أن يكون هذا الحساب ضمن مجموعة يُسمح لها بالتدريس:
**Admin console → Apps → Google Workspace → Classroom → Teacher permissions**.

## 8. ملف `.env`

```dotenv
GOOGLE_CLASSROOM_ENABLED=true
GOOGLE_SERVICE_ACCOUNT_JSON="C:\vis-secrets\google-classroom.json"
GOOGLE_WORKSPACE_DOMAIN=vis.edu.ly
GOOGLE_IMPERSONATE_EMAIL=classroom@vis.edu.ly
```

ثم:

```bash
php artisan config:clear
```

بعدها تُظهر الصفحة شارة **متصل بـ Google** بدل **وضع التجربة**، وشارة **ملف المفتاح موجود**.

## 9. ربط عناوين الطلبة والأساتذة

صفحة **Google Classroom** ← اختر الفصل ← **اقترح للفارغين** (من رقم القبول أو من الاسم) ←
راجع وصحّح ← **حفظ العناوين**.

القواعد المطبَّقة:

- العنوان خارج نطاق المدرسة يُرفض.
- العنوان الواحد لا يُعطى لشخصين.
- صف واحد خاطئ يُبطل الدفعة كلها، فلا يبقى الربط نصف مطبَّق.

## 10. إنشاء الفصول وإضافة الأستاذ والطلبة

لكل مادة في الجدول:

| الزر | ما يفعله |
|---|---|
| **إنشاء الفصل** | ينشئ فصلاً في Classroom باسم المادة. الضغط مرة أخرى **لا** ينشئ فصلاً ثانياً. |
| **إعادة التسمية** | يغيّر اسم الفصل في Classroom. الاسم هنا هو المرجع. |
| **إضافة الأستاذ** | يضيف أستاذ المادة المسجّل في المنظومة. |
| **إضافة الطلبة** | يفتح قائمة لاختيار من يُسجَّل. من بلا عنوان يظهر معطّلاً. |
| **أرشفة** | يؤرشف الفصل في Classroom. الدرجات في المنظومة لا تتأثر. |

---

## لماذا لا تتكرر الفصول

عند الإنشاء يُبنى **alias** ثابت من السنة الدراسية ورقم المادة ورمزها
(`ClassroomSyncService::aliasFor`)، ويُسجَّل في Google كـ `p:<alias>` — أي alias خاص بهذا
التطبيق وحده، فلا يصطدم بأسماء المدرسة في Classroom.

قبل الإنشاء يبحث النظام بهذا الـ alias: إن وُجد الفصل تبنّاه، وإن لم يوجد أنشأه. لذلك:

- الضغط على «إنشاء الفصل» مرتين ينتج فصلاً واحداً.
- ضياع المعرّف المخزَّن في قاعدة البيانات لا يسبب تكراراً — يُستعاد الفصل نفسه.

## حالة «دُعي» بدل «أُضيف»

بعض إعدادات Workspace لا تسمح بإضافة الطالب مباشرة، فيرسل Google دعوة. هذا ليس خطأً:
يُسجَّل العضو بحالة `INVITED` حتى يقبل من حسابه. للإضافة المباشرة، اسمح بها لحساب النيابة من
إعدادات Classroom في لوحة الإدارة.

## وضع التجربة

`GOOGLE_CLASSROOM_ENABLED=false` يجعل الحاوية تربط `ClassroomClient` بـ `FakeClassroomClient`
(`AppServiceProvider::register`) — نسخة في الذاكرة تجيب بنفس أشكال Google. كل الشاشات والقواعد
تعمل، ولا يخرج طلب واحد إلى الإنترنت. هذا ما تعمل عليه الاختبارات أيضاً.

كل صف يُنشأ في وضع التجربة يُعلَّم `simulated = true`. حين تُفعَّل الخطوة 8 يصبح هذا الصف
**قديماً**: تُعامَل المادة كأنها غير مربوطة، ولا يُعرض معرّف الفصل ولا رمز الانضمام، ويعود زر
«إنشاء الفصل» ليُنشئ الفصل فعلياً. أي أن التجربة قبل الربط لا تترك أثراً خاطئاً بعده.

## استكشاف الأخطاء

| الرسالة | السبب الغالب |
|---|---|
| `The Google service-account file was not found` | مسار خاطئ في `GOOGLE_SERVICE_ACCOUNT_JSON`، أو الخادم لا يقرأ الملف. |
| `Google refused the service account: unauthorized_client` | الـ Client ID أو النطاقات لم تُضف في Domain-wide delegation (الخطوة 6). |
| `GOOGLE_IMPERSONATE_EMAIL is not set` | لم يُحدَّد حساب النيابة (الخطوة 7). |
| `Google rejected ...: Requested entity was not found` | الحساب النائب ليس مصرَّحاً له بالتدريس في Classroom. |
| `لم يُربط بريد Google Workspace للمستخدم ...` | الأستاذ بلا عنوان — يُضبط من نفس الصفحة. |

## الملفات

| الملف | الدور |
|---|---|
| `config/google.php` | الإعدادات المقروءة من `.env`. |
| `app/Services/Google/ClassroomClient.php` | الواجهة التي يعتمد عليها كل شيء. |
| `app/Services/Google/GoogleClassroomClient.php` | التنفيذ الفعلي عبر REST. |
| `app/Services/Google/GoogleServiceAccount.php` | توقيع JWT وتبديله بتوكن (يُخزَّن 50 دقيقة). |
| `app/Services/Google/FakeClassroomClient.php` | نسخة الذاكرة لوضع التجربة والاختبارات. |
| `app/Services/Google/ClassroomSyncService.php` | المزامنة: الحالة، الإنشاء، الأستاذ، الطلبة، الأرشفة. |
| `app/Http/Controllers/GoogleClassroomController.php` | نقاط الـ API تحت `permission:settings.manage`. |
| `frontend/src/components/grading/GoogleClassroom.vue` | الصفحة. |
| `frontend/src/components/grading/GoogleClassroomGuide.vue` | هذا الدليل داخل الواجهة. |
| `tests/Feature/GoogleClassroomSyncTest.php` | 23 اختباراً تغطي المسار كاملاً. |
