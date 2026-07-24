<script setup>
/**
 * Обвивка за таблици — каноничната рамка (радиус/бордер) на едно място.
 * Винаги overflow-x-auto: тясна таблица не показва скролбар, а широка
 * (напр. класирането на мобилно) може да се скролва хоризонтално.
 */
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-zinc-800">
        <slot />
    </div>
</template>
