<script setup>
import { computed } from 'vue'
import { isArabic } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * The setup, written out where the admin needs it.
 *
 * Linking Classroom is a one-off job done half in Google's consoles and half
 * here, and the two halves reference each other — so the steps live beside the
 * page that finishes them rather than in a document nobody opens.
 */

// Each step is a title, a body, an optional block to copy, and where it is done.
const steps = [
  {
    where: { ar: 'Google Workspace', en: 'Google Workspace' },
    ar: {
      title: 'تأكد أن للمدرسة نطاقاً على Google Workspace',
      body: 'لا يمكن للمنظومة إدارة حسابات @gmail.com الشخصية. تحتاج نطاق المدرسة مثل vis.edu.ly مُدَاراً عبر Google Workspace for Education (مجاني للمدارس). إن لم يكن موجوداً، سجّل النطاق أولاً من workspace.google.com/education، ثم أنشئ حساباً لكل طالب وأستاذ عليه.',
    },
    en: {
      title: 'Make sure the school has a Google Workspace domain',
      body: 'The system cannot manage personal @gmail.com accounts. You need the school\'s own domain — vis.edu.ly, say — managed through Google Workspace for Education, which is free for schools. If there is none yet, sign the domain up at workspace.google.com/education first, then create an account there for every student and teacher.',
    },
  },
  {
    where: { ar: 'console.cloud.google.com', en: 'console.cloud.google.com' },
    ar: {
      title: 'أنشئ مشروعاً في Google Cloud',
      body: 'ادخل console.cloud.google.com بحساب الإدارة، ثم New Project واسمه مثلاً VIS Student System. المشروع مجرد وعاء للأذونات؛ لا تكلفة عليه.',
    },
    en: {
      title: 'Create a Google Cloud project',
      body: 'Sign in to console.cloud.google.com with the admin account, then New Project — name it VIS Student System. The project is only a container for the permissions; it costs nothing.',
    },
  },
  {
    where: { ar: 'console.cloud.google.com', en: 'console.cloud.google.com' },
    ar: {
      title: 'فعّل واجهة Google Classroom',
      body: 'من داخل المشروع: APIs & Services ← Library ← ابحث عن Google Classroom API ← Enable. بدون هذه الخطوة تُرفض كل الطلبات.',
    },
    en: {
      title: 'Enable the Google Classroom API',
      body: 'Inside the project: APIs & Services → Library → search for Google Classroom API → Enable. Without this every request is refused.',
    },
  },
  {
    where: { ar: 'console.cloud.google.com', en: 'console.cloud.google.com' },
    ar: {
      title: 'أنشئ حساب خدمة (Service Account)',
      body: 'IAM & Admin ← Service Accounts ← Create service account ← سمّه vis-classroom ← Done. بعد إنشائه افتحه وانسخ رقم Unique ID (Client ID) الطويل؛ ستحتاجه في خطوة التفويض.',
    },
    en: {
      title: 'Create a service account',
      body: 'IAM & Admin → Service Accounts → Create service account → name it vis-classroom → Done. Open it afterwards and copy the long Unique ID (Client ID); the delegation step needs it.',
    },
  },
  {
    where: { ar: 'console.cloud.google.com', en: 'console.cloud.google.com' },
    danger: true,
    ar: {
      title: 'أنشئ مفتاح JSON واحفظه خارج المشروع',
      body: 'من حساب الخدمة: Keys ← Add key ← Create new key ← JSON. سيُنزَّل ملف. هذا الملف يفتح نطاق المدرسة كاملاً: لا تضعه داخل مجلد المشروع ولا في public ولا ترسله في محادثة أو بريد. ضعه في مجلد خاص على الخادم فقط، مثل C:\\vis-secrets\\google-classroom.json، واجعل صلاحية قراءته لحساب الخادم وحده.',
    },
    en: {
      title: 'Create a JSON key and keep it outside the project',
      body: 'From the service account: Keys → Add key → Create new key → JSON. A file downloads. That file opens the school\'s entire domain: never put it inside the project folder or public/, and never send it in a chat or an email. Keep it in its own folder on the server — C:\\vis-secrets\\google-classroom.json, for instance — readable only by the account the server runs as.',
    },
  },
  {
    where: { ar: 'admin.google.com', en: 'admin.google.com' },
    ar: {
      title: 'امنح التفويض على مستوى النطاق',
      body: 'في console.cloud: افتح حساب الخدمة وفعّل Enable Google Workspace domain-wide delegation. ثم في لوحة الإدارة admin.google.com: Security ← Access and data control ← API controls ← Domain-wide delegation ← Add new. ضع Client ID الذي نسخته، وضع النطاقات الثلاثة التالية مفصولة بفواصل.',
      code: [
        'https://www.googleapis.com/auth/classroom.courses',
        'https://www.googleapis.com/auth/classroom.rosters',
        'https://www.googleapis.com/auth/classroom.profile.emails',
      ].join('\n'),
    },
    en: {
      title: 'Grant domain-wide delegation',
      body: 'In console.cloud: open the service account and switch on Enable Google Workspace domain-wide delegation. Then in admin.google.com: Security → Access and data control → API controls → Domain-wide delegation → Add new. Paste the Client ID you copied, and add these three scopes, comma separated.',
      code: [
        'https://www.googleapis.com/auth/classroom.courses',
        'https://www.googleapis.com/auth/classroom.rosters',
        'https://www.googleapis.com/auth/classroom.profile.emails',
      ].join('\n'),
    },
  },
  {
    where: { ar: 'admin.google.com', en: 'admin.google.com' },
    ar: {
      title: 'اختر الحساب الذي ينوب عنه النظام',
      body: 'حساب الخدمة لا يملك فصولاً في Classroom؛ لا بد أن ينوب عن شخص حقيقي على النطاق يكون هو مالك الفصول، مثل classroom@vis.edu.ly. يجب أن يكون هذا الحساب في مجموعة يُسمح لها بالتدريس داخل Classroom (Admin console ← Apps ← Google Workspace ← Classroom ← Teacher permissions).',
    },
    en: {
      title: 'Choose the account the system acts as',
      body: 'A service account cannot own Classroom courses; it has to act as a real person on the domain who owns them — classroom@vis.edu.ly, say. That account must sit in a group allowed to teach in Classroom (Admin console → Apps → Google Workspace → Classroom → Teacher permissions).',
    },
  },
  {
    where: { ar: 'ملف backend/.env', en: 'backend/.env' },
    ar: {
      title: 'اكتب الإعدادات في ملف .env',
      body: 'أضف هذه الأسطر إلى backend/.env مع تبديل القيم بقيم مدرستك، ثم شغّل php artisan config:clear ليقرأها الخادم. المسار هو مسار الملف الذي نزّلته في الخطوة الخامسة — لا تلصق محتوى الملف نفسه هنا.',
      code: [
        'GOOGLE_CLASSROOM_ENABLED=true',
        'GOOGLE_SERVICE_ACCOUNT_JSON="C:\\vis-secrets\\google-classroom.json"',
        'GOOGLE_WORKSPACE_DOMAIN=vis.edu.ly',
        'GOOGLE_IMPERSONATE_EMAIL=classroom@vis.edu.ly',
      ].join('\n'),
    },
    en: {
      title: 'Write the settings into .env',
      body: 'Add these lines to backend/.env with your school\'s values, then run php artisan config:clear so the server rereads them. The path is the path of the file you downloaded in step five — do not paste the file\'s contents here.',
      code: [
        'GOOGLE_CLASSROOM_ENABLED=true',
        'GOOGLE_SERVICE_ACCOUNT_JSON="C:\\vis-secrets\\google-classroom.json"',
        'GOOGLE_WORKSPACE_DOMAIN=vis.edu.ly',
        'GOOGLE_IMPERSONATE_EMAIL=classroom@vis.edu.ly',
      ].join('\n'),
    },
  },
  {
    where: { ar: 'هذه الصفحة', en: 'This page' },
    ar: {
      title: 'اربط بريد كل طالب وأستاذ',
      body: 'اختر الفصل من الأعلى، ثم اضغط «اقترح للفارغين» ليملأ العناوين من رقم القبول أو الاسم، راجعها وصحّح ما يلزم، ثم «حفظ العناوين». العنوان الذي لا ينتمي لنطاق المدرسة يُرفَض، والعنوان الواحد لا يُعطى لشخصين.',
    },
    en: {
      title: 'Link each student and teacher address',
      body: 'Pick the class above, press Suggest for the blanks to fill addresses from the admission number or the name, correct anything wrong, then Save addresses. An address outside the school domain is refused, and one address is never given to two people.',
    },
  },
  {
    where: { ar: 'هذه الصفحة', en: 'This page' },
    ar: {
      title: 'أنشئ الفصول وأضف الأستاذ والطلبة',
      body: 'لكل مادة: «إنشاء الفصل» ينشئ فصلاً واحداً في Classroom باسم المادة (وإعادة الضغط لا تُنشئ فصلاً ثانياً)، ثم «إضافة الأستاذ» يضيف أستاذ المادة المسجّل في المنظومة، ثم «إضافة الطلبة» يفتح قائمة تختار منها من تريد تسجيلهم.',
    },
    en: {
      title: 'Create the courses, then add the teacher and students',
      body: 'For each subject: Create course makes one Classroom course named after the subject — pressing it again never makes a second — then Add teacher adds the subject\'s teacher as recorded here, then Add students opens a list to tick whoever should be enrolled.',
    },
  },
  {
    where: { ar: 'ملاحظة', en: 'Note' },
    ar: {
      title: 'إن ظهرت الحالة «دُعي» بدل «أُضيف»',
      body: 'بعض إعدادات Workspace لا تسمح بإضافة الطالب مباشرة، فيرسل Google دعوة يقبلها الطالب من حسابه. هذا ليس خطأ: يبقى مسجّلاً كـ INVITED حتى يقبلها. للإضافة المباشرة اسمح لحساب النيابة بذلك من إعدادات Classroom في لوحة الإدارة.',
    },
    en: {
      title: 'If the state reads Invited rather than Added',
      body: 'Some Workspace configurations do not allow adding a student outright, so Google sends an invitation the student accepts from their own account. This is not a failure: they stay INVITED until they accept. To add directly, allow it for the impersonated account in the Classroom settings of the Admin console.',
    },
  },
]

const rendered = computed(() => steps.map((step, index) => ({
  number: index + 1,
  where: isArabic.value ? step.where.ar : step.where.en,
  danger: step.danger ?? false,
  ...(isArabic.value ? step.ar : step.en),
})))
</script>

<template>
  <article class="panel">
    <h3>{{ tr('كيف تربط بريد المدرسة بالمنظومة — خطوة بخطوة') }}</h3>
    <p class="muted">
      {{ tr('تُنفَّذ الخطوات مرة واحدة فقط. الخطوات من 1 إلى 8 في لوحات Google وملف الإعدادات، والخطوتان الأخيرتان في هذه الصفحة.') }}
    </p>

    <ol class="guide">
      <li v-for="step in rendered" :key="step.number" :class="{ danger: step.danger }">
        <div class="guide-head">
          <span class="guide-number">{{ step.number }}</span>
          <div>
            <strong>{{ step.title }}</strong>
            <span class="guide-where">{{ step.where }}</span>
          </div>
        </div>
        <p>{{ step.body }}</p>
        <pre v-if="step.code" dir="ltr">{{ step.code }}</pre>
      </li>
    </ol>
  </article>
</template>

<style scoped>
.guide {
  display: grid;
  gap: 0.85rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.guide li {
  border: 1px solid var(--app-border);
  border-radius: 0.9rem;
  padding: 0.9rem 1rem;
  background: var(--app-surface-muted);
}

/* The key file is the one step that can hand the school's domain to a stranger. */
.guide li.danger {
  border-color: var(--app-warn-border);
  background: var(--app-warn-soft);
}

.guide-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  margin-bottom: 0.4rem;
}

.guide-number {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.9rem;
  height: 1.9rem;
  flex: none;
  border-radius: 999px;
  background: var(--app-accent);
  color: #fff;
  font-weight: 700;
  font-size: 0.85rem;
}

.guide-where {
  display: block;
  font-size: 0.75rem;
  color: var(--app-text-muted);
}

.guide p {
  margin: 0;
  line-height: 1.75;
  color: var(--app-text);
}

.guide pre {
  margin: 0.6rem 0 0;
  padding: 0.7rem 0.85rem;
  border-radius: 0.6rem;
  background: var(--app-surface-raised);
  border: 1px solid var(--app-border);
  overflow-x: auto;
  font-size: 0.8rem;
  white-space: pre;
}
</style>
