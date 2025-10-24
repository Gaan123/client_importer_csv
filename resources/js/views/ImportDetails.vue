<template>
  <div class="min-h-screen bg-gray-100">
    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <div class="flex items-center gap-4">
            <Button icon="pi pi-arrow-left" @click="goBack" text />
            <h1 class="text-2xl font-bold">Import Details #{{ importData?.import_id }}</h1>
          </div>
          <Button label="Logout" icon="pi pi-sign-out" @click="handleLogout" severity="secondary" />
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Summary Card -->
      <Card class="mb-4">
        <template #title>Import Summary</template>
        <template #content>
          <div v-if="importData" class="grid grid-cols-4 gap-4">
            <div>
              <div class="text-sm text-gray-600">Total Rows</div>
              <div class="font-semibold">{{ importData.total_rows || 0 }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Success</div>
              <div class="font-semibold text-green-600">{{ importData.summary?.imported || 0 }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Failed</div>
              <div class="font-semibold text-red-600">{{ importData.summary?.failed || 0 }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Duplicates</div>
              <div class="font-semibold text-yellow-600">{{ importData.summary?.duplicates || 0 }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-600">Status</div>
              <Tag
                v-if="importData.status === 'completed'"
                value="Completed"
                severity="success"
              />
              <Tag
                v-else-if="importData.status === 'processing'"
                value="Processing"
                severity="info"
              />
              <Tag
                v-else-if="importData.status === 'failed'"
                value="Failed"
                severity="danger"
              />
            </div>
            <div>
              <div class="text-sm text-gray-600">Created At</div>
              <div class="font-semibold">{{ formatDate(importData.created_at) }}</div>
            </div>
          </div>
        </template>
      </Card>

      <!-- Row Details Table -->
      <Card>
        <template #title>
          <div class="flex justify-between items-center">
            <span>Import Rows ({{ totalRecords }})</span>
            <div class="flex gap-2">
              <Button
                :label="`All (${totalRecords})`"
                :severity="statusFilter === null ? 'primary' : 'secondary'"
                size="small"
                @click="filterByStatus(null)"
              />
              <Button
                :label="`Success (${importData?.summary?.imported || 0})`"
                severity="success"
                size="small"
                @click="filterByStatus('success')"
              />
              <Button
                :label="`Failed (${importData?.summary?.failed || 0})`"
                severity="danger"
                size="small"
                @click="filterByStatus('failed')"
              />
            </div>
          </div>
        </template>
        <template #content>
          <DataTable
            :value="rows"
            :loading="loading"
            lazy
            paginator
            v-model:rows="perPage"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 20, 50, 100]"
            @page="onPage"
            tableStyle="min-width: 50rem"
          >
            <Column field="row_number" header="Row #" style="width: 6rem"></Column>
            <Column field="company" header="Company"></Column>
            <Column field="email" header="Email"></Column>
            <Column field="phone" header="Phone"></Column>
            <Column header="Status" style="width: 8rem">
              <template #body="slotProps">
                <Tag
                  v-if="slotProps.data.status === 'success'"
                  value="Success"
                  severity="success"
                />
                <Tag
                  v-else-if="slotProps.data.status === 'failed'"
                  value="Failed"
                  severity="danger"
                />
              </template>
            </Column>
            <Column header="Duplicate" style="width: 8rem">
              <template #body="slotProps">
                <Tag
                  v-if="slotProps.data.is_duplicate"
                  value="Yes"
                  severity="warning"
                />
                <Tag v-else value="No" severity="success" />
              </template>
            </Column>
            <Column field="error" header="Error">
              <template #body="slotProps">
                <span v-if="slotProps.data.error" class="text-sm text-red-600">
                  {{ slotProps.data.error }}
                </span>
              </template>
            </Column>

            <template #empty>
              <div class="text-center py-4">No rows found.</div>
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

const importData = ref(null);
const rows = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const perPage = ref(10);
const currentPage = ref(1);
const statusFilter = ref(null);

const fetchImportDetails = async (page = 1, rowsPerPage = 10) => {
  loading.value = true;
  try {
    const importId = route.params.id;
    const params = {
      page: page,
      per_page: rowsPerPage
    };

    if (statusFilter.value) {
      params.status = statusFilter.value;
    }

    const response = await axios.get(`/api/imports/${importId}`, { params });

    importData.value = {
      import_id: response.data.import_id,
      status: response.data.status,
      total_rows: response.data.total_rows,
      summary: response.data.summary
    };
    rows.value = response.data.clients.data;
    totalRecords.value = response.data.clients.total;
    currentPage.value = response.data.clients.current_page;
  } catch (error) {
    console.error('Error fetching import details:', error);
  } finally {
    loading.value = false;
  }
};

const onPage = (event) => {
  fetchImportDetails(event.page + 1, event.rows);
};

const filterByStatus = (status) => {
  statusFilter.value = status;
  fetchImportDetails(1, perPage.value);
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString();
};

const goBack = () => {
  router.push('/imports');
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

onMounted(() => {
  fetchImportDetails();
});
</script>
