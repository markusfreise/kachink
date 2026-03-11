<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon, XMarkIcon } from '@heroicons/vue/24/outline'

export interface ComboOption {
  id: string
  label: string
  subtitle?: string
  color?: string
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: ComboOption[]
  placeholder?: string
  clearLabel?: string
  clearable?: boolean
  allowCreate?: boolean
  disabled?: boolean
}>(), {
  placeholder: 'Select...',
  clearLabel: 'All',
  clearable: false,
  allowCreate: false,
  disabled: false,
})

const { t } = useI18n()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'create': [label: string]
}>()

const query = ref('')
const isOpen = ref(false)
const inputRef = ref<HTMLInputElement>()

const selectedOption = computed(() =>
  props.modelValue ? props.options.find(o => o.id === props.modelValue) ?? null : null
)

const filteredOptions = computed(() => {
  if (!query.value) return props.options
  const q = query.value.toLowerCase()
  return props.options.filter(o =>
    o.label.toLowerCase().includes(q) ||
    (o.subtitle?.toLowerCase().includes(q) ?? false)
  )
})

const showCreate = computed(() =>
  props.allowCreate &&
  query.value.trim().length > 0 &&
  !filteredOptions.value.some(o => o.label.toLowerCase() === query.value.trim().toLowerCase())
)

function onFocus() {
  isOpen.value = true
  query.value = ''
}

function onBlur() {
  setTimeout(() => {
    isOpen.value = false
    query.value = ''
  }, 150)
}

function select(id: string) {
  emit('update:modelValue', id)
  isOpen.value = false
  query.value = ''
  inputRef.value?.blur()
}

function onInput(event: Event) {
  query.value = (event.target as HTMLInputElement).value
}

function requestCreate() {
  const label = query.value.trim()
  if (label) emit('create', label)
  isOpen.value = false
  query.value = ''
}
</script>

<template>
  <div class="combobox" :class="{ 'combobox-disabled': disabled }">
    <div class="combobox-control" @click="!disabled && inputRef?.focus()">
      <span
        v-if="!isOpen && selectedOption?.color"
        class="color-dot"
        :style="{ backgroundColor: selectedOption.color }"
      ></span>
      <input
        ref="inputRef"
        class="combobox-input"
        :value="isOpen ? query : (selectedOption?.label ?? '')"
        :placeholder="isOpen ? t('common.typeToSearch') : placeholder"
        :disabled="disabled"
        autocomplete="off"
        @input="onInput"
        @focus="onFocus"
        @blur="onBlur"
      />
      <button
        v-if="clearable && modelValue && !isOpen"
        class="combobox-clear"
        @mousedown.prevent="select('')"
      >
        <XMarkIcon class="combobox-icon-sm" />
      </button>
      <ChevronDownIcon
        class="combobox-chevron"
        :class="{ 'combobox-chevron-open': isOpen }"
      />
    </div>
    <div v-if="isOpen" class="combobox-dropdown">
      <div
        v-if="clearable"
        class="combobox-option combobox-option-clear"
        @mousedown.prevent="select('')"
      >
        {{ clearLabel }}
      </div>
      <div
        v-for="opt in filteredOptions"
        :key="opt.id"
        class="combobox-option"
        :class="{ 'combobox-option-selected': opt.id === modelValue }"
        @mousedown.prevent="select(opt.id)"
      >
        <span v-if="opt.color" class="color-dot" :style="{ backgroundColor: opt.color }"></span>
        <div class="combobox-option-text">
          <div class="combobox-option-label">{{ opt.label }}</div>
          <div v-if="opt.subtitle" class="combobox-option-subtitle">{{ opt.subtitle }}</div>
        </div>
      </div>
      <div
        v-if="showCreate"
        class="combobox-option combobox-option-create"
        @mousedown.prevent="requestCreate"
      >
        {{ $t('common.create', { name: query.trim() }) }}
      </div>
      <div v-if="!filteredOptions.length && !showCreate" class="combobox-empty">
        {{ $t('common.noResults') }}
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";

.combobox {
  @apply relative;
}

.combobox-disabled {
  @apply opacity-50 pointer-events-none;
}

.combobox-control {
  @apply flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 h-10 cursor-text;
}

.combobox-control:focus-within {
  @apply ring-2 ring-primary-500 border-primary-500;
}

.combobox-input {
  @apply flex-1 min-w-0 bg-transparent text-sm text-gray-900 outline-none placeholder-gray-400;
}

.combobox-clear {
  @apply text-gray-400 hover:text-gray-600 shrink-0;
}

.combobox-icon-sm {
  @apply h-3.5 w-3.5;
}

.combobox-chevron {
  @apply h-4 w-4 text-gray-400 shrink-0 transition-transform;
}

.combobox-chevron-open {
  @apply rotate-180;
}

.combobox-dropdown {
  @apply absolute z-50 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg max-h-60 overflow-y-auto;
}

.combobox-option {
  @apply flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-gray-50;
}

.combobox-option-selected {
  @apply bg-primary-50 text-primary-700;
}

.combobox-option-clear {
  @apply text-gray-500 border-b border-gray-100;
}

.combobox-option-create {
  @apply text-primary-600 font-medium border-t border-gray-100;
}

.combobox-option-text {
  @apply flex-1 min-w-0;
}

.combobox-option-label {
  @apply truncate;
}

.combobox-option-subtitle {
  @apply text-xs text-gray-500 truncate;
}

.combobox-empty {
  @apply px-3 py-2 text-sm text-gray-500 text-center;
}
</style>
