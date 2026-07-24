<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import {
  DialogRoot,
  DialogPortal,
  DialogOverlay,
  DialogContent,
  DialogTitle,
  DialogClose,
  VisuallyHidden,
} from 'reka-ui'
import Tooltip from '@/components/Tooltip.vue'
import { useToasts } from '@/composables/useToasts'
import { endpoints } from '@/api/endpoints'
import type { ReadingCard } from '@/types'

const props = defineProps<{
  cards: ReadingCard[]
  cardBackUrl: string
  initialIndex: number
  deckId: number
}>()

const { success: toastSuccess, error: toastError } = useToasts()

const emit = defineEmits<{ close: [] }>()

const index = ref(props.initialIndex)
const flipped = ref(false)

const MIN_ZOOM = 1
const MAX_ZOOM = 4
const STEP_ZOOM = 2.5 // zoom level used by double-click / double-tap toggle

const imgEl = ref<HTMLImageElement>()
const zoom = ref(1)
const panX = ref(0)
const panY = ref(0)
const isPanning = ref(false)
const animate = ref(false) // smooth transition for button / double-click zoom

const imageSrc = computed(() => {
  if (index.value === -1) return props.cardBackUrl
  const card = props.cards[index.value] as ReadingCard | undefined
  return card ? card.imgUrl : ''
})

const imageTitle = computed(() => {
  if (index.value === -1) return 'Card Back'
  const card = props.cards[index.value] as ReadingCard | undefined
  return card ? card.card_name : ''
})

const isReversed = computed(() => {
  if (index.value === -1) return false
  const card = props.cards[index.value] as ReadingCard | undefined
  return card ? card.reversed : false
})

const showReversed = computed(() => isReversed.value && !flipped.value)

/* ── Report a card scan issue ──────────────────────────── */
const reportedCards = ref<Set<number>>(new Set())
const reportingCard = ref(false)

const currentCard = computed(() =>
  index.value >= 0 ? (props.cards[index.value] as ReadingCard | undefined) : undefined,
)
const alreadyReported = computed(() =>
  currentCard.value ? reportedCards.value.has(currentCard.value.card_id) : false,
)

async function reportCard() {
  const card = currentCard.value
  if (!card || reportingCard.value || reportedCards.value.has(card.card_id)) return

  reportingCard.value = true
  try {
    const res = await fetch('/api' + endpoints.cardReports, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        deck_id: props.deckId,
        card_id: card.card_id,
        card_name: card.card_name,
      }),
    })

    if (res.status === 429) {
      toastError('You have reported too many cards recently. Please try again later.')
      return
    }
    if (!res.ok) {
      toastError('Could not submit your report. Please try again.')
      return
    }

    reportedCards.value = new Set(reportedCards.value).add(card.card_id)
    toastSuccess('Thanks! This card has been reported for re-scanning.')
  } catch {
    toastError('Could not submit your report. Please try again.')
  } finally {
    reportingCard.value = false
  }
}

const isZoomed = computed(() => zoom.value > MIN_ZOOM + 0.001)

const imageTransform = computed(() => {
  // translate is applied outermost (screen-space pan), then scale combines the
  // zoom level with the reversed flip (scale(-1,-1)) so both stay in one matrix.
  const sign = showReversed.value ? -1 : 1
  return `translate(${panX.value}px, ${panY.value}px) scale(${zoom.value * sign}, ${zoom.value * sign})`
})

/** Largest distance the image can be panned from centre on each axis. */
function panBounds() {
  const el = imgEl.value
  if (!el) return { x: 0, y: 0 }
  return {
    x: Math.max(0, (el.clientWidth * zoom.value - el.clientWidth) / 2),
    y: Math.max(0, (el.clientHeight * zoom.value - el.clientHeight) / 2),
  }
}

function clampPan() {
  const b = panBounds()
  panX.value = Math.min(b.x, Math.max(-b.x, panX.value))
  panY.value = Math.min(b.y, Math.max(-b.y, panY.value))
}

function resetZoom() {
  zoom.value = 1
  panX.value = 0
  panY.value = 0
}

/**
 * Set a new zoom level while keeping the point at (clientX, clientY) anchored
 * under the cursor/finger. Falls back to the image centre when no point given.
 */
function zoomTo(newZoom: number, clientX?: number, clientY?: number) {
  const el: HTMLImageElement | undefined = imgEl.value
  if (!el) return
  const next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, newZoom))
  const rect = el.getBoundingClientRect()
  const centerX = rect.left + rect.width / 2
  const centerY = rect.top + rect.height / 2
  const cx = clientX ?? centerX
  const cy = clientY ?? centerY
  const dx = cx - centerX
  const dy = cy - centerY
  const ratio = next / zoom.value
  panX.value = dx - ratio * (dx - panX.value)
  panY.value = dy - ratio * (dy - panY.value)
  zoom.value = next
  if (next === MIN_ZOOM) {
    panX.value = 0
    panY.value = 0
  } else {
    clampPan()
  }
}

function zoomInBtn() {
  withAnimation(() => zoomTo(Number(zoom.value) + 0.75))
}

function zoomOutBtn() {
  withAnimation(() => zoomTo(zoom.value - 0.75))
}

function resetBtn() {
  withAnimation(resetZoom)
}

function withAnimation(fn: () => void) {
  animate.value = true
  fn()
  window.setTimeout(() => (animate.value = false), 220)
}

function onWheel(e: WheelEvent) {
  e.preventDefault()
  const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15
  zoomTo(zoom.value * factor, e.clientX, e.clientY)
}

function onDoubleClick(e: MouseEvent) {
  withAnimation(() => {
    if (isZoomed.value) resetZoom()
    else zoomTo(STEP_ZOOM, e.clientX, e.clientY)
  })
}

/* ── Mouse panning ──────────────────────────────────────── */
let dragStartX = 0
let dragStartY = 0
let panStartX = 0
let panStartY = 0

function onMouseDown(e: MouseEvent) {
  if (!isZoomed.value) return
  e.preventDefault()
  isPanning.value = true
  dragStartX = e.clientX
  dragStartY = e.clientY
  panStartX = panX.value
  panStartY = panY.value
  window.addEventListener('mousemove', onMouseMove)
  window.addEventListener('mouseup', onMouseUp)
}

function onMouseMove(e: MouseEvent) {
  panX.value = panStartX + (e.clientX - dragStartX)
  panY.value = panStartY + (e.clientY - dragStartY)
  clampPan()
}

function onMouseUp() {
  isPanning.value = false
  window.removeEventListener('mousemove', onMouseMove)
  window.removeEventListener('mouseup', onMouseUp)
}

/* ── Touch: swipe-nav, drag-pan, pinch-zoom, double-tap ─── */
let touchStartX = 0
let touchStartY = 0
let touchPanStartX = 0
let touchPanStartY = 0
let pinchStartDist = 0
let pinchStartZoom = 1
let pinchMidX = 0
let pinchMidY = 0
let lastTapTime = 0
let touchMoved = false

function touchDist(e: TouchEvent) {
  const [a, b] = [e.touches[0], e.touches[1]]
  return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY)
}

function onTouchStart(e: TouchEvent) {
  if (e.touches.length === 2) {
    pinchStartDist = touchDist(e)
    pinchStartZoom = zoom.value
    pinchMidX = (e.touches[0].clientX + e.touches[1].clientX) / 2
    pinchMidY = (e.touches[0].clientY + e.touches[1].clientY) / 2
    touchMoved = true
  } else if (e.touches.length === 1) {
    touchStartX = e.touches[0].clientX
    touchStartY = e.touches[0].clientY
    touchPanStartX = panX.value
    touchPanStartY = panY.value
    touchMoved = false
  }
}

function onTouchMove(e: TouchEvent) {
  if (e.touches.length === 2 && pinchStartDist > 0) {
    e.preventDefault()
    const ratio = touchDist(e) / pinchStartDist
    zoomTo(pinchStartZoom * ratio, pinchMidX, pinchMidY)
  } else if (e.touches.length === 1 && isZoomed.value) {
    // Pan the zoomed image instead of navigating.
    e.preventDefault()
    panX.value = touchPanStartX + (e.touches[0].clientX - touchStartX)
    panY.value = touchPanStartY + (e.touches[0].clientY - touchStartY)
    clampPan()
    touchMoved = true
  }
}

function onTouchEnd(e: TouchEvent) {
  if (pinchStartDist > 0 && e.touches.length === 0) {
    pinchStartDist = 0
    return
  }
  if (touchMoved) return

  const endX = e.changedTouches[0].clientX
  const endY = e.changedTouches[0].clientY
  const dx = touchStartX - endX
  const dy = touchStartY - endY

  // Double-tap to toggle zoom.
  const now = Date.now()
  if (now - lastTapTime < 300 && Math.abs(dx) < 30 && Math.abs(dy) < 30) {
    lastTapTime = 0
    withAnimation(() => {
      if (isZoomed.value) resetZoom()
      else zoomTo(STEP_ZOOM, endX, endY)
    })
    return
  }
  lastTapTime = now

  // Horizontal swipe navigates (only when not zoomed).
  if (!isZoomed.value && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
    if (dx > 0) next()
    else prev()
  }
}

function prev() {
  if (index.value > -1) {
    index.value--
    flipped.value = false
    resetZoom()
  }
}

function next() {
  if (index.value < props.cards.length - 1) {
    index.value++
    flipped.value = false
    resetZoom()
  }
}

watch(index, () => resetZoom())

// Escape is Reka's (DismissableLayer is layer-stack aware, so the topmost layer
// wins); everything below is lightbox-specific and stays hand-rolled.
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowLeft') prev()
  else if (e.key === 'ArrowRight') next()
  else if (e.key === '+' || e.key === '=') zoomInBtn()
  else if (e.key === '-' || e.key === '_') zoomOutBtn()
  else if (e.key === '0') resetBtn()
}

// Reka owns the open state; mirror every close request (Escape, backdrop,
// DialogClose) back out as the `close` event the parent already listens for.
function onOpenChange(open: boolean): void {
  if (!open) emit('close')
}

/**
 * Chromium fires `pointerdown` on a native scrollbar with the scroll container
 * itself as the event target, and Reka dismisses on any pointerdown outside
 * DialogContent. The overlay IS our scroll container (a full-size card is taller
 * than the viewport), so dragging its scrollbar would otherwise close the
 * lightbox mid-drag. A pointer on the scrollbar reports an offset past the
 * element's padding box — that's how we detect it and veto the dismissal.
 */
function onPointerDownOutside(e: Event): void {
  const original = (e as CustomEvent<{ originalEvent: PointerEvent }>).detail.originalEvent
  const target = original.target as HTMLElement | null
  if (!target) return
  if (original.offsetX > target.clientWidth || original.offsetY > target.clientHeight) {
    e.preventDefault()
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('mousemove', onMouseMove)
  window.removeEventListener('mouseup', onMouseUp)
})
</script>

<template>
  <DialogRoot :open="true" @update:open="onOpenChange">
    <DialogPortal>
      <!--
        The overlay is BOTH the backdrop and the scroll container, with
        DialogContent nested INSIDE it. That keeps a tall image fully reachable
        (margin:auto centring — align-items:center would clip the top and block
        scrolling) while leaving the backdrop genuinely outside the content, so
        Reka's outside-interaction close still works. Reka keys that off
        pointer-DOWN, so a pan-drag that starts on the image and releases over
        the backdrop no longer closes the lightbox.
      -->
      <DialogOverlay class="lightbox-overlay">
        <DialogContent
          class="lightbox-content"
          :aria-describedby="undefined"
          @pointer-down-outside="onPointerDownOutside"
        >
          <VisuallyHidden>
            <DialogTitle>{{ imageTitle || 'Card image' }}</DialogTitle>
          </VisuallyHidden>

          <DialogClose class="lightbox-close" aria-label="Close lightbox">&times;</DialogClose>

          <button
            v-if="index > -1 && !isZoomed"
            class="lightbox-nav lightbox-prev"
            type="button"
            aria-label="Previous image"
            @click="prev"
          >
            &#10094;
          </button>
          <div
            class="lightbox-viewport"
            :class="{ zoomed: isZoomed, panning: isPanning }"
            @wheel="onWheel"
            @dblclick="onDoubleClick"
            @mousedown="onMouseDown"
            @touchstart="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
          >
            <img
              ref="imgEl"
              :src="imageSrc"
              :alt="imageTitle"
              :style="{
                transform: imageTransform,
                transition: animate ? 'transform 0.2s ease' : 'none',
              }"
              draggable="false"
            />
          </div>

          <p v-if="imageTitle" class="lightbox-caption">
            {{ imageTitle }}
            <span v-if="isReversed" class="lightbox-reversed-tag">(Reversed)</span>
          </p>

          <div class="lightbox-toolbar">
            <button
              class="lightbox-zoom-btn"
              :disabled="zoom <= MIN_ZOOM"
              aria-label="Zoom out"
              @click="zoomOutBtn"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-minus']"
              /></span>
            </button>
            <button
              class="lightbox-zoom-btn lightbox-zoom-reset"
              aria-label="Reset zoom"
              @click="resetBtn"
            >
              {{ Math.round(zoom * 100) }}%
            </button>
            <button
              class="lightbox-zoom-btn"
              :disabled="zoom >= MAX_ZOOM"
              aria-label="Zoom in"
              @click="zoomInBtn"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-plus']"
              /></span>
            </button>

            <button
              v-if="isReversed"
              class="button is-small is-rounded lightbox-flip-btn"
              @click="flipped = !flipped"
            >
              <span class="icon is-small"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate']"
              /></span>
              <span>{{ flipped ? 'View Reversed' : 'View Upright' }}</span>
            </button>

            <Tooltip
              v-if="index > -1"
              :text="
                alreadyReported ? 'Reported' : 'Report a scan artefact or issue with this card'
              "
            >
              <button
                class="button is-small is-rounded lightbox-report-btn"
                :class="{ 'is-success': alreadyReported }"
                :disabled="reportingCard || alreadyReported"
                @click="reportCard"
              >
                <span class="icon is-small"
                  ><FontAwesomeIcon
                    :icon="
                      alreadyReported
                        ? byPrefixAndName.fas['circle-check']
                        : byPrefixAndName.fas['triangle-exclamation']
                    "
                /></span>
                <span>{{ alreadyReported ? 'Reported' : 'Report scan issue' }}</span>
              </button>
            </Tooltip>
          </div>

          <p class="lightbox-hint">Scroll or double-click to zoom &middot; drag to pan</p>

          <p v-if="index >= 0 && cards.length > 1" class="lightbox-counter">
            Image {{ index + 1 }} of {{ cards.length }}
          </p>
          <button
            v-if="index < cards.length - 1 && !isZoomed"
            class="lightbox-nav lightbox-next"
            type="button"
            aria-label="Next image"
            @click="next"
          >
            &#10095;
          </button>
        </DialogContent>
      </DialogOverlay>
    </DialogPortal>
  </DialogRoot>
</template>
