import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import {
  useSpreadLayout,
  computeSpreadFit,
  CARD_WIDTH_PCT,
  CARD_ASPECT,
} from '@/composables/useSpreadLayout'
import type { SpreadPosition } from '@/types'

// computeSpreadFit's own bounds are pinned in spreadLayout.spec.ts; this covers
// how the composable places cards with that fit.

const pos = (order: number, x: number, y: number, rotation = 0): SpreadPosition => ({
  order,
  title: `Position ${order}`,
  x,
  y,
  rotation,
})

describe('useSpreadLayout', () => {
  it('leaves a spread that already fills the canvas where it is', () => {
    const { fit, project, cardSize } = useSpreadLayout([pos(1, 0, 0), pos(2, 100, 100)])

    expect(fit.value).toEqual({ scale: 1, cx: 50, cy: 50 })
    expect(project({ x: 20, y: 70 })).toEqual({ x: 20, y: 70 })
    expect(cardSize.value).toEqual({ w: CARD_WIDTH_PCT, h: CARD_WIDTH_PCT * CARD_ASPECT })
  })

  it('centres a small spread and zooms it, cards growing with it', () => {
    const { fit, project, cardSize } = useSpreadLayout([pos(1, 20, 30), pos(2, 40, 30)])
    const { scale } = fit.value

    expect(scale).toBeGreaterThan(1)
    // The middle of the spread lands on the middle of the canvas…
    expect(project({ x: 30, y: 30 })).toEqual({ x: 50, y: 50 })
    // …and both cards sit symmetrically either side of it.
    const left = project({ x: 20, y: 30 })
    const right = project({ x: 40, y: 30 })
    expect(50 - left.x).toBeCloseTo(right.x - 50)
    expect(cardSize.value.w).toBeCloseTo(CARD_WIDTH_PCT * scale)
  })

  it('positions a card by its centre, size and rotation', () => {
    const { cardStyle, project, cardSize } = useSpreadLayout([pos(1, 30, 50), pos(2, 70, 50, 90)])
    const { x, y } = project({ x: 70, y: 50 })

    expect(cardStyle(pos(2, 70, 50, 90))).toEqual({
      left: `${x}%`,
      top: `${y}%`,
      width: `${cardSize.value.w}%`,
      '--rotation': '90deg',
    })
  })

  it('follows the positions when the spread changes', () => {
    const positions = ref([pos(1, 0, 0), pos(2, 100, 100)])
    const { fit } = useSpreadLayout(positions)
    expect(fit.value.scale).toBe(1)

    positions.value = [pos(1, 45, 45), pos(2, 55, 55)]

    expect(fit.value).toEqual(computeSpreadFit(positions.value))
    expect(fit.value.scale).toBeGreaterThan(1)
  })
})
