/**
 * The minimal per-card state every spread-canvas item carries. Each editor
 * extends this with its own fields (the admin SpreadEditor adds none; the reading
 * editors add `cardId` / `reversed` / `cardName`). `placed` distinguishes an item
 * sitting in the unplaced tray from one positioned on the canvas.
 *
 * Lives in a plain module (not the SFC) so it resolves for both vue-tsc and the
 * eslint type-checker, and is importable by every caller of SpreadCanvasEditor.
 */
export interface SpreadSlotBase {
  title: string
  x: number
  y: number
  rotation: number
  placed: boolean
}
