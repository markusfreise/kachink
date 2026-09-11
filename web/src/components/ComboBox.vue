<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import UserAvatar from '@/components/UserAvatar.vue'

export interface ComboOption {
  id: string
  label: string
  subtitle?: string
  color?: string
  /** Picture shown in front of the label (falls back to the initial). */
  avatarUrl?: string | null
  avatar?: boolean
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: ComboOption[]
  placeholder?: string
  clearLabel?: string
  clearable?: boolean
  allowCreate?: boolean
  disabled?: boolean
  size?: 'default' | 'sm'
  id?: string
}>(), {
  placeholder: '',
  clearLabel: '',
  clearable: false,
  allowCreate: false,
  disabled: false,
  size: 'default',
  id: undefined,
})

const { t } = useI18n()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'create': [label: string]
}>()

const uid = `combobox-${Math.random().toString(36).slice(2, 8)}`
const listId = `${uid}-list`

const query = ref('')
const isOpen = ref(false)
const activeIndex = ref(-1)
const inputRef = ref<HTMLInputElement>()
const listRef = ref<HTMLElement>()

const selectedOption = computed(() =>
  props.modelValue ? props.options.find((o) => o.id === props.modelValue) ?? null : null
)

const filteredOptions = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter(
    (o) => o.label.toLowerCase().includes(q) || (o.subtitle?.toLowerCase().includes(q) ?? false)
  )
})

const showCreate = computed(
  () =>
    props.allowCreate &&
    query.value.trim().length > 0 &&
    !filteredOptions.value.some((o) => o.label.toLowerCase() === query.value.trim().toLowerCase())
)

/** Rows in the listbox in visual order: clear, options, create. */
type Row = { kind: 'clear' } | { kind: 'option'; option: ComboOption } | { kind: 'create' }
const rows = computed<Row[]>(() => {
  const list: Row[] = []
  if (props.clearable && !query.value) list.push({ kind: 'clear' })
  for (const option of filteredOptions.value) list.push({ kind: 'option', option })
  if (showCreate.value) list.push({ kind: 'create' })
  return list
})

function rowId(index: number) {
  return `${uid}-row-${index}`
}

function open() {
  if (props.disabled || isOpen.value) return
  isOpen.value = true
  query.value = ''
  const current = rows.value.findIndex((r) => r.kind === 'option' && r.option.id === props.modelValue)
  activeIndex.value = current >= 0 ? current : rows.value.length > 0 ? 0 : -1
}

function close() {
  isOpen.value = false
  query.value = ''
  activeIndex.value = -1
}

function select(id: string) {
  emit('update:modelValue', id)
  close()
}

function activate(row: Row) {
  if (row.kind === 'clear') select('')
  else if (row.kind === 'option') select(row.option.id)
  else requestCreate()
}

function requestCreate() {
  const label = query.value.trim()
  if (label) emit('create', label)
  close()
}

function onInput(event: Event) {
  query.value = (event.target as HTMLInputElement).value
  if (!isOpen.value) isOpen.value = true
  activeIndex.value = rows.value.length > 0 ? 0 : -1
}

function onKeydown(e: KeyboardEvent) {
  if (props.disabled) return
  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault()
      if (!isOpen.value) open()
      else moveActive(1)
      break
    case 'ArrowUp':
      e.preventDefault()
      if (!isOpen.value) open()
      else moveActive(-1)
      break
    case 'Home':
      if (isOpen.value) { e.preventDefault(); activeIndex.value = 0 }
      break
    case 'End':
      if (isOpen.value) { e.preventDefault(); activeIndex.value = rows.value.length - 1 }
      break
    case 'Enter':
      if (isOpen.value) {
        e.preventDefault()
        const row = rows.value[activeIndex.value]
        if (row) activate(row)
      }
      break
    case 'Escape':
      if (isOpen.value) {
        e.preventDefault()
        e.stopPropagation()
        close()
      }
      break
    case 'Tab':
      if (isOpen.value) close()
      break
    case 'Backspace':
      if (!isOpen.value && props.clearable && props.modelValue) {
        select('')
      }
      break
  }
}

function moveActive(delta: number) {
  const count = rows.value.length
  if (count === 0) return
  activeIndex.value = (activeIndex.value + delta + count) % count
}

watch(activeIndex, async (index) => {
  await nextTick()
  const el = listRef.value?.querySelector<HTMLElement>(`#${rowId(index)}`)
  el?.scrollIntoView({ block: 'nearest' })
})

function onBlur(e: FocusEvent) {
  const next = e.relatedTarget as Node | null
  if (next && listRef.value?.contains(next)) return
  close()
}

function focusInput() {
  if (!props.disabled) inputRef.value?.focus()
}
</script>

<template>
  <div class="combobox" :class="{ 'combobox--disabled': disabled, 'combobox--open': isOpen, 'combobox--sm': size === 'sm' }">
    <div class="combobox__control" @mousedown.prevent="focusInput(); isOpen ? close() : open()">
      <UserAvatar v-if="!isOpen && selectedOption?.avatar" :name="selectedOption.label" :avatar-url="selectedOption.avatarUrl" size="sm" />
      <span
        v-else-if="!isOpen && selectedOption?.color"
        class="color-dot"
        :style="{ backgroundColor: selectedOption.color }"
      ></span>
      <input
        :id="id"
        ref="inputRef"
        class="combobox__input"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="isOpen"
        :aria-controls="listId"
        :aria-activedescendant="isOpen && activeIndex >= 0 ? rowId(activeIndex) : undefined"
        :value="isOpen ? query : (selectedOption?.label ?? '')"
        :placeholder="isOpen ? t('common.typeToSearch') : (placeholder || t('common.select'))"
        :disabled="disabled"
        autocomplete="off"
        @input="onInput"
        @focus="open"
        @blur="onBlur"
        @keydown="onKeydown"
      />
      <span v-if="!isOpen && selectedOption?.subtitle" class="combobox__subtitle">{{ selectedOption.subtitle }}</span>
      <button
        v-if="clearable && modelValue && !isOpen && !disabled"
        type="button"
        class="combobox__clear"
        :aria-label="$t('common.clear')"
        @mousedown.prevent.stop="select('')"
      >
        <XMarkIcon class="combobox__icon" />
      </button>
      <ChevronDownIcon class="combobox__chevron" aria-hidden="true" />
    </div>

    <ul v-if="isOpen" :id="listId" ref="listRef" class="combobox__list" role="listbox" tabindex="-1">
      <li
        v-for="(row, index) in rows"
        :id="rowId(index)"
        :key="row.kind === 'option' ? row.option.id : row.kind"
        class="combobox__option"
        :class="{
          'combobox__option--active': index === activeIndex,
          'combobox__option--selected': row.kind === 'option' && row.option.id === modelValue,
          'combobox__option--muted': row.kind === 'clear',
          'combobox__option--create': row.kind === 'create',
        }"
        role="option"
        :aria-selected="row.kind === 'option' && row.option.id === modelValue"
        @mousedown.prevent="activate(row)"
        @mousemove="activeIndex = index"
      >
        <template v-if="row.kind === 'clear'">{{ clearLabel || $t('common.all') }}</template>
        <template v-else-if="row.kind === 'option'">
          <UserAvatar v-if="row.option.avatar" :name="row.option.label" :avatar-url="row.option.avatarUrl" size="sm" />
          <span v-else-if="row.option.color" class="color-dot" :style="{ backgroundColor: row.option.color }"></span>
          <span class="combobox__label">{{ row.option.label }}</span>
          <span v-if="row.option.subtitle" class="combobox__subtitle">{{ row.option.subtitle }}</span>
        </template>
        <template v-else>{{ $t('common.create', { name: query.trim() }) }}</template>
      </li>
      <li v-if="rows.length === 0" class="combobox__empty">{{ $t('common.noResults') }}</li>
    </ul>
  </div>
</template>
