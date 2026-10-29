<script setup lang="ts">
import { onMounted } from 'vue'
import { useChangelog } from '@/composables/useChangelog'
import { renderMarkdown } from '@/utils/markdown'
import PageHeader from '@/components/PageHeader.vue'

const { entries, loaded, error, fetchChangelog } = useChangelog()

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
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <PageHeader title="Changelog" subtitle="News and updates about the generator." />

          <div class="settings-panel">
            <div class="changelog-timeline">
              <article v-for="entry in entries" :key="entry.entry_id" class="changelog-entry">
                <div class="changelog-entry-head">
                  <time class="changelog-date">{{ formatDate(entry.entry_date) }}</time>
                  <h2 class="changelog-title">{{ entry.title }}</h2>
                </div>
                <!-- Sanitized by renderMarkdown() (marked + DOMPurify) — see utils/markdown.ts -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="content changelog-body" v-html="renderMarkdown(entry.body)"></div>
              </article>
            </div>

            <template v-if="entries.length === 0">
              <p v-if="error" class="has-text-danger">Couldn't load the changelog. {{ error }}</p>
              <p v-else-if="loaded" class="has-text-grey">No changelog entries yet.</p>
            </template>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
