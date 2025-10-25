<template>
  <div class="min-h-screen bg-gray-100">
    <ConfirmDialog></ConfirmDialog>
    <ClientFormModal
      v-model:visible="showClientDialog"
      :client="selectedClient"
      @saved="handleClientSaved"
    />

    <!-- Import CSV Dialog -->
    <Dialog v-model:visible="showImportDialog" modal header="Import Clients from CSV" :style="{ width: '500px' }">
      <div class="space-y-4">
        <div>
          <FileUpload
            mode="basic"
            name="file"
            accept=".csv"
            :auto="false"
            chooseLabel="Choose CSV File"
            @select="onFileSelect"
            :disabled="uploading"
          />
          <small class="text-gray-500 mt-2 block">
            Accepted format: CSV
          </small>
        </div>

        <div v-if="selectedFile" class="bg-gray-50 p-3 rounded">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <i class="pi pi-file text-blue-500"></i>
              <span class="text-sm font-medium">{{ selectedFile.name }}</span>
            </div>
            <Button
              icon="pi pi-times"
              text
              rounded
              severity="danger"
              @click="clearFile"
              :disabled="uploading"
            />
          </div>
          <div class="text-xs text-gray-500 mt-1">
            Size: {{ formatFileSize(selectedFile.size) }}
          </div>
        </div>

        <div v-if="uploading" class="space-y-2">
          <ProgressBar mode="indeterminate" style="height: 6px" />
          <p class="text-sm text-center text-gray-600">Uploading and processing CSV file...</p>
        </div>

        <div v-if="importResult" class="border rounded p-3" :class="importResult.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'">
          <div class="flex items-start gap-2">
            <i :class="importResult.success ? 'pi pi-check-circle text-green-600' : 'pi pi-times-circle text-red-600'"></i>
            <div class="flex-1">
              <p class="font-medium" :class="importResult.success ? 'text-green-800' : 'text-red-800'">
                {{ importResult.message }}
              </p>
              <div v-if="importResult.success && importResult.stats" class="mt-2 text-sm text-gray-700">
                <p>Total Rows: {{ importResult.stats.total }}</p>
                <p class="text-green-600">Imported: {{ importResult.stats.imported }}</p>
                <p v-if="importResult.stats.failed > 0" class="text-red-600">Failed: {{ importResult.stats.failed }}</p>
                <p v-if="importResult.stats.duplicates > 0" class="text-yellow-600">Duplicates: {{ importResult.stats.duplicates }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Cancel" icon="pi pi-times" @click="closeImportDialog" severity="secondary" :disabled="uploading" />
        <Button label="Upload & Import" icon="pi pi-upload" @click="uploadFile" :disabled="!selectedFile || uploading" :loading="uploading" />
      </template>
    </Dialog>

    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <h1 class="text-2xl font-bold">Clients</h1>
          <div class="flex gap-2">
            <Button label="Create Client" icon="pi pi-plus" @click="openCreateDialog" />
            <Button label="Import CSV" icon="pi pi-upload" @click="openImportDialog" severity="info" />
            <Button label="Export Clients" icon="pi pi-file-excel" @click="goToExports" severity="success" />
            <Button label="Import Logs" icon="pi pi-file" @click="goToImports" severity="secondary" />
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
                    v-model="searchQuery"
                    placeholder="Search clients..."
                    @input="onSearchInput"
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
import Dialog from 'primevue/dialog';
import FileUpload from 'primevue/fileupload';
import ProgressBar from 'primevue/progressbar';
import ClientFormModal from '../components/ClientFormModal.vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

const router = useRouter();
const authStore = useAuthStore();
const confirm = useConfirm();
const toast = useToast();

const clients = ref([]);
const loading = ref(false);
const totalRecords = ref(0);
const perPage = ref(10);
const currentPage = ref(1);
const showOnlyDuplicates = ref(false);
const searchQuery = ref('');
const filters = ref({
  global: { value: null, matchMode: FilterMatchMode.CONTAINS },
  company: { value: null, matchMode: FilterMatchMode.CONTAINS },
  email: { value: null, matchMode: FilterMatchMode.CONTAINS },
});

const showClientDialog = ref(false);
const selectedClient = ref(null);

// Import CSV Dialog
const showImportDialog = ref(false);
const selectedFile = ref(null);
const uploading = ref(false);
const importResult = ref(null);

// Export
const exporting = ref(false);

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

    if (searchQuery.value) {
      params.search = searchQuery.value;
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

const goToImports = () => {
  router.push('/imports');
};

const goToExports = () => {
  router.push('/exports');
};

const openImportDialog = () => {
  showImportDialog.value = true;
  selectedFile.value = null;
  importResult.value = null;
};

const closeImportDialog = () => {
  showImportDialog.value = false;
  selectedFile.value = null;
  importResult.value = null;
};

const onFileSelect = (event) => {
  selectedFile.value = event.files[0];
  importResult.value = null;
};

const clearFile = () => {
  selectedFile.value = null;
  importResult.value = null;
};

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const uploadFile = async () => {
  if (!selectedFile.value) return;

  uploading.value = true;
  importResult.value = null;

  try {
    const formData = new FormData();
    formData.append('file', selectedFile.value);

    const response = await axios.post('/api/clients/import', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.success) {
      importResult.value = {
        success: true,
        message: 'Import completed successfully!',
        stats: response.data.data
      };

      toast.add({
        severity: 'success',
        summary: 'Import Successful',
        detail: `Imported ${response.data.data.imported} clients successfully`,
        life: 5000
      });

      // Refresh client list after successful import
      setTimeout(() => {
        fetchClients(currentPage.value, perPage.value);
        closeImportDialog();
      }, 2000);
    } else {
      importResult.value = {
        success: false,
        message: response.data.message || 'Import failed',
      };

      toast.add({
        severity: 'error',
        summary: 'Import Failed',
        detail: response.data.message || 'Failed to import CSV',
        life: 5000
      });
    }
  } catch (error) {
    console.error('Error uploading file:', error);

    const errorMessage = error.response?.data?.message || error.response?.data?.error || 'Failed to upload file';

    importResult.value = {
      success: false,
      message: errorMessage,
    };

    toast.add({
      severity: 'error',
      summary: 'Upload Error',
      detail: errorMessage,
      life: 5000
    });
  } finally {
    uploading.value = false;
  }
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

let searchTimeout = null;
const onSearchInput = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchClients(1, perPage.value);
  }, 500);
};

const exportClients = async () => {
  if (exporting.value) return;

  exporting.value = true;
  try {
    const response = await axios.get('/api/clients/export');

    if (response.data.async) {
      const exportId = response.data.export_id;

      toast.add({
        severity: 'info',
        summary: 'Export Queued',
        detail: 'Large export queued. Preparing your file...',
        life: 3000
      });

      pollExportStatus(exportId);
    } else {
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `clients_export_${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);

      toast.add({
        severity: 'success',
        summary: 'Export Successful',
        detail: 'Clients exported successfully',
        life: 3000
      });

      exporting.value = false;
    }
  } catch (error) {
    console.error('Error exporting clients:', error);
    toast.add({
      severity: 'error',
      summary: 'Export Failed',
      detail: error.response?.data?.message || 'Failed to export clients',
      life: 5000
    });
    exporting.value = false;
  }
};

const pollExportStatus = async (exportId) => {
  const maxAttempts = 60;
  let attempts = 0;

  const poll = async () => {
    try {
      const response = await axios.get(`/api/clients/export/${exportId}/status`);
      const status = response.data;

      if (status.status === 'completed') {
        await downloadExport(exportId);
        exporting.value = false;
        return;
      }

      if (status.status === 'failed') {
        toast.add({
          severity: 'error',
          summary: 'Export Failed',
          detail: status.error || 'Export generation failed',
          life: 5000
        });
        exporting.value = false;
        return;
      }

      attempts++;
      if (attempts >= maxAttempts) {
        toast.add({
          severity: 'warn',
          summary: 'Export Timeout',
          detail: 'Export is taking longer than expected. Please try again later.',
          life: 5000
        });
        exporting.value = false;
        return;
      }

      setTimeout(poll, 2000);
    } catch (error) {
      console.error('Error polling export status:', error);
      exporting.value = false;
    }
  };

  poll();
};

const downloadExport = async (exportId) => {
  try {
    const response = await axios.get(`/api/clients/export/${exportId}/download`, {
      responseType: 'blob'
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `clients_export_${new Date().toISOString().split('T')[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);

    toast.add({
      severity: 'success',
      summary: 'Export Complete',
      detail: 'File downloaded successfully',
      life: 3000
    });
  } catch (error) {
    console.error('Error downloading export:', error);
    toast.add({
      severity: 'error',
      summary: 'Download Failed',
      detail: 'Failed to download export file',
      life: 5000
    });
  }
};

onMounted(() => {
  fetchClients();
});
</script>
