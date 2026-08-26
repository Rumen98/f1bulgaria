<script setup>
import SurveyForm from '@/Components/Feedback/SurveyForm.vue';
import Card from '@/Components/UI/Card.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
</script>

<template>
    <PublicLayout>
        <!-- meta/og таговете идват сървърно от App\Support\Seo (виж app.blade.php). -->
        <div class="mx-auto max-w-xl">
            <h1 class="mb-2 font-display text-2xl font-black sm:text-3xl">
                Обратна <span class="text-red-600">връзка</span>
            </h1>
            <p class="mb-6 text-sm text-zinc-400">
                Падок се прави от един човек за общността — всяко мнение се чете лично
                и направо определя какво строим след това.
            </p>
            <Card padding="lg">
                <SurveyForm source="page" />
            </Card>
        </div>
    </PublicLayout>
</template>
