<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDecks } from '@/composables/useDecks'
import { useUser } from '@/composables/useUser'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useToasts } from '@/composables/useToasts'
import { usePanZoom } from '@/composables/usePanZoom'
import { useLayoutTools } from '@/composables/useLayoutTools'
import { useUndoRedo } from '@/composables/useUndoRedo'
import { cardAspectStyle } from '@/utils/cardAspect'
import { readApiError } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'
import type { ReadingInfo, Deck } from '@/types'

const CARD_HALF_H_PCT = 9.46
const SNAP = 2.5

const route = useRoute()
const router = useRouter()
const { decks } = useDecks()
const { isLoggedIn } = useUser()
const { createUserSpread } = useUserSpreads()
const toasts = useToasts()

const readingId = computed(() => route.params.id as string)

// Loading state
const loading = ref(true)
const saving = ref(false)
const readingInfo = ref<ReadingInfo | null>(null)
const deck = ref<Deck | null>(null)

// Canvas pan/zoom
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

interface Slot {
  title: string
  x: number
  y: number
  rotation: number
  placed: boolean
  cardId: number
  reversed: boolean
  cardName: string
}

const slots: Slot[] = reactive<Slot[]>([])
const snapToGrid = ref(true)
const canvasRef = ref<HTMLElement | null>(null)
let draggingIndex = -1

// True when the reading already had a placed spread (e.g. the owner drew more
// cards into it) — we then pre-place the existing cards, preserve the spread's
// name, and skip the "save as spread" prompt that the fresh free-draw flow shows.
const isExistingSpread = ref(false)
const existingSpreadName = ref('')

// Save-spread modal
const showSaveSpreadModal = ref(false)
const spreadName = ref('')
const spreadDescription = ref('')
const spreadSaveMode = ref<'public' | 'personal'>('personal')
const spreadSaving = ref(false)

// Fetch the reading data
onMounted(async () => {
  try {
    const res = await fetch('/api' + endpoints.readings.byId(readingId.value))
    if (!res.ok) {
      toasts.error('Could not load the reading.')
      void router.replace({ name: 'home' })
      return
    }
    const data = await res.json()

    // A finalized reading is locked — there is nothing to arrange.
    if (data.is_final) {
      toasts.warning('This reading is final and can no longer be edited.')
      void router.replace({ name: 'reading', params: { id: readingId.value } })
      return
    }

    const info = data.reading_info as ReadingInfo
    readingInfo.value = info

    // Find deck
    deck.value = decks.value.find((dk) => dk.deck_id === info.deck_id) ?? null

    // Existing placement (if any), keyed by position order, so cards already
    // arranged into a spread come back pre-placed and only the new draws sit
    // in the tray awaiting placement.
    const positions = info.spread?.positions ?? []
    isExistingSpread.value = positions.length > 0
    existingSpreadName.value = info.spread?.name ?? ''
    const byOrder = new Map(positions.map((p) => [p.order, p]))

    // Populate slots from draw data
    info.draw.forEach((card, i) => {
      const pos = byOrder.get(i + 1)
      slots.push({
        title: pos?.title ?? '',
        x: pos?.x ?? 50,
        y: pos?.y ?? 50,
        rotation: pos?.rotation ?? 0,
        placed: pos !== undefined,
        cardId: card.card_id,
        reversed: card.reversed,
        cardName: card.card_name,
      })
    })
  } catch {
    toasts.error('Failed to load reading data.')
    void router.replace({ name: 'home' })
  } finally {
    loading.value = false
  }
})

// If decks load after the reading, sync
watch(
  decks,
  (val) => {
    const info: ReadingInfo | null = readingInfo.value
    if (info && !deck.value) {
      deck.value = val.find((d) => d.deck_id === info.deck_id) ?? null
    }
  },
  { immediate: true },
)

const placedIndexes = computed(() =>
  slots
    .map((s, i) => ({ s, i }))
    .filter((x) => x.s.placed)
    .map((x) => x.i),
)
const unplacedIndexes = computed(() =>
  slots
    .map((s, i) => ({ s, i }))
    .filter((x) => !x.s.placed)
    .map((x) => x.i),
)

// Multi-select + layout tools
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

// Undo/redo
const { canUndo, canRedo, undo, redo, record, suspend, resume } = useUndoRedo(slots, () => {
  pruneSelection(slots.length)
})

const allPlaced = computed(() => slots.length > 0 && slots.every((s) => s.placed))

function onCanvasPointerUp(e: PointerEvent) {
  onPanEnd(e)
  if (!wasDragged()) clearSelection()
}

function placeFromTray(index: number) {
  const placedCount = placedIndexes.value.length
  slots[index].x = snapVal(40 + (placedCount % 5) * 5)
  slots[index].y = snapVal(40 + Math.floor(placedCount / 5) * 8)
  slots[index].placed = true
  setSelection([index])
}

function unplace(index: number) {
  slots[index].placed = false
  if (selected.value.has(index)) toggleSelection(index)
}

function onCardPointerDown(e: PointerEvent, index: number) {
  e.stopPropagation()
  if (e.shiftKey || e.ctrlKey || e.metaKey) {
    toggleSelection(index)
    return
  }
  if (!selected.value.has(index)) {
    setSelection([index])
  }
  draggingIndex = index
  suspend()
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
  e?.stopPropagation()
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

function cardThumb(cardId: number): string {
  if (!deck.value) return ''
  const filename = 'Card_' + String(cardId).padStart(4, '0')
  return '/assets/decks/' + String(deck.value.deck_id) + '/thumbs/' + filename + '.webp'
}

// Editing title modal
const editIndex = ref<number | null>(null)
const editingSlot = computed(() => (editIndex.value === null ? null : slots[editIndex.value]))

watch(editIndex, (val, old) => {
  if (val !== null) suspend()
  else if (old !== null) {
    resume()
    record()
  }
})

// Submit placement
async function finalizePlacement() {
  if (!allPlaced.value) {
    toasts.warning('Please place all cards on the canvas first.')
    return
  }

  saving.value = true
  try {
    const positions = slots.map((s) => ({
      title: s.title,
      x: s.x,
      y: s.y,
      rotation: s.rotation,
    }))

    const res = await fetch('/api' + endpoints.readings.placement(readingId.value), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        positions,
        spread_name: existingSpreadName.value || 'Free Draw Placement',
      }),
    })

    if (!res.ok) {
      toasts.error(await readApiError(res, 'Failed to save placement.'))
      return
    }

    toasts.success('Placement saved!')

    // Editing an existing spread (drew more cards): no need to re-offer
    // saving it as a reusable spread — go straight back to the reading.
    if (isExistingSpread.value) {
      void router.push({ name: 'reading', params: { id: readingId.value } })
      return
    }

    // Fresh free draw: offer to save the layout as a reusable spread.
    showSaveSpreadModal.value = true
  } catch (e) {
    toasts.error('Network error. Please try again.', { detail: e })
  } finally {
    saving.value = false
  }
}

// Save the spread (optional step after placement is saved)
async function saveSpread() {
  if (!spreadName.value.trim()) {
    toasts.warning('Please enter a name for the spread.')
    return
  }

  spreadSaving.value = true
  try {
    const positions = slots.map((s, idx) => ({
      order: idx + 1,
      title: s.title,
      x: s.x,
      y: s.y,
      rotation: s.rotation,
    }))

    const payload = {
      name: spreadName.value.trim(),
      description: spreadDescription.value.trim(),
      card_count: slots.length,
      positions,
    }

    if (spreadSaveMode.value === 'personal' && isLoggedIn.value) {
      const spread = await createUserSpread(payload)
      if (spread) {
        toasts.success('Spread saved to your personal collection!')
      } else {
        toasts.error('Failed to save personal spread.')
      }
    } else {
      // Submit as public
      const res = await fetch('/api' + endpoints.spreads.list, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
      if (res.ok) {
        toasts.success('Spread submitted for review!')
      } else {
        toasts.error('Failed to submit spread.')
      }
    }
  } finally {
    spreadSaving.value = false
    showSaveSpreadModal.value = false
    void router.push({ name: 'reading', params: { id: readingId.value } })
  }
}

function skipSaveSpread() {
  showSaveSpreadModal.value = false
  void router.push({ name: 'reading', params: { id: readingId.value } })
}
</script>

<template>
  <section v-if="!loading" class="section">
    <div class="container">
      <h1 class="title is-3 is-size-4-mobile">Arrange Your Draw</h1>
      <p class="subtitle is-5 is-size-6-mobile">
        Place your drawn cards on the canvas, name each position, and finalize the layout.
      </p>

      <!-- Tray of unplaced cards -->
      <div class="field">
        <label class="label"
          >Unplaced Cards
          <span class="has-text-grey is-size-7"
            >(click to place, then drag to position)</span
          ></label
        >
        <div class="spread-token-tray">
          <button
            v-for="i in unplacedIndexes"
            :key="'tray-' + i"
            class="spread-token"
            :title="slots[i].cardName + (slots[i].reversed ? ' (Reversed)' : '')"
            @click="placeFromTray(i)"
          >
            <span>{{ i + 1 }}</span>
            <span class="fdp-tray-name">{{
              slots[i].cardName.split(' ').slice(0, 2).join(' ')
            }}</span>
          </button>
          <span v-if="unplacedIndexes.length === 0" class="has-text-grey is-align-self-center"
            >All cards placed.</span
          >
        </div>
      </div>

      <!-- Layout toolbar -->
      <div class="field">
        <div class="spread-tools is-flex is-flex-wrap-wrap is-align-items-center mb-2">
          <label class="label mb-0 mr-1">Layout</label>
          <span class="has-text-grey is-size-7 spread-tools-hint"
            >{{ selectedCount }} selected</span
          >

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
            title="Center all cards"
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
              title="Align left"
              @click="align('left')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-left']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 2"
              title="Align center"
              @click="align('hcenter')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-center']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 2"
              title="Align right"
              @click="align('right')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['align-right']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 2"
              title="Align top"
              @click="align('top')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 2"
              title="Align middle"
              @click="align('vmiddle')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['bars']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 2"
              title="Align bottom"
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
              title="Distribute horizontal"
              @click="distribute('h')"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-left-right']"
              /></span>
            </button>
            <button
              class="button is-small"
              :disabled="selectedCount < 3"
              title="Distribute vertical"
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

        <!-- Canvas -->
        <div
          ref="viewportRef"
          class="spread-canvas-viewport"
          :class="{ 'is-zoomed': zoom > 1 }"
          :style="cardAspectStyle(deck)"
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
                <img
                  :src="cardThumb(slots[i].cardId)"
                  :class="{ reversed: slots[i].reversed }"
                  class="fdp-card-img"
                  :alt="slots[i].cardName"
                  draggable="false"
                />
              </div>

              <!-- Floating controls -->
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

      <!-- Finalize button -->
      <div class="field mt-4">
        <div class="buttons">
          <button
            class="button is-primary is-medium"
            :class="{ 'is-loading': saving }"
            :disabled="!allPlaced || saving"
            @click="finalizePlacement"
          >
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
            <span>Finalize Placement</span>
          </button>
          <button
            class="button is-medium"
            @click="router.push({ name: 'reading', params: { id: readingId } })"
          >
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['forward']" /></span>
            <span>Skip Placement</span>
          </button>
        </div>
      </div>
    </div>
  </section>

  <LoadingOverlay v-if="loading" message="Loading your reading..." />
  <LoadingOverlay v-if="saving" message="Saving placement..." />

  <!-- Position title edit modal -->
  <BaseModal
    :active="editingSlot !== null"
    :title="
      editIndex !== null ? 'Position #' + (editIndex + 1) + ' — ' + slots[editIndex!].cardName : ''
    "
    max-width="26rem"
    @close="editIndex = null"
  >
    <template v-if="editingSlot">
      <div class="field">
        <label class="label"
          >Position Title
          <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
        >
        <input
          v-model="editingSlot.title"
          class="input"
          placeholder="e.g. The Present"
          autocomplete="off"
        />
      </div>
    </template>
    <template #footer>
      <button class="button is-primary" @click="editIndex = null">Done</button>
    </template>
  </BaseModal>

  <!-- Save spread modal (appears after placement is saved) -->
  <BaseModal
    :active="showSaveSpreadModal"
    title="Save This Layout as a Spread?"
    max-width="32rem"
    @close="skipSaveSpread"
  >
    <p class="mb-4">
      Your reading has been saved. Would you like to also save this card layout as a reusable
      spread?
    </p>

    <div class="field">
      <label class="label">Spread Name</label>
      <input
        v-model="spreadName"
        class="input"
        placeholder="e.g. My Three-Card Pull"
        maxlength="100"
        autocomplete="off"
      />
    </div>

    <div class="field">
      <label class="label"
        >Description
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <textarea
        v-model="spreadDescription"
        class="textarea"
        rows="3"
        placeholder="Describe the spread layout..."
      ></textarea>
    </div>

    <div v-if="isLoggedIn" class="field">
      <label class="label">Save As</label>
      <div class="myst-segmented">
        <button
          type="button"
          class="myst-segmented__btn"
          :class="{ 'is-active': spreadSaveMode === 'personal' }"
          @click="spreadSaveMode = 'personal'"
        >
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']" /></span>
          <span>Personal</span>
        </button>
        <button
          type="button"
          class="myst-segmented__btn"
          :class="{ 'is-active': spreadSaveMode === 'public' }"
          @click="spreadSaveMode = 'public'"
        >
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['globe']" /></span>
          <span>Public</span>
        </button>
      </div>
      <p v-if="spreadSaveMode === 'personal'" class="help">Saved to your personal collection.</p>
      <p v-else class="help">Submitted for review to become a public spread.</p>
    </div>

    <template #footer>
      <button class="button" @click="skipSaveSpread">Skip</button>
      <button
        class="button is-primary"
        :class="{ 'is-loading': spreadSaving }"
        :disabled="spreadSaving || !spreadName.trim()"
        @click="saveSpread"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
        <span>Save Spread</span>
      </button>
    </template>
  </BaseModal>
</template>

<style scoped>
.spread-tools {
  gap: 0.4rem 0.75rem;
}

.spread-tools-hint {
  min-width: 5rem;
}

.fdp-card-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 8px;
}

.fdp-card-img.reversed {
  transform: rotate(180deg);
}

.fdp-tray-name {
  font-size: 0.6rem;
  color: var(--myst-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

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
