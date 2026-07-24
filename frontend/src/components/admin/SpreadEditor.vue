<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch } from 'vue'
import type { Spread, SpreadPosition } from '@/types'
import { renderMarkdown } from '@/utils/markdown'
import BaseModal from '@/components/BaseModal.vue'
import SpreadCanvasEditor from '@/components/SpreadCanvasEditor.vue'
import type { SpreadSlotBase } from '@/components/spreadCanvas'

const props = withDefaults(defineProps<{ spread: Spread | null; saveLabel?: string }>(), {
  saveLabel: 'Save Spread',
})
const emit = defineEmits<{
  save: [
    payload: { name: string; description: string; card_count: number; positions: SpreadPosition[] },
  ]
  cancel: []
}>()

const name = ref('')
const description = ref('')
const showPreview = ref(false)
const cardCount = ref(1)
// A stable reactive array, mutated in place so the canvas composables keep their
// captured reference; the canvas is remounted (via editorKey) on each (re)load.
const slots = ref<SpreadSlotBase[]>([])
const editorKey = ref(0)
const error = ref('')
const saving = ref(false)

const previewHtml = computed(() => renderMarkdown(description.value))
const allPlaced = computed(() => slots.value.every((s) => s.placed))

// ── Initialize from prop ────────────────────────────────────
function init() {
  name.value = props.spread?.name ?? ''
  description.value = props.spread?.description ?? ''
  slots.value.length = 0

  const positions = props.spread?.positions ?? []
  if (positions.length > 0) {
    positions
      .slice()
      .sort((a, b) => a.order - b.order)
      .forEach((p) => {
        slots.value.push({ title: p.title, x: p.x, y: p.y, rotation: p.rotation, placed: true })
      })
  } else {
    slots.value.push({ title: '', x: 50, y: 50, rotation: 0, placed: false })
  }
  cardCount.value = slots.value.length
  editorKey.value++ // remount the canvas → fresh history / selection / zoom
}

watch(() => props.spread, init, { immediate: true })

// ── Card count → resize slots (in place) ────────────────────
watch(cardCount, (val) => {
  const target = Math.max(1, Math.min(78, Math.floor(val || 1)))
  while (slots.value.length < target) {
    slots.value.push({ title: '', x: 50, y: 50, rotation: 0, placed: false })
  }
  while (slots.value.length > target) {
    slots.value.pop()
  }
})

// Keep the card-count field in sync when an undo/redo changes the slot count.
function onRestore(length: number) {
  cardCount.value = length
}

// ── Save ────────────────────────────────────────────────────
function onSave() {
  error.value = ''
  if (!name.value.trim()) {
    error.value = 'Please enter a spread name.'
    return
  }
  if (!allPlaced.value) {
    error.value = 'Every card must be placed on the layout before saving.'
    return
  }

  const positions: SpreadPosition[] = slots.value.map((s: SpreadSlotBase, i: number) => ({
    order: i + 1,
    title: s.title,
    x: s.x,
    y: s.y,
    rotation: s.rotation,
  }))

  saving.value = true
  emit('save', {
    name: name.value.trim(),
    description: description.value,
    card_count: slots.value.length,
    positions,
  })
}

defineExpose({
  finishSaving: () => {
    saving.value = false
  },
  setError: (msg: string) => {
    saving.value = false
    error.value = msg
  },
})
</script>

<template>
  <div>
    <div class="columns">
      <div class="column is-8">
        <div class="field">
          <label class="label">Spread Name</label>
          <input v-model="name" class="input" placeholder="e.g. Celtic Cross" />
        </div>
      </div>
      <div class="column is-4">
        <div class="field">
          <label class="label">Number of Cards</label>
          <input v-model.number="cardCount" class="input" type="number" min="1" max="78" />
        </div>
      </div>
    </div>

    <div class="field">
      <label class="label">
        Description (Markdown)
        <button class="button is-small is-text ml-2" @click="showPreview = !showPreview">
          {{ showPreview ? 'Edit' : 'Preview' }}
        </button>
      </label>
      <textarea
        v-if="!showPreview"
        v-model="description"
        class="textarea"
        rows="5"
        placeholder="Describe the spread. Markdown is supported."
      ></textarea>
      <!-- Sanitized by renderMarkdown() (marked + DOMPurify) — see utils/markdown.ts -->
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div v-else class="content box" v-html="previewHtml"></div>
    </div>

    <SpreadCanvasEditor :key="editorKey" v-model="slots" @restore="onRestore">
      <template #card="{ item }">
        <span v-if="item.title" class="editor-card-title">{{ item.title }}</span>
      </template>

      <template #edit-modal="{ item, index, close }">
        <BaseModal
          :active="index !== null"
          :title="index !== null ? 'Position #' + (index + 1) : ''"
          max-width="28rem"
          @close="close"
        >
          <div v-if="item" class="field">
            <label class="label">Position Title</label>
            <input
              v-model="item.title"
              class="input"
              placeholder="e.g. The Present"
              @keyup.enter="close"
            />
          </div>
          <template #footer>
            <button class="button is-primary" @click="close">Done</button>
          </template>
        </BaseModal>
      </template>
    </SpreadCanvasEditor>

    <div v-if="error" class="notification is-danger is-light">{{ error }}</div>

    <div class="field is-grouped mt-4">
      <div class="control">
        <button class="button is-success" :class="{ 'is-loading': saving }" @click="onSave">
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
          <span>{{ props.saveLabel }}</span>
        </button>
      </div>
      <div class="control">
        <button class="button" @click="emit('cancel')">Cancel</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.editor-card-title {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
  font-size: 0.62rem;
  line-height: 1.1;
  text-align: center;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
  overflow: hidden;
  max-height: 2.4em;
}
</style>
