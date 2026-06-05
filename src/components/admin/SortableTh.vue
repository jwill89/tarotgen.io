<script setup lang="ts">
defineProps<{
    label: string
    sortKey: string
    activeKey: string | null
    dir: 'asc' | 'desc'
}>()

const emit = defineEmits<{ sort: [string] }>()
</script>

<template>
    <th class="sortable-th" @click="emit('sort', sortKey)">
        <span>{{ label }}</span>
        <span class="icon is-small sort-icon">
            <i
                class="fa-solid"
                :class="activeKey === sortKey ? (dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'"
            ></i>
        </span>
    </th>
</template>

<style scoped>
.sortable-th {
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}

.sortable-th:hover {
    color: var(--myst-gold, #e9c46a);
}

.sort-icon {
    opacity: 0.55;
}
</style>
