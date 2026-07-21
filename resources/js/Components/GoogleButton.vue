<script setup>
/**
 * „Продължи с Google" — обикновен <a> (не Inertia Link), защото OAuth
 * redirect-ът напуска SPA-то към accounts.google.com.
 */
</script>

<template>
    <div>
        <div class="flex items-center gap-3" aria-hidden="true">
            <div class="h-px flex-1 bg-zinc-800" />
            <span class="text-xs uppercase tracking-wide text-zinc-500">или</span>
            <div class="h-px flex-1 bg-zinc-800" />
        </div>

        <a
            :href="route('google.redirect')"
            class="mt-4 flex w-full items-center justify-center gap-3 rounded-lg border border-zinc-700 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-900 transition hover:bg-zinc-100"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z" />
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z" />
                <path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z" />
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" />
            </svg>
            Продължи с Google
        </a>
    </div>
</template>
