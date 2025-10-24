<template>
  <Dialog
    :visible="visible"
    @update:visible="$emit('update:visible', $event)"
    :header="isEditMode ? 'Edit Client' : 'Create Client'"
    :modal="true"
    :style="{ width: '600px' }"
  >
    <div class="flex flex-col gap-4 py-4">
      <div>
        <label for="company" class="block text-sm font-medium mb-2">Company *</label>
        <InputText
          id="company"
          v-model="form.company"
          class="w-full"
          :class="{ 'p-invalid': errors.company }"
        />
        <small v-if="errors.company" class="text-red-500">{{ errors.company }}</small>
      </div>
      <div>
        <label for="email" class="block text-sm font-medium mb-2">Email *</label>
        <InputText
          id="email"
          v-model="form.email"
          type="email"
          class="w-full"
          :class="{ 'p-invalid': errors.email }"
        />
        <small v-if="errors.email" class="text-red-500">{{ errors.email }}</small>
      </div>
      <div>
        <label for="phone" class="block text-sm font-medium mb-2">Phone *</label>
        <InputText
          id="phone"
          v-model="form.phone"
          class="w-full"
          :class="{ 'p-invalid': errors.phone }"
        />
        <small v-if="errors.phone" class="text-red-500">{{ errors.phone }}</small>
      </div>
    </div>
    <template #footer>
      <Button label="Cancel" icon="pi pi-times" @click="handleCancel" severity="secondary" />
      <Button
        :label="isEditMode ? 'Update' : 'Create'"
        icon="pi pi-check"
        @click="handleSave"
        :loading="saving"
      />
    </template>
  </Dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import axios from 'axios';

const props = defineProps({
  visible: {
    type: Boolean,
    required: true
  },
  client: {
    type: Object,
    default: null
  }
});

const emit = defineEmits(['update:visible', 'saved']);

const isEditMode = ref(false);
const saving = ref(false);
const form = ref({
  id: null,
  company: '',
  email: '',
  phone: ''
});
const errors = ref({});

watch(() => props.client, (newClient) => {
  if (newClient) {
    isEditMode.value = true;
    form.value = {
      id: newClient.id,
      company: newClient.company,
      email: newClient.email,
      phone: newClient.phone
    };
  } else {
    isEditMode.value = false;
    form.value = {
      id: null,
      company: '',
      email: '',
      phone: ''
    };
  }
  errors.value = {};
}, { immediate: true });

const handleCancel = () => {
  emit('update:visible', false);
  resetForm();
};

const resetForm = () => {
  form.value = {
    id: null,
    company: '',
    email: '',
    phone: ''
  };
  errors.value = {};
};

const handleSave = async () => {
  errors.value = {};
  saving.value = true;

  try {
    let response;
    if (isEditMode.value) {
      response = await axios.put(`/api/clients/${form.value.id}`, {
        company: form.value.company,
        email: form.value.email,
        phone: form.value.phone
      });
    } else {
      response = await axios.post('/api/clients', {
        company: form.value.company,
        email: form.value.email,
        phone: form.value.phone
      });
    }

    emit('saved', response.data);
    emit('update:visible', false);
    resetForm();
  } catch (error) {
    if (error.response?.data?.errors) {
      errors.value = error.response.data.errors;
    } else {
      console.error('Error saving client:', error);
    }
  } finally {
    saving.value = false;
  }
};
</script>
