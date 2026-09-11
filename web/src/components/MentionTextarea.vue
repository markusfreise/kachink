<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import type { User } from '@/types'
import UserAvatar from '@/components/UserAvatar.vue'

/**
 * Textarea with @-mentions: typing "@" opens a member list, picking one
 * inserts "@Vorname Nachname". The API resolves those names to members and
 * notifies them.
 */
const props = withDefaults(defineProps<{
  modelValue: string
  users: User[]
  rows?: number
  placeholder?: string
  id?: string
  ariaLabel?: string
  autofocus?: boolean
}>(), { rows: 3, placeholder: '', id: undefined, ariaLabel: undefined, autofocus: false })

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'submit'): void
  (e: 'escape'): void
}>()

const textarea = ref<HTMLTextAreaElement | null>(null)
const open = ref(false)
const query = ref('')
const anchor = ref(-1)
const active = ref(0)

const matches = computed(() => {
  if (!open.value) return []
  const q = query.value.toLowerCase()
  return props.users.filter((u) => u.is_active !== false && u.name.toLowerCase().includes(q)).slice(0, 8)
})

function onInput(event: Event) {
  const el = event.target as HTMLTextAreaElement
  emit('update:modelValue', el.value)
  detect(el)
}

/** Looks backwards from the caret for an "@" that starts a mention. */
function detect(el: HTMLTextAreaElement) {
  const caret = el.selectionStart ?? el.value.length
  const before = el.value.slice(0, caret)
  const at = before.lastIndexOf('@')
  if (at < 0 || (at > 0 && /[\p{L}\p{N}]/u.test(before[at - 1] ?? ''))) {
    close()
    return
  }
  const text = before.slice(at + 1)
  if (/\n/.test(text) || text.length > 40) {
    close()
    return
  }
  anchor.value = at
  query.value = text
  open.value = true
  active.value = 0
}

function close() {
  open.value = false
  query.value = ''
  anchor.value = -1
}

async function pick(user: User) {
  const el = textarea.value
  if (!el || anchor.value < 0) return
  const caret = el.selectionStart ?? el.value.length
  const value = `${el.value.slice(0, anchor.value)}@${user.name} ${el.value.slice(caret)}`
  emit('update:modelValue', value)
  close()
  await nextTick()
  const pos = anchor.value + user.name.length + 2
  el.focus()
  el.setSelectionRange(pos, pos)
}

function onKeydown(event: KeyboardEvent) {
  if (open.value && matches.value.length) {
    if (event.key === 'ArrowDown') { event.preventDefault(); active.value = (active.value + 1) % matches.value.length; return }
    if (event.key === 'ArrowUp') { event.preventDefault(); active.value = (active.value - 1 + matches.value.length) % matches.value.length; return }
    if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); pick(matches.value[active.value]!); return }
    if (event.key === 'Escape') { event.preventDefault(); close(); return }
  }
  if (event.key === 'Escape') emit('escape')
  if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) { event.preventDefault(); emit('submit') }
}

function onBlur() {
  // Let a click on the list land first.
  setTimeout(close, 150)
}
</script>

<template>
  <div class="mention">
    <textarea
      :id="id"
      ref="textarea"
      class="form__textarea"
      :rows="rows"
      :value="modelValue"
      :placeholder="placeholder"
      :aria-label="ariaLabel"
      :autofocus="autofocus"
      @input="onInput"
      @keydown="onKeydown"
      @click="detect($event.target as HTMLTextAreaElement)"
      @blur="onBlur"
    ></textarea>
    <ul v-if="open && matches.length" class="mention__list" role="listbox">
      <li
        v-for="(user, index) in matches"
        :key="user.id"
        class="mention__item"
        :class="{ 'mention__item--active': index === active }"
        role="option"
        :aria-selected="index === active"
        @mousedown.prevent="pick(user)"
        @mousemove="active = index"
      >
        <UserAvatar :name="user.name" :avatar-url="user.avatar_url" size="sm" />
        <span>{{ user.name }}</span>
      </li>
    </ul>
    <p class="mention__hint">{{ $t('mentions.hint') }}</p>
  </div>
</template>
