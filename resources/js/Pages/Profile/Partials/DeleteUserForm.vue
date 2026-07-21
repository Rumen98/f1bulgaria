<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    // Google акаунтите нямат парола — потвърждават без нея.
    hasPassword: { type: Boolean, default: true },
});

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    if (props.hasPassword) {
        nextTick(() => passwordInput.value.focus());
    }
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-lg font-bold text-white">Изтриване на акаунт</h2>

            <p class="mt-1 text-sm text-zinc-400">
                След изтриване всички данни на акаунта се премахват безвъзвратно.
                Преди това запази информацията, която искаш да съхраниш.
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">Изтрий акаунта</DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-bold text-white">
                    Сигурен ли си, че искаш да изтриеш акаунта си?
                </h2>

                <p class="mt-1 text-sm text-zinc-400">
                    <template v-if="hasPassword">
                        След изтриване всички данни се премахват безвъзвратно. Въведи
                        паролата си, за да потвърдиш окончателното изтриване.
                    </template>
                    <template v-else>
                        След изтриване всички данни се премахват безвъзвратно.
                        Действието не може да бъде отменено.
                    </template>
                </p>

                <div v-if="hasPassword" class="mt-6">
                    <InputLabel for="password" value="Парола" class="sr-only" />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="Парола"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">Отказ</SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Изтрий акаунта
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
