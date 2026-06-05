<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'

const route = useRoute()
const { activate } = useUser()

const state = ref<'working' | 'success' | 'error'>('working')
const message = ref('')

onMounted(async () => {
    const token = typeof route.query.token === 'string' ? route.query.token : ''
    if (token === '') {
        state.value = 'error'
        message.value = 'No activation token was provided.'
        return
    }

    const res = await activate(token)
    if (res.ok) {
        state.value = 'success'
        message.value = res.message ?? 'Your account is now active.'
    } else {
        state.value = 'error'
        message.value = res.error ?? 'This activation link is invalid or has expired.'
    }
})
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-5-desktop is-7-tablet has-text-centered">
                    <h1 class="title is-3">Account Activation</h1>

                    <div class="box">
                        <template v-if="state === 'working'">
                            <span class="icon is-large has-text-grey-light">
                                <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
                            </span>
                            <p class="mt-3">Activating your account…</p>
                        </template>

                        <template v-else-if="state === 'success'">
                            <span class="icon is-large has-text-success">
                                <i class="fa-solid fa-circle-check fa-2x"></i>
                            </span>
                            <p class="mt-3">{{ message }}</p>
                            <div class="buttons is-centered mt-4">
                                <router-link class="button is-primary" :to="{ name: 'login' }">
                                    <span class="icon"><i class="fa-solid fa-right-to-bracket"></i></span>
                                    <span>Log In</span>
                                </router-link>
                            </div>
                        </template>

                        <template v-else>
                            <span class="icon is-large has-text-danger">
                                <i class="fa-solid fa-circle-exclamation fa-2x"></i>
                            </span>
                            <p class="mt-3">{{ message }}</p>
                            <div class="buttons is-centered mt-4">
                                <router-link class="button" :to="{ name: 'register' }">Register again</router-link>
                                <router-link class="button" :to="{ name: 'home' }">Back to Home</router-link>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
