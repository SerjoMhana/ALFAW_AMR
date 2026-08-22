import { computed, ref } from 'vue'

/**
 * Light or dark, remembered between visits. Falls back to whatever the operating
 * system asks for so the first visit already matches the user's habit.
 */
const stored = localStorage.getItem('ui_theme')
const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false

export const theme = ref(stored ?? (prefersDark ? 'dark' : 'light'))

export const isDark = computed(() => theme.value === 'dark')

function paint() {
  // A class on the root element, so every stylesheet can react with one selector
  // and there is no flash of the wrong palette inside components.
  document.documentElement.classList.toggle('dark', theme.value === 'dark')
  document.documentElement.style.colorScheme = theme.value
}

export function setTheme(value) {
  theme.value = value === 'dark' ? 'dark' : 'light'
  localStorage.setItem('ui_theme', theme.value)
  paint()
}

export function toggleTheme() {
  setTheme(isDark.value ? 'light' : 'dark')
}

export function useTheme() {
  return { theme, isDark, setTheme, toggleTheme }
}

paint()
