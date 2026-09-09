import { createI18n } from 'vue-i18n'
import en from './en'
import de from './de'

type Messages = Record<string, unknown>

/**
 * Per-feature message additions live in ./additions/*.ts and export
 * `{ en: {...}, de: {...} }`. They are deep-merged into the base catalogs so
 * features can ship their own strings without touching en.ts / de.ts.
 */
const additionModules = import.meta.glob<{ default: { en: Messages; de: Messages } }>('./additions/*.ts', { eager: true })

function deepMerge(target: Messages, source: Messages): Messages {
  for (const [key, value] of Object.entries(source)) {
    const existing = target[key]
    if (value && typeof value === 'object' && !Array.isArray(value) && existing && typeof existing === 'object') {
      target[key] = deepMerge({ ...(existing as Messages) }, value as Messages)
    } else {
      target[key] = value
    }
  }
  return target
}

const messagesEn: Messages = { ...en }
const messagesDe: Messages = { ...de }
for (const mod of Object.values(additionModules)) {
  deepMerge(messagesEn, mod.default.en)
  deepMerge(messagesDe, mod.default.de)
}

function getDefaultLocale(): string {
  const stored = localStorage.getItem('setting:locale')
  if (stored) {
    try {
      const parsed = JSON.parse(stored)
      if (parsed && parsed !== 'auto') return parsed
    } catch { /* ignore */ }
  }
  const browserLang = navigator.language.split('-')[0]
  return browserLang === 'de' ? 'de' : 'en'
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: getDefaultLocale(),
  fallbackLocale: 'en',
  messages: { en: messagesEn as typeof en, de: messagesDe as typeof en },
})

export default i18n
