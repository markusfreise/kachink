/** Escapes text for HTML and wraps "@Vorname Nachname" of known members in a mention span. */
export function mentionHtml(text: string | null | undefined, names: string[]): string {
  const escaped = (text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
  const sorted = [...names].filter(Boolean).sort((a, b) => b.length - a.length)
  let html = escaped
  for (const name of sorted) {
    const safe = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    html = html.replace(new RegExp(`(^|[^\\p{L}\\p{N}])@${safe}(?![\\p{L}\\p{N}])`, 'giu'), (m, pre: string) => `${pre}<span class="mention__tag">@${name}</span>`)
  }
  return html
}
