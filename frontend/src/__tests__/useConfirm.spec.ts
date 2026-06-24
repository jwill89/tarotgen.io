import { describe, it, expect, beforeEach } from 'vitest'
import { useConfirm } from '@/composables/useConfirm'

const { state, confirm, settle } = useConfirm()

describe('useConfirm', () => {
    beforeEach(() => {
        // Resolve any pending dialog so tests start from a clean slate.
        settle(false)
    })

    it('activates the dialog with the given options and defaults', () => {
        confirm({ message: 'Delete this?', title: 'Delete', danger: true })
        expect(state.value.active).toBe(true)
        expect(state.value.title).toBe('Delete')
        expect(state.value.message).toBe('Delete this?')
        expect(state.value.danger).toBe(true)
        expect(state.value.confirmLabel).toBe('Confirm')
        expect(state.value.cancelLabel).toBe('Cancel')
    })

    it('resolves true when settled with true and closes', async () => {
        const promise = confirm({ message: 'Proceed?' })
        settle(true)
        await expect(promise).resolves.toBe(true)
        expect(state.value.active).toBe(false)
    })

    it('resolves false when settled with false', async () => {
        const promise = confirm({ message: 'Proceed?' })
        settle(false)
        await expect(promise).resolves.toBe(false)
    })

    it('uses custom labels when provided', () => {
        confirm({ message: 'Clear all?', confirmLabel: 'Clear all', cancelLabel: 'Keep' })
        expect(state.value.confirmLabel).toBe('Clear all')
        expect(state.value.cancelLabel).toBe('Keep')
    })

    it('ignores settle when no dialog is active', () => {
        settle(false) // ensure inactive
        expect(() => settle(true)).not.toThrow()
        expect(state.value.active).toBe(false)
    })
})
