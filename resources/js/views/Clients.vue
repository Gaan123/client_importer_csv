<template>
  <div class="min-h-screen bg-gray-100">
    <ConfirmDialog></ConfirmDialog>
    <ClientFormModal
      v-model:visible="showClientDialog"
      :client="selectedClient"
      @saved="handleClientSaved"
    />

    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <h1 class="text-2xl font-bold">Clients</h1>
          <div class="flex gap-2">
            <Button label="Create Client" icon="pi pi-plus" @click="openCreateDialog" />
            <Button label="Logout" icon="pi pi-sign-out" @click="handleLogout" severity="secondary" />
          </div>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <Card>
        <template #content>
          <DataTable
            v-model:filters="filters"
            :value="clients"
            :loading="loading"
            lazy
            paginator
            :rows="perPage"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[5, 10, 20, 50]"
            @page="onPage"
            @sort="onSort"
            :globalFilterFields="['company', 'email', 'phone', 'address']"
            tableStyle="min-width: 50rem"
          >
            <template #header>
              <div class="flex justify-between items-center">
                <div class="flex items-center gap-4">
                  <span class="text-xl font-semibold">Client List</span>
                  <div class="flex items-center gap-2">
                    <Checkbox v-model="showOnlyDuplicates" :binary="true" inputId="duplicates" @change="onDuplicateFilterChange" />
                    <label for="duplicates" class="cursor-pointer">Show only duplicates</label>
                  </div>
                </div>
                <IconField iconPosition="left">
                  <InputIcon>
                    <i class="pi pi-search" />
                  </InputIcon>
                  <InputText
                    v-model="filters['global'].value"
                    placeholder="Search clients..."
                  />
                </IconField>
              </div>
            </template>

            <Column field="id" header="ID" sortable style="width: 5rem"></Column>
            <Column field="company" header="Company" sortable></Column>
            <Column field="email" header="Email" sortable></Column>
            <Column field="phone" header="Phone" sortable></Column>
            <Column field="has_duplicates" header="Duplicates" sortable style="width: 8rem">
              <template #body="slotProps">
                <Tag
                  v-if="slotProps.data.has_duplicates"
                  severity="warning"
                  value="Yes"
                  icon="pi pi-exclamation-triangle"
                />
                <Tag v-else severity="success" value="No" icon="pi pi-check" />
              </template>
            </Column>
            <Column header="Actions" style="width: 20rem">
              <template #body="slotProps">
                <div class="flex gap-2">
                  <Button
                    v-if="slotProps.data.has_duplicates"
                    label="View"
                    icon="pi pi-eye"
                    size="small"
                    @click="viewDuplicates(slotProps.data.id)"
                  />
                  <Button
                    label="Edit"
                    icon="pi pi-pencil"
                    size="small"
                    severity="info"
                    @click="openEditDialog(slotProps.data)"
                  />
                  <Button
                    label="Delete"
                    icon="pi pi-trash"
                    size="small"
                    severity="danger"
                    @click="confirmDelete(slotProps.data)"
                  />
                </div>
              </template>
            </Column>

            <template #empty>
              <div class="text-center py-4">No clients found.</div>
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
import { useConfirm } from 'primevue/useconfirm';
import { FilterMatchMode } from '@primevue/core/api';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import ConfirmDialog from 'primevue/confirmdialog';
import Checkbox from 'primevue/checkbox';
import ClientFormModal from '../components/ClientFormModal.vue';
import axios from 'axios';

const router = useRouter();
const authStore = useAuthStore();
const confirm = useConfirm();

const clients = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const perPage = ref(10);
const currentPage = ref(1);
const showOnlyDuplicates = ref(false);
const filters = ref({
  global: { value: null, matchMode: FilterMatchMode.CONTAINS },
  company: { value: null, matchMode: FilterMatchMode.CONTAINS },
  email: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const showClientDialog = ref(false);
const selectedClient = ref(null);

const fetchClients = async (page = 1, rows = 10) => {
  loading.value = true;
  try {
    const params = {
      page: page,
      per_page: rows
    };

    if (showOnlyDuplicates.value) {
      params.has_duplicates = 1;
    }

    const response = await axios.get('/api/clients', { params });
    clients.value = response.data.data;
    totalRecords.value = response.data.meta.total;
    currentPage.value = response.data.meta.current_page;
    perPage.value = response.data.meta.per_page;
  } catch (error) {
    console.error('Error fetching clients:', error);
  } finally {
    loading.value = false;
  }
};

const onPage = (event) => {
  fetchClients(event.page + 1, event.rows);
};

const onSort = (event) => {
  fetchClients(currentPage.value, perPage.value);
};

const onFilter = (event) => {
  fetchClients(1, perPage.value);
};

const onDuplicateFilterChange = () => {
  fetchClients(1, perPage.value);
};

const viewDuplicates = (clientId) => {
  router.push(`/clients/${clientId}/duplicates`);
};

const confirmDelete = (client) => {
  confirm.require({
    message: `Are you sure you want to delete ${client.company}?`,
    header: 'Delete Confirmation',
    icon: 'pi pi-exclamation-triangle',
    rejectLabel: 'Cancel',
    acceptLabel: 'Delete',
    accept: () => {
      deleteClient(client.id);
    }
  });
};

const deleteClient = async (clientId) => {
  try {
    await axios.delete(`/api/clients/${clientId}`);
    clients.value = clients.value.filter(c => c.id !== clientId);
    totalRecords.value--;
  } catch (error) {
    console.error('Error deleting client:', error);
  }
};

const openCreateDialog = () => {
  selectedClient.value = null;
  showClientDialog.value = true;
};

const openEditDialog = (client) => {
  selectedClient.value = client;
  showClientDialog.value = true;
};

const handleClientSaved = (data) => {
  fetchClients(currentPage.value, perPage.value);
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

onMounted(() => {
  fetchClients();
});
</script>
