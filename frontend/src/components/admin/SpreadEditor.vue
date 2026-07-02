<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, reactive, computed, watch } from 'vue'
import type { Spread, SpreadPosition } from '@/types'
import { renderMarkdown } from '@/utils/markdown'
import { usePanZoom } from '@/composables/usePanZoom'
import { useLayoutTools } from '@/composables/useLayoutTools'
import { useUndoRedo } from '@/composables/useUndoRedo'
import BaseModal from '@/components/BaseModal.vue'

interface EditorSlot {
  title: string
  x: number
  y: number
  rotation: number
  placed: boolean
}

const props = withDefaults(defineProps<{ spread: Spread | null; saveLabel?: string }>(), {
  saveLabel: 'Save Spread',
})
const emit = defineEmits<{
  save: [
    payload: { name: string; description: string; card_count: number; positions: SpreadPosition[] },
  ]
  cancel: []
}>()

// Snap matches the 2.5% grid cell so card centers land on grid intersections.
const SNAP = 2.5
// Half a card's height as a percentage of the (square) canvas — used to float
// the per-card control bar just above the card.
const CARD_HALF_H_PCT = 9.46

// Canvas pan/zoom (on-screen only; coordinates stay 0–100%).
const {
  zoom,
  MIN_ZOOM,
  MAX_ZOOM,
  viewportRef,
  zoomIn,
  zoomOut,
  resetZoom,
  onPanStart,
  onPanMove,
  onPanEnd,
  onWheel,
  wasDragged,
  canvasStyle,
} = usePanZoom({ max: 3 })

// A background drag pans; a background click (no drag) deselects.
function onCanvasPointerUp(e: PointerEvent) {
  onPanEnd(e)
  if (!wasDragged()) clearSelection()
}

const name = ref('')
const description = ref('')
const showPreview = ref(false)
const snapToGrid = ref(true)
const cardCount = ref(1)
const slots: EditorSlot[] = reactive<EditorSlot[]>([])
const editIndex = ref<number | null>(null)
const error = ref('')
const saving = ref(false)

const canvasRef = ref<HTMLElement | null>(null)
let draggingIndex = -1

const unplacedIndexes = computed(() =>
  slots
    .map((s, i) => ({ s, i }))
    .filter((x) => !x.s.placed)
    .map((x) => x.i),
)
const placedIndexes = computed(() =>
  slots
    .map((s, i) => ({ s, i }))
    .filter((x) => x.s.placed)
    .map((x) => x.i),
)

// Multi-select + alignment/distribution/centering tools (shared across editors).
const {
  selected,
  selectedCount,
  isSelected,
  setSelection,
  toggleSelection,
  clearSelection,
  pruneSelection,
  snapVal,
  centerAll,
  align,
  distribute,
} = useLayoutTools(slots, () => placedIndexes.value, snapToGrid, SNAP)

// Undo/redo over the slot layout. cardCount + selection are re-synced on restore.
const {
  canUndo,
  canRedo,
  undo,
  redo,
  reset: resetHistory,
  record,
  suspend,
  resume,
} = useUndoRedo(slots, () => {
  cardCount.value = slots.length
  pruneSelection(slots.length)
})

// Collapse modal edits (title) into a single history entry.
watch(editIndex, (val, old) => {
  if (val !== null) {
    suspend()
  } else if (old !== null) {
    resume()
    record()
  }
})

// ── Initialize from prop ────────────────────────────────────
function init() {
  name.value = props.spread?.name ?? ''
  description.value = props.spread?.description ?? ''
  slots.length = 0

  const positions = props.spread?.positions ?? []
  if (positions.length > 0) {
    positions
      .slice()
      .sort((a, b) => a.order - b.order)
      .forEach((p) => {
        slots.push({ title: p.title, x: p.x, y: p.y, rotation: p.rotation, placed: true })
      })
  } else {
    slots.push({ title: '', x: 50, y: 50, rotation: 0, placed: false })
  }
  cardCount.value = slots.length
  clearSelection()
  resetHistory()
}

watch(() => props.spread, init, { immediate: true })

// ── Card count → resize slots ───────────────────────────────
watch(cardCount, (val) => {
  const target = Math.max(1, Math.min(78, Math.floor(val || 1)))
  while (slots.length < target) {
    slots.push({ title: '', x: 50, y: 50, rotation: 0, placed: false })
  }
  while (slots.length > target) {
    slots.pop()
  }
  pruneSelection(slots.length)
})

const previewHtml = computed(() => renderMarkdown(description.value))
const allPlaced = computed(() => slots.every((s) => s.placed))

// ── Placement & drag ────────────────────────────────────────
function placeFromTray(index: number) {
  // Cascade new placements so they don't stack exactly.
  const placedCount = placedIndexes.value.length
  slots[index].x = snapVal(40 + (placedCount % 5) * 5)
  slots[index].y = snapVal(40 + Math.floor(placedCount / 5) * 8)
  slots[index].placed = true
  setSelection([index])
}

function unplace(index: number) {
  slots[index].placed = false
  if (selected.value.has(index)) toggleSelection(index)
  if (editIndex.value === index) editIndex.value = null
}

function onCardPointerDown(e: PointerEvent, index: number) {
  e.stopPropagation() // don't let the background pan handler also fire

  // Shift/Ctrl/Cmd-click toggles multi-selection without starting a drag.
  if (e.shiftKey || e.ctrlKey || e.metaKey) {
    toggleSelection(index)
    return
  }

  // A plain click on an unselected card selects just it; then drag moves it.
  if (!selected.value.has(index)) {
    setSelection([index])
  }
  draggingIndex = index
  suspend() // collapse the whole drag into one undo step
  ;(e.currentTarget as HTMLElement | null)?.setPointerCapture(e.pointerId)
  e.preventDefault()
}

function onDrag(e: PointerEvent) {
  if (draggingIndex < 0 || !canvasRef.value) return
  const rect = canvasRef.value.getBoundingClientRect()
  const x = ((e.clientX - rect.left) / rect.width) * 100
  const y = ((e.clientY - rect.top) / rect.height) * 100
  slots[draggingIndex].x = snapVal(x)
  slots[draggingIndex].y = snapVal(y)
}

function endDrag(e?: PointerEvent) {
  e?.stopPropagation() // keep the background pointerup (deselect) from firing
  if (draggingIndex !== -1) {
    draggingIndex = -1
    resume()
    record()
  }
}

function rotate(index: number, delta: number) {
  let r = (slots[index].rotation + delta) % 360
  if (r < 0) r += 360
  slots[index].rotation = r
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

  const positions: SpreadPosition[] = slots.map((s, i) => ({
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
    card_count: slots.length,
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
      <div v-else class="content box" v-html="previewHtml"></div>
    </div>

    <!-- Tray of unplaced cards -->
    <div class="field">
      <label class="label"
        >Unplaced Cards
        <span class="has-text-grey is-size-7">(click to place, then drag to position)</span></label
      >
      <div class="spread-token-tray">
        <button
          v-for="i in unplacedIndexes"
          :key="'tray-' + i"
          class="spread-token"
          :title="'Place card ' + (i + 1)"
          @click="placeFromTray(i)"
        >
          <span>{{ i + 1 }}</span>
        </button>
        <span v-if="unplacedIndexes.length === 0" class="has-text-grey is-align-self-center"
          >All cards placed.</span
        >
      </div>
    </div>

    <!-- Full-width layout canvas -->
    <div class="field">
      <!-- Layout toolbar: snap, undo/redo, center, align, distribute, zoom -->
      <div class="spread-tools is-flex is-flex-wrap-wrap is-align-items-center mb-2">
        <label class="label mb-0 mr-1" title="Drag to move · shift-click to multi-select"
          >Layout</label
        >
        <span class="has-text-grey is-size-7 spread-tools-hint">{{ selectedCount }} selected</span>

        <label class="toggle-switch">
          <input v-model="snapToGrid" type="checkbox" />
          <span class="toggle-track"><span class="toggle-thumb"></span></span>
          <span class="toggle-state">Snap to grid</span>
        </label>

        <div class="buttons has-addons are-small mb-0">
          <button
            class="button is-small"
            tabindex="-1"
            :disabled="!canUndo"
            title="Undo"
            @click="undo"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
            /></span>
          </button>
          <button
            class="button is-small"
            tabindex="-1"
            :disabled="!canRedo"
            title="Redo"
            @click="redo"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']"
            /></span>
          </button>
        </div>

        <button
          class="button is-small"
          :disabled="placedIndexes.length === 0"
          title="Center all cards in the panel (keeps spacing)"
          @click="centerAll"
        >
          <span class="icon is-small"
            ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-to-dot']"
          /></span>
          <span>Center</span>
        </button>

        <div class="buttons has-addons are-small mb-0">
          <span class="button is-static is-small">Align</span>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align left edges"
            @click="align('left')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-left']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align horizontal centers"
            @click="align('hcenter')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-center']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align right edges"
            @click="align('right')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-right']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align top edges"
            @click="align('top')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align to same height (vertical centers)"
            @click="align('vmiddle')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['bars']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 2"
            title="Align bottom edges"
            @click="align('bottom')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-down']"
            /></span>
          </button>
        </div>

        <div class="buttons has-addons are-small mb-0">
          <span class="button is-static is-small">Distribute</span>
          <button
            class="button is-small"
            :disabled="selectedCount < 3"
            title="Equal horizontal spacing"
            @click="distribute('h')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-left-right']"
            /></span>
          </button>
          <button
            class="button is-small"
            :disabled="selectedCount < 3"
            title="Equal vertical spacing"
            @click="distribute('v')"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']"
            /></span>
          </button>
        </div>

        <div class="buttons has-addons are-small mb-0 ml-auto">
          <button
            class="button is-small"
            tabindex="-1"
            :disabled="zoom <= MIN_ZOOM"
            title="Zoom out"
            @click="zoomOut"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-minus']"
            /></span>
          </button>
          <button
            class="button is-small"
            tabindex="-1"
            title="Reset zoom"
            style="min-width: 3.5rem"
            @click="resetZoom"
          >
            {{ Math.round(zoom * 100) }}%
          </button>
          <button
            class="button is-small"
            tabindex="-1"
            :disabled="zoom >= MAX_ZOOM"
            title="Zoom in"
            @click="zoomIn"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-plus']"
            /></span>
          </button>
        </div>
      </div>

      <div
        ref="viewportRef"
        class="spread-canvas-viewport"
        :class="{ 'is-zoomed': zoom > 1 }"
        @pointerdown="onPanStart"
        @pointermove="onPanMove"
        @pointerup="onCanvasPointerUp"
        @wheel="onWheel"
      >
        <div
          ref="canvasRef"
          class="spread-canvas spread-canvas--zoomable"
          :class="{ 'has-grid': snapToGrid }"
          :style="canvasStyle"
        >
          <template v-for="i in placedIndexes" :key="'card-' + i">
            <div
              class="spread-card spread-card--editor"
              :class="{ 'is-selected': isSelected(i) }"
              :style="{
                left: slots[i].x + '%',
                top: slots[i].y + '%',
                '--rotation': slots[i].rotation + 'deg',
              }"
              @pointerdown="onCardPointerDown($event, i)"
              @pointermove="onDrag"
              @pointerup="endDrag"
            >
              <span class="spread-order-badge">{{ i + 1 }}</span>
              <span v-if="slots[i].title" class="editor-card-title">{{ slots[i].title }}</span>
            </div>

            <!-- Floating controls for a single selected card -->
            <div
              v-if="selectedCount === 1 && isSelected(i)"
              class="card-controls"
              :style="{ left: slots[i].x + '%', top: slots[i].y - CARD_HALF_H_PCT + '%' }"
              @pointerdown.stop
              @pointerup.stop
            >
              <button
                class="button is-small"
                tabindex="-1"
                title="Rotate left"
                @click.stop="rotate(i, -15)"
              >
                <span class="icon is-small"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']"
                /></span>
              </button>
              <button
                class="button is-small"
                tabindex="-1"
                title="Rotate right"
                @click.stop="rotate(i, 15)"
              >
                <span class="icon is-small"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']"
                /></span>
              </button>
              <button
                class="button is-small is-info"
                tabindex="-1"
                title="Edit title"
                @click.stop="editIndex = i"
              >
                <span class="icon is-small"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                /></span>
              </button>
              <button
                class="button is-small is-danger"
                tabindex="-1"
                title="Remove from layout"
                @click.stop="unplace(i)"
              >
                <span class="icon is-small"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']"
                /></span>
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>

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

    <!-- Per-position edit modal -->
    <BaseModal
      :active="editIndex !== null"
      :title="editIndex !== null ? 'Position #' + (editIndex + 1) : ''"
      max-width="28rem"
      @close="editIndex = null"
    >
      <div v-if="editIndex !== null" class="field">
        <label class="label">Position Title</label>
        <input
          v-model="slots[editIndex].title"
          class="input"
          placeholder="e.g. The Present"
          @keyup.enter="editIndex = null"
        />
      </div>
      <template #footer>
        <button class="button is-primary" @click="editIndex = null">Done</button>
      </template>
    </BaseModal>
  </div>
</template>

<style scoped>
.spread-tools {
  gap: 0.4rem 0.75rem;
}

.spread-tools-hint {
  min-width: 5rem;
}

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

/* Floating control bar above the selected card; stays upright (not rotated). */
.card-controls {
  position: absolute;
  transform: translate(-50%, -100%);
  margin-top: -0.35rem;
  z-index: 6;
  display: flex;
  gap: 0.2rem;
  padding: 0.25rem;
  border-radius: 8px;
  background: var(--myst-surface);
  border: 1px solid var(--myst-border-strong);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.5);
}

.card-controls .button {
  height: 1.9rem;
  width: 1.9rem;
  padding: 0;
}
</style>
