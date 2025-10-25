<template>
  <div class="min-h-screen bg-gray-100">
    <ConfirmDialog></ConfirmDialog>
    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <h1 class="text-2xl font-bold">Client Exports</h1>
          <div class="flex gap-2">
            <Button label="Back to Clients" icon="pi pi-arrow-left" @click="goToClients" severity="secondary" />
            <Button label="Logout" icon="pi pi-sign-out" @click="handleLogout" severity="secondary" />
          </div>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <Card>
        <template #content>
          <DataTable
            :value="exports"
            :loading="loading"
            tableStyle="min-width: 50rem"
          >
            <template #header>
              <div class="flex justify-between items-center">
                <span class="text-xl font-semibold">Export Files</span>
                <div class="flex gap-2">
                  <Button
                    label="New Export"
                    icon="pi pi-plus"
                    @click="createNewExport"
                    severity="success"
                    :loading="exporting"
                  />
                  <Button label="Refresh" icon="pi pi-refresh" @click="fetchExports" size="small" />
                </div>
              </div>
            </template>

            <Column field="filename" header="File Name" style="min-width: 20rem"></Column>
            <Column header="File Size" style="width: 10rem">
              <template #body="slotProps">
                {{ formatFileSize(slotProps.data.size) }}
              </template>
            </Column>
            <Column field="created_at" header="Created At" sortable style="width: 12rem">
              <template #body="slotProps">
                {{ formatDate(slotProps.data.created_at) }}
              </template>
            </Column>
            <Column header="Actions" style="width: 15rem">
              <template #body="slotProps">
                <div class="flex gap-2">
                  <Button
                    label="Download"
                    icon="pi pi-download"
                    size="small"
                    severity="success"
                    @click="downloadExport(slotProps.data.filename)"
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
              <div class="text-center py-4">No exports found.</div>
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
import { useToast } from 'primevue/usetoast';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Card from 'primevue/card';
import ConfirmDialog from 'primevue/confirmdialog';
import axios from 'axios';

const router = useRouter();
const authStore = useAuthStore();
const confirm = useConfirm();
const toast = useToast();

const exports = ref([]);
const loading = ref(false);
const exporting = ref(false);

const fetchExports = async () => {
  loading.value = true;
  try {
    const response = await axios.get('/api/clients/exports');
    exports.value = response.data.data;
  } catch (error) {
    console.error('Error fetching exports:', error);
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to load exports',
      life: 3000
    });
  } finally {
    loading.value = false;
  }
};

const downloadExport = async (filename) => {
  try {
    const response = await axios.get(`/api/clients/exports/${filename}/download`, {
      responseType: 'blob'
    });

    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);

    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Export downloaded successfully',
      life: 3000
    });
  } catch (error) {
    console.error('Error downloading export:', error);
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to download export',
      life: 3000
    });
  }
};

const confirmDelete = (exportFile) => {
  confirm.require({
    message: `Are you sure you want to delete ${exportFile.filename}?`,
    header: 'Delete Confirmation',
    icon: 'pi pi-exclamation-triangle',
    rejectLabel: 'Cancel',
    acceptLabel: 'Delete',
    accept: () => {
      deleteExport(exportFile.filename);
    }
  });
};

const deleteExport = async (filename) => {
  try {
    await axios.delete(`/api/clients/exports/${filename}`);
    exports.value = exports.value.filter(e => e.filename !== filename);

    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: 'Export deleted successfully',
      life: 3000
    });
  } catch (error) {
    console.error('Error deleting export:', error);
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Failed to delete export',
      life: 3000
    });
  }
};

const formatFileSize = (bytes) => {
  if (!bytes || bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleString();
};

const createNewExport = async () => {
  if (exporting.value) return;

  exporting.value = true;
  try {
    const response = await axios.get('/api/clients/export');

    if (response.data.async) {
      const exportId = response.data.export_id;

      toast.add({
        severity: 'info',
        summary: 'Export Queued',
        detail: 'Large export queued. Check back in a few minutes.',
        life: 5000
      });

      // Auto-refresh after 30 seconds
      setTimeout(() => {
        fetchExports();
      }, 30000);
    } else {
      toast.add({
        severity: 'success',
        summary: 'Export Created',
        detail: 'Export completed successfully',
        life: 3000
      });

      fetchExports();
    }
  } catch (error) {
    console.error('Error creating export:', error);
    toast.add({
      severity: 'error',
      summary: 'Export Failed',
      detail: error.response?.data?.message || 'Failed to create export',
      life: 5000
    });
  } finally {
    exporting.value = false;
  }
};

const goToClients = () => {
  router.push('/clients');
};

const handleLogout = async () => {
  await authStore.logout();
  router.push('/login');
};

onMounted(() => {
  fetchExports();
});
</script>
