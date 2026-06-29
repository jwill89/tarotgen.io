<script setup lang="ts">
import { onMounted } from 'vue'
import { useChangelog } from '@/composables/useChangelog'
import { renderMarkdown } from '@/utils/markdown'

const { entries, fetchChangelog } = useChangelog()

onMounted(fetchChangelog)

function formatDate(date: string): string {
    // Parse as a plain date (avoid timezone shifting a YYYY-MM-DD string).
    const parts = date.split('-').map(Number)
    if (parts.length !== 3 || parts.some(isNaN)) return date
    const d = new Date(parts[0], parts[1] - 1, parts[2])
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<template>
    <section class="section">
        <div class="container">
            <h1 class="title is-3 is-size-4-mobile">Changelog</h1>
            <p class="subtitle is-5 is-size-6-mobile">News and updates about the generator.</p>

            <div class="changelog-timeline">
                <article v-for="entry in entries" :key="entry.entry_id" class="changelog-entry">
                    <div class="changelog-entry-head">
                        <time class="changelog-date">{{ formatDate(entry.entry_date) }}</time>
                        <h2 class="changelog-title">{{ entry.title }}</h2>
                    </div>
                    <div class="content changelog-body" v-html="renderMarkdown(entry.body)"></div>
                </article>
            </div>

            <p class="has-text-grey" v-if="entries.length === 0">No changelog entries yet.</p>
        </div>
    </section>
</template>
