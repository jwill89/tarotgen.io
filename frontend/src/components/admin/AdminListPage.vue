<script setup lang="ts">
/**
 * The frame every admin list page shares: the admin column width, the way back
 * to the dashboard, and the page header (with optional actions beside the title).
 * The page itself fills the default slot — search, tables, empty states.
 *
 *   <AdminListPage title="Manage Users" subtitle="…">
 *     <template #actions><button …>Add</button></template>
 *     <AdminSearchField v-model="search" placeholder="Search…" />
 *     <div class="settings-panel">…</div>
 *   </AdminListPage>
 */
import { byPrefixAndName } from '@/fontawesome'
import PageHeader from '@/components/PageHeader.vue'

defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-11-desktop is-12-tablet">
          <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader :title="title" :subtitle="subtitle">
            <!-- Only when there are actions: PageHeader drops its actions box otherwise. -->
            <template v-if="$slots.actions" #default><slot name="actions" /></template>
          </PageHeader>

          <slot />
        </div>
      </div>
    </div>
  </section>
</template>
