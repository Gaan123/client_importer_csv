<template>
  <div class="min-h-screen bg-gray-100">
    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <div class="flex items-center gap-4">
            <Button icon="pi pi-arrow-left" @click="goBack" text />
            <h1 class="text-2xl font-bold">Duplicates for: {{ originalClient?.company }}</h1>
          </div>
          <Button label="Logout" icon="pi pi-sign-out" @click="handleLogout" severity="secondary" />
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Color Legend -->
      <Card class="mb-4">
        <template #content>
          <div class="flex items-center gap-6">
            <span class="font-semibold">Duplicate Types:</span>
            <div class="flex items-center gap-2">
              <div class="w-4 h-4 bg-yellow-200 border border-yellow-400 rounded"></div>
              <span>Company Match</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-4 h-4 bg-blue-200 border border-blue-400 rounded"></div>
              <span>Email Match</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-4 h-4 bg-green-200 border border-green-400 rounded"></div>
              <span>Phone Match</span>
            </div>
          </div>
        </template>
      </Card>

      <!-- Original Client -->
      <Card class="mb-4">
        <template #title>
          <div class="flex items-center gap-2">
            <i class="pi pi-user text-blue-500"></i>
            <span>Original Client</span>
          </div>
        </template>
        <template #content>
          <div v-if="originalClient" class="grid grid-cols-3 gap-4">
            <div>
              <div class="text-sm text-gray-600">Company</div>
              <div class="font-semibold">{{ originalClient.company }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Email</div>
              <div class="font-semibold">{{ originalClient.email }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Phone</div>
              <div class="font-semibold">{{ originalClient.phone }}</div>
            </div>
          </div>
        </template>
      </Card>

      <!-- Duplicates Table -->
      <Card>
        <template #title>
          <span>Duplicate Clients ({{ duplicates.length }})</span>
        </template>
        <template #content>
          <DataTable :value="duplicates" :loading="loading" tableStyle="min-width: 50rem">
            <Column field="id" header="ID" style="width: 5rem"></Column>
            <Column field="company" header="Company">
              <template #body="slotProps">
                <div
                  :class="getCellClass(slotProps.data.id, 'company')"
                  class="p-2 rounded"
                >
                  {{ slotProps.data.company }}
                </div>
              </template>
            </Column>
            <Column field="email" header="Email">
              <template #body="slotProps">
                <div
                  :class="getCellClass(slotProps.data.id, 'email')"
                  class="p-2 rounded"
                >
                  {{ slotProps.data.email }}
                </div>
              </template>
            </Column>
            <Column field="phone" header="Phone">
              <template #body="slotProps">
                <div
                  :class="getCellClass(slotProps.data.id, 'phone')"
                  class="p-2 rounded"
                >
                  {{ slotProps.data.phone }}
                </div>
              </template>
            </Column>
            <Column header="Match Types" style="width: 12rem">
              <template #body="slotProps">
                <div class="flex gap-1">
                  <Tag
                    v-if="isMatchType(slotProps.data.id, 'company')"
                    value="Company"
                    severity="warning"
                    class="text-xs"
                  />
                  <Tag
                    v-if="isMatchType(slotProps.data.id, 'email')"
                    value="Email"
                    severity="info"
                    class="text-xs"
                  />
                  <Tag
                    v-if="isMatchType(slotProps.data.id, 'phone')"
                    value="Phone"
                    severity="success"
                    class="text-xs"
                  />
                </div>
              </template>
            </Column>

            <template #empty>
              <div class="text-center py-4">No duplicates found.</div>
            </template>
          </DataTable>
        </template>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import axios from 'axios';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const originalClient = ref(null);
const duplicates = ref([]);
const loading = ref(false);
const duplicateIds = ref({
  company: [],
  email: [],
  phone: []
});

const fetchDuplicates = async () => {
  loading.value = true;
  try {
    const clientId = route.params.id;
    const response = await axios.get(`/api/clients/${clientId}/duplicates`);

    originalClient.value = response.data.original_client;
    duplicates.value = response.data.data;

    if (originalClient.value.extras?.duplicate_ids) {
      duplicateIds.value = {
        company: originalClient.value.extras.duplicate_ids.company || [],
        email: originalClient.value.extras.duplicate_ids.email || [],
        phone: originalClient.value.extras.duplicate_ids.phone || []
      };
    }
  } catch (error) {
    console.error('Error fetching duplicates:', error);
  } finally {
    loading.value = false;
  }
};

const isMatchType = (clientId, type) => {
  return duplicateIds.value[type]?.includes(clientId);
};

const getCellClass = (clientId, type) => {
  if (isMatchType(clientId, type)) {
    if (type === 'company') return 'bg-yellow-200 border border-yellow-400';
    if (type === 'email') return 'bg-blue-200 border border-blue-400';
    if (type === 'phone') return 'bg-green-200 border border-green-400';
  }
  return '';
};

const goBack = () => {
  router.push('/clients');
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

onMounted(() => {
  fetchDuplicates();
});
</script>
