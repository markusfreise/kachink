import { describe, it, expect } from 'vitest'
import { mentionHtml } from '@/utils/mentions'

describe('mentionHtml', () => {
  it('escapes html and wraps known names', () => {
    const html = mentionHtml('Bitte @Eva Maria Klein <b>pruefen</b>, @Eva nicht', ['Eva Maria Klein', 'Eva'])
    expect(html).toContain('<span class="mention__tag">@Eva Maria Klein</span> &lt;b&gt;pruefen&lt;/b&gt;')
    expect(html).toContain('<span class="mention__tag">@Eva</span> nicht')
  })

  it('leaves unknown mentions and emails alone', () => {
    expect(mentionHtml('mail@example.com und @Niemand', ['Anna Berg'])).toBe('mail@example.com und @Niemand')
  })
})
