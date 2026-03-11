import { createI18n } from 'vue-i18n'
import en from './en'
import de from './de'

function getDefaultLocale(): string {
  // Check localStorage for user preference
  const stored = localStorage.getItem('setting:locale')
  if (stored) {
    try {
      const parsed = JSON.parse(stored)
      if (parsed && parsed !== 'auto') return parsed
    } catch { /* ignore */ }
  }

  // Auto-detect from browser
  const browserLang = navigator.language.split('-')[0]
  return browserLang === 'de' ? 'de' : 'en'
}

const i18n = createI18n({
  legacy: false,
  locale: getDefaultLocale(),
  fallbackLocale: 'en',
  messages: { en, de },
})

export default i18n
