<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import PageHeader from '@/components/PageHeader.vue'
import { useUser } from '@/composables/useUser'
import { useRouter } from 'vue-router'

const { logout } = useUser()
const router = useRouter()

async function doLogout() {
  await logout()
  void router.push({ name: 'home' })
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-10-desktop is-11-tablet">
          <PageHeader title="Dashboard" subtitle="Welcome back! Manage your account from here." />

          <div class="columns is-multiline">
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-readings' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-info">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['scroll']" size="2x" />
                </span>
                <p class="title is-4 mt-3">My Readings</p>
                <p class="subtitle is-6">View &amp; manage your saved readings</p>
              </router-link>
            </div>
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-spreads' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-link">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['table-cells']" size="2x" />
                </span>
                <p class="title is-4 mt-3">My Spreads</p>
                <p class="subtitle is-6">View &amp; manage your custom spreads</p>
              </router-link>
            </div>
            <div class="column is-3">
              <router-link
                :to="{ name: 'account-settings' }"
                class="box home-action has-text-centered"
              >
                <span class="icon is-large has-text-warning">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['gear']" size="2x" />
                </span>
                <p class="title is-4 mt-3">Account Settings</p>
                <p class="subtitle is-6">Update your profile &amp; security</p>
              </router-link>
            </div>
            <div class="column is-3">
              <a class="box home-action has-text-centered" @click="doLogout">
                <span class="icon is-large has-text-danger">
                  <FontAwesomeIcon :icon="byPrefixAndName.fad['right-from-bracket']" size="2x" />
                </span>
                <p class="title is-4 mt-3">Logout</p>
                <p class="subtitle is-6">Sign out of your account</p>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
