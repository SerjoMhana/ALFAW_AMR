<script setup>
import { computed, ref, watch } from 'vue'
import { useApi } from './api.js'
import { notifyError, notifySuccess } from './notify.js'
import { isArabic, language, setLanguage, pick, t } from './i18n.js'
import { isDark, toggleTheme } from './theme.js'
import { confirmAction, confirmDelete } from './confirm.js'
import ConfirmDialog from './components/shared/ConfirmDialog.vue'
import TeacherGradeEntry from './components/grading/TeacherGradeEntry.vue'
import AdminGradeManagement from './components/grading/AdminGradeManagement.vue'
import GradingSettings from './components/grading/GradingSettings.vue'
import TeacherSubjectPicker from './components/teachers/TeacherSubjectPicker.vue'
import ReportCardDesigner from './components/grading/ReportCardDesigner.vue'
import GoogleClassroom from './components/grading/GoogleClassroom.vue'
import Classroom from './components/classroom/Classroom.vue'
import SchoolCalendar from './components/calendar/SchoolCalendar.vue'
import AttendanceRegister from './components/attendance/AttendanceRegister.vue'
import GradeSubmissionReport from './components/grading/GradeSubmissionReport.vue'
import TermWindows from './components/grading/TermWindows.vue'
import ParentDashboard from './components/parent/ParentDashboard.vue'
import StudentGradeCards from './components/shared/StudentGradeCards.vue'
import StudentPromotion from './components/students/StudentPromotion.vue'
import StudentAccounts from './components/finance/StudentAccounts.vue'
import DiscountApprovals from './components/finance/DiscountApprovals.vue'
import FinanceReports from './components/finance/FinanceReports.vue'
import FinanceSetup from './components/finance/FinanceSetup.vue'
import EnrolmentPayment from './components/finance/EnrolmentPayment.vue'
import CashAdvances from './components/finance/CashAdvances.vue'
import MultiSelect from './components/shared/MultiSelect.vue'
import { tr } from './phrases.js'
import {
  LayoutDashboard, GraduationCap, Users, School, BookOpen,
  ClipboardList, Wallet, Settings, UserCog, FileBarChart,
  LogOut, Moon, Sun, Languages, ChevronDown, MessagesSquare, CalendarDays,
} from 'lucide-vue-next'

const { apiBaseUrl, api, apiUpload, apiBlob } = useApi()

const credentials = ref({ email: '', password: '' })
const user = ref(null)
const loading = ref(false)
const activeTab = ref('home')
// Only one sidebar group stands open at a time, so opening a second one closes
// the first rather than stacking them.
const openMenu = ref('')

const students = ref([])
const users = ref([])
const teachers = ref([])
const permissions = ref([])
const courses = ref([])
const sections = ref([])
const academicYears = ref([])
const teacherClasses = ref([])
const teacherCourses = ref([])
const studentCourses = ref([])
const studentOpenTerms = ref([])
const studentPublications = ref([])
const gradeReport = ref(null)
const teacherSummary = ref([])
const gpaReport = ref(null)
const studentDashboard = ref(null)
const reportCard = ref(null)
const studentAttendance = ref([])
const lookups = ref({ teachers: [], students: [] })
const archivedFilter = ref('active')
const editingStudent = ref(null)
const editingTeacher = ref(null)
const subjectsModal = ref(null)
const enrolmentPayment = ref(null)
const studentsViewYear = ref('')
const selectedStudent = ref(null)
const expandedStudentGroups = ref([])
const courseModalSectionId = ref(null)
const editingCourse = ref(null)
const editingSection = ref(null)
const userDirectory = ref({ teachers: [], sections: [] })
const userSearch = ref('')
const userGroupOpen = ref({ teachers: false, students: false, parents: false, system: false })
const openStudentSectionId = ref(false)
const openParentSectionId = ref(false)
const credentialsModal = ref(null)
const userDetails = ref(null)
const newUserPermissions = ref([])
const permissionsModal = ref(null)
const importFile = ref(null)
const importFileInput = ref(null)
const importPreview = ref(null)
const studentSearch = ref('')
const studentRequiredErrors = ref({})
const parentRequiredErrors = ref({})
const siblingSearch = ref('')
const siblingResults = ref([])
const selectedSiblings = ref([])
const siblingLoading = ref(false)
const siblingSearchTimer = ref(null)
const useSiblingParent = ref(false)
const parentForm = ref({
  first_name: '',
  last_name: '',
  relation: 'father',
  mobile: '',
})
const studentFilters = ref({
  academic_year: '',
  grade: '',
  section_id: '',
  gender: '',
  nationality: '',
})
const studentPage = ref(1)
const studentPagination = ref(null)
const todayDate = () => new Date().toISOString().slice(0, 10)

const emptyStudentForm = () => ({
  user_name: '',
  user_email: '',
  user_password: '',
  student_code: '',
  admission_no: '',
  student_number: '',
  admission_date: todayDate(),
  grade_level: 'G10',
  current_grade_level: 10,
  academic_year: '',
  course: '',
  section_id: '',
  student_category: '',
  batch: '',
  first_name: '',
  middle_name: '',
  last_name: '',
  full_name: '',
  arabic_name: '',
  date_of_birth: '',
  gender: '',
  country: '',
  nationality: '',
  nationality_ar: '',
  national_id: '',
  birth_place: '',
  address_line_1: '',
  address_line_2: '',
  city: '',
  state: '',
  pin_code: '',
  phone: '',
  mobile: '',
  roll_number: '',
  biometric_id: '',
  guardian_name: '',
  guardian_phone: '',
  parent_first_name: '',
  parent_last_name: '',
  parent_full_name: '',
  parent_relation: '',
  parent_nationality: '',
  parent_username: '',
  parent_email: '',
  parent_mobile_phone: '',
  parent_occupation: '',
  second_parent_full_name: '',
  second_parent_relation: '',
  second_parent_nationality: '',
  second_parent_phone: '',
  second_parent_email: '',
})

const userForm = ref({
  name: '',
  email: '',
  password: '',
  user_type: 'staff',
  is_active: true,
})
const teacherForm = ref({
  name: '',
  phone: '',
  email: '',
  address: '',
  phone_2: '',
  academic_qualification: '',
  username: '',
  password: '',
  password_confirmation: '',
  is_active: true,
})
const studentForm = ref(emptyStudentForm())
const courseForm = ref({ class_section_ids: [] })
const courseRows = ref([{ code: '', name: '', periods_per_week: '', has_exam: true }])
const academicYearForm = ref({ name: '', is_active: true })
const reportSettings = ref({ semester_report_message: '', default_semester_report_message: '' })
const savingReportSettings = ref(false)
const sectionForm = ref({
  class_name: '',
  section_code: '',
  academic_year: '2026-2027',
  capacity: 25,
})
const reportTerm = ref('Quarter 1')
const reportSectionId = ref('')
const reportCardForm = ref({
  course_section_id: '',
  type: 'quarter',
  term: 'Quarter 1',
  semester: 1,
  student_profile_id: '',
})
const reportClassStudents = ref([])
const reportCardMissing = ref([])
const reportCardLoading = ref(false)
const classPublications = ref([])
const publishingReport = ref(false)
const pdfType = ref('quarter')

const tabs = [
  { key: 'home', label: 'Home', view: null },
  // Everyone gets the classroom: which subjects appear inside it is decided by
  // the server from what each person teaches or is enrolled in.
  { key: 'classroom', label: 'Classroom', everyone: true },
  // The calendar is published to the whole school, so everyone sees the tab;
  // only the office gets the buttons inside it.
  { key: 'calendar', label: 'School Calendar', everyone: true },
  { key: 'users', label: 'Users', view: 'users.view' },
  { key: 'student-list', label: 'Students', view: 'view_students' },
  { key: 'student-create', label: 'Add Student', view: 'create_students' },
  { key: 'student-archive', label: 'Archive', view: 'view_student_archive' },
  { key: 'teacher-list', label: 'Teachers', view: 'teachers.view' },
  { key: 'teacher-create', label: 'Add Teacher', view: 'teachers.manage' },
  { key: 'course-list', label: 'Courses', view: 'courses.view' },
  { key: 'course-create', label: 'Add Course', view: 'courses.manage' },
  { key: 'section-list', label: 'Classes', view: 'sections.view' },
  { key: 'section-create', label: 'Add Class', view: 'sections.manage' },
  { key: 'finance-accounts', label: 'Student Accounts', view: 'finance.view' },
  { key: 'finance-discounts', label: 'Discount Approvals', view: 'finance.discounts.approve' },
  { key: 'finance-reports', label: 'Financial Reports', view: 'finance.reports.view' },
  { key: 'finance-setup', label: 'Fee Setup', view: 'finance.fees.manage' },
  { key: 'finance-advances', label: 'Cash Advances', views: ['finance.advances.manage', 'finance.advances.settle', 'finance.view'] },
  { key: 'settings', label: 'General Settings', view: 'settings.view' },
  { key: 'student-promotion', label: 'Student Promotion', view: 'students.manage' },
  { key: 'google-classroom', label: 'Google Classroom', view: 'settings.manage' },
  { key: 'grade-entry', label: 'Grade Entry', role: 'teacher' },
  { key: 'admin-grade-management', label: 'Admin Grade Management', view: 'admin_manage_grades' },
  { key: 'grade-submission-report', label: 'Grade Submission Report', view: 'admin_manage_grades' },
  { key: 'term-windows', label: 'Open / Close Terms', view: 'admin_manage_grades' },
  { key: 'parent-dashboard', label: 'My Children', role: 'parent' },
  { key: 'grading-settings', label: 'Grading Settings', view: 'manage_grading_structure' },
  { key: 'report-card-designer', label: 'Report Card Designer', view: 'settings.view' },
  // The register is the office's, not the classroom's: it shows for whoever
  // holds the permission rather than for every teacher.
  { key: 'attendance', label: 'Attendance', views: ['attendance.view', 'attendance.manage'] },
  { key: 'student-report', label: 'Student Report', role: 'student' },
  { key: 'student-attendance', label: 'My Attendance', role: 'student' },
  { key: 'student-dashboard', label: 'Student Dashboard', role: 'student' },
  { key: 'report-card', label: 'Report Card', role: 'student' },
  { key: 'teacher-summary', label: 'Grade Summary', role: 'teacher' },
  { key: 'admin-grade-report', label: 'Admin Grade Report', view: 'students.view' },
]

const studentTabKeys = ['student-list', 'student-create', 'student-archive']
const teacherTabKeys = ['teacher-list', 'teacher-create']
const sectionTabKeys = ['section-list', 'section-create']
const courseTabKeys = ['course-list', 'course-create']
const gradesTabKeys = ['admin-grade-management', 'grade-submission-report', 'term-windows', 'grading-settings', 'report-card-designer']
const settingsTabKeys = ['settings', 'student-promotion', 'google-classroom']
const financeTabKeys = ['finance-accounts', 'finance-discounts', 'finance-reports', 'finance-setup', 'finance-advances']

const translations = {
  en: {
    appName: 'Vision International School',
    systemName: 'Student Management System',
    headline: 'Modern academic dashboard',
    summary: 'Manage students, teachers, courses, attendance, grades, GPA, and report cards from one clean workspace.',
    email: 'Email / Admission Number',
    password: 'Password',
    signIn: 'Login',
    signingIn: 'Signing in...',
    logout: 'Logout',
    currentArea: 'Current Area',
    active: 'Active',
    language: 'العربية',
    searchPlaceholder: 'Search students, teachers, courses...',
    noPages: 'This account has no administrative pages.',
    quickStats: 'Overview',
    signedInAs: 'Signed in as',
    home: {
      welcome: 'Welcome',
      overview: 'School overview',
      students: 'Students',
      teachers: 'Teachers',
      courses: 'Courses',
      classes: 'Classes',
      users: 'Users',
      activeYear: 'Active academic year',
      quickActions: 'Quick actions',
      addStudent: 'Add Student',
      addTeacher: 'Add Teacher',
      addCourse: 'Add Course',
      addClass: 'Add Class',
      reports: 'Reports',
      gradeReport: 'Grade Reports (PDF)',
      gradeManagement: 'Grade Management',
      settings: 'Settings',
      recentStudents: 'Latest students',
      noStudents: 'No students yet.',
    },
    tabs: {
      users: 'Users',
      home: 'Home',
      classroom: 'Classroom',
      calendar: 'School Calendar',
      studentsMenu: 'Students',
      teachersMenu: 'Teachers',
      sectionsMenu: 'Classes',
      coursesMenu: 'Courses',
      gradesMenu: 'Grade Management',
      settingsMenu: 'Settings',
      financeMenu: 'Finance',
      'finance-accounts': 'Student Accounts',
      'finance-discounts': 'Discount Approvals',
      'finance-reports': 'Financial Reports',
      'finance-setup': 'Fee Setup',
      'finance-advances': 'Cash Advances',
      'student-promotion': 'Student Promotion',
      'student-list': 'Student Data',
      'student-create': 'Add Students',
      'student-archive': 'Student Archive',
      'teacher-list': 'Teacher Data',
      'teacher-create': 'Add Teacher',
      'course-list': 'Course Data',
      'course-create': 'Add Course',
      'section-list': 'Class Data',
      'section-create': 'Add Class',
      settings: 'General Settings',
      'grade-entry': 'Grade Entry',
      'admin-grade-management': 'Admin Grade Management',
      'grade-submission-report': 'Grade Submission Report',
      'term-windows': 'Open / Close Terms',
      'parent-dashboard': 'My Children',
      'grading-settings': 'Grading Settings',
      'report-card-designer': 'Report Card Designer',
      'google-classroom': 'Google Classroom',
      attendance: 'Attendance Register',
      'student-report': 'Student Report',
      'student-attendance': 'My Attendance',
      'student-dashboard': 'Student Dashboard',
      'report-card': 'Report Card',
      'teacher-summary': 'Grade Summary',
      'admin-grade-report': 'Admin Grade Report',
    },
    roles: {
      Login: 'Login',
      'Staff Dashboard': 'Staff Dashboard',
      'Teacher Dashboard': 'Teacher Dashboard',
      'Student Dashboard': 'Student Dashboard',
      'Admin Dashboard': 'Admin Dashboard',
    },
  },
  ar: {
    appName: 'مدرسة ڤيجن الدولية',
    systemName: 'نظام إدارة الطلاب',
    headline: 'لوحة أكاديمية حديثة',
    summary: 'إدارة الطلاب، الأساتذة، المواد، الحضور، الدرجات، المعدل، والتقارير من مساحة واحدة واضحة.',
    email: 'البريد الإلكتروني / رقم القبول',
    password: 'كلمة المرور',
    signIn: 'تسجيل الدخول',
    signingIn: 'جاري تسجيل الدخول...',
    logout: 'تسجيل الخروج',
    currentArea: 'القسم الحالي',
    active: 'نشط',
    language: 'English',
    searchPlaceholder: 'ابحث عن طالب أو أستاذ أو مادة...',
    noPages: 'هذا الحساب لا يملك صفحات إدارية.',
    quickStats: 'نظرة عامة',
    signedInAs: 'مسجل باسم',
    home: {
      welcome: 'مرحباً',
      overview: 'نظرة عامة على المدرسة',
      students: 'الطلبة',
      teachers: 'الأساتذة',
      courses: 'المواد',
      classes: 'الفصول',
      users: 'المستخدمون',
      activeYear: 'السنة الدراسية الحالية',
      quickActions: 'إجراءات سريعة',
      addStudent: 'إضافة طالب',
      addTeacher: 'إضافة أستاذ',
      addCourse: 'إضافة مادة',
      addClass: 'إضافة فصل',
      reports: 'التقارير',
      gradeReport: 'تقارير الدرجات (PDF)',
      gradeManagement: 'إدارة الدرجات',
      settings: 'الإعدادات',
      recentStudents: 'أحدث الطلبة',
      noStudents: 'لا يوجد طلبة بعد.',
    },
    tabs: {
      users: 'المستخدمون',
      home: 'الرئيسية',
      classroom: 'الفصل الإلكتروني',
      calendar: 'تقويم المدرسة',
      studentsMenu: 'الطلبة',
      teachersMenu: 'الأساتذة',
      sectionsMenu: 'الفصول',
      coursesMenu: 'المواد',
      gradesMenu: 'إدارة الدرجات',
      settingsMenu: 'الإعدادات',
      financeMenu: 'المالية',
      'finance-accounts': 'حسابات الطلبة',
      'finance-discounts': 'اعتماد الخصومات',
      'finance-reports': 'التقارير المالية',
      'finance-setup': 'إعداد الرسوم',
      'finance-advances': 'العهد',
      'student-promotion': 'نقل الطلبة',
      'student-list': 'بيانات الطلبة',
      'student-create': 'إضافة طلبة',
      'student-archive': 'أرشيف الطلبة',
      'teacher-list': 'بيانات الأساتذة',
      'teacher-create': 'إضافة أستاذ',
      'course-list': 'بيانات المواد',
      'course-create': 'إضافة مواد',
      'section-list': 'بيانات الفصل',
      'section-create': 'إضافة فصل',
      settings: 'إعدادات عامة',
      'grade-entry': 'إدخال الدرجات',
      'admin-grade-management': 'إدارة الدرجات (الأدمن)',
      'grade-submission-report': 'تقرير إدخال الدرجات',
      'term-windows': 'فتح وإغلاق الفصول',
      'parent-dashboard': 'أبنائي',
      'grading-settings': 'إعدادات نظام الدرجات',
      'report-card-designer': 'تصميم كشف الدرجات',
      'google-classroom': 'Google Classroom',
      attendance: 'الحضور والغياب',
      'student-report': 'تقرير الطالب',
      'student-attendance': 'حضوري',
      'student-dashboard': 'لوحة الطالب',
      'report-card': 'كشف الدرجات',
      'teacher-summary': 'ملخص الدرجات',
      'admin-grade-report': 'تقرير درجات الأدمن',
    },
    roles: {
      Login: 'تسجيل الدخول',
      'Staff Dashboard': 'لوحة الموظف',
      'Teacher Dashboard': 'لوحة الأستاذ',
      'Student Dashboard': 'لوحة الطالب',
      'Admin Dashboard': 'لوحة الأدمن',
    },
  },
}

const ui = computed(() => translations[language.value])
const visibleTabs = computed(() => tabs.filter((tab) => {
  if (tab.key === 'home' || tab.everyone) return true
  // `views` lists alternatives: holding any one of them opens the page.
  if (tab.views) return tab.views.some((permission) => can(permission))
  return !tab.role ? can(tab.view) : user.value?.user_type === tab.role
}))
const studentTabs = computed(() => visibleTabs.value.filter((tab) => studentTabKeys.includes(tab.key)))
const teacherTabs = computed(() => visibleTabs.value.filter((tab) => teacherTabKeys.includes(tab.key)))
const sectionTabs = computed(() => visibleTabs.value.filter((tab) => sectionTabKeys.includes(tab.key)))
const courseTabs = computed(() => visibleTabs.value.filter((tab) => courseTabKeys.includes(tab.key)))
const gradesTabs = computed(() => visibleTabs.value.filter((tab) => gradesTabKeys.includes(tab.key)))
const settingsTabs = computed(() => visibleTabs.value.filter((tab) => settingsTabKeys.includes(tab.key)))
const financeTabs = computed(() => visibleTabs.value.filter((tab) => financeTabKeys.includes(tab.key)))
const sidebarTabs = computed(() => visibleTabs.value.filter((tab) =>
  tab.key !== 'home'
  // Rendered beside Home rather than in the leftovers: these are where teachers
  // and students spend their day.
  && tab.key !== 'classroom'
  && tab.key !== 'calendar'
  && !studentTabKeys.includes(tab.key)
  && !teacherTabKeys.includes(tab.key)
  && !sectionTabKeys.includes(tab.key)
  && !courseTabKeys.includes(tab.key)
  && !gradesTabKeys.includes(tab.key)
  && !settingsTabKeys.includes(tab.key)
  && !financeTabKeys.includes(tab.key),
))
const currentTabLabel = computed(() => ui.value.tabs[activeTab.value] ?? visibleTabs.value.find((tab) => tab.key === activeTab.value)?.label ?? '')
const dashboardTitle = computed(() => ui.value.roles[roleHome.value] ?? roleHome.value)
const academicYearOptions = computed(() => [
  ...new Set([
    ...academicYears.value.filter((year) => year.is_active).map((year) => year.name),
    ...sections.value.map((section) => section.academic_year).filter(Boolean),
  ]),
])
const studentClassOptions = computed(() => {
  if (!studentForm.value.academic_year) return []
  const seen = new Set()

  return sections.value
    .filter((section) => section.class_name && section.academic_year === studentForm.value.academic_year)
    .filter((section) => (seen.has(section.class_name) ? false : (seen.add(section.class_name), true)))
    .map((section) => ({ value: section.class_name, label: section.class_name }))
})

watch(() => studentForm.value.academic_year, () => {
  if (studentForm.value.course && !studentClassOptions.value.some((option) => option.value === studentForm.value.course)) {
    studentForm.value.course = ''
  }
})
const sectionOptions = computed(() => sections.value.map((section) => ({
  value: section.id,
  label: section.class_name || section.section_code,
  hint: section.academic_year,
})))
const courseGroups = computed(() => sections.value.map((section) => ({
  ...section,
  materials: courses.value.filter(
    (course) => Number(course.class_section_id) === Number(section.id),
  ),
})))
const courseModalGroup = computed(() => courseGroups.value.find(
  (group) => Number(group.id) === Number(courseModalSectionId.value),
) ?? null)
/*
 * Subjects with no class of their own.
 *
 * The list above is grouped by class, so an unattached subject would otherwise
 * be invisible here — and invisible to its students too, because a student
 * reaches a subject through their class.
 */
const orphanCourses = computed(() => courses.value.filter((course) => !course.class_section_id))
const studentsTotal = computed(() => studentPagination.value?.total ?? students.value.length)
const studentLastPage = computed(() => studentPagination.value?.last_page ?? 1)
const activeAcademicYear = computed(() => academicYears.value.find((year) => year.is_active)?.name
  ?? academicYearOptions.value[0]
  ?? '—')
const recentStudents = computed(() => students.value.slice(0, 5))
// Years come back newest first, so anything other than the first one is history.
const latestAcademicYear = computed(() => academicYears.value[0]?.name ?? '')
// The school as a whole is parked in an earlier year.
const viewingPastYear = computed(() => Boolean(
  latestAcademicYear.value && activeAcademicYear.value !== latestAcademicYear.value,
))
// The student list can also be pointed at a single year on its own.
const studentListPastYear = computed(() => Boolean(
  studentsViewYear.value && latestAcademicYear.value && studentsViewYear.value !== latestAcademicYear.value,
))
const userSearchTerm = computed(() => userSearch.value.trim().toLowerCase())

function matchesUserSearch(...values) {
  if (!userSearchTerm.value) return true
  return values.some((value) => String(value ?? '').toLowerCase().includes(userSearchTerm.value))
}

const filteredTeachers = computed(() => (userDirectory.value.teachers ?? [])
  .filter((teacher) => matchesUserSearch(teacher.name, teacher.username)))
const filteredStudentSections = computed(() => (userDirectory.value.sections ?? [])
  .map((section) => ({ ...section, students: section.students.filter((student) => matchesUserSearch(student.name, student.username)) }))
  .filter((section) => (userSearchTerm.value ? section.students.length : true)))
const filteredParentSections = computed(() => (userDirectory.value.sections ?? [])
  .map((section) => ({ ...section, parents: section.parents.filter((parent) => matchesUserSearch(parent.full_name, parent.username)) }))
  .filter((section) => (userSearchTerm.value ? section.parents.length : true)))
const systemUsers = computed(() => users.value
  .filter((managedUser) => ['admin', 'staff'].includes(managedUser.user_type))
  .filter((managedUser) => matchesUserSearch(managedUser.name, managedUser.username)))
const nextStudentNumber = computed(() => {
  const maxNumber = students.value
    .map((student) => Number(String(student.admission_no ?? '').replace(/\D+/g, '')))
    .filter(Boolean)
    .reduce((max, number) => Math.max(max, number), 0)

  return `S${String(maxNumber + 1).padStart(5, '0')}`
})
const displayedStudentNumber = computed(() => studentForm.value.admission_no || nextStudentNumber.value)
const parentAdmissionNo = computed(() => displayedStudentNumber.value ? displayedStudentNumber.value.replace(/^S/i, 'P') : '')
const sectionCodePreview = computed(() => {
  const grade = sectionForm.value.class_name || 'CLS'
  const year = sectionForm.value.academic_year ? sectionForm.value.academic_year.replace(/\D+/g, '').slice(2) : '0000'
  const sequence = String(
    sections.value.filter((section) => section.academic_year === sectionForm.value.academic_year).length + 1,
  ).padStart(2, '0')

  return `${grade}-${year}-${sequence}`.toUpperCase()
})
const requiredStudentFields = [
  ['admission_no', 'رقم الطالب', 'text'],
  ['admission_date', 'تاريخ القبول', 'date'],
  ['first_name', 'الاسم الأول', 'text'],
  ['middle_name', 'اسم الأب', 'text'],
  ['last_name', 'اسم العائلة', 'text'],
  ['date_of_birth', 'تاريخ الميلاد', 'date'],
  ['gender', 'الجنس', 'select'],
  ['academic_year', 'السنة الدراسية', 'academic_year'],
  ['course', 'الصف الدراسي', 'grade'],
]
const groupedStudents = computed(() => {
  const groups = new Map()

  students.value.forEach((student) => {
    const key = student.section?.section_code || student.batch || student.academic_year || 'بدون فصل'
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        label: key,
        academic_year: student.academic_year || student.section?.academic_year || '',
        students: [],
      })
    }
    groups.get(key).students.push(student)
  })

  return [...groups.values()]
})
const studentFieldGroups = [
  {
    title: 'بيانات إضافية للطالب',
    fields: [
      ['user_email', 'البريد الإلكتروني', 'email'],
      ['religion', 'الديانة', 'text'],
      ['mother_tongue', 'اللغة الأم', 'text'],
      ['blood_group', 'فصيلة الدم', 'text'],
      ['phone', 'هاتف نقال آخر', 'text'],
    ],
  },
  {
    title: 'الهوية والجنسية',
    fields: [
      ['national_id', 'الرقم الوطني', 'text'],
      ['country', 'الدولة', 'text'],
      ['nationality', 'الجنسية', 'text'],
      ['birth_place', 'مكان الميلاد', 'text'],
    ],
  },
  {
    title: 'التواصل والعنوان',
    fields: [
      ['address_line_1', 'العنوان 1', 'text'],
      ['address_line_2', 'العنوان 2', 'text'],
      ['city', 'المدينة', 'text'],
      ['state', 'الولاية / المنطقة', 'text'],
      ['pin_code', 'الرمز البريدي', 'text'],
      ['mobile', 'الموبايل', 'text'],
    ],
  },
  {
    title: 'ولي أمر آخر',
    fields: [
      ['second_parent_full_name', 'اسم ولي الأمر الآخر', 'text'],
      ['second_parent_relation', 'صلة القرابة', 'text'],
      ['second_parent_nationality', 'الجنسية', 'text'],
      ['second_parent_phone', 'رقم الهاتف', 'text'],
      ['second_parent_email', 'البريد الإلكتروني', 'email'],
    ],
  },
]
const roleHome = computed(() => {
  if (!user.value) return 'Login'
  if (user.value.user_type === 'staff') return 'Staff Dashboard'
  if (user.value.user_type === 'teacher') return 'Teacher Dashboard'
  if (user.value.user_type === 'student') return 'Student Dashboard'
  return 'Admin Dashboard'
})

function can(permission) {
  if (!user.value) return false
  return user.value.user_type === 'admin' || user.value.permissions.includes('*') || user.value.permissions.includes(permission)
}

async function login() {
  loading.value = true

  try {
    // The reply carries no credential: the session arrives as an HttpOnly
    // cookie the browser stores and this code can never read.
    const data = await api('/login', {
      method: 'POST',
      body: JSON.stringify(credentials.value),
    })
    user.value = data.user
    // A new session starts at the dashboard with the sidebar collapsed, rather
    // than wherever the previous session happened to leave off.
    activeTab.value = 'home'
    openMenu.value = ''
    notifySuccess(pick('تم تسجيل الدخول بنجاح.', 'Logged in successfully.'))
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

/**
 * On a reload there is nothing in storage to inspect, so ask the server whether
 * the cookie it holds still identifies someone.
 */
async function loadCurrentUser() {
  try {
    const data = await api('/user')
    user.value = data.user
    await loadAcademicData()
  } catch {
    user.value = null
  }
}

async function loadAcademicData() {
  if (!user.value) return
  await Promise.all([
    loadIfAllowed('users.view', '/users', users),
    loadIfAllowed('users.view', '/users/directory', userDirectory),
    loadIfAllowed('teachers.view', '/teachers', teachers),
    loadStudents(),
    loadIfAllowed('courses.view', '/courses', courses),
    loadIfAllowed('sections.view', '/course-sections', sections),
    loadIfAllowed('settings.view', '/academic-years', academicYears),
    loadTeacherClasses(),
    loadTeacherCourses(),
    loadStudentCourses(),
    loadStudentTerms(),
    loadStudentPublications(),
    loadReportSettings(),
    loadPermissions(),
    loadLookups(),
  ])
  // 'home' is not listed in visibleTabs but is always reachable, so only
  // redirect when the current tab is genuinely unavailable to this role.
  if (activeTab.value !== 'home' && !visibleTabs.value.some((tab) => tab.key === activeTab.value)) {
    activeTab.value = visibleTabs.value[0]?.key ?? 'home'
  }
}

async function loadIfAllowed(permission, path, target) {
  if (!can(permission)) return
  const data = await api(path)
  target.value = data.data
}

async function loadStudents() {
  if (!can('view_students') && !can('students.view')) return
  const params = new URLSearchParams({
    archived: archivedFilter.value,
    per_page: '15',
    page: String(studentPage.value),
  })
  if (studentSearch.value) params.set('search', studentSearch.value)
  Object.entries(studentFilters.value).forEach(([key, value]) => {
    if (value) params.set(key, value)
  })
  const data = await api(`/students?${params.toString()}`)
  students.value = data.data ?? []
  studentPagination.value = data.total !== undefined ? data : null
  // The backend decides which year the list is showing when we do not ask for
  // one, so read it back rather than guessing.
  studentsViewYear.value = data.academic_year ?? ''
}

async function loadLookups() {
  if (!can('users.view')) return
  lookups.value = await api('/academic/lookups/users')
}

// Still needed by the permissions modal on the Users page.
async function loadPermissions() {
  if (!can('staff.permissions.manage')) return
  permissions.value = (await api('/permissions')).data
}

async function loadTeacherClasses() {
  if (user.value?.user_type !== 'teacher') return
  const data = await api('/teacher/classes')
  teacherClasses.value = data.data
}

async function loadTeacherCourses() {
  if (user.value?.user_type !== 'teacher') return
  const data = await api('/teacher/courses')
  teacherCourses.value = data.data
}

async function loadStudentCourses() {
  if (user.value?.user_type !== 'student') return
  const data = await api('/student/courses')
  studentCourses.value = data.data
}

// The student may only pick quarters the admin has opened.
async function loadStudentTerms() {
  if (user.value?.user_type !== 'student') return
  const data = await api('/student/terms')
  studentOpenTerms.value = data.data
  if (!studentOpenTerms.value.includes(reportTerm.value)) {
    reportTerm.value = studentOpenTerms.value[0] ?? ''
  }
}

async function loadStudentPublications() {
  if (user.value?.user_type !== 'student') return
  const data = await api('/student/report-cards')
  studentPublications.value = data.data
}

async function downloadPublishedReportCard(publication) {

  try {
    const blob = await apiBlob(`/student/report-cards/${publication.id}/pdf`)
    window.open(URL.createObjectURL(blob), '_blank')
  } catch (err) {
    notifyError(err.message)
  }
}

async function logout() {
  // The server clears the session cookie; there is nothing stored locally.
  await api('/logout', { method: 'POST' }).catch(() => {})
  user.value = null
  activeTab.value = 'home'
  openMenu.value = ''
  notifySuccess(pick('تم تسجيل الخروج.', 'Logged out.'))
}

async function submitStudent() {
  if (!validateRequiredStudentFields()) {
    notifyError(pick('يرجى تعبئة جميع الحقول المطلوبة قبل الحفظ.', 'Please fill all required fields before saving.'))
    return
  }
  if (!validateParentFields()) {
    notifyError(pick('اسم ولي الأمر الأول وصلة القرابة مطلوبان.', 'Parent first name and relation are required.'))
    return
  }

  const payload = studentPayload(studentForm.value)
  const academicYear = studentForm.value.academic_year

  try {
    const created = await api('/students', { method: 'POST', body: JSON.stringify(payload) })
    notifySuccess(pick('تم حفظ بيانات الطالب.', 'Student saved.'))

    studentForm.value = emptyStudentForm()
    studentRequiredErrors.value = {}
    resetSiblingAndParentFlow()
    await loadAcademicData()

    // Offer to take the enrolment payment straight away; whoever cannot record
    // payments simply lands on the student list as before.
    if (can('finance.payments.record')) {
      enrolmentPayment.value = {
        studentId: created.data.id,
        studentName: created.data.full_name || created.data.user?.name || '',
        academicYear: academicYear || activeAcademicYear.value,
      }
    } else {
      activeTab.value = 'student-list'
    }
  } catch (err) {
    notifyError(err.message)
  }
}

function finishEnrolmentPayment() {
  enrolmentPayment.value = null
  activeTab.value = 'student-list'
}

function validateRequiredStudentFields() {
  const required = ['admission_date', 'first_name', 'date_of_birth', 'gender', 'course', 'academic_year']
  studentRequiredErrors.value = Object.fromEntries(
    required
      .filter((field) => !String(studentForm.value[field] ?? '').trim())
      .map((field) => [field, true]),
  )

  return Object.keys(studentRequiredErrors.value).length === 0
}

function validateParentFields() {
  const required = ['first_name', 'relation']
  parentRequiredErrors.value = Object.fromEntries(
    required
      .filter((field) => !String(parentForm.value[field] ?? '').trim())
      .map((field) => [field, true]),
  )

  return Object.keys(parentRequiredErrors.value).length === 0
}

function resetSiblingAndParentFlow() {
  parentRequiredErrors.value = {}
  parentForm.value = {
    first_name: '',
    last_name: '',
    relation: 'father',
    mobile: '',
  }
  siblingSearch.value = ''
  siblingResults.value = []
  selectedSiblings.value = []
  useSiblingParent.value = false
}

function searchSiblingsDebounced() {
  if (siblingSearchTimer.value) clearTimeout(siblingSearchTimer.value)
  siblingSearchTimer.value = setTimeout(searchSiblings, 350)
}

async function searchSiblings() {
  const q = siblingSearch.value.trim()
  if (q.length < 2) {
    siblingResults.value = []
    return
  }

  siblingLoading.value = true
  try {
    const params = new URLSearchParams({ q })
    const data = await api(`/students/search-siblings?${params.toString()}`)
    siblingResults.value = (data.data ?? []).filter(
      (student) => !selectedSiblings.value.some((selected) => selected.id === student.id),
    )
  } catch (err) {
    notifyError(err.message)
  } finally {
    siblingLoading.value = false
  }
}

function selectSibling(student) {
  if (!selectedSiblings.value.some((selected) => selected.id === student.id)) {
    selectedSiblings.value.push(student)
  }
  siblingResults.value = siblingResults.value.filter((result) => result.id !== student.id)

  if (useSiblingParent.value && student.parent) {
    fillParentFromSibling(student)
  }
}

function removeSibling(studentId) {
  selectedSiblings.value = selectedSiblings.value.filter((student) => student.id !== studentId)
  if (!selectedSiblings.value.length) useSiblingParent.value = false
}

function toggleSiblingParentReuse() {
  if (useSiblingParent.value) {
    const siblingWithParent = selectedSiblings.value.find((student) => student.parent)
    if (siblingWithParent) fillParentFromSibling(siblingWithParent)
  }
}

function fillParentFromSibling(student) {
  if (!student.parent) return
  parentForm.value = {
    first_name: student.parent.first_name ?? '',
    last_name: student.parent.last_name ?? '',
    relation: student.parent.relation ?? 'father',
    mobile: student.parent.mobile ?? '',
  }
}

function studentPayload(source) {
  const fullName = source.full_name || [source.first_name, source.middle_name, source.last_name].filter(Boolean).join(' ')
  const parentUsername = parentAdmissionNo.value || source.parent_username || source.parent_mobile_phone || source.guardian_phone
  const generatedStudentNumber = source.admission_no || undefined
  const generatedBatch = [source.course, source.academic_year].filter(Boolean).join(' ')
  return {
    ...source,
    full_name: fullName,
    user_name: source.user_name || fullName,
    user_password: generatedStudentNumber ? `${generatedStudentNumber}123` : undefined,
    student_number: generatedStudentNumber,
    admission_no: generatedStudentNumber,
    student_code: source.student_code || generatedStudentNumber,
    admission_date: source.admission_date || new Date().toISOString().slice(0, 10),
    batch: generatedBatch || source.batch,
    grade_level: source.course,
    parent_username: parentUsername,
    sibling_ids: selectedSiblings.value.map((student) => student.id),
    use_sibling_parent: useSiblingParent.value,
    parent: {
      parent_admission_no: parentAdmissionNo.value,
      first_name: parentForm.value.first_name,
      last_name: parentForm.value.last_name,
      relation: parentForm.value.relation,
      mobile: parentForm.value.mobile,
    },
  }
}

function parentPasswordFor(source) {
  const username = source?.parent_username || source?.parent_mobile_phone || source?.guardian_phone
  return username ? `${username}123` : ''
}

function startEditStudent(student) {
  editingStudent.value = {
    ...emptyStudentForm(),
    ...student,
    user_name: student.user?.name ?? '',
    user_email: student.user?.email ?? '',
    admission_date: student.admission_date ?? '',
    date_of_birth: student.date_of_birth ?? '',
  }
  activeTab.value = 'student-list'
}

async function updateStudent() {
  if (!editingStudent.value) return
  await submit(`/students/${editingStudent.value.id}`, studentPayload(editingStudent.value), async () => {
    editingStudent.value = null
    await loadStudents()
  }, 'PUT')
}

async function archiveStudent(student) {
  await submit(`/students/${student.id}/archive`, { archive_reason: 'Archived from dashboard' }, loadStudents)
}

async function restoreStudent(student) {
  await submit(`/students/${student.id}/restore`, {}, loadStudents)
}

async function deleteStudentPermanently(student) {
  const name = student.full_name || student.user?.name || student.student_number
  if (!await confirmDelete(pick(`سيتم حذف الطالب "${name}" وجميع بياناته نهائياً.`, `Student "${name}" and all of their data will be permanently deleted.`))) return
  try {
    await api(`/students/${student.id}`, { method: 'DELETE' })
    notifySuccess(pick('تم حذف الطالب نهائياً.', 'Student permanently deleted.'))
    await loadStudents()
  } catch (err) {
    notifyError(err.message)
  }
}

function setImportFile(event) {
  importFile.value = event.target.files?.[0] ?? null
  importPreview.value = null
  if (importFile.value) {
    previewStudentImport()
  }
}

function openImportPicker() {
  importFileInput.value?.click()
}

async function importStudents() {
  if (!importFile.value) return

  const formData = new FormData()
  formData.append('file', importFile.value)

  try {
    const data = await apiUpload('/students/import/confirm', formData)
    notifySuccess(`Import complete. Created: ${data.created}, Updated: ${data.updated}, Skipped: ${data.skipped}.`)
    await loadStudents()
  } catch (err) {
    notifyError(err.message)
  }
}

async function previewStudentImport() {
  if (!importFile.value) return

  const formData = new FormData()
  formData.append('file', importFile.value)

  try {
    importPreview.value = await apiUpload('/students/import/preview', formData)
  } catch (err) {
    notifyError(err.message)
  }
}

async function submitUser() {
  try {
    const created = await api('/users', { method: 'POST', body: JSON.stringify(userForm.value) })
    if (userForm.value.user_type === 'staff' && newUserPermissions.value.length && can('staff.permissions.manage')) {
      await api(`/staff-permissions/${created.data.id}`, {
        method: 'PUT',
        body: JSON.stringify({ permissions: newUserPermissions.value }),
      })
    }
    notifySuccess(pick('تم إنشاء المستخدم بنجاح.', 'User created.'))
    userForm.value = {
      name: '',
      email: '',
      password: '',
      user_type: 'staff',
      is_active: true,
    }
    newUserPermissions.value = []
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  }
}

function openCredentials(kind, entry) {
  credentialsModal.value = {
    kind,
    id: kind === 'parent' ? entry.id : (entry.user_id ?? entry.id),
    name: entry.name ?? entry.full_name,
    username: entry.username ?? '',
    password: '',
  }
}

async function saveCredentials() {
  const modal = credentialsModal.value
  if (!modal) return
  if (modal.kind === 'parent' && !modal.password) {
    notifyError(pick('كلمة المرور مطلوبة لإنشاء أو تحديث حساب ولي الأمر.', 'A password is required to create or update the guardian account.'))
    return
  }
  if (modal.password && modal.password.length < 8) {
    notifyError(pick('كلمة المرور يجب أن تكون 8 أحرف على الأقل.', 'The password must be at least 8 characters.'))
    return
  }
  const payload = {}
  if (modal.username) payload.username = modal.username
  if (modal.password) payload.password = modal.password
  const path = modal.kind === 'parent' ? `/parents/${modal.id}/credentials` : `/users/${modal.id}`
  await submit(path, payload, async () => {
    credentialsModal.value = null
    await loadAcademicData()
  }, 'PUT')
}

function showUserStudentDetails(student, section) {
  userDetails.value = {
    title: student.name,
    rows: [
      ['رقم الطالب', student.admission_no],
      ['اسم المستخدم', student.username],
      ['البريد الإلكتروني', student.email],
      ['الفصل', section.class_name],
      ['السنة الدراسية', student.academic_year || section.academic_year],
      ['الجنس', student.gender === 'male' ? 'ذكر' : student.gender === 'female' ? 'أنثى' : student.gender],
      ['تاريخ الميلاد', student.date_of_birth],
      ['الموبايل', student.mobile],
      ['الجنسية', student.nationality],
      ['ولي الأمر', student.parent_name],
      ['هاتف ولي الأمر', student.parent_mobile],
    ],
  }
}

const permissionLabelsAr = {
  'users.view': 'عرض المستخدمين',
  'users.manage': 'إدارة المستخدمين',
  'students.view': 'عرض الطلبة',
  'students.manage': 'إدارة الطلبة',
  view_students: 'عرض بيانات الطلبة',
  create_students: 'إضافة طلبة',
  edit_students: 'تعديل الطلبة',
  archive_students: 'أرشفة الطلبة',
  restore_students: 'استرجاع الطلبة',
  delete_students: 'حذف الطلبة نهائياً',
  import_students: 'استيراد الطلبة',
  view_student_archive: 'عرض أرشيف الطلبة',
  'teachers.view': 'عرض الأساتذة',
  'teachers.manage': 'إدارة الأساتذة',
  'courses.view': 'عرض المواد',
  'courses.manage': 'إدارة المواد',
  'sections.view': 'عرض الفصول',
  'sections.manage': 'إدارة الفصول',
  'enrollments.manage': 'إدارة التسجيل',
  'attendance.view': 'عرض الحضور',
  'attendance.manage': 'إدارة الحضور',
  'settings.view': 'عرض الإعدادات',
  'settings.manage': 'إدارة الإعدادات',
  'staff.permissions.manage': 'إدارة صلاحيات الموظفين',
  view_grades: 'عرض الدرجات',
  enter_grades: 'إدخال الدرجات',
  edit_grades: 'تعديل الدرجات',
  admin_manage_grades: 'إدارة درجات جميع الفصول',
  manage_grading_structure: 'إدارة نظام الدرجات',
  import_grading_structure: 'استيراد نظام الدرجات',
  view_grade_audit_logs: 'عرض سجل تدقيق الدرجات',
  'finance.view': 'عرض المالية',
  'finance.fees.manage': 'إدارة الرسوم وخطط التقسيط وقواعد الخصم',
  'finance.discounts.request': 'طلب خصم',
  'finance.discounts.approve': 'اعتماد الخصم',
  'finance.payments.record': 'تسجيل الدفعات',
  'finance.payments.void': 'إلغاء إيصال',
  'finance.reports.view': 'عرض التقارير المالية',
}

function permissionLabel(permission) {
  if (isArabic.value) {
    return permissionLabelsAr[permission.name] ?? permission.label ?? permission.name
  }
  return permission.label ?? permission.name
}

function openPermissionsModal(managedUser) {
  permissionsModal.value = {
    id: managedUser.id,
    name: managedUser.name,
    selected: (managedUser.permissions ?? []).map((permission) => permission.name),
  }
}

async function savePermissionsModal() {
  const modal = permissionsModal.value
  if (!modal) return
  await submit(`/staff-permissions/${modal.id}`, { permissions: modal.selected }, async () => {
    permissionsModal.value = null
    await loadAcademicData()
  }, 'PUT')
}

function showUserParentDetails(parent) {
  const relations = { father: 'الأب', mother: 'الأم', other: 'أخرى' }
  userDetails.value = {
    title: parent.full_name,
    rows: [
      ['اسم المستخدم', parent.username],
      ['صلة القرابة', relations[parent.relation] ?? parent.relation],
      ['الموبايل', parent.mobile],
      ['الأبناء', (parent.children ?? []).join('، ')],
      ['حساب الدخول', parent.has_account ? 'مفعّل' : 'غير مفعّل بعد (حدد كلمة مرور لتفعيله)'],
    ],
  }
}

async function submitTeacher() {
  if (teacherForm.value.password !== teacherForm.value.password_confirmation) {
    notifyError(pick('كلمة المرور وتأكيد كلمة المرور غير متطابقين.', 'The password and its confirmation do not match.'))
    return
  }

  await submit('/teachers', {
    name: teacherForm.value.name,
    phone: teacherForm.value.phone,
    email: teacherForm.value.email,
    address: teacherForm.value.address,
    phone_2: teacherForm.value.phone_2,
    academic_qualification: teacherForm.value.academic_qualification,
    username: teacherForm.value.username,
    password: teacherForm.value.password,
    user_type: 'teacher',
    is_active: teacherForm.value.is_active,
  }, async () => {
    teacherForm.value = {
      name: '',
      phone: '',
      email: '',
      address: '',
      phone_2: '',
      academic_qualification: '',
      username: '',
      password: '',
      password_confirmation: '',
      is_active: true,
    }
    await loadAcademicData()
    activeTab.value = 'teacher-list'
  })
}

function openSubjectsModal(teacher) {
  subjectsModal.value = {
    id: teacher.user_id ?? teacher.id,
    name: teacher.name,
    selected: [],
  }
}

async function saveSubjectsModal() {
  const modal = subjectsModal.value
  if (!modal) return

  await submit(`/teachers/${modal.id}/courses`, { course_ids: modal.selected }, async () => {
    subjectsModal.value = null
    await loadAcademicData()
  }, 'PUT')
}

async function submitCourse() {
  const chosen = courseForm.value.class_section_ids

  if (!chosen.length) {
    notifyError(pick('يرجى اختيار فصل دراسي واحد على الأقل.', 'Choose at least one class.'))
    return
  }

  const materials = courseRows.value
    .map((course) => ({
      code: course.code.trim().toUpperCase(),
      name: course.name.trim(),
      periods_per_week: course.periods_per_week ? Number(course.periods_per_week) : null,
      has_exam: course.has_exam !== false,
    }))
    .filter((course) => course.code || course.name)

  if (!materials.length || materials.some((course) => !course.code || !course.name)) {
    notifyError(pick('يرجى تعبئة كود واسم كل مادة قبل الحفظ.', 'Fill in a code and a name for every subject before saving.'))
    return
  }

  // The same list goes into every class chosen — grades usually share a
  // syllabus, and typing it out per grade is the thing to avoid.
  await submit('/courses/bulk', {
    class_section_ids: chosen,
    courses: materials,
  }, async () => {
    courseForm.value = { class_section_ids: [] }
    courseRows.value = [{ code: '', name: '', periods_per_week: '', has_exam: true }]
    await loadAcademicData()
    activeTab.value = 'course-list'
  })
}

function addCourseRow() {
  courseRows.value.push({ code: '', name: '', periods_per_week: '', has_exam: true })
}

function removeCourseRow(index) {
  if (courseRows.value.length === 1) return
  courseRows.value.splice(index, 1)
}

async function activateAcademicYear(year) {
  if (!await confirmAction(pick(`سيتم اعتماد ${year.name} كسنة دراسية حالية للنظام.`, `${year.name} will become the system’s current academic year.`))) return

  try {
    await api(`/academic-years/${year.id}/activate`, { method: 'PUT' })
    notifySuccess(pick(`تم اعتماد ${year.name} كسنة دراسية حالية.`, `${year.name} is now the current academic year.`))
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  }
}

/*
 * Deleting an academic year.
 *
 * The year carries the school's classes, marks, registers and receipts, so the
 * warning is built from a count of what would actually go rather than from a
 * general caution — and the admin has to tick to say they have read it.
 */
const yearImpactLabels = {
  classes: { ar: 'فصل دراسي', en: 'classes' },
  courses: { ar: 'مادة', en: 'subjects' },
  enrollments: { ar: 'تسجيل طالب في فصل', en: 'class enrolments' },
  scores: { ar: 'درجة', en: 'marks' },
  attendance: { ar: 'سجل حضور', en: 'attendance records' },
  grade_submissions: { ar: 'تسليم درجات', en: 'grade submissions' },
  term_windows: { ar: 'فترة دراسية', en: 'term windows' },
  report_cards: { ar: 'كشف درجات منشور', en: 'published report cards' },
  posts: { ar: 'منشور في الفصل الإلكتروني', en: 'classroom posts' },
  fee_templates: { ar: 'قالب رسوم', en: 'fee templates' },
  student_fees: { ar: 'رسم على طالب', en: 'student charges' },
  payments: { ar: 'إيصال قبض', en: 'receipts' },
  advances: { ar: 'عهدة', en: 'cash advances' },
  calendar_events: { ar: 'فعالية في التقويم', en: 'calendar events' },
  students: { ar: 'طالب لا يوجد في سنة أخرى', en: 'students who exist in no other year' },
}

async function removeAcademicYear(year) {
  let impact

  try {
    impact = (await api(`/academic-years/${year.id}/impact`)).data
  } catch (err) {
    notifyError(err.message)

    return
  }

  const lines = Object.entries(impact.counts)
    .filter(([, count]) => count > 0)
    .map(([key, count]) => `• ${count} ${pick(yearImpactLabels[key]?.ar ?? key, yearImpactLabels[key]?.en ?? key)}`)

  const message = impact.is_empty
    ? pick(
      `ستُحذف السنة الدراسية ${year.name}. لا توجد أي بيانات مرتبطة بها.`,
      `Academic year ${year.name} will be deleted. Nothing is recorded under it.`,
    )
    : pick(
      `حذف السنة الدراسية ${year.name} يحذف معها كل البيانات الخاصة بها:`,
      `Deleting academic year ${year.name} deletes everything recorded under it:`,
    )

  const detail = impact.is_empty
    ? undefined
    : `${lines.join('\n')}\n\n${pick(
      'تُؤخذ نسخة احتياطية من قاعدة البيانات قبل الحذف. الطلبة المسجّلون في سنوات أخرى لا يُحذفون.',
      'A copy of the database is taken first. Students who also sit in other years are not deleted.',
    )}`

  const ok = await confirmDelete(message, {
    detail,
    confirmLabel: pick('حذف السنة وكل بياناتها', 'Delete the year and its data'),
    requireAcknowledgement: !impact.is_empty,
  })
  if (!ok) return

  try {
    const response = await api(`/academic-years/${year.id}`, { method: 'DELETE' })
    notifySuccess(response.message)
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  }
}

async function loadReportSettings() {
  if (!can('settings.view')) return
  const data = await api('/report-settings')
  reportSettings.value = data.data
}

async function saveReportSettings() {
  savingReportSettings.value = true

  try {
    const data = await api('/report-settings', {
      method: 'PUT',
      body: JSON.stringify({ semester_report_message: reportSettings.value.semester_report_message }),
    })
    reportSettings.value = data.data
    notifySuccess(pick('تم حفظ الكلمة الافتتاحية. ستظهر في تقارير السيمستر الجديدة.', 'Opening message saved. It will appear on new semester reports.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    savingReportSettings.value = false
  }
}

function restoreDefaultReportMessage() {
  reportSettings.value.semester_report_message = reportSettings.value.default_semester_report_message
}

async function submitAcademicYear() {
  await submit('/academic-years', academicYearForm.value, async () => {
    academicYearForm.value = { name: '', is_active: true }
    await loadAcademicData()
  })
}

async function submitSection() {
  const requiredFields = ['section_code', 'class_name', 'academic_year']
  const hasMissingField = requiredFields.some(
    (field) => !String(sectionForm.value[field] ?? '').trim(),
  )

  if (hasMissingField) {
    notifyError(pick('يرجى تعبئة رمز الفصل واسم الفصل والسنة الدراسية.', 'Fill in the class code, class name and academic year.'))
    return
  }

  await submit('/course-sections', {
    ...sectionForm.value,
    section_code: sectionForm.value.section_code.trim().toUpperCase(),
    class_name: sectionForm.value.class_name.trim(),
    capacity: sectionForm.value.capacity || null,
  }, async () => {
    sectionForm.value = {
      class_name: '',
      section_code: '',
      academic_year: academicYearOptions.value[0] ?? '2025-2026',
      capacity: 25,
    }
    await loadAcademicData()
    activeTab.value = 'section-list'
  })
}

async function loadStudentReport() {
  if (!reportSectionId.value) return
  gradeReport.value = (await api(`/student/courses/${reportSectionId.value}/grade-report?term=${encodeURIComponent(reportTerm.value)}`)).data
  gpaReport.value = (await api(`/student/gpa?term=${encodeURIComponent(reportTerm.value)}`))
}

async function loadStudentDashboard() {
  studentDashboard.value = (await api(`/student/dashboard?term=${encodeURIComponent(reportTerm.value)}`))
}

async function loadReportCard() {
  reportCard.value = (await api('/student/report-card'))
}

async function downloadStudentReportPdf() {
  let blob
  try {
    blob = await apiBlob(`/student/report-card/pdf?type=${pdfType.value}&term=${encodeURIComponent(reportTerm.value)}`)
  } catch {
    notifyError(pick('تعذّر تحميل كشف الدرجات PDF.', 'Could not download the PDF report card.'))
    return
  }
  const objectUrl = URL.createObjectURL(blob)
  window.open(objectUrl, '_blank')
}

async function loadStudentAttendance() {
  studentAttendance.value = (await api('/student/attendance')).data
}

async function loadTeacherSummary() {
  if (!reportSectionId.value) return
  teacherSummary.value = (await api(`/teacher/courses/${reportSectionId.value}/grade-summary?term=${encodeURIComponent(reportTerm.value)}`)).data
}

async function onReportClassChange() {
  reportCardForm.value.student_profile_id = ''
  reportCardMissing.value = []
  reportClassStudents.value = []
  classPublications.value = []
  if (!reportCardForm.value.course_section_id) return

  try {
    const data = await api(`/course-sections/${reportCardForm.value.course_section_id}`)
    reportClassStudents.value = (data.data.enrollments ?? [])
      .filter((enrollment) => enrollment.status === 'active' && enrollment.student_profile)
      .map((enrollment) => enrollment.student_profile)
    await loadClassPublications()
  } catch (err) {
    notifyError(err.message)
  }
}

const currentPublishPeriod = computed(() => (reportCardForm.value.type === 'semester'
  ? `Semester ${reportCardForm.value.semester}`
  : reportCardForm.value.term))
const isCurrentPeriodPublished = computed(() => classPublications.value
  .some((publication) => publication.period === currentPublishPeriod.value))

async function loadClassPublications() {
  if (!reportCardForm.value.course_section_id) return
  const data = await api(`/classes/${reportCardForm.value.course_section_id}/report-card-publications`)
  classPublications.value = data.data
}

async function publishReportCard(publish) {
  if (!reportCardForm.value.course_section_id) return
  publishingReport.value = true

  const payload = reportCardForm.value.type === 'semester'
    ? { type: 'semester', semester: Number(reportCardForm.value.semester) }
    : { type: 'quarter', term: reportCardForm.value.term }

  try {
    await api(`/classes/${reportCardForm.value.course_section_id}/report-card-publications`, {
      method: publish ? 'POST' : 'DELETE',
      body: JSON.stringify(payload),
    })
    notifySuccess(publish ? `تم نشر ${currentPublishPeriod.value} — يستطيع الطلبة وأولياء الأمور تحميله الآن.` : `تم إلغاء نشر ${currentPublishPeriod.value}.`)
    await loadClassPublications()
  } catch (err) {
    notifyError(err.message)
  } finally {
    publishingReport.value = false
  }
}

async function downloadClassReportCard() {
  if (!reportCardForm.value.course_section_id) return
  reportCardMissing.value = []
  reportCardLoading.value = true

  const form = reportCardForm.value
  const params = new URLSearchParams()
  if (form.student_profile_id) params.set('student_profile_id', form.student_profile_id)

  let path
  if (form.type === 'quarter') {
    params.set('term', form.term)
    path = `/admin/classes/${form.course_section_id}/report-cards/quarter/pdf`
  } else {
    params.set('semester', String(form.semester))
    path = `/admin/classes/${form.course_section_id}/report-cards/semester/pdf`
  }

  try {
    // Kept as a bare fetch because a failure carries a JSON body listing which
    // subjects are missing marks, which the shared blob helper would discard.
    const response = await fetch(`${apiBaseUrl}${path}?${params.toString()}`, {
      credentials: 'include',
      headers: {
        Accept: 'application/pdf',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (!response.ok) {
      const data = await response.json().catch(() => ({}))
      reportCardMissing.value = data.missing ?? []
      notifyError(data.message ?? 'Could not generate the report.')
      return
    }

    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)
    window.open(objectUrl, '_blank')
  } catch (err) {
    notifyError(err.message)
  } finally {
    reportCardLoading.value = false
  }
}

async function submit(path, payload, afterSave, method = 'POST') {
  try {
    await api(path, { method, body: JSON.stringify(payload) })
    notifySuccess(pick('تم الحفظ بنجاح.', 'Saved successfully.'))
    await afterSave()
  } catch (err) {
    notifyError(err.message)
  }
}

/**
 * Every delete in the shell goes through here, so the confirmation lives here
 * too rather than being remembered at each of the six call sites.
 */
async function removeItem(path, label) {
  const message = label
    ? pick(`سيتم حذف «${label}» نهائياً.`, `"${label}" will be permanently deleted.`)
    : t('confirmDeleteDefault')

  if (!await confirmDelete(message)) return

  try {
    await api(path, { method: 'DELETE' })
    notifySuccess(t('deletedSuccessfully'))
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  }
}

function toggleLanguage() {
  setLanguage(language.value === 'ar' ? 'en' : 'ar')
}

/**
 * Opens a sidebar group and closes whichever was open. Landing on a group also
 * lands on its first page, so the panel beside it is never left stale.
 */
function toggleMenu(name, tabs, keys) {
  openMenu.value = openMenu.value === name ? '' : name

  if (openMenu.value === name && !keys.includes(activeTab.value) && tabs.length) {
    activeTab.value = tabs[0].key
  }
}

function toggleStudentGroup(key) {
  if (expandedStudentGroups.value.includes(key)) {
    expandedStudentGroups.value = expandedStudentGroups.value.filter((item) => item !== key)
  } else {
    expandedStudentGroups.value = [...expandedStudentGroups.value, key]
  }
}

function openCourseModal(sectionId) {
  courseModalSectionId.value = sectionId
  editingCourse.value = null
}

function closeCourseModal() {
  courseModalSectionId.value = null
  editingCourse.value = null
}

function startEditCourse(course) {
  editingCourse.value = {
    id: course.id,
    code: course.code,
    name: course.name,
    periods_per_week: course.periods_per_week ?? '',
    class_section_id: course.class_section_id ?? '',
    has_exam: course.has_exam !== false,
  }
}

async function saveCourseEdit() {
  if (!editingCourse.value) return

  const movedTo = Number(editingCourse.value.class_section_id) || null
  const movedAway = movedTo !== Number(courseModalSectionId.value)

  await submit(`/courses/${editingCourse.value.id}`, {
    code: editingCourse.value.code.trim().toUpperCase(),
    name: editingCourse.value.name.trim(),
    periods_per_week: editingCourse.value.periods_per_week ? Number(editingCourse.value.periods_per_week) : null,
    has_exam: editingCourse.value.has_exam !== false,
    ...(movedTo ? { class_section_id: movedTo } : {}),
  }, async () => {
    editingCourse.value = null
    // Moved out of the class being viewed, so the open list no longer holds it.
    if (movedAway) closeCourseModal()
    await loadAcademicData()
  }, 'PUT')
}

// ---- subjects with no class -------------------------------------------------

const orphanSelection = ref([])
const orphanTargetSection = ref('')
const linkingOrphans = ref(false)

function toggleOrphan(id) {
  const index = orphanSelection.value.indexOf(id)
  index === -1 ? orphanSelection.value.push(id) : orphanSelection.value.splice(index, 1)
}

/*
 * Clearing out subjects created by mistake.
 *
 * A subject carries its marks and its submitted grade sheets, so the count of
 * what would go with it is read first and shown in the warning — the same shape
 * as deleting an academic year.
 */
const orphanDeleteLabels = {
  courses: { ar: 'مادة', en: 'subjects' },
  scores: { ar: 'درجة مسجّلة', en: 'recorded marks' },
  grade_submissions: { ar: 'تسليم درجات', en: 'grade submissions' },
  posts: { ar: 'منشور في الفصل الإلكتروني', en: 'classroom posts' },
  classroom_links: { ar: 'ربط بـ Google Classroom', en: 'Google Classroom links' },
}

const deletingOrphans = ref(false)

async function deleteOrphans() {
  if (!orphanSelection.value.length) return

  let impact

  try {
    impact = (await api('/courses/deletion-impact', {
      method: 'POST',
      body: JSON.stringify({ course_ids: orphanSelection.value }),
    })).data
  } catch (err) {
    notifyError(err.message)

    return
  }

  const lines = Object.entries(impact.counts)
    .filter(([, count]) => count > 0)
    .map(([key, count]) => `• ${count} ${pick(orphanDeleteLabels[key]?.ar ?? key, orphanDeleteLabels[key]?.en ?? key)}`)

  const names = impact.names.slice(0, 8).join('، ')
  const rest = impact.names.length > 8 ? pick(` و${impact.names.length - 8} غيرها`, ` and ${impact.names.length - 8} more`) : ''

  const ok = await confirmDelete(
    pick(
      `سيتم حذف ${impact.counts.courses} مادة نهائياً: ${names}${rest}`,
      `${impact.counts.courses} subjects will be permanently deleted: ${names}${rest}`,
    ),
    {
      detail: lines.join('\n'),
      confirmLabel: pick('حذف المواد', 'Delete the subjects'),
      requireAcknowledgement: impact.carries_data,
    },
  )
  if (!ok) return

  deletingOrphans.value = true

  try {
    const response = await api('/courses/delete-many', {
      method: 'POST',
      body: JSON.stringify({ course_ids: orphanSelection.value }),
    })
    notifySuccess(response.message)
    orphanSelection.value = []
    await loadAcademicData()
  } catch (err) {
    notifyError(err.message)
  } finally {
    deletingOrphans.value = false
  }
}

async function linkOrphans() {
  if (!orphanTargetSection.value || !orphanSelection.value.length) return

  linkingOrphans.value = true

  await submit('/courses/assign-section', {
    class_section_id: Number(orphanTargetSection.value),
    course_ids: orphanSelection.value,
  }, async () => {
    orphanSelection.value = []
    orphanTargetSection.value = ''
    await loadAcademicData()
  })

  linkingOrphans.value = false
}

function startEditSection(section) {
  editingSection.value = {
    id: section.id,
    section_code: section.section_code ?? '',
    class_name: section.class_name ?? '',
    academic_year: section.academic_year ?? '',
    capacity: section.capacity ?? '',
    // A class may legitimately have no teacher, so '' maps to null on save.
    teacher_id: section.teacher_id ?? '',
  }
}

async function saveSectionEdit() {
  if (!editingSection.value) return

  await submit(`/course-sections/${editingSection.value.id}`, {
    section_code: editingSection.value.section_code.trim().toUpperCase(),
    class_name: editingSection.value.class_name.trim(),
    academic_year: editingSection.value.academic_year,
    capacity: editingSection.value.capacity || null,
    teacher_id: editingSection.value.teacher_id || null,
  }, async () => {
    editingSection.value = null
    await loadAcademicData()
  }, 'PUT')
}

function startEditTeacher(teacher) {
  editingTeacher.value = {
    id: teacher.id,
    name: teacher.name ?? '',
    email: teacher.email ?? '',
    phone: teacher.phone ?? '',
    phone_2: teacher.phone_2 ?? '',
    address: teacher.address ?? '',
    academic_qualification: teacher.academic_qualification ?? '',
    password: '',
    is_active: Boolean(teacher.is_active),
  }
}

async function updateTeacher() {
  if (!editingTeacher.value) return
  const { id, password, ...fields } = editingTeacher.value
  const payload = password ? { ...fields, password } : fields
  await submit(`/teachers/${id}`, payload, async () => {
    editingTeacher.value = null
    await loadAcademicData()
  }, 'PUT')
}

function showStudentDetails(student) {
  selectedStudent.value = student
}

loadCurrentUser()
</script>

<template>
  <main class="app-shell" :dir="isArabic ? 'rtl' : 'ltr'" :class="{ rtl: isArabic }">
    <section v-if="!user" class="login-shell">
      <div class="login-hero">
        <img class="login-logo" src="/images/logo.png" alt="Vision International School" />
        <p class="eyebrow">{{ ui.systemName }}</p>
        <h1>{{ ui.headline }}</h1>
        <p class="summary">{{ ui.summary }}</p>
      </div>

      <form class="auth-panel login-form" @submit.prevent="login">
        <div class="form-heading">
          <h2>{{ ui.signIn }}</h2>
          <div class="heading-actions">
            <button type="button" class="secondary compact" @click="toggleLanguage"><Languages :size="15" /> {{ ui.language }}</button>
            <button
              type="button"
              class="secondary compact"
              :title="isDark ? t('lightMode') : t('darkMode')"
              @click="toggleTheme"
            ><Sun v-if="isDark" :size="15" /><Moon v-else :size="15" /></button>
          </div>
        </div>
        <label>{{ ui.email }}<input v-model="credentials.email" type="text" autocomplete="username" required /></label>
        <label>{{ ui.password }}<input v-model="credentials.password" type="password" autocomplete="current-password" required /></label>
        <button type="submit" :disabled="loading">{{ loading ? ui.signingIn : ui.signIn }}</button>
        
      </form>
    </section>

    <section v-else class="app-frame">
      <aside class="sidebar">
        <div class="side-brand">
          <img src="/images/logo.png" alt="Vision International School" />
          <div>
            <strong>{{ ui.appName }}</strong>
            <small>{{ ui.systemName }}</small>
          </div>
        </div>
        <nav class="tabs">
          <button
            type="button"
            :class="{ active: activeTab === 'home' }"
            @click="activeTab = 'home'"
          >
            <LayoutDashboard class="tab-icon" :size="18" />
            {{ ui.tabs.home }}
          </button>

          <button
            type="button"
            :class="{ active: activeTab === 'classroom' }"
            @click="activeTab = 'classroom'"
          >
            <MessagesSquare class="tab-icon" :size="18" />
            {{ ui.tabs.classroom }}
          </button>

          <button
            type="button"
            :class="{ active: activeTab === 'calendar' }"
            @click="activeTab = 'calendar'"
          >
            <CalendarDays class="tab-icon" :size="18" />
            {{ ui.tabs.calendar }}
          </button>

          <div v-if="studentTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: studentTabKeys.includes(activeTab) }"
              @click="toggleMenu('student', studentTabs, studentTabKeys)"
            >
              <GraduationCap class="tab-icon" :size="18" />
              {{ ui.tabs.studentsMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'student' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'student'" class="tab-submenu">
              <button
                v-for="tab in studentTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="teacherTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: teacherTabKeys.includes(activeTab) }"
              @click="toggleMenu('teacher', teacherTabs, teacherTabKeys)"
            >
              <Users class="tab-icon" :size="18" />
              {{ ui.tabs.teachersMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'teacher' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'teacher'" class="tab-submenu">
              <button
                v-for="tab in teacherTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="sectionTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: sectionTabKeys.includes(activeTab) }"
              @click="toggleMenu('section', sectionTabs, sectionTabKeys)"
            >
              <School class="tab-icon" :size="18" />
              {{ ui.tabs.sectionsMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'section' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'section'" class="tab-submenu">
              <button
                v-for="tab in sectionTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="courseTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: courseTabKeys.includes(activeTab) }"
              @click="toggleMenu('course', courseTabs, courseTabKeys)"
            >
              <BookOpen class="tab-icon" :size="18" />
              {{ ui.tabs.coursesMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'course' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'course'" class="tab-submenu">
              <button
                v-for="tab in courseTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="gradesTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: gradesTabKeys.includes(activeTab) }"
              @click="toggleMenu('grades', gradesTabs, gradesTabKeys)"
            >
              <ClipboardList class="tab-icon" :size="18" />
              {{ ui.tabs.gradesMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'grades' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'grades'" class="tab-submenu">
              <button
                v-for="tab in gradesTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="financeTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: financeTabKeys.includes(activeTab) }"
              @click="toggleMenu('finance', financeTabs, financeTabKeys)"
            >
              <Wallet class="tab-icon" :size="18" />
              {{ ui.tabs.financeMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'finance' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'finance'" class="tab-submenu">
              <button
                v-for="tab in financeTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <div v-if="settingsTabs.length" class="tab-group">
            <button
              type="button"
              class="tab-parent"
              :class="{ active: settingsTabKeys.includes(activeTab) }"
              @click="toggleMenu('settings', settingsTabs, settingsTabKeys)"
            >
              <Settings class="tab-icon" :size="18" />
              {{ ui.tabs.settingsMenu }}
              <ChevronDown class="tab-caret" :class="{ open: openMenu === 'settings' }" :size="16" />
            </button>
            <Transition name="submenu" :duration="180"><div v-if="openMenu === 'settings'" class="tab-submenu">
              <button
                v-for="tab in settingsTabs"
                :key="tab.key"
                type="button"
                :class="{ active: activeTab === tab.key }"
                @click="activeTab = tab.key"
              >
                {{ ui.tabs[tab.key] ?? tab.label }}
              </button>
            </div>
            </Transition>
          </div>

          <button
            v-for="tab in sidebarTabs"
            :key="tab.key"
            type="button"
            :class="{ active: activeTab === tab.key }"
            @click="activeTab = tab.key"
          >
            <span class="tab-dot"></span>
            {{ ui.tabs[tab.key] ?? tab.label }}
          </button>
        </nav>
        <div class="side-user">
          <span>{{ ui.signedInAs }}</span>
          <strong>{{ user.name }}</strong>
          <small>{{ user.user_type }}</small>
        </div>
      </aside>

      <section class="dashboard">
        <header class="topbar">
          <div>
            <p class="eyebrow">{{ ui.currentArea }}</p>
            <h2>{{ currentTabLabel || dashboardTitle }}</h2>
          </div>
          <div class="topbar-actions">
            <input class="search-input" :placeholder="ui.searchPlaceholder" />
            <button type="button" class="secondary compact" @click="toggleLanguage"><Languages :size="15" /> {{ ui.language }}</button>
            <button
              type="button"
              class="secondary compact"
              :title="isDark ? t('lightMode') : t('darkMode')"
              :aria-label="isDark ? t('lightMode') : t('darkMode')"
              @click="toggleTheme"
            ><Sun v-if="isDark" :size="15" /><Moon v-else :size="15" /></button>
            <span v-if="user?.is_active" class="status">{{ ui.active }}</span>
            <button type="button" class="secondary compact" @click="logout"><LogOut :size="15" /> {{ ui.logout }}</button>
          </div>
        </header>


      <p v-if="user && !visibleTabs.length" class="muted empty">
        {{ ui.noPages }}
      </p>

      <section v-if="activeTab === 'home'" class="workspace">
        <article class="form-card">
          <h3>{{ ui.home.welcome }}, {{ user.name }} 👋</h3>
          <p class="muted">{{ ui.home.overview }} — {{ ui.home.activeYear }}: <strong>{{ activeAcademicYear }}</strong></p>
          <p v-if="viewingPastYear" class="notice past-year">
            {{ tr('🕘 النظام مضبوط على سنة سابقة، والأرقام والقوائم أدناه تخص') }} <strong>{{ activeAcademicYear }}</strong>{{ tr('. فعّل') }} <strong>{{ latestAcademicYear }}</strong> {{ tr('من الإعدادات العامة للعودة إلى السنة الحالية.') }}
          </p>
        </article>

        <div class="stats-grid">
          <article v-if="can('view_students')" class="stat-card stat-blue"><span>{{ ui.home.students }}</span><strong>{{ studentsTotal }}</strong></article>
          <article v-if="can('teachers.view')" class="stat-card stat-green"><span>{{ ui.home.teachers }}</span><strong>{{ teachers.length }}</strong></article>
          <article v-if="can('courses.view')" class="stat-card stat-violet"><span>{{ ui.home.courses }}</span><strong>{{ courses.length }}</strong></article>
          <article v-if="can('sections.view')" class="stat-card stat-rose"><span>{{ ui.home.classes }}</span><strong>{{ sections.length }}</strong></article>
          <article v-if="can('users.view')" class="stat-card stat-blue"><span>{{ ui.home.users }}</span><strong>{{ users.length }}</strong></article>
        </div>

        <article class="form-card">
          <h3>{{ ui.home.quickActions }}</h3>
          <div class="actions">
            <button v-if="can('create_students')" type="button" @click="activeTab = 'student-create'">{{ ui.home.addStudent }}</button>
            <button v-if="can('teachers.manage')" type="button" class="secondary" @click="activeTab = 'teacher-create'">{{ ui.home.addTeacher }}</button>
            <button v-if="can('courses.manage')" type="button" class="secondary" @click="activeTab = 'course-create'">{{ ui.home.addCourse }}</button>
            <button v-if="can('sections.manage')" type="button" class="secondary" @click="activeTab = 'section-create'">{{ ui.home.addClass }}</button>
          </div>
        </article>

        <article class="form-card">
          <h3>{{ ui.home.reports }}</h3>
          <div class="actions">
            <button v-if="can('students.view')" type="button" class="secondary" @click="activeTab = 'admin-grade-report'">{{ ui.home.gradeReport }}</button>
            <button v-if="can('admin_manage_grades')" type="button" class="secondary" @click="activeTab = 'admin-grade-management'">{{ ui.home.gradeManagement }}</button>
            <button v-if="can('settings.view')" type="button" class="secondary" @click="activeTab = 'settings'">{{ ui.home.settings }}</button>
          </div>
        </article>

        <article v-if="can('view_students')" class="form-card">
          <h3>{{ ui.home.recentStudents }}</h3>
          <div class="table-wrap">
            <table>
              <thead><tr><th>#</th><th>{{ ui.home.students }}</th><th>{{ ui.home.classes }}</th></tr></thead>
              <tbody>
                <tr v-for="student in recentStudents" :key="`home-${student.id}`">
                  <td>{{ student.admission_no || student.student_number }}</td>
                  <td>{{ student.full_name || student.user?.name }}</td>
                  <td>{{ student.section?.section_code || student.batch || '-' }}</td>
                </tr>
                <tr v-if="!recentStudents.length"><td colspan="3" class="muted">{{ ui.home.noStudents }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'users' && can('users.view')" class="workspace">
        <article v-if="can('users.manage')" class="form-card">
          <h3>{{ tr('إنشاء مستخدم جديد للنظام') }}</h3>
          <form class="crud-form" @submit.prevent="submitUser">
            <label>{{ tr('الاسم') }}<input v-model="userForm.name" required /></label>
            <label>{{ tr('البريد الإلكتروني') }}<input v-model="userForm.email" type="email" required /></label>
            <label>{{ tr('كلمة المرور') }}
              <input v-model="userForm.password" type="password" autocomplete="new-password" required minlength="10" />
              <span class="muted">{{ tr('10 أحرف على الأقل، مع أرقام ورموز.') }}</span>
            </label>
            <label class="checkbox-label"><input v-model="userForm.is_active" type="checkbox" /> {{ tr('نشط') }}</label>
            <button type="submit">{{ tr('إنشاء موظف') }}</button>
          </form>
          <p class="muted">{{ tr('يُنشأ المستخدم الجديد كموظف نظام، وتحدد صلاحياته من القائمة التالية.') }}</p>
          <div v-if="can('staff.permissions.manage') && permissions.length" class="permission-list" style="margin-top: 12px">
            <p class="muted" style="grid-column: 1 / -1">{{ isArabic ? tr('صلاحيات المستخدم الجديد:') : 'New user permissions:' }}</p>
            <label v-for="permission in permissions" :key="`new-${permission.id}`" class="checkbox-label">
              <input v-model="newUserPermissions" type="checkbox" :value="permission.name" />
              {{ permissionLabel(permission) }}
            </label>
          </div>
        </article>

        <div class="list-toolbar">
          <label>{{ tr('بحث (الاسم أو اسم المستخدم)') }}
            <input v-model="userSearch" :placeholder="tr('ابحث بالاسم أو اسم المستخدم...')" />
          </label>
        </div>

        <article class="form-card">
          <div class="permission-card-header">
            <h3>{{ tr('الأساتذة (') }}{{ filteredTeachers.length }})</h3>
            <button type="button" class="add-course-button" @click="userGroupOpen.teachers = !userGroupOpen.teachers">
              {{ userGroupOpen.teachers ? '−' : '+' }}
            </button>
          </div>
          <div v-if="userGroupOpen.teachers || userSearchTerm" class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الاسم') }}</th><th>{{ tr('اسم المستخدم') }}</th><th>{{ tr('البريد الإلكتروني') }}</th><th>{{ tr('المواد') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="teacher in filteredTeachers" :key="`dir-teacher-${teacher.id}`">
                  <td>{{ teacher.name }}</td>
                  <td>{{ teacher.username || '-' }}</td>
                  <td>{{ teacher.email }}</td>
                  <td>
                    <span v-if="teacher.courses_count" class="subject-count">{{ teacher.courses_count }} {{ tr('مادة') }}</span>
                    <span v-else class="muted">{{ tr('لا توجد') }}</span>
                  </td>
                  <td>{{ teacher.is_active ? tr('نشط') : tr('غير نشط') }}</td>
                  <td>
                    <div class="actions">
                      <button v-if="can('teachers.manage')" class="secondary compact" @click="openSubjectsModal(teacher)">{{ tr('إضافة مادة') }}</button>
                      <button v-if="can('users.manage')" class="secondary compact" @click="openCredentials('user', teacher)">{{ tr('تعديل اسم المستخدم / كلمة المرور') }}</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!filteredTeachers.length"><td colspan="6" class="muted">{{ tr('لا يوجد أساتذة مطابقون.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="form-card">
          <div class="permission-card-header">
            <h3>{{ tr('الطلبة') }}</h3>
            <button type="button" class="add-course-button" @click="userGroupOpen.students = !userGroupOpen.students">
              {{ userGroupOpen.students ? '−' : '+' }}
            </button>
          </div>
          <div v-if="userGroupOpen.students || userSearchTerm" class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الفصل') }}</th><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('عدد الطلبة') }}</th><th></th></tr></thead>
              <tbody>
                <template v-for="section in filteredStudentSections" :key="`stu-sec-${section.id}`">
                  <tr>
                    <td><strong>{{ section.class_name }}</strong> <span class="muted">{{ section.section_code }}</span></td>
                    <td>{{ section.academic_year || '-' }}</td>
                    <td>{{ section.students.length }}</td>
                    <td>
                      <button type="button" class="secondary compact" @click="openStudentSectionId = openStudentSectionId === section.id ? false : section.id">
                        {{ openStudentSectionId === section.id ? tr('إخفاء') : tr('عرض الطلبة') }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="openStudentSectionId === section.id || userSearchTerm">
                    <td colspan="4">
                      <div class="table-wrap">
                        <table>
                          <thead><tr><th>{{ tr('الاسم') }}</th><th>{{ tr('اسم المستخدم') }}</th><th>{{ tr('رقم الطالب') }}</th><th></th></tr></thead>
                          <tbody>
                            <tr v-for="student in section.students" :key="`dir-student-${student.profile_id}`">
                              <td>{{ student.name }}</td>
                              <td>{{ student.username || '-' }}</td>
                              <td>{{ student.admission_no || '-' }}</td>
                              <td>
                                <div class="actions">
                                  <button class="secondary compact" @click="showUserStudentDetails(student, section)">{{ tr('بياناته') }}</button>
                                  <button v-if="can('users.manage') && student.user_id" class="secondary compact" @click="openCredentials('user', student)">{{ tr('تغيير كلمة المرور') }}</button>
                                </div>
                              </td>
                            </tr>
                            <tr v-if="!section.students.length"><td colspan="4" class="muted">{{ tr('لا يوجد طلبة في هذا الفصل.') }}</td></tr>
                          </tbody>
                        </table>
                      </div>
                    </td>
                  </tr>
                </template>
                <tr v-if="!filteredStudentSections.length"><td colspan="4" class="muted">{{ tr('لا توجد نتائج مطابقة.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="form-card">
          <div class="permission-card-header">
            <h3>{{ tr('أولياء الأمور') }}</h3>
            <button type="button" class="add-course-button" @click="userGroupOpen.parents = !userGroupOpen.parents">
              {{ userGroupOpen.parents ? '−' : '+' }}
            </button>
          </div>
          <div v-if="userGroupOpen.parents || userSearchTerm" class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الفصل') }}</th><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('عدد أولياء الأمور') }}</th><th></th></tr></thead>
              <tbody>
                <template v-for="section in filteredParentSections" :key="`par-sec-${section.id}`">
                  <tr>
                    <td><strong>{{ section.class_name }}</strong> <span class="muted">{{ section.section_code }}</span></td>
                    <td>{{ section.academic_year || '-' }}</td>
                    <td>{{ section.parents.length }}</td>
                    <td>
                      <button type="button" class="secondary compact" @click="openParentSectionId = openParentSectionId === section.id ? false : section.id">
                        {{ openParentSectionId === section.id ? tr('إخفاء') : tr('عرض أولياء الأمور') }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="openParentSectionId === section.id || userSearchTerm">
                    <td colspan="4">
                      <div class="table-wrap">
                        <table>
                          <thead><tr><th>{{ tr('الاسم') }}</th><th>{{ tr('اسم المستخدم') }}</th><th>{{ tr('الموبايل') }}</th><th>{{ tr('الأبناء') }}</th><th>{{ tr('الحساب') }}</th><th></th></tr></thead>
                          <tbody>
                            <tr v-for="parent in section.parents" :key="`dir-parent-${parent.id}`">
                              <td>{{ parent.full_name }}</td>
                              <td>{{ parent.username || '-' }}</td>
                              <td>{{ parent.mobile || '-' }}</td>
                              <td>{{ (parent.children ?? []).join('، ') }}</td>
                              <td>{{ parent.has_account ? tr('مفعّل') : tr('غير مفعّل') }}</td>
                              <td>
                                <div class="actions">
                                  <button class="secondary compact" @click="showUserParentDetails(parent)">{{ tr('بياناته') }}</button>
                                  <button v-if="can('users.manage')" class="secondary compact" @click="openCredentials('parent', parent)">{{ tr('تغيير كلمة المرور') }}</button>
                                </div>
                              </td>
                            </tr>
                            <tr v-if="!section.parents.length"><td colspan="6" class="muted">{{ tr('لا يوجد أولياء أمور لهذا الفصل.') }}</td></tr>
                          </tbody>
                        </table>
                      </div>
                    </td>
                  </tr>
                </template>
                <tr v-if="!filteredParentSections.length"><td colspan="4" class="muted">{{ tr('لا توجد نتائج مطابقة.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="form-card">
          <div class="permission-card-header">
            <h3>{{ tr('مستخدمو النظام — أدمن وموظفون (') }}{{ systemUsers.length }})</h3>
            <button type="button" class="add-course-button" @click="userGroupOpen.system = !userGroupOpen.system">
              {{ userGroupOpen.system ? '−' : '+' }}
            </button>
          </div>
          <div v-if="userGroupOpen.system || userSearchTerm" class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الاسم') }}</th><th>{{ tr('البريد الإلكتروني') }}</th><th>{{ tr('النوع') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="managedUser in systemUsers" :key="managedUser.id">
                  <td>{{ managedUser.name }}</td>
                  <td>{{ managedUser.email }}</td>
                  <td>{{ managedUser.user_type === 'admin' ? tr('أدمن') : tr('موظف') }}</td>
                  <td>{{ managedUser.is_active ? tr('نشط') : tr('غير نشط') }}</td>
                  <td>
                    <div class="actions">
                      <button v-if="can('users.manage')" class="secondary compact" @click="openCredentials('user', managedUser)">{{ tr('تعديل الدخول') }}</button>
                      <button
                        v-if="managedUser.user_type === 'staff' && can('staff.permissions.manage')"
                        class="secondary compact"
                        @click="openPermissionsModal(managedUser)"
                      >{{ tr('تعديل الصلاحيات') }}</button>
                      <button v-if="can('users.manage')" class="danger compact" @click="removeItem(`/users/${managedUser.id}`, managedUser.name)">{{ tr('حذف') }}</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!systemUsers.length"><td colspan="5" class="muted">{{ tr('لا يوجد مستخدمون مطابقون.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <div v-if="credentialsModal" class="modal-backdrop" @click.self="credentialsModal = null">
          <article class="student-modal">
            <form @submit.prevent="saveCredentials">
              <div class="permission-card-header">
                <h3>{{ tr('بيانات الدخول —') }} {{ credentialsModal.name }}</h3>
                <button type="button" class="secondary compact" @click="credentialsModal = null">{{ tr('إغلاق') }}</button>
              </div>
              <div class="form-grid">
                <label>{{ tr('اسم المستخدم') }} <input v-model="credentialsModal.username" /></label>
                <label>{{ tr('كلمة المرور الجديدة') }} <input v-model="credentialsModal.password" type="password" minlength="8" autocomplete="new-password" :required="credentialsModal.kind === 'parent'" /></label>
              </div>
              <p v-if="credentialsModal.kind === 'parent'" class="muted">{{ tr('إذا لم يكن لولي الأمر حساب دخول بعد فسيتم إنشاؤه بهذه البيانات.') }}</p>
              <div class="actions">
                <button type="submit">{{ tr('حفظ') }}</button>
              </div>
            </form>
          </article>
        </div>

        <div v-if="permissionsModal" class="modal-backdrop" @click.self="permissionsModal = null">
          <article class="student-modal">
            <div class="permission-card-header">
              <h3>{{ isArabic ? tr('صلاحيات') : 'Permissions' }} — {{ permissionsModal.name }}</h3>
              <button type="button" class="secondary compact" @click="permissionsModal = null">{{ tr('إغلاق') }}</button>
            </div>
            <div class="permission-list">
              <label v-for="permission in permissions" :key="`perm-modal-${permission.id}`" class="checkbox-label">
                <input v-model="permissionsModal.selected" type="checkbox" :value="permission.name" />
                {{ permissionLabel(permission) }}
              </label>
            </div>
            <div class="actions">
              <button type="button" @click="savePermissionsModal">{{ tr('حفظ الصلاحيات') }}</button>
            </div>
          </article>
        </div>

        <div v-if="userDetails" class="modal-backdrop" @click.self="userDetails = null">
          <article class="student-modal">
            <div class="permission-card-header">
              <h3>{{ userDetails.title }}</h3>
              <button type="button" class="secondary compact" @click="userDetails = null">{{ tr('إغلاق') }}</button>
            </div>
            <div class="details-grid">
              <p v-for="row in userDetails.rows" :key="row[0]"><strong>{{ row[0] }}:</strong> {{ row[1] || '-' }}</p>
            </div>
          </article>
        </div>
      </section>

      <section v-if="activeTab === 'student-create' && can('create_students')" class="workspace">
        <article v-if="can('import_students')" class="excel-upload-card" role="button" tabindex="0" @click="openImportPicker" @keydown.enter="openImportPicker">
          <input ref="importFileInput" class="hidden-file-input" type="file" accept=".xlsx,.xls,.csv" @change="setImportFile" />
          <div class="excel-upload-icon">XLS</div>
          <div>
            <h3>{{ tr('رفع Excel') }}</h3>
            <p class="muted">{{ importFile ? importFile.name : tr('اضغط هنا لاختيار ملف Excel ورفع بيانات الطلاب') }}</p>
          </div>
          <button v-if="importPreview" type="button" @click.stop="importStudents">{{ tr('تأكيد الاستيراد') }}</button>
        </article>

        <article v-if="importPreview" class="form-card">
          <h3>{{ tr('معاينة الاستيراد') }}</h3>
          <div class="stats-grid">
            <article class="stat-card stat-blue"><span>{{ tr('الإجمالي') }}</span><strong>{{ importPreview.total }}</strong></article>
            <article class="stat-card stat-green"><span>{{ tr('الجدد') }}</span><strong>{{ importPreview.new_count }}</strong></article>
            <article class="stat-card stat-violet"><span>{{ tr('المكررون') }}</span><strong>{{ importPreview.duplicate_count }}</strong></article>
            <article class="stat-card stat-rose"><span>{{ tr('الأخطاء') }}</span><strong>{{ importPreview.error_count }}</strong></article>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الرقم') }}</th><th>{{ tr('الاسم') }}</th><th>{{ tr('الصف') }}</th><th>{{ tr('الرقم الوطني') }}</th><th>{{ tr('ولي الأمر') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in importPreview.preview" :key="`${row.student_number}-${row.user_name}`">
                  <td>{{ row.student_number }}</td>
                  <td>{{ row.user_name }}</td>
                  <td>{{ row.grade_level }}</td>
                  <td>{{ row.national_id }}</td>
                  <td>{{ row.parent_full_name }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <form class="student-form" @submit.prevent="submitStudent">
          <article class="form-card required-card">
            <h3>{{ tr('معلومات الطالب المطلوبة') }}</h3>
            <div class="form-grid">
              <label
                v-for="field in requiredStudentFields"
                :key="field[0]"
                :class="{ 'field-error': studentRequiredErrors[field[0]] }"
              >
                <span>
                  {{ field[1] }}
                  <strong v-if="!['middle_name', 'last_name'].includes(field[0])" class="required-star">*</strong>
                </span>
                <select v-if="field[0] === 'gender'" v-model="studentForm.gender">
                  <option value="">{{ tr('اختر الجنس') }}</option>
                  <option value="male">{{ tr('ذكر') }}</option>
                  <option value="female">{{ tr('أنثى') }}</option>
                </select>
                <input
                  v-else-if="field[0] === 'admission_no'"
                  class="readonly-input"
                  :value="displayedStudentNumber"
                  readonly
                />
                <select v-else-if="field[0] === 'course'" v-model="studentForm.course" :disabled="!studentForm.academic_year">
                  <option value="">{{ studentForm.academic_year ? tr('اختر الصف الدراسي') : tr('اختر السنة الدراسية أولاً') }}</option>
                  <option v-for="classItem in studentClassOptions" :key="classItem.label" :value="classItem.value">{{ classItem.label }}</option>
                </select>
                <select v-else-if="field[0] === 'academic_year'" v-model="studentForm.academic_year">
                  <option value="">{{ tr('اختر السنة الدراسية') }}</option>
                  <option v-for="year in academicYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
                <input v-else v-model="studentForm[field[0]]" :type="field[2]" />
              </label>
            </div>
            <p v-if="Object.keys(studentRequiredErrors).length" class="notice error">
              {{ tr('يرجى تعبئة جميع الحقول المطلوبة قبل الحفظ.') }}
            </p>
          </article>

          <article class="form-card">
            <div class="permission-card-header">
              <div>
                <h3>{{ tr('Sibling Search / البحث عن شقيق') }}</h3>
                <p class="muted">{{ tr('اختياري ويمكن تخطيه بدون اختيار شقيق.') }}</p>
              </div>
            </div>
            <label>
              Search sibling by student name
              <input
                v-model="siblingSearch"
                placeholder="Search sibling by student name"
                type="search"
                @input="searchSiblingsDebounced"
              />
            </label>
            <p v-if="siblingLoading" class="muted">Searching...</p>
            <p v-else-if="siblingSearch.trim().length >= 2 && !siblingResults.length" class="muted">No matching students found.</p>
            <div v-if="siblingResults.length" class="sibling-results">
              <article v-for="student in siblingResults" :key="student.id" class="sibling-card">
                <div>
                  <strong>{{ student.full_name || '-' }}</strong>
                  <p class="muted">
                    {{ student.admission_no || '-' }} · {{ student.course || '-' }} · {{ student.batch || '-' }}
                  </p>
                  <p class="muted">Parent: {{ student.parent_full_name || '-' }}</p>
                </div>
                <button type="button" class="secondary compact" @click="selectSibling(student)">Select as sibling</button>
              </article>
            </div>
            <div v-if="selectedSiblings.length" class="selected-siblings">
              <h4>Selected Sibling</h4>
              <article v-for="student in selectedSiblings" :key="`selected-${student.id}`" class="selected-sibling">
                <span>{{ student.full_name }} - {{ student.admission_no }}</span>
                <button type="button" class="danger compact" @click="removeSibling(student.id)">Remove</button>
              </article>
              <label class="checkbox-label">
                <input v-model="useSiblingParent" type="checkbox" @change="toggleSiblingParentReuse" />
                Use same parent details as selected sibling
              </label>
            </div>
          </article>

          <article class="form-card required-card">
            <h3>{{ tr('بيانات ولي الأمر الشخصية') }}</h3>
            <div class="form-grid">
              <label>
                {{ tr('رقم ولي الأمر') }}
                <input class="readonly-input" :value="parentAdmissionNo" readonly />
              </label>
              <label :class="{ 'field-error': parentRequiredErrors.first_name }">
                <span>{{ tr('الاسم الأول') }} <strong class="required-star">*</strong></span>
                <input v-model="parentForm.first_name" type="text" />
              </label>
              <label>
                {{ tr('اسم العائلة') }}
                <input v-model="parentForm.last_name" type="text" />
              </label>
              <label :class="{ 'field-error': parentRequiredErrors.relation }">
                <span>{{ tr('صلة القرابة') }} <strong class="required-star">*</strong></span>
                <select v-model="parentForm.relation">
                  <option value="">{{ tr('اختر صلة القرابة') }}</option>
                  <option value="father">{{ tr('الأب') }}</option>
                  <option value="mother">{{ tr('الأم') }}</option>
                  <option value="other">{{ tr('أخرى') }}</option>
                </select>
              </label>
              <label>
                {{ tr('الهاتف النقال') }}
                <input v-model="parentForm.mobile" type="text" />
              </label>
            </div>
            <p v-if="Object.keys(parentRequiredErrors).length" class="notice error">
              {{ tr('اسم ولي الأمر الأول وصلة القرابة مطلوبان.') }}
            </p>
          </article>

          <article class="form-card">
            <h3>{{ tr('بيانات إضافية للطالب') }}</h3>
            <p class="muted">{{ tr('هذه البيانات اختيارية ولا تمنع حفظ الطالب إذا تُركت فارغة.') }}</p>
          </article>

          <article v-for="group in studentFieldGroups" :key="group.title" class="form-card">
            <h3>{{ group.title }}</h3>
            <div class="form-grid">
              <label v-for="field in group.fields" :key="field[0]">
                {{ field[1] }}
                <select v-if="field[0] === 'academic_year'" v-model="studentForm.academic_year">
                  <option value="">{{ tr('اختر السنة الدراسية') }}</option>
                  <option v-for="year in academicYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
                <input v-else v-model="studentForm[field[0]]" :type="field[2]" :required="field[0] === 'full_name'" />
              </label>
            </div>
          </article>
          <button type="submit">{{ tr('حفظ الطالب') }}</button>
        </form>
      </section>

      <section v-if="activeTab === 'student-list' && can('view_students')" class="workspace">
        <p v-if="studentListPastYear" class="notice past-year">
          {{ tr('🕘 تعرض الآن بيانات السنة الدراسية') }} <strong>{{ studentsViewYear }}</strong> {{ tr('— الطلبة وصفوفهم ودرجاتهم كما كانت في تلك السنة. للعودة إلى السنة الحالية فعّل') }} <strong>{{ latestAcademicYear }}</strong> {{ tr('من الإعدادات العامة.') }}
        </p>

        <div class="list-toolbar">
          <label>Search
            <input v-model="studentSearch" placeholder="Name, admission no, national ID, grade, section" @keyup.enter="loadStudents" />
          </label>
          <label>Academic Year
            <select v-model="studentFilters.academic_year" @change="studentPage = 1; loadStudents()">
              <option value="">{{ tr('السنة المفعّلة (') }}{{ activeAcademicYear }})</option>
              <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
            </select>
          </label>
          <label>Grade<input v-model="studentFilters.grade" /></label>
          <label>Gender
            <select v-model="studentFilters.gender">
              <option value="">All</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </label>
          <label>Student Status
            <select v-model="archivedFilter" @change="loadStudents">
              <option value="active">Active Students</option>
              <option value="with">All Students</option>
            </select>
          </label>
          <button type="button" @click="studentPage = 1; loadStudents()">Apply</button>
          <button v-if="can('create_students')" type="button" @click="activeTab = 'student-create'">Add Student</button>
        </div>

        <form v-if="editingStudent && can('edit_students')" class="student-form edit-panel" @submit.prevent="updateStudent">
          <div class="permission-card-header">
            <h3>Edit Student</h3>
            <button type="button" class="secondary compact" @click="editingStudent = null">Cancel</button>
          </div>
          <article v-for="group in studentFieldGroups" :key="`edit-${group.title}`" class="form-card">
            <h3>{{ group.title }}</h3>
            <div class="form-grid">
              <label v-for="field in group.fields" :key="`edit-${field[0]}`">
                {{ field[1] }}
                <select v-if="field[0] === 'academic_year'" v-model="editingStudent.academic_year">
                  <option value="">{{ tr('اختر السنة الدراسية') }}</option>
                  <option v-for="year in academicYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
                <input v-else v-model="editingStudent[field[0]]" :type="field[2]" :required="field[0] === 'full_name'" />
              </label>
            </div>
          </article>
          <article class="form-card parent-login-card">
            <h3>{{ tr('بيانات دخول ولي الأمر لاحقاً') }}</h3>
            <div class="details-grid">
              <p><strong>{{ tr('اسم المستخدم:') }}</strong> {{ editingStudent.parent_username || editingStudent.parent_mobile_phone || editingStudent.guardian_phone || tr('لم يتم إدخال الرقم') }}</p>
              <p><strong>{{ tr('كلمة المرور:') }}</strong> {{ parentPasswordFor(editingStudent) || tr('تظهر بعد إدخال رقم ولي الأمر') }}</p>
            </div>
          </article>
          <button type="submit">{{ tr('حفظ التعديل') }}</button>
        </form>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الفصل / الشعبة') }}</th><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('عدد الطلبة') }}</th><th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in groupedStudents" :key="group.key">
                <tr>
                  <td>{{ group.label }}</td>
                  <td>{{ group.academic_year || '-' }}</td>
                  <td>{{ group.students.length }}</td>
                  <td>
                    <button type="button" class="secondary compact" @click="toggleStudentGroup(group.key)">
                      {{ expandedStudentGroups.includes(group.key) ? '−' : '+' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedStudentGroups.includes(group.key)" class="students-row">
                  <td colspan="4">
                    <div class="student-name-list">
                      <button
                        v-for="student in group.students"
                        :key="student.id"
                        type="button"
                        class="student-name-button"
                        @click="showStudentDetails(student)"
                      >
                        {{ student.full_name || student.user.name }}
                      </button>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="!groupedStudents.length">
                <td colspan="4" class="muted">{{ tr('لا توجد بيانات طلبة حسب الفلاتر الحالية.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="studentPagination" class="actions">
          <button type="button" class="secondary compact" :disabled="studentPage <= 1" @click="studentPage--; loadStudents()">{{ tr('السابق') }}</button>
          <span class="muted">{{ tr('صفحة') }} {{ studentPage }} {{ tr('من') }} {{ studentLastPage }} {{ tr('· إجمالي') }} {{ studentsTotal }} {{ tr('طالب') }}</span>
          <button type="button" class="secondary compact" :disabled="studentPage >= studentLastPage" @click="studentPage++; loadStudents()">{{ tr('التالي') }}</button>
        </div>

        <div v-if="selectedStudent" class="modal-backdrop" @click.self="selectedStudent = null">
          <article class="student-modal">
            <div class="permission-card-header">
              <h3>{{ tr('بيانات الطالب') }}</h3>
              <button class="secondary compact" @click="selectedStudent = null">{{ tr('إغلاق') }}</button>
            </div>
            <div class="details-grid">
              <p><strong>{{ tr('الاسم الكامل:') }}</strong> {{ selectedStudent.full_name || selectedStudent.user.name }}</p>
              <p><strong>{{ tr('الاسم العربي:') }}</strong> {{ selectedStudent.arabic_name || '-' }}</p>
              <p><strong>{{ tr('البريد:') }}</strong> {{ selectedStudent.user.email }}</p>
              <p><strong>{{ tr('السنة الدراسية:') }}</strong> {{ selectedStudent.academic_year || '-' }}</p>
              <p><strong>{{ tr('الفصل:') }}</strong> {{ selectedStudent.section?.section_code || selectedStudent.batch || '-' }}</p>
              <p><strong>{{ tr('الجنسية:') }}</strong> {{ selectedStudent.nationality_ar || selectedStudent.nationality || '-' }}</p>
              <p><strong>{{ tr('الرقم الوطني:') }}</strong> {{ selectedStudent.national_id || '-' }}</p>
              <p><strong>{{ tr('الموبايل:') }}</strong> {{ selectedStudent.mobile || '-' }}</p>
              <p><strong>{{ tr('ولي الأمر:') }}</strong> {{ selectedStudent.parent_full_name || selectedStudent.guardian_name || '-' }}</p>
              <p><strong>{{ tr('اسم مستخدم ولي الأمر:') }}</strong> {{ selectedStudent.parent_login_username || selectedStudent.parent_username || '-' }}</p>
              <p><strong>{{ tr('كلمة مرور ولي الأمر:') }}</strong> {{ selectedStudent.parent_initial_password || '-' }}</p>
              <p><strong>{{ tr('الحالة:') }}</strong> {{ selectedStudent.archived_at ? tr('مؤرشف') : tr('نشط') }}</p>
            </div>
            <div class="actions">
              <button v-if="can('edit_students')" class="secondary compact" @click="startEditStudent(selectedStudent); selectedStudent = null">{{ tr('تعديل') }}</button>
              <button v-if="can('archive_students') && !selectedStudent.archived_at" class="danger compact" @click="archiveStudent(selectedStudent); selectedStudent = null">{{ tr('أرشفة') }}</button>
              <button v-if="can('restore_students') && selectedStudent.archived_at" class="secondary compact" @click="restoreStudent(selectedStudent); selectedStudent = null">{{ tr('استرجاع') }}</button>
            </div>
          </article>
        </div>
      </section>

      <section v-if="activeTab === 'student-archive' && can('view_student_archive')" class="workspace">
        <div class="list-toolbar">
          <button type="button" @click="archivedFilter = 'only'; studentPage = 1; loadStudents()">Load Archive</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>No.</th><th>Name</th><th>Grade</th><th>Archived At</th><th>Reason</th><th></th></tr></thead>
            <tbody>
              <tr v-for="student in students" :key="`archive-${student.id}`">
                <td>{{ student.student_number }}</td>
                <td>{{ student.user.name }}</td>
                <td>{{ student.grade_level }}</td>
                <td>{{ student.archived_at }}</td>
                <td>{{ student.archive_reason }}</td>
                <td>
                  <div class="actions">
                    <button v-if="can('restore_students')" class="secondary compact" @click="restoreStudent(student)">{{ tr('استرجاع') }}</button>
                    <button v-if="can('delete_students')" class="danger compact" @click="deleteStudentPermanently(student)">{{ tr('حذف نهائي') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="activeTab === 'teacher-list' && can('teachers.view')" class="workspace">
        <div class="list-toolbar">
          <button v-if="can('teachers.manage')" type="button" @click="activeTab = 'teacher-create'">{{ tr('إضافة أستاذ') }}</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الاسم الكامل') }}</th><th>{{ tr('الهاتف') }}</th><th>{{ tr('البريد الإلكتروني') }}</th><th>{{ tr('المؤهل الأكاديمي') }}</th><th>{{ tr('المواد المسندة') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="teacher in teachers" :key="teacher.id">
                <td>{{ teacher.name }}</td>
                <td>{{ teacher.phone || '-' }}</td>
                <td>{{ teacher.email }}</td>
                <td>{{ teacher.academic_qualification || '-' }}</td>
                <td>
                  <span v-if="teacher.courses?.length" class="subject-count">{{ teacher.courses.length }} {{ tr('مادة') }}</span>
                  <span v-else class="muted">{{ tr('لا توجد') }}</span>
                </td>
                <td>{{ teacher.is_active ? tr('نشط') : tr('غير نشط') }}</td>
                <td>
                  <div class="actions">
                    <button v-if="can('teachers.manage')" class="secondary compact" @click="startEditTeacher(teacher)">{{ tr('تعديل') }}</button>
                    <button v-if="can('teachers.manage')" class="danger compact" @click="removeItem(`/teachers/${teacher.id}`, teacher.name)">{{ tr('حذف') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="editingTeacher" class="modal-backdrop" @click.self="editingTeacher = null">
          <article class="student-modal">
            <form @submit.prevent="updateTeacher">
              <div class="permission-card-header">
                <h3>{{ tr('تعديل بيانات الأستاذ') }}</h3>
                <button type="button" class="secondary compact" @click="editingTeacher = null">{{ tr('إغلاق') }}</button>
              </div>
              <div class="form-grid">
                <label>{{ tr('الاسم الكامل') }} <input v-model="editingTeacher.name" required /></label>
                <label>{{ tr('البريد الإلكتروني') }} <input v-model="editingTeacher.email" type="email" required /></label>
                <label>{{ tr('رقم الهاتف') }} <input v-model="editingTeacher.phone" /></label>
                <label>{{ tr('رقم هاتف 2') }} <input v-model="editingTeacher.phone_2" /></label>
                <label>{{ tr('مكان السكن') }} <input v-model="editingTeacher.address" /></label>
                <label>{{ tr('المؤهل الأكاديمي') }} <input v-model="editingTeacher.academic_qualification" /></label>
                <label>{{ tr('كلمة مرور جديدة (اختياري)') }} <input v-model="editingTeacher.password" type="password" minlength="8" autocomplete="new-password" /></label>
                <label class="checkbox-label"><input v-model="editingTeacher.is_active" type="checkbox" /> {{ tr('نشط') }}</label>
              </div>
              <div class="actions">
                <button type="submit">{{ tr('حفظ التعديل') }}</button>
              </div>
            </form>
          </article>
        </div>
      </section>

      <section v-if="activeTab === 'teacher-create' && can('teachers.manage')" class="workspace">
        <form class="student-form" @submit.prevent="submitTeacher">
          <article class="form-card required-card">
            <h3>{{ tr('البيانات الشخصية') }}</h3>
            <div class="form-grid">
              <label>{{ tr('الاسم الكامل') }} <input v-model="teacherForm.name" required /></label>
              <label>{{ tr('رقم الهاتف') }} <input v-model="teacherForm.phone" required /></label>
              <label>{{ tr('البريد الإلكتروني') }} <input v-model="teacherForm.email" type="email" required /></label>
              <label>{{ tr('مكان السكن') }} <input v-model="teacherForm.address" required /></label>
              <label>{{ tr('رقم هاتف 2') }} <input v-model="teacherForm.phone_2" required /></label>
              <label>{{ tr('المؤهل الأكاديمي') }} <input v-model="teacherForm.academic_qualification" required /></label>
            </div>
          </article>

          <article class="form-card required-card">
            <h3>{{ tr('بيانات الدخول') }}</h3>
            <div class="form-grid">
              <label>{{ tr('اسم المستخدم') }} <input v-model="teacherForm.username" required autocomplete="username" /></label>
              <label>{{ tr('كلمة المرور') }} <input v-model="teacherForm.password" type="password" minlength="8" required autocomplete="new-password" /></label>
              <label>{{ tr('تأكيد كلمة المرور') }} <input v-model="teacherForm.password_confirmation" type="password" minlength="8" required autocomplete="new-password" /></label>
            </div>
          </article>

          <button type="submit">{{ tr('حفظ الأستاذ') }}</button>
        </form>
      </section>

      <section v-if="activeTab === 'course-list' && can('courses.view')" class="workspace">
        <div class="list-toolbar">
          <button v-if="can('courses.manage')" type="button" @click="activeTab = 'course-create'">{{ tr('إضافة مادة') }}</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('اسم الفصل') }}</th><th>{{ tr('رمز الفصل') }}</th><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('عدد المواد') }}</th><th>{{ tr('المواد') }}</th></tr></thead>
            <tbody>
              <tr v-for="group in courseGroups" :key="group.id">
                <td><strong>{{ group.class_name || group.section_code }}</strong></td>
                <td>{{ group.section_code }}</td>
                <td>{{ group.academic_year }}</td>
                <td>{{ group.materials.length }}</td>
                <td>
                  <button type="button" class="secondary" @click="openCourseModal(group.id)">{{ tr('رؤية المواد') }}</button>
                </td>
              </tr>
              <tr v-if="!courseGroups.length">
                <td colspan="5">{{ tr('لا توجد فصول مسجلة. أضف فصلاً أولاً ثم أضف مواده.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <article v-if="orphanCourses.length && can('courses.manage')" class="panel">
          <h3>{{ tr('مواد غير مرتبطة بفصل') }}</h3>
          <p class="muted">
            {{ tr('هذه المواد لا تنتمي إلى أي فصل، فلا يراها طلابها ولا تظهر في القوائم أعلاه. اختر الفصل ثم حدّد المواد التي تتبعه.') }}
          </p>

          <div class="crud-form">
            <label class="grow">{{ tr('الفصل الدراسي') }}
              <select v-model="orphanTargetSection">
                <option value="">{{ tr('اختر الفصل الدراسي') }}</option>
                <option v-for="section in sections" :key="section.id" :value="section.id">
                  {{ section.class_name || section.section_code }} - {{ section.academic_year }}
                </option>
              </select>
            </label>
            <button
              type="button"
              :disabled="!orphanTargetSection || !orphanSelection.length || linkingOrphans"
              @click="linkOrphans"
            >
              {{ linkingOrphans ? tr('جاري الحفظ...') : tr('ربط بالفصل') }} ({{ orphanSelection.length }})
            </button>
            <!-- The other way out: a subject created by mistake is removed. -->
            <button
              type="button"
              class="danger"
              :disabled="!orphanSelection.length || deletingOrphans"
              @click="deleteOrphans"
            >
              {{ deletingOrphans ? tr('جاري الحذف...') : tr('حذف المحدّدة') }} ({{ orphanSelection.length }})
            </button>
            <button
              type="button"
              class="secondary"
              @click="orphanSelection = orphanCourses.map((course) => course.id)"
            >
              {{ tr('تحديد الكل') }}
            </button>
          </div>

          <div class="orphan-list">
            <label v-for="course in orphanCourses" :key="course.id" class="checkbox-label">
              <input
                type="checkbox"
                :checked="orphanSelection.includes(course.id)"
                @change="toggleOrphan(course.id)"
              />
              <strong>{{ course.name }}</strong>
              <span class="course-code">{{ course.code }}</span>
              <span v-if="course.grade_level" class="course-code">{{ course.grade_level }}</span>
            </label>
          </div>
        </article>

        <div v-if="courseModalGroup" class="modal-backdrop" @click.self="closeCourseModal">
          <article class="student-modal">
            <div class="permission-card-header">
              <div>
                <h3>{{ tr('مواد') }} {{ courseModalGroup.class_name || courseModalGroup.section_code }}</h3>
                <p class="muted">{{ courseModalGroup.section_code }} · {{ courseModalGroup.academic_year }}</p>
              </div>
              <button class="secondary compact" @click="closeCourseModal">{{ tr('إغلاق') }}</button>
            </div>
            <div v-if="courseModalGroup.materials.length" class="course-materials-list">
              <div v-for="course in courseModalGroup.materials" :key="course.id" class="course-material-item">
                <template v-if="editingCourse?.id === course.id">
                  <input v-model="editingCourse.code" :placeholder="tr('كود المادة')" />
                  <input v-model="editingCourse.name" :placeholder="tr('اسم المادة')" />
                  <input v-model.number="editingCourse.periods_per_week" type="number" min="1" max="40" :placeholder="tr('حصص/أسبوع')" style="width: 90px" />
                  <select v-model="editingCourse.class_section_id" :title="tr('الفصل الدراسي')">
                    <option v-for="section in sections" :key="section.id" :value="section.id">
                      {{ section.class_name || section.section_code }} - {{ section.academic_year }}
                    </option>
                  </select>
                  <label class="checkbox-label" :title="tr('مادة بلا امتحان لا تُدرج في صحيفة الدرجات ولا تدخل في المعدل.')">
                    <input v-model="editingCourse.has_exam" type="checkbox" />
                    {{ tr('لها امتحان') }}
                  </label>
                  <button type="button" class="compact" @click="saveCourseEdit">{{ tr('حفظ') }}</button>
                  <button type="button" class="secondary compact" @click="editingCourse = null">{{ tr('إلغاء') }}</button>
                </template>
                <template v-else>
                  <strong>{{ course.name }}</strong>
                  <span class="course-code">{{ course.code }}</span>
                  <span v-if="course.has_exam === false" class="badge danger">{{ tr('بلا امتحان') }}</span>
                  <span class="course-code">{{ course.periods_per_week ? `${course.periods_per_week} حصص/أسبوع · ${course.credit_hours} Credits` : `${course.credit_hours} Credits` }}</span>
                  <div class="course-item-actions">
                    <button v-if="can('courses.manage')" type="button" class="secondary compact" @click="startEditCourse(course)">{{ tr('تعديل') }}</button>
                    <button v-if="can('courses.manage')" type="button" class="danger compact" @click="removeItem(`/courses/${course.id}`, course.name)">{{ tr('حذف') }}</button>
                  </div>
                </template>
              </div>
            </div>
            <p v-else class="empty-state">{{ tr('لم تتم إضافة مواد لهذا الفصل بعد.') }}</p>
          </article>
        </div>
      </section>

      <section v-if="activeTab === 'course-create' && can('courses.manage')" class="workspace">
        <form class="student-form" @submit.prevent="submitCourse">
          <article class="form-card required-card">
            <h3>{{ tr('إضافة مواد للفصل') }}</h3>
            <div class="form-grid">
              <label class="section-picker">{{ tr('الفصول الدراسية') }}
                <MultiSelect
                  v-model="courseForm.class_section_ids"
                  :options="sectionOptions"
                  :placeholder="tr('اختر فصلاً أو أكثر')"
                />
                <span class="muted">{{ tr('يمكن اختيار أكثر من فصل — تُضاف نفس المواد لكل فصل مختار.') }}</span>
              </label>
              <p v-if="!sections.length" class="notice error">
                {{ tr('لا توجد فصول بعد. يرجى إضافة فصل أولاً من قسم "الفصول" قبل إضافة مادة.') }}
              </p>
            </div>

            <div class="bulk-course-list">
              <div v-for="(course, index) in courseRows" :key="index" class="bulk-course-row">
                <label>{{ tr('كود المادة') }}
                  <input v-model="course.code" required />
                </label>
                <label>{{ tr('اسم المادة') }}
                  <input v-model="course.name" required />
                </label>
                <label>{{ tr('عدد الحصص في الأسبوع') }}
                  <input v-model.number="course.periods_per_week" type="number" min="1" max="40" required style="width: 90px" />
                </label>
                <label class="checkbox-label" :title="tr('مادة بلا امتحان لا تُدرج في صحيفة الدرجات ولا تدخل في المعدل.')">
                  <input v-model="course.has_exam" type="checkbox" />
                  {{ tr('لها امتحان') }}
                </label>
                <button type="button" class="add-course-button" :title="tr('إضافة مادة أخرى')" @click="addCourseRow">+</button>
                <button
                  v-if="courseRows.length > 1"
                  type="button"
                  class="danger remove-course-button"
                  :title="tr('حذف هذا الصف')"
                  @click="removeCourseRow(index)"
                >{{ tr('حذف') }}</button>
              </div>
            </div>
          </article>
          <p v-if="courseForm.class_section_ids.length > 1" class="muted">
            {{ tr('سيتم إنشاء') }} {{ courseRows.length * courseForm.class_section_ids.length }}
            {{ tr('مادة') }} ({{ courseRows.length }} × {{ courseForm.class_section_ids.length }} {{ tr('فصل') }}).
          </p>
          <button type="submit">{{ tr('حفظ جميع المواد') }}</button>
        </form>
      </section>

      <section v-if="activeTab === 'section-list' && can('sections.view')" class="workspace">
        <div class="list-toolbar">
          <button v-if="can('sections.manage')" type="button" @click="activeTab = 'section-create'">{{ tr('إضافة فصل') }}</button>
        </div>
        <form v-if="editingSection && can('sections.manage')" class="student-form edit-panel" @submit.prevent="saveSectionEdit">
          <article class="form-card required-card">
            <div class="permission-card-header">
              <h3>{{ tr('تعديل بيانات الفصل') }}</h3>
              <button type="button" class="secondary compact" @click="editingSection = null">{{ tr('إلغاء') }}</button>
            </div>
            <div class="form-grid">
              <label>{{ tr('رمز الفصل') }} <input v-model="editingSection.section_code" required /></label>
              <label>{{ tr('اسم الفصل') }} <input v-model="editingSection.class_name" required /></label>
              <label>{{ tr('السنة الدراسية') }}
                <select v-model="editingSection.academic_year" required>
                  <option v-for="year in academicYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
              </label>
              <label>{{ tr('الاستيعاب') }} <input v-model="editingSection.capacity" type="number" min="1" /></label>
              <label>{{ tr('الأستاذ') }}
                <select v-model="editingSection.teacher_id">
                  <option value="">{{ tr('بلا معلم') }}</option>
                  <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                </select>
              </label>
            </div>
          </article>
          <button type="submit">{{ tr('حفظ التعديل') }}</button>
        </form>

        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('رمز الفصل') }}</th><th>{{ tr('اسم الفصل') }}</th><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('الأستاذ') }}</th><th>{{ tr('الاستيعاب') }}</th><th>{{ tr('الإجراءات') }}</th></tr></thead>
            <tbody>
              <tr v-for="section in sections" :key="section.id">
                <td>{{ section.section_code }}</td>
                <td>{{ section.class_name || '-' }}</td>
                <td>{{ section.academic_year }}</td>
                <td>
                  <template v-if="section.teacher?.name">{{ section.teacher.name }}</template>
                  <span v-else class="muted">{{ tr('بلا معلم') }}</span>
                </td>
                <td>{{ section.capacity || '-' }}</td>
                <td>
                  <div class="actions">
                    <button v-if="can('sections.manage')" class="secondary compact" @click="startEditSection(section)">{{ tr('تعديل') }}</button>
                    <button v-if="can('sections.manage')" class="danger compact" @click="removeItem(`/course-sections/${section.id}`, section.class_name || section.section_code)">{{ tr('حذف') }}</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!sections.length">
                <td colspan="6">{{ tr('لا توجد فصول مسجلة.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="activeTab === 'section-create' && can('sections.manage')" class="workspace">
        <form class="student-form" @submit.prevent="submitSection">
          <article class="form-card required-card">
            <h3>{{ tr('إضافة فصل') }}</h3>
            <div class="form-grid">
              <label>{{ tr('رمز الفصل') }} <input v-model="sectionForm.section_code" required /></label>
              <label>{{ tr('اسم الفصل') }} <input v-model="sectionForm.class_name" required /></label>
              <label>{{ tr('السنة الدراسية') }}
                <select v-model="sectionForm.academic_year" required>
                  <option value="">{{ tr('اختر السنة الدراسية') }}</option>
                  <option v-for="year in academicYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
              </label>
              <label>{{ tr('الاستيعاب') }} <input v-model="sectionForm.capacity" type="number" min="1" /></label>
            </div>
          </article>
          <button type="submit">{{ tr('حفظ الفصل') }}</button>
        </form>
      </section>

      <section v-if="activeTab === 'settings' && can('settings.view')" class="workspace">
        <form v-if="can('settings.manage')" class="crud-form" @submit.prevent="submitAcademicYear">
          <label>{{ tr('السنة الدراسية') }}
            <input v-model="academicYearForm.name" placeholder="2025-2026" pattern="\d{4}-\d{4}" required />
          </label>
          <label class="checkbox-label">
            <input v-model="academicYearForm.is_active" type="checkbox" />
            {{ tr('مفعلة') }}
          </label>
          <button type="submit">{{ tr('إضافة سنة دراسية') }}</button>
        </form>

        <article class="form-card">
          <h3>{{ tr('السنة الدراسية الحالية') }}</h3>
          <p class="muted">
            {{ tr('يعمل النظام على سنة دراسية واحدة مفعّلة في كل وقت. عند تفعيل سنة سابقة تظهر قوائم الطلبة وصفوفهم ودرجاتهم كما كانت في تلك السنة — الطالب الذي صار في G2 يظهر في G1 — وبالرجوع إلى السنة الحالية تعود البيانات الحالية كما هي. لا يُعدَّل أي شيء بالتبديل.') }}
          </p>

          <p v-if="viewingPastYear" class="notice past-year">
            {{ tr('🕘 السنة المفعّلة الآن') }} <strong>{{ activeAcademicYear }}</strong> {{ tr('وهي سنة سابقة. فعّل') }} <strong>{{ latestAcademicYear }}</strong> {{ tr('للعودة إلى السنة الحالية.') }}
          </p>
          <div class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('السنة الدراسية') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="year in academicYears" :key="year.id">
                  <td><strong>{{ year.name }}</strong></td>
                  <td>
                    <span v-if="year.is_active" class="pill pill-success">{{ tr('مفعّلة حالياً') }}</span>
                    <span v-else class="muted">{{ tr('غير مفعّلة') }}</span>
                  </td>
                  <td>
                    <div class="actions">
                      <button
                        v-if="can('settings.manage') && !year.is_active"
                        type="button"
                        class="secondary compact"
                        @click="activateAcademicYear(year)"
                      >
                        {{ tr('تفعيل هذه السنة') }}
                      </button>
                      <button
                        v-if="can('settings.manage') && !year.is_active"
                        type="button"
                        class="danger compact"
                        @click="removeAcademicYear(year)"
                      >
                        {{ tr('حذف') }}
                      </button>
                      <!-- Otherwise the empty cell reads as a missing button. -->
                      <span v-if="can('settings.manage') && year.is_active" class="muted">
                        {{ tr('فعّل سنة أخرى أولاً لتتمكن من حذف هذه.') }}
                      </span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="form-card">
          <h3>{{ tr('الكلمة الافتتاحية في تقرير السيمستر') }}</h3>
          <p class="muted">
            {{ tr('هذا النص يظهر في أعلى كشف درجات السيمستر لجميع الطلبة. اتركه فارغاً لإخفائه من التقرير.') }}
          </p>
          <label>
            {{ tr('نص الكلمة') }}
            <textarea
              v-model="reportSettings.semester_report_message"
              rows="7"
              :disabled="!can('settings.manage')"
              :placeholder="tr('اكتب الكلمة التي تريد ظهورها لجميع الطلبة...')"
            ></textarea>
          </label>
          <div v-if="can('settings.manage')" class="actions">
            <button type="button" :disabled="savingReportSettings" @click="saveReportSettings">
              {{ savingReportSettings ? tr('جاري الحفظ...') : tr('حفظ الكلمة') }}
            </button>
            <button type="button" class="secondary" @click="restoreDefaultReportMessage">
              {{ tr('استعادة النص الافتراضي') }}
            </button>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'grade-entry' && user?.user_type === 'teacher'">
        <TeacherGradeEntry />
      </section>

      <section v-if="activeTab === 'admin-grade-management' && can('admin_manage_grades')">
        <AdminGradeManagement />
      </section>

      <section v-if="activeTab === 'grade-submission-report' && can('admin_manage_grades')">
        <GradeSubmissionReport />
      </section>

      <section v-if="activeTab === 'term-windows' && can('admin_manage_grades')">
        <TermWindows />
      </section>

      <section v-if="activeTab === 'student-promotion' && can('students.manage')">
        <StudentPromotion />
      </section>

      <section v-if="activeTab === 'finance-accounts' && can('finance.view')">
        <StudentAccounts :can="can" />
      </section>

      <section v-if="activeTab === 'finance-discounts' && can('finance.discounts.approve')">
        <DiscountApprovals />
      </section>

      <section v-if="activeTab === 'finance-reports' && can('finance.reports.view')">
        <FinanceReports />
      </section>

      <section v-if="activeTab === 'finance-setup' && can('finance.fees.manage')">
        <FinanceSetup />
      </section>

      <section v-if="activeTab === 'finance-advances' && can('finance.view')">
        <CashAdvances :can="can" />
      </section>

      <section v-if="activeTab === 'parent-dashboard' && user?.user_type === 'parent'">
        <ParentDashboard />
      </section>

      <section v-if="activeTab === 'report-card-designer' && can('settings.view')">
        <ReportCardDesigner />
      </section>

      <section v-if="activeTab === 'google-classroom' && can('settings.manage')">
        <GoogleClassroom />
      </section>

      <section v-if="activeTab === 'classroom'">
        <Classroom />
      </section>

      <section v-if="activeTab === 'grading-settings' && can('manage_grading_structure')">
        <GradingSettings />
      </section>

      <section v-if="activeTab === 'attendance' && (can('attendance.view') || can('attendance.manage'))">
        <AttendanceRegister />
      </section>

      <section v-if="activeTab === 'calendar'">
        <SchoolCalendar />
      </section>

      <section v-if="activeTab === 'student-report' && user?.user_type === 'student'" class="workspace">
        <p v-if="!studentOpenTerms.length" class="notice warn">
          {{ tr('لم تفتح إدارة المدرسة أي فصل دراسي بعد. ستظهر درجاتك هنا فور فتحه.') }}
        </p>
        <div v-else class="crud-form">
          <label>Course
            <select v-model="reportSectionId" required>
              <option value="">Select course</option>
              <option v-for="course in studentCourses" :key="course.id" :value="course.id">
                {{ course.name }} - {{ course.class_section?.class_name || tr('بدون اسم') }}
              </option>
            </select>
          </label>
          <label>Term
            <select v-model="reportTerm">
              <option v-for="term in studentOpenTerms" :key="term" :value="term">{{ term }}</option>
            </select>
          </label>
          <button type="button" @click="loadStudentReport">Load Read-Only Report</button>
        </div>
      </section>

      <section v-if="activeTab === 'student-attendance' && user?.user_type === 'student'" class="workspace">
        <button type="button" @click="loadStudentAttendance">Load Attendance</button>
        <div v-if="studentAttendance.length" class="table-wrap">
          <table>
            <thead><tr><th>Date</th><th>Class</th><th>Status</th><th>Notes</th></tr></thead>
            <tbody>
              <tr v-for="record in studentAttendance" :key="record.id">
                <td>{{ record.attendance_date }}</td>
                <td>{{ record.course_section.section_code }} - {{ record.course_section.class_name || tr('بدون اسم') }}</td>
                <td>{{ record.status }}</td>
                <td>{{ record.notes }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="activeTab === 'student-dashboard' && user?.user_type === 'student'" class="workspace">
        <p v-if="!studentOpenTerms.length" class="notice warn">
          {{ tr('لم تفتح إدارة المدرسة أي فصل دراسي بعد. ستظهر درجاتك هنا فور فتحه.') }}
        </p>
        <template v-else>
          <div class="crud-form">
            <label>{{ tr('الفصل الدراسي') }}
              <select v-model="reportTerm">
                <option v-for="term in studentOpenTerms" :key="term" :value="term">{{ term }}</option>
              </select>
            </label>
            <button type="button" @click="loadStudentDashboard">{{ tr('عرض الدرجات') }}</button>
          </div>

          <article v-if="studentDashboard" class="form-card">
            <h3>{{ tr('درجاتي —') }} {{ studentDashboard.term }}</h3>
            <StudentGradeCards :courses="studentDashboard.courses" />
          </article>
        </template>

        <article v-if="studentPublications.length" class="form-card">
          <h3>{{ tr('كشوف الدرجات المتاحة') }}</h3>
          <div class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الفترة') }}</th><th>{{ tr('تاريخ النشر') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="publication in studentPublications" :key="publication.id">
                  <td>{{ publication.period }}</td>
                  <td>{{ publication.published_at }}</td>
                  <td>
                    <button type="button" class="secondary compact" @click="downloadPublishedReportCard(publication)">
                      {{ tr('تحميل PDF') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </section>

      <section v-if="activeTab === 'report-card' && user?.user_type === 'student'" class="workspace">
        <div class="crud-form">
          <button type="button" @click="loadReportCard">Load Report Card</button>
          <label>PDF Type
            <select v-model="pdfType">
              <option value="quarter">Quarter</option>
              <option value="semester">Semester</option>
              <option value="final">Final</option>
            </select>
          </label>
          <label>PDF Term
            <select v-model="reportTerm">
              <option>Quarter 1</option>
              <option>Quarter 2</option>
              <option>Quarter 3</option>
              <option>Quarter 4</option>
              <option>Semester 1</option>
              <option>Semester 2</option>
            </select>
          </label>
          <button type="button" @click="downloadStudentReportPdf">Download PDF</button>
        </div>
        <div v-if="reportCard" class="student-course-grid">
          <article v-for="term in reportCard.terms" :key="term.term" class="permission-card">
            <div class="permission-card-header">
              <h3>{{ term.term }}</h3>
              <span class="status">GPA {{ term.gpa?.gpa ?? 'N/A' }}</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Course</th><th>Final</th><th>Applied Weight</th><th>Missing</th></tr></thead>
                <tbody>
                  <tr v-for="course in term.courses" :key="`${term.term}-${course.section.id}`">
                    <td>{{ course.course.code }} - {{ course.course.name }}</td>
                    <td>{{ course.report.final_grade }}</td>
                    <td>{{ course.report.total_applied_weight }}</td>
                    <td>{{ course.report.missing_scores.join(', ') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>

      <section v-if="activeTab === 'teacher-summary' && user?.user_type === 'teacher'" class="workspace">
        <div class="crud-form">
          <label>Course
            <select v-model="reportSectionId" required>
              <option value="">Select course</option>
              <option v-for="course in teacherCourses" :key="course.id" :value="course.id">
                {{ course.name }} - {{ course.class_section?.class_name || tr('بدون اسم') }}
              </option>
            </select>
          </label>
          <label>Term
            <select v-model="reportTerm">
              <option>Quarter 1</option>
              <option>Quarter 2</option>
              <option>Quarter 3</option>
              <option>Quarter 4</option>
            </select>
          </label>
          <button type="button" @click="loadTeacherSummary">Load Summary</button>
        </div>

        <div v-if="teacherSummary.length" class="table-wrap">
          <table>
            <thead><tr><th>Student</th><th>Final</th><th>Applied Weight</th><th>Missing</th></tr></thead>
            <tbody>
              <tr v-for="row in teacherSummary" :key="row.student_profile.id">
                <td>{{ row.student_profile.user.name }}</td>
                <td>{{ row.report.final_grade }}</td>
                <td>{{ row.report.total_applied_weight }}</td>
                <td>{{ row.report.missing_scores.join(', ') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="activeTab === 'admin-grade-report' && can('students.view')" class="workspace">
        <div class="crud-form">
          <label>{{ tr('الفصل') }}
            <select v-model="reportCardForm.course_section_id" @change="onReportClassChange">
              <option value="">{{ tr('اختر الفصل') }}</option>
              <option v-for="section in sections" :key="section.id" :value="section.id">
                {{ section.class_name || section.section_code }} ({{ section.academic_year }})
              </option>
            </select>
          </label>
          <label>{{ tr('نوع التقرير') }}
            <select v-model="reportCardForm.type">
              <option value="quarter">{{ tr('تقرير Quarter') }}</option>
              <option value="semester">{{ tr('تقرير سيمستر') }}</option>
            </select>
          </label>
          <label v-if="reportCardForm.type === 'quarter'">{{ tr('الفترة (Quarter)') }}
            <select v-model="reportCardForm.term">
              <option>Quarter 1</option>
              <option>Quarter 2</option>
              <option>Quarter 3</option>
              <option>Quarter 4</option>
            </select>
          </label>
          <label v-else>{{ tr('السيمستر') }}
            <select v-model="reportCardForm.semester">
              <option :value="1">{{ tr('السيمستر الأول (Quarter 1 + Quarter 2)') }}</option>
              <option :value="2">{{ tr('السيمستر الثاني (Quarter 3 + Quarter 4)') }}</option>
            </select>
          </label>
          <label>{{ tr('الطالب') }}
            <select v-model="reportCardForm.student_profile_id">
              <option value="">{{ tr('كل طلاب الفصل (ملف واحد)') }}</option>
              <option v-for="row in reportClassStudents" :key="row.id" :value="row.id">
                {{ row.full_name || row.user?.name }} - {{ row.admission_no || row.student_number }}
              </option>
            </select>
          </label>
          <button type="button" :disabled="!reportCardForm.course_section_id || reportCardLoading" @click="downloadClassReportCard">
            {{ reportCardLoading ? tr('جاري إنشاء التقرير...') : tr('إنشاء التقرير (PDF)') }}
          </button>
        </div>

        <article v-if="reportCardForm.course_section_id" class="form-card">
          <h3>{{ tr('نشر التقرير للطلبة وأولياء الأمور') }}</h3>
          <p class="muted">
            {{ tr('عند النشر يظهر زر تحميل كشف الدرجات في لوحة الطالب ولوحة ولي الأمر لهذا الفصل.') }}
          </p>
          <div class="actions">
            <button type="button" :disabled="publishingReport" @click="publishReportCard(true)">
              {{ publishingReport ? tr('جاري النشر...') : `📤 نشر ${currentPublishPeriod}` }}
            </button>
            <button
              v-if="isCurrentPeriodPublished"
              type="button"
              class="danger"
              :disabled="publishingReport"
              @click="publishReportCard(false)"
            >
              {{ tr('إلغاء النشر') }}
            </button>
          </div>

          <div v-if="classPublications.length" class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الفترة المنشورة') }}</th><th>{{ tr('تاريخ النشر') }}</th><th>{{ tr('بواسطة') }}</th></tr></thead>
              <tbody>
                <tr v-for="publication in classPublications" :key="publication.id">
                  <td>{{ publication.period }}</td>
                  <td>{{ publication.published_at }}</td>
                  <td>{{ publication.published_by?.name || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="muted">{{ tr('لم يُنشر أي كشف لهذا الفصل بعد.') }}</p>
        </article>

        <div v-if="reportCardMissing.length" class="table-wrap">
          <p class="notice error" style="margin: 12px">
            {{ tr('لا يمكن إنشاء التقرير — توجد درجات ناقصة. أكمل إدخال الدرجات التالية أولاً:') }}
          </p>
          <table>
            <thead><tr><th>{{ tr('الطالب') }}</th><th>{{ tr('المادة') }}</th><th>{{ tr('الفترة') }}</th><th>{{ tr('الدرجات الناقصة') }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, index) in reportCardMissing" :key="index">
                <td>{{ row.student }} ({{ row.student_number }})</td>
                <td>{{ row.subject }}</td>
                <td>{{ row.term }}</td>
                <td>{{ row.missing_items.join('، ') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="gradeReport" class="workspace report-panel">
        <div class="dashboard-header">
          <div>
            <p class="eyebrow">Final Grade</p>
            <h2>{{ gradeReport.final_grade }} / 100</h2>
          </div>
          <span class="status">{{ gradeReport.total_applied_weight }}% applied</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Category</th><th>Weight</th><th>Average</th><th>Weighted Points</th><th>Items</th></tr></thead>
            <tbody>
              <tr v-for="category in gradeReport.category_breakdown" :key="category.category_name">
                <td>{{ category.category_name }}</td>
                <td>{{ category.weight }}</td>
                <td>{{ category.average_percent ?? 'Missing' }}</td>
                <td>{{ category.weighted_points }}</td>
                <td>{{ category.items.length }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="muted">Missing scores: {{ gradeReport.missing_scores.join(', ') || 'None' }}</p>
      </section>

      <section v-if="gpaReport?.term_gpa" class="workspace report-panel">
        <div class="dashboard-header">
          <div>
            <p class="eyebrow">GPA</p>
            <h2>{{ gpaReport.term_gpa.gpa ?? 'N/A' }}</h2>
          </div>
          <span class="status">{{ gpaReport.term_gpa.term }}</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Course</th><th>AP</th><th>Credits</th><th>Final</th><th>Points</th></tr></thead>
            <tbody>
              <tr v-for="course in gpaReport.term_gpa.courses" :key="course.course_id">
                <td>{{ course.course_code }} - {{ course.course_name }}</td>
                <td>{{ course.is_ap ? 'Yes' : 'No' }}</td>
                <td>{{ course.credit_hours }}</td>
                <td>{{ course.final_grade }}</td>
                <td>{{ course.gpa_points }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <EnrolmentPayment
        v-if="enrolmentPayment"
        :key="enrolmentPayment.studentId"
        :student-id="enrolmentPayment.studentId"
        :student-name="enrolmentPayment.studentName"
        :academic-year="enrolmentPayment.academicYear"
        :can-manage-fees="can('finance.fees.manage')"
        @done="finishEnrolmentPayment"
      />

      <div v-if="subjectsModal" class="modal-backdrop" @click.self="subjectsModal = null">
        <article class="student-modal subjects-modal">
          <div class="permission-card-header">
            <div>
              <h3>{{ tr('المواد المسندة —') }} {{ subjectsModal.name }}</h3>
              <p class="muted">{{ tr('يستطيع هذا الأستاذ إدخال درجات المواد المختارة فقط.') }}</p>
            </div>
            <button type="button" class="secondary compact" @click="subjectsModal = null">{{ tr('إغلاق') }}</button>
          </div>
          <TeacherSubjectPicker v-model="subjectsModal.selected" :teacher-id="subjectsModal.id" />
          <div class="actions">
            <button type="button" @click="saveSubjectsModal">{{ tr('حفظ المواد') }}</button>
            <button type="button" class="secondary" @click="subjectsModal = null">{{ tr('إلغاء') }}</button>
          </div>
        </article>
      </div>
    </section>
    </section>

    <ConfirmDialog />
  </main>
</template>
