<template>
  <div class="min-h-screen bg-gray-100">
    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <h1 class="text-2xl font-bold">Import Logs</h1>
          <div class="flex gap-2">
            <Button label="Clients" icon="pi pi-users" @click="goToClients" severity="secondary" />
            <Button label="Logout" icon="pi pi-sign-out" @click="handleLogout" severity="secondary" />
          </div>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <Card>
        <template #content>
          <DataTable
            :value="imports"
            :loading="loading"
            lazy
            paginator
            :rows="perPage"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[10, 20, 50]"
            @page="onPage"
            tableStyle="min-width: 50rem"
          >
            <template #header>
              <div class="flex justify-between items-center">
                <span class="text-xl font-semibold">Import History</span>
              </div>
            </template>

            <Column field="id" header="ID" sortable style="width: 5rem"></Column>
            <Column header="File Name" sortable>
              <template #body="slotProps">
                {{ slotProps.data.metadata?.original_filename || 'N/A' }}
              </template>
            </Column>
            <Column field="importable_type" header="Type" sortable style="width: 8rem">
              <template #body="slotProps">
                <Tag :value="slotProps.data.importable_type" />
              </template>
            </Column>
            <Column field="total_rows" header="Total Rows" sortable style="width: 8rem"></Column>
            <Column header="Success" style="width: 7rem">
              <template #body="slotProps">
                <Tag :value="String(slotProps.data.summary?.success || 0)" severity="success" />
              </template>
            </Column>
            <Column header="Failed" style="width: 7rem">
              <template #body="slotProps">
                <Tag :value="String(slotProps.data.summary?.failed || 0)" severity="danger" />
              </template>
            </Column>
            <Column header="Duplicates" style="width: 8rem">
              <template #body="slotProps">
                <Tag :value="String(slotProps.data.summary?.duplicates || 0)" severity="warning" />
              </template>
            </Column>
            <Column header="File Size" style="width: 8rem">
              <template #body="slotProps">
                {{ formatFileSize(slotProps.data.metadata?.file_size) }}
              </template>
            </Column>
            <Column header="Status" style="width: 12rem">
              <template #body="slotProps">
                <Tag
                  v-if="slotProps.data.status === 'completed'"
                  value="Completed"
                  severity="success"
                  icon="pi pi-check"
                />
                <Tag
                  v-else-if="slotProps.data.status === 'completed_with_errors'"
                  value="With Errors"
                  severity="warning"
                  icon="pi pi-exclamation-triangle"
                />
                <Tag
                  v-else-if="slotProps.data.status === 'processing'"
                  value="Processing"
                  severity="info"
                  icon="pi pi-spin pi-spinner"
                />
                <Tag
                  v-else-if="slotProps.data.status === 'failed'"
                  value="Failed"
                  severity="danger"
                  icon="pi pi-times"
                />
                <Tag v-else :value="slotProps.data.status" />
              </template>
            </Column>
            <Column field="created_at" header="Created At" sortable style="width: 12rem">
              <template #body="slotProps">
                {{ formatDate(slotProps.data.created_at) }}
              </template>
            </Column>
            <Column header="Actions" style="width: 10rem">
              <template #body="slotProps">
                <Button
                  label="View Details"
                  icon="pi pi-eye"
                  size="small"
                  @click="viewDetails(slotProps.data.id)"
                />
              </template>
            </Column>

            <template #empty>
              <div class="text-center py-4">No imports found.</div>
            </template>
          </DataTable>
        </template>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import axios from 'axios';

const router = useRouter();
const authStore = useAuthStore();

const imports = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const perPage = ref(10);
const currentPage = ref(1);

const fetchImports = async (page = 1, rows = 10) => {
  loading.value = true;
  try {
    const response = await axios.get('/api/imports', {
      params: {
        page: page,
        per_page: rows
      }
    });
    imports.value = response.data.data;
    totalRecords.value = response.data.meta.total;
    currentPage.value = response.data.meta.current_page;
    perPage.value = response.data.meta.per_page;
  } catch (error) {
    console.error('Error fetching imports:', error);
  } finally {
    loading.value = false;
  }
};

const onPage = (event) => {
  fetchImports(event.page + 1, event.rows);
};

const formatFileSize = (bytes) => {
  if (!bytes) return 'N/A';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
  return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleString();
};

const viewDetails = (importId) => {
  router.push(`/imports/${importId}`);
};

const goToClients = () => {
  router.push('/clients');
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

onMounted(() => {
  fetchImports();
});
</script>
