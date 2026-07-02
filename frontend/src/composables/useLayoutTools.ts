import { ref, computed, toValue, type MaybeRefOrGetter } from 'vue'

/**
 * Shared selection + alignment/distribution/centering tools for the spread
 * layout editors. Operates on any reactive array of objects carrying `x`/`y`
 * percentages (0–100); mutations are applied in place so the host component's
 * reactivity drives the canvas.
 */

export interface XYPoint {
  x: number
  y: number
}

export type AlignEdge = 'left' | 'hcenter' | 'right' | 'top' | 'vmiddle' | 'bottom'

export function useLayoutTools(
  slots: XYPoint[],
  placedIndexes: MaybeRefOrGetter<number[]>,
  snapToGrid: MaybeRefOrGetter<boolean>,
  snapStep = 2.5,
) {
  // ── Multi-selection of placed-card indices ──────────────────
  const selected = ref<Set<number>>(new Set())
  const selectedCount = computed(() => selected.value.size)

  const isSelected = (i: number): boolean => selected.value.has(i)
  const setSelection = (indices: number[]): void => {
    selected.value = new Set(indices)
  }
  const clearSelection = (): void => {
    selected.value = new Set()
  }

  function toggleSelection(i: number): void {
    const next = new Set(selected.value)
    if (next.has(i)) {
      next.delete(i)
    } else {
      next.add(i)
    }
    selected.value = next
  }

  /** Drop any selected indices that are out of range (e.g. after a resize). */
  function pruneSelection(length: number): void {
    selected.value = new Set([...selected.value].filter((i) => i < length))
  }

  // ── Geometry helpers ────────────────────────────────────────
  const clampPct = (v: number): number => Math.max(0, Math.min(100, v))
  const round2 = (v: number): number => Math.round(v * 100) / 100

  function snapVal(v: number): number {
    const c = clampPct(v)
    return toValue(snapToGrid) ? Math.round(c / snapStep) * snapStep : round2(c)
  }

  /** Center all placed cards in the panel, preserving relative spacing. */
  function centerAll(): void {
    const idx = toValue(placedIndexes)
    if (idx.length === 0) return
    const xs = idx.map((i) => slots[i].x)
    const ys = idx.map((i) => slots[i].y)
    const dx = 50 - (Math.min(...xs) + Math.max(...xs)) / 2
    const dy = 50 - (Math.min(...ys) + Math.max(...ys)) / 2
    idx.forEach((i) => {
      slots[i].x = round2(clampPct(slots[i].x + dx))
      slots[i].y = round2(clampPct(slots[i].y + dy))
    })
  }

  /** Align selected cards' edges/centers on one axis (needs 2+). */
  function align(edge: AlignEdge): void {
    const idx = [...selected.value]
    if (idx.length < 2) return
    const xs = idx.map((i) => slots[i].x)
    const ys = idx.map((i) => slots[i].y)

    switch (edge) {
      case 'left': {
        const v = snapVal(Math.min(...xs))
        idx.forEach((i) => {
          slots[i].x = v
        })
        break
      }
      case 'right': {
        const v = snapVal(Math.max(...xs))
        idx.forEach((i) => {
          slots[i].x = v
        })
        break
      }
      case 'hcenter': {
        const v = round2((Math.min(...xs) + Math.max(...xs)) / 2)
        idx.forEach((i) => {
          slots[i].x = v
        })
        break
      }
      case 'top': {
        const v = snapVal(Math.min(...ys))
        idx.forEach((i) => {
          slots[i].y = v
        })
        break
      }
      case 'bottom': {
        const v = snapVal(Math.max(...ys))
        idx.forEach((i) => {
          slots[i].y = v
        })
        break
      }
      case 'vmiddle': {
        const v = round2((Math.min(...ys) + Math.max(...ys)) / 2)
        idx.forEach((i) => {
          slots[i].y = v
        })
        break
      }
    }
  }

  /**
   * Space the selected cards equally between the two extremes (needs 3+).
   * Cards sharing (nearly) the same position on the axis are grouped and moved
   * together, so symmetric arrangements (e.g. the arms of a V) keep their
   * matching positions instead of being pulled apart.
   */
  function distribute(axis: 'h' | 'v'): void {
    const idx = [...selected.value]
    if (idx.length < 3) return

    const coord = (i: number): number => (axis === 'h' ? slots[i].x : slots[i].y)
    const apply = (i: number, v: number): void => {
      if (axis === 'h') slots[i].x = v
      else slots[i].y = v
    }

    const TOLERANCE = 1 // percent within which cards count as one row/column
    const sorted = idx.slice().sort((a, b) => coord(a) - coord(b))
    const groups: number[][] = []
    for (const i of sorted) {
      const last = groups[groups.length - 1] as number[] | undefined
      if (last && Math.abs(coord(i) - coord(last[0])) <= TOLERANCE) {
        last.push(i)
      } else {
        groups.push([i])
      }
    }
    if (groups.length < 2) return

    const first = coord(groups[0][0])
    const step = (coord(groups[groups.length - 1][0]) - first) / (groups.length - 1)
    groups.forEach((group, k) => {
      const v = round2(first + step * k)
      group.forEach((i) => apply(i, v))
    })
  }

  return {
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
  }
}
