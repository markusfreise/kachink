/** Escapes text for HTML and wraps "@Vorname Nachname" of known members in a mention span. */
export function mentionHtml(text: string | null | undefined, names: string[]): string {
  const escaped = (text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
  const sorted = [...new Set(names)].filter(Boolean).sort((a, b) => b.length - a.length)
  if (sorted.length === 0) return escaped
  // One pass with the longest names first, so "Eva Maria Klein" wins over "Eva" and nothing is wrapped twice.
  const pattern = sorted.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')
  return escaped.replace(
    new RegExp(`(^|[^\\p{L}\\p{N}])@(${pattern})(?![\\p{L}\\p{N}])`, 'giu'),
    (_m, pre: string, name: string) => `${pre}<span class="mention__tag">@${name}</span>`,
  )
}
