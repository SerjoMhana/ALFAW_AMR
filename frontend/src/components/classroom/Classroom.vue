<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmDelete } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * The school's own class stream: one wall per subject.
 *
 * The stream is read a page at a time by cursor rather than loaded whole — a
 * subject accumulates thousands of posts over the years, and opening it should
 * cost the same in June as it did in September.
 */
const { api, apiUpload, apiBlob } = useApi()

const courses = ref([])
const courseId = ref('')
const stream = ref(null)
const posts = ref([])
const cursor = ref(null)
const loading = ref(false)
const loadingMore = ref(false)
const busy = ref('')

// The composer.
const draft = ref(emptyDraft())
const files = ref([])
const fileInput = ref(null)
const posting = ref(false)

// Comment boxes, keyed by post, so one open box does not disturb another.
const commentDraft = ref({})

const course = computed(() => stream.value?.course ?? null)
const canPost = computed(() => Boolean(course.value?.can_post))

function emptyDraft() {
  return { type: 'announcement', title: '', body: '', comments_enabled: true }
}

onMounted(loadCourses)

watch(courseId, (value) => {
  posts.value = []
  cursor.value = null
  stream.value = null
  if (value) openCourse()
})

async function loadCourses() {
  try {
    courses.value = (await api('/classroom/courses')).data
    if (!courseId.value && courses.value.length) courseId.value = courses.value[0].id
  } catch (err) {
    notifyError(err.message)
  }
}

async function openCourse() {
  loading.value = true

  try {
    const response = await api(`/classroom/courses/${courseId.value}/stream`)
    stream.value = response.data
    posts.value = response.data.posts
    cursor.value = response.data.next_cursor

    // Reading the top of the stream is what marks it read; the mark is one
    // moving number per person, not a row per post.
    await api(`/classroom/courses/${courseId.value}/seen`, { method: 'POST' })
    const entry = courses.value.find((row) => row.id === courseId.value)
    if (entry) entry.unread = 0
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (!cursor.value || loadingMore.value) return
  loadingMore.value = true

  try {
    const response = await api(`/classroom/courses/${courseId.value}/stream?before=${cursor.value}`)
    posts.value = posts.value.concat(response.data.posts)
    cursor.value = response.data.next_cursor
  } catch (err) {
    notifyError(err.message)
  } finally {
    loadingMore.value = false
  }
}

// ---- writing ---------------------------------------------------------------

function chooseFiles(event) {
  const chosen = [...(event.target.files ?? [])]
  files.value = files.value.concat(chosen)
  if (fileInput.value) fileInput.value.value = ''
}

function dropFile(index) {
  files.value.splice(index, 1)
}

async function publish() {
  if (!draft.value.title.trim() && !draft.value.body.trim()) {
    notifyError(pick('اكتب عنواناً أو نصاً للمنشور.', 'Write a title or some text first.'))

    return
  }

  posting.value = true

  try {
    // Held back as a draft while the files upload, so the class never sees an
    // announcement whose attachment is still on its way.
    const created = await api(`/classroom/courses/${courseId.value}/posts`, {
      method: 'POST',
      body: JSON.stringify({ ...draft.value, publish: files.value.length === 0 }),
    })
    const postId = created.data.id

    for (const file of files.value) {
      const body = new FormData()
      body.append('file', file)
      await apiUpload(`/classroom/posts/${postId}/attachments`, body)
    }

    if (files.value.length) {
      await api(`/classroom/posts/${postId}/publish`, { method: 'POST' })
    }

    draft.value = emptyDraft()
    files.value = []
    notifySuccess(pick('تم نشر المنشور.', 'Posted.'))
    await openCourse()
  } catch (err) {
    notifyError(err.message)
  } finally {
    posting.value = false
  }
}

async function togglePin(post) {
  busy.value = `pin-${post.id}`

  try {
    await api(`/classroom/posts/${post.id}/pin`, { method: 'POST' })
    await openCourse()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

async function removePost(post) {
  const ok = await confirmDelete(pick(
    'سيُحذف المنشور بمرفقاته وتعليقاته من الفصل.',
    'The post, its files and its comments are removed from the class.',
  ))
  if (!ok) return

  try {
    await api(`/classroom/posts/${post.id}`, { method: 'DELETE' })
    posts.value = posts.value.filter((row) => row.id !== post.id)
    notifySuccess(pick('تم حذف المنشور.', 'Post deleted.'))
  } catch (err) {
    notifyError(err.message)
  }
}

// ---- files -----------------------------------------------------------------

async function download(attachment) {
  busy.value = `file-${attachment.id}`

  try {
    const blob = await apiBlob(`/classroom/attachments/${attachment.id}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.name
    link.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

function sizeLabel(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// ---- comments ---------------------------------------------------------------

async function sendComment(post) {
  const body = (commentDraft.value[post.id] ?? '').trim()
  if (!body) return

  busy.value = `comment-${post.id}`

  try {
    const created = await api(`/classroom/posts/${post.id}/comments`, {
      method: 'POST',
      body: JSON.stringify({ body }),
    })
    post.comments.push(created.data)
    post.comments_count += 1
    commentDraft.value[post.id] = ''
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

async function showAllComments(post) {
  busy.value = `thread-${post.id}`

  try {
    const response = await api(`/classroom/posts/${post.id}/comments`)
    post.comments = response.data
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

async function removeComment(post, comment) {
  const ok = await confirmDelete(pick('سيُحذف التعليق.', 'The comment is removed.'))
  if (!ok) return

  try {
    await api(`/classroom/comments/${comment.id}`, { method: 'DELETE' })
    post.comments = post.comments.filter((row) => row.id !== comment.id)
    post.comments_count = Math.max(0, post.comments_count - 1)
  } catch (err) {
    notifyError(err.message)
  }
}

function when(iso) {
  if (!iso) return ''

  return new Date(iso).toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <div class="workspace">
    <article v-if="!courses.length" class="panel">
      <h3>{{ tr('الفصل الإلكتروني') }}</h3>
      <p class="muted">{{ tr('لا توجد مواد متاحة لك بعد.') }}</p>
    </article>

    <div v-else class="classroom">
      <!-- ------------------------------ subjects ------------------------------ -->
      <aside class="panel classroom-courses">
        <h3>{{ tr('موادي') }}</h3>
        <button
          v-for="row in courses"
          :key="row.id"
          type="button"
          :class="['course-chip', row.id === courseId ? '' : 'secondary']"
          @click="courseId = row.id"
        >
          <span class="course-chip-name">{{ row.name }}</span>
          <span class="course-chip-meta">{{ row.class_name }} · {{ row.academic_year }}</span>
          <span v-if="row.unread" class="course-chip-unread">{{ row.unread }}</span>
        </button>
      </aside>

      <!-- ------------------------------- stream ------------------------------- -->
      <div class="classroom-stream">
        <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

        <template v-else-if="course">
          <article class="panel classroom-header">
            <div>
              <h3>{{ course.name }}</h3>
              <p class="muted">
                {{ course.class_name }} · {{ course.academic_year }}
                <template v-if="course.teacher"> · {{ course.teacher }}</template>
              </p>
            </div>
          </article>

          <!-- ------------------------------ composer ------------------------------ -->
          <article v-if="canPost" class="panel">
            <div class="crud-form">
              <label>{{ tr('نوع المنشور') }}
                <select v-model="draft.type">
                  <option value="announcement">{{ tr('إعلان') }}</option>
                  <option value="material">{{ tr('مادة تعليمية') }}</option>
                </select>
              </label>
              <label class="grow">{{ tr('العنوان') }} <input v-model="draft.title" /></label>
              <label class="checkbox-label">
                <input v-model="draft.comments_enabled" type="checkbox" /> {{ tr('السماح بالتعليقات') }}
              </label>
            </div>

            <label class="grow">{{ tr('النص') }}
              <textarea v-model="draft.body" rows="3" :placeholder="tr('اكتب ما تريد قوله للفصل...')"></textarea>
            </label>

            <div v-if="files.length" class="file-chips">
              <span v-for="(file, index) in files" :key="`${file.name}-${index}`" class="file-chip">
                {{ file.name }} <small>{{ sizeLabel(file.size) }}</small>
                <button type="button" class="chip-x" @click="dropFile(index)">×</button>
              </span>
            </div>

            <div class="actions">
              <input ref="fileInput" type="file" multiple :disabled="posting" @change="chooseFiles" />
              <button type="button" :disabled="posting" @click="publish">
                {{ posting ? tr('جاري النشر...') : tr('نشر') }}
              </button>
            </div>
          </article>

          <!-- -------------------------------- posts -------------------------------- -->
          <p v-if="!posts.length" class="muted">{{ tr('لا توجد منشورات بعد.') }}</p>

          <article v-for="post in posts" :key="post.id" class="panel class-post">
            <header class="class-post-head">
              <div>
                <strong>{{ post.author.name }}</strong>
                <span class="muted"> · {{ when(post.published_at) }}</span>
                <span v-if="post.is_pinned" class="badge">{{ tr('مثبّت') }}</span>
                <span v-if="!post.is_published" class="badge danger">{{ tr('مسودة') }}</span>
                <span v-if="post.type === 'material'" class="badge">{{ tr('مادة تعليمية') }}</span>
              </div>
              <div class="actions">
                <button
                  v-if="post.can_pin"
                  type="button"
                  class="secondary compact"
                  :disabled="busy === `pin-${post.id}`"
                  @click="togglePin(post)"
                >
                  {{ post.is_pinned ? tr('إلغاء التثبيت') : tr('تثبيت') }}
                </button>
                <button v-if="post.can_delete" type="button" class="danger compact" @click="removePost(post)">
                  {{ tr('حذف') }}
                </button>
              </div>
            </header>

            <h4 v-if="post.title">{{ post.title }}</h4>
            <p v-if="post.body" class="class-post-body">{{ post.body }}</p>

            <div v-if="post.attachments.length" class="attachments">
              <button
                v-for="file in post.attachments"
                :key="file.id"
                type="button"
                class="secondary attachment"
                :disabled="busy === `file-${file.id}`"
                @click="download(file)"
              >
                <span class="attachment-kind">{{ file.is_pdf ? 'PDF' : tr('ملف') }}</span>
                <span class="attachment-name">{{ file.name }}</span>
                <small class="muted">{{ sizeLabel(file.size) }}</small>
              </button>
            </div>

            <!-- ------------------------------ comments ------------------------------ -->
            <div v-if="post.comments_enabled" class="comments">
              <button
                v-if="post.comments_count > post.comments.length"
                type="button"
                class="link-button"
                :disabled="busy === `thread-${post.id}`"
                @click="showAllComments(post)"
              >
                {{ tr('عرض كل التعليقات') }} ({{ post.comments_count }})
              </button>

              <div v-for="comment in post.comments" :key="comment.id" class="comment">
                <span><strong>{{ comment.user.name }}</strong> {{ comment.body }}</span>
                <button
                  v-if="comment.can_delete"
                  type="button"
                  class="chip-x"
                  @click="removeComment(post, comment)"
                >×</button>
              </div>

              <div v-if="post.is_published" class="comment-box">
                <input
                  v-model="commentDraft[post.id]"
                  :placeholder="tr('اكتب تعليقاً...')"
                  @keyup.enter="sendComment(post)"
                />
                <button
                  type="button"
                  class="compact"
                  :disabled="busy === `comment-${post.id}`"
                  @click="sendComment(post)"
                >
                  {{ tr('إرسال') }}
                </button>
              </div>
            </div>
          </article>

          <div v-if="cursor" class="actions">
            <button type="button" class="secondary" :disabled="loadingMore" @click="loadMore">
              {{ loadingMore ? tr('جاري التحميل...') : tr('عرض منشورات أقدم') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.classroom {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 1024px) {
  .classroom {
    grid-template-columns: 260px minmax(0, 1fr);
    align-items: start;
  }

  .classroom-courses {
    position: sticky;
    top: 1rem;
  }
}

.classroom-stream {
  display: grid;
  gap: 1.25rem;
  min-width: 0;
}

.course-chip {
  display: grid;
  gap: 0.15rem;
  text-align: start;
  position: relative;
  padding-inline-end: 2.5rem;
}

.course-chip-name { font-weight: 600; }
.course-chip-meta { font-size: 0.75rem; opacity: 0.8; }

.course-chip-unread {
  position: absolute;
  inset-inline-end: 0.6rem;
  top: 50%;
  transform: translateY(-50%);
  min-width: 1.4rem;
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
  background: var(--app-danger);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 700;
  text-align: center;
}

.classroom-header { padding-block: 1rem; }

.class-post-head {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  justify-content: space-between;
}

/* Line breaks the teacher typed are meaningful; the text is escaped by Vue. */
.class-post-body { white-space: pre-wrap; line-height: 1.8; }

.attachments, .file-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.attachment {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  max-width: 100%;
}

.attachment-kind {
  font-size: 0.68rem;
  font-weight: 700;
  padding: 0.1rem 0.4rem;
  border-radius: 0.35rem;
  background: var(--app-accent-soft);
  color: var(--app-accent);
}

.attachment-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 22ch;
}

.file-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.6rem;
  border-radius: 999px;
  border: 1px solid var(--app-border);
  background: var(--app-surface-muted);
  font-size: 0.8rem;
}

.chip-x {
  background: none;
  border: none;
  color: var(--app-text-muted);
  padding: 0 0.2rem;
  font-size: 1rem;
  line-height: 1;
  box-shadow: none;
}

.chip-x:hover { color: var(--app-danger-text); background: none; }

.comments {
  display: grid;
  gap: 0.4rem;
  border-top: 1px solid var(--app-border);
  padding-top: 0.75rem;
}

.comment {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.88rem;
  line-height: 1.7;
}

.comment-box {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.comment-box input { flex: 1; }

.link-button {
  background: none;
  border: none;
  box-shadow: none;
  padding: 0;
  color: var(--app-accent);
  font-size: 0.85rem;
  justify-content: start;
}

.link-button:hover { background: none; text-decoration: underline; }
</style>
