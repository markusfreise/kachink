import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import i18n from '@/i18n'

function load<T>(key: string, fallback: T): T {
  const raw = localStorage.getItem(`setting:${key}`)
  if (raw === null) return fallback
  try { return JSON.parse(raw) as T } catch { return fallback }
}

export const useSettingsStore = defineStore('settings', () => {
  // Minutes to round up to (0 = disabled, 5, 10, 15, 30, 60)
  const roundingInterval = ref<number>(load('roundingInterval', 15))

  // Language: 'auto', 'en', or 'de'
  const locale = ref<string>(load('locale', 'auto'))

  watch(roundingInterval, (val) => {
    localStorage.setItem('setting:roundingInterval', JSON.stringify(val))
  })

  watch(locale, (val) => {
    localStorage.setItem('setting:locale', JSON.stringify(val))
    applyLocale(val)
  })

  function applyLocale(loc: string) {
    if (loc === 'auto') {
      const browserLang = navigator.language.split('-')[0]
      i18n.global.locale.value = browserLang === 'de' ? 'de' : 'en'
    } else {
      i18n.global.locale.value = loc as 'en' | 'de'
    }
  }

  function roundUpSeconds(seconds: number | null): number {
    if (!seconds) return 0
    if (!roundingInterval.value) return seconds
    const intervalSec = roundingInterval.value * 60
    return Math.ceil(seconds / intervalSec) * intervalSec
  }

  return { roundingInterval, locale, roundUpSeconds }
})
