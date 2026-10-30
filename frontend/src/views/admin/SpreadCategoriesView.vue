<script setup lang="ts">
/** The spread library's categories, kept on a page of their own under the spread list. */
import { onMounted } from 'vue'
import { useAdminSpreads } from '@/composables/useAdminSpreads'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import SpreadCategoryManager from '@/components/admin/SpreadCategoryManager.vue'

const BACK = { to: { name: 'admin-spreads' }, label: 'Back to Spreads' }

const { categories, loadCategories } = useAdminSpreads()

onMounted(() => {
  void loadCategories()
})
</script>

<template>
  <AdminListPage
    title="Spread Categories"
    subtitle="The spread library groups spreads under these, in this order. A new or approved spread goes into the first unless you choose another."
    :back="BACK"
  >
    <SpreadCategoryManager :categories="categories" @changed="loadCategories" />
  </AdminListPage>
</template>
