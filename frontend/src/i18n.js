import { computed, ref } from 'vue'

/**
 * One language setting for the whole app. It lives here rather than in App.vue
 * so every component can read it and translate its own text — the shell used to
 * be the only translated part while the pages stayed hardcoded Arabic.
 */
export const language = ref(localStorage.getItem('ui_language') ?? 'ar')

export const isArabic = computed(() => language.value === 'ar')
export const direction = computed(() => (isArabic.value ? 'rtl' : 'ltr'))

/** Set by notify.js, so i18n does not have to depend on the toast library. */
let onLanguageChange = () => {}

export function setOnLanguageChange(handler) {
  onLanguageChange = handler
}

export function setLanguage(value) {
  language.value = value === 'en' ? 'en' : 'ar'
  localStorage.setItem('ui_language', language.value)
  document.documentElement.lang = language.value
  document.documentElement.dir = direction.value
  onLanguageChange()
}

/**
 * Shared vocabulary. Keys are grouped by area; a missing key falls back to the
 * other language and then to the key itself, so a gap shows up as readable text
 * rather than a blank.
 */
const dictionary = {
  ar: {
    // generic actions
    save: 'حفظ',
    saving: 'جاري الحفظ...',
    cancel: 'إلغاء',
    close: 'إغلاق',
    delete: 'حذف',
    edit: 'تعديل',
    add: 'إضافة',
    search: 'بحث',
    apply: 'تطبيق',
    back: 'رجوع',
    confirm: 'تأكيد',
    loading: 'جاري التحميل...',
    none: 'لا يوجد',
    yes: 'نعم',
    no: 'لا',
    all: 'الكل',
    actions: 'الإجراءات',
    status: 'الحالة',
    name: 'الاسم',
    amount: 'المبلغ',
    date: 'التاريخ',
    notes: 'ملاحظات',
    total: 'الإجمالي',
    download: 'تحميل',
    print: 'طباعة',
    continue: 'متابعة',
    skip: 'تخطي',

    // confirm dialog
    confirmTitle: 'تأكيد الإجراء',
    confirmDelete: 'تأكيد الحذف',
    confirmDeleteDefault: 'هل أنت متأكد من الحذف؟ لا يمكن التراجع عن هذا الإجراء.',
    irreversible: 'لا يمكن التراجع عن هذا الإجراء.',

    // outcomes
    savedSuccessfully: 'تم الحفظ بنجاح.',
    deletedSuccessfully: 'تم الحذف بنجاح.',
    addedSuccessfully: 'تمت الإضافة بنجاح.',
    updatedSuccessfully: 'تم التعديل بنجاح.',
    unexpectedError: 'حدث خطأ غير متوقع.',
    sessionExpired: 'انتهت جلستك. يرجى تسجيل الدخول من جديد.',
    loggedIn: 'تم تسجيل الدخول بنجاح.',
    loggedOut: 'تم تسجيل الخروج.',

    // theme
    darkMode: 'الوضع الليلي',
    lightMode: 'الوضع النهاري',

    // domain nouns used across pages
    student: 'الطالب',
    students: 'الطلبة',
    teacher: 'الأستاذ',
    teachers: 'الأساتذة',
    course: 'المادة',
    courses: 'المواد',
    class: 'الفصل',
    classes: 'الفصول',
    academicYear: 'السنة الدراسية',
    term: 'الفصل الدراسي',
    grade: 'الصف',
    finance: 'المالية',
    fee: 'الرسم',
    fees: 'الرسوم',
    payment: 'الدفعة',
    paymentMethod: 'طريقة الدفع',
    receipt: 'الإيصال',
    discount: 'الخصم',
    scheme: 'المخطط',
    category: 'الفئة',
    item: 'البند',
    weight: 'الوزن',
    maxScore: 'الدرجة القصوى',
    finalGrade: 'الدرجة النهائية',
    credits: 'الساعات المعتمدة',
    dueDate: 'تاريخ الاستحقاق',
    paid: 'المدفوع',
    outstanding: 'المتبقي',
    noData: 'لا توجد بيانات.',
    required: 'هذا الحقل مطلوب.',
  },

  en: {
    save: 'Save',
    saving: 'Saving...',
    cancel: 'Cancel',
    close: 'Close',
    delete: 'Delete',
    edit: 'Edit',
    add: 'Add',
    search: 'Search',
    apply: 'Apply',
    back: 'Back',
    confirm: 'Confirm',
    loading: 'Loading...',
    none: 'None',
    yes: 'Yes',
    no: 'No',
    all: 'All',
    actions: 'Actions',
    status: 'Status',
    name: 'Name',
    amount: 'Amount',
    date: 'Date',
    notes: 'Notes',
    total: 'Total',
    download: 'Download',
    print: 'Print',
    continue: 'Continue',
    skip: 'Skip',

    confirmTitle: 'Confirm action',
    confirmDelete: 'Confirm deletion',
    confirmDeleteDefault: 'Are you sure you want to delete this? This cannot be undone.',
    irreversible: 'This action cannot be undone.',

    savedSuccessfully: 'Saved successfully.',
    deletedSuccessfully: 'Deleted successfully.',
    addedSuccessfully: 'Added successfully.',
    updatedSuccessfully: 'Updated successfully.',
    unexpectedError: 'Something went wrong.',
    sessionExpired: 'Your session has expired. Please sign in again.',
    loggedIn: 'Logged in successfully.',
    loggedOut: 'Logged out.',

    darkMode: 'Dark mode',
    lightMode: 'Light mode',

    student: 'Student',
    students: 'Students',
    teacher: 'Teacher',
    teachers: 'Teachers',
    course: 'Course',
    courses: 'Courses',
    class: 'Class',
    classes: 'Classes',
    academicYear: 'Academic year',
    term: 'Term',
    grade: 'Grade',
    finance: 'Finance',
    fee: 'Fee',
    fees: 'Fees',
    payment: 'Payment',
    paymentMethod: 'Payment method',
    receipt: 'Receipt',
    discount: 'Discount',
    scheme: 'Scheme',
    category: 'Category',
    item: 'Item',
    weight: 'Weight',
    maxScore: 'Max score',
    finalGrade: 'Final grade',
    credits: 'Credits earned',
    dueDate: 'Due date',
    paid: 'Paid',
    outstanding: 'Outstanding',
    noData: 'No data.',
    required: 'This field is required.',
  },
}

/**
 * Look a key up in the current language. Values in `params` replace `{name}`
 * placeholders, which keeps word order natural in both languages instead of
 * gluing sentences together from fragments.
 */
export function t(key, params = {}) {
  const value = dictionary[language.value]?.[key]
    ?? dictionary[language.value === 'ar' ? 'en' : 'ar']?.[key]
    ?? key

  return String(value).replace(/\{(\w+)\}/g, (match, name) => (
    Object.hasOwn(params, name) ? String(params[name]) : match
  ))
}

/**
 * Pick between two ready-made strings without adding a dictionary key — for the
 * long one-off sentences that only appear in a single place.
 */
export function pick(arabic, english) {
  return isArabic.value ? arabic : english
}

export function useI18n() {
  return { t, pick, language, isArabic, direction, setLanguage }
}

// Apply the stored preference before the first paint.
document.documentElement.lang = language.value
document.documentElement.dir = direction.value
