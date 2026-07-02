import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import { useDataTable } from '@/composables/useDataTable'

interface Row {
  id: number
  name: string
}

const rows: Row[] = [
  { id: 3, name: 'Charlie' },
  { id: 1, name: 'alice' },
  { id: 2, name: 'Bob' },
]

function table(initialSort?: string, initialDir?: 'asc' | 'desc') {
  return useDataTable(ref(rows), {
    searchText: (r) => `${r.name} ${r.id}`,
    sortAccessors: {
      id: (r) => r.id,
      name: (r) => r.name,
    },
    initialSort,
    initialDir,
  })
}

describe('useDataTable — search', () => {
  it('filters case-insensitively across the search text', () => {
    const t = table()
    t.search.value = 'bob'
    expect(t.rows.value.map((r) => r.id)).toEqual([2])
  })

  it('matches numeric fields included in the search text', () => {
    const t = table()
    t.search.value = '3'
    expect(t.rows.value.map((r) => r.id)).toEqual([3])
  })

  it('returns all rows when the query is blank', () => {
    const t = table()
    t.search.value = '   '
    expect(t.rows.value).toHaveLength(3)
  })
})

describe('useDataTable — sort', () => {
  it('sorts numerically ascending and descending', () => {
    const t = table('id', 'asc')
    expect(t.rows.value.map((r) => r.id)).toEqual([1, 2, 3])
    t.sortDir.value = 'desc'
    expect(t.rows.value.map((r) => r.id)).toEqual([3, 2, 1])
  })

  it('sorts strings case-insensitively-ish via localeCompare', () => {
    const t = table('name', 'asc')
    // alice, Bob, Charlie — localeCompare puts them in natural order.
    expect(t.rows.value.map((r) => r.name)).toEqual(['alice', 'Bob', 'Charlie'])
  })

  it('does not mutate the source array', () => {
    void table('id', 'asc').rows.value // touch the computed to force the sort
    expect(rows.map((r) => r.id)).toEqual([3, 1, 2]) // original order intact
  })
})

describe('useDataTable — toggleSort & headerIcon', () => {
  it('flips direction on the active key and resets to asc on a new key', () => {
    const t = table('id', 'asc')
    t.toggleSort('id')
    expect(t.sortDir.value).toBe('desc')
    t.toggleSort('name')
    expect(t.sortKey.value).toBe('name')
    expect(t.sortDir.value).toBe('asc')
  })

  it('reports the correct sort icon for each column state', () => {
    const t = table('id', 'asc')
    expect(t.headerIcon('id')).toBe('fa-sort-up')
    t.sortDir.value = 'desc'
    expect(t.headerIcon('id')).toBe('fa-sort-down')
    expect(t.headerIcon('name')).toBe('fa-sort') // inactive column
  })
})
