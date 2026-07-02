import { describe, it, expect } from 'vitest'
import { computeSpreadFit } from '@/composables/useSpreadLayout'
import type { SpreadPosition } from '@/types'

const pos = (x: number, y: number, order = 1): SpreadPosition => ({
  order,
  title: '',
  x,
  y,
  rotation: 0,
})

describe('computeSpreadFit', () => {
  it('returns an identity fit for no positions', () => {
    expect(computeSpreadFit([])).toEqual({ scale: 1, cx: 50, cy: 50 })
  })

  it('centres on the bounding box of the positions', () => {
    const fit = computeSpreadFit([pos(20, 20), pos(60, 80)])
    expect(fit.cx).toBe(40)
    expect(fit.cy).toBe(50)
  })

  it('clamps the scale between 1 and 2.5', () => {
    // Tightly clustered positions would scale up a lot; must cap at 2.5.
    const tight = computeSpreadFit([pos(49, 49), pos(51, 51)])
    expect(tight.scale).toBeGreaterThanOrEqual(1)
    expect(tight.scale).toBeLessThanOrEqual(2.5)

    // Positions spanning the whole canvas never scale below 1.
    const wide = computeSpreadFit([pos(0, 0), pos(100, 100)])
    expect(wide.scale).toBe(1)
  })
})
