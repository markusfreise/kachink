import api from '@/api/client'

/**
 * Downloads an authenticated file (PDF/CSV) through axios so the bearer
 * token and organization header are sent, then triggers a browser save.
 */
export async function downloadFile(url: string, params: Record<string, unknown> = {}): Promise<void> {
  const response = await api.get(url, { params, responseType: 'blob' })

  const disposition: string = response.headers['content-disposition'] ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = match?.[1] ? decodeURIComponent(match[1]) : 'download'

  const blobUrl = URL.createObjectURL(response.data as Blob)
  const link = document.createElement('a')
  link.href = blobUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
}
