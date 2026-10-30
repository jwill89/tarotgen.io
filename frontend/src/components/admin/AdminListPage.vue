<script setup lang="ts">
/**
 * The frame every admin list page shares: the admin column width, the way back
 * (to the dashboard unless `back` says otherwise), and the page header (with
 * optional actions beside the title). The page itself fills the default slot —
 * search, tables, empty states.
 *
 *   <AdminListPage title="Manage Users" subtitle="…">
 *     <template #actions><button …>Add</button></template>
 *     <AdminSearchField v-model="search" placeholder="Search…" />
 *     <div class="settings-panel">…</div>
 *   </AdminListPage>
 */
import { byPrefixAndName } from '@/fontawesome'
import type { RouteLocationRaw } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { useReturnTo } from '@/composables/useReturnTo'

const props = withDefaults(
  defineProps<{
    title: string
    subtitle?: string
    /**
     * The link back: where to, and its label. `returning` is for a page opened
     * from `to` (an editor from its list): a click leaves it the way the admin
     * came (useReturnTo) rather than stacking `to` again. The link keeps its
     * href either way, so it still opens in a new tab.
     */
    back?: { to: RouteLocationRaw; label: string; returning?: boolean }
  }>(),
  {
    subtitle: undefined,
    back: () => ({ to: { name: 'admin-dashboard' }, label: 'Back to Dashboard' }),
  },
)

const returnTo = useReturnTo()

function follow(event: MouseEvent, navigate: (e?: MouseEvent) => unknown): void {
  const plainClick =
    event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey
  if (props.back.returning === true && plainClick) {
    event.preventDefault()
    returnTo(props.back.to)
  } else {
    // RouterLink's own handling, which leaves a modified click to the browser.
    void navigate(event)
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-11-desktop is-12-tablet">
          <router-link v-slot="{ href, navigate }" :to="back.to" custom>
            <a :href="href" class="button is-small is-ghost mb-4" @click="follow($event, navigate)">
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']"
              /></span>
              <span>{{ back.label }}</span>
            </a>
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
