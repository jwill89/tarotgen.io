<script setup lang="ts">
import { useUser } from '@/composables/useUser'
import { useRouter } from 'vue-router'

const { logout } = useUser()
const router = useRouter()

async function doLogout() {
    await logout()
    router.push({ name: 'home' })
}
</script>

<template>
    <section class="section">
        <div class="container">
            <h1 class="title is-3">Dashboard</h1>
            <p class="subtitle is-5">Welcome back! Manage your account from here.</p>

            <div class="columns is-multiline">
                <div class="column is-3">
                    <router-link :to="{ name: 'account-readings' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-info">
                            <i class="fa-solid fa-scroll fa-2x"></i>
                        </span>
                        <p class="title is-4 mt-3">My Readings</p>
                        <p class="subtitle is-6">View &amp; manage your saved readings</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'account-spreads' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-link">
                            <i class="fa-solid fa-table-cells fa-2x"></i>
                        </span>
                        <p class="title is-4 mt-3">My Spreads</p>
                        <p class="subtitle is-6">View &amp; manage your custom spreads</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'account-settings' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-warning">
                            <i class="fa-solid fa-gear fa-2x"></i>
                        </span>
                        <p class="title is-4 mt-3">Account Settings</p>
                        <p class="subtitle is-6">Update your profile &amp; security</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <a class="box home-action has-text-centered" @click="doLogout">
                        <span class="icon is-large has-text-danger">
                            <i class="fa-solid fa-right-from-bracket fa-2x"></i>
                        </span>
                        <p class="title is-4 mt-3">Logout</p>
                        <p class="subtitle is-6">Sign out of your account</p>
                    </a>
                </div>
            </div>
        </div>
    </section>
</template>

