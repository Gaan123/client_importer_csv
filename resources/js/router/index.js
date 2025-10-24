import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import Login from '../views/Login.vue';
import Clients from '../views/Clients.vue';
import ClientDuplicates from '../views/ClientDuplicates.vue';

const routes = [
  {
    path: '/login',
    name: 'login',
    component: Login,
    meta: { guest: true }
  },
  {
    path: '/',
    name: 'home',
    redirect: '/clients'
  },
  {
    path: '/clients',
    name: 'clients',
    component: Clients,
    meta: { requiresAuth: true }
  },
  {
    path: '/clients/:id/duplicates',
    name: 'client-duplicates',
    component: ClientDuplicates,
    meta: { requiresAuth: true }
  }
];

const router = createRouter({
  history: createWebHistory('/'),
  routes
});

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore();

  if (to.meta.guest && authStore.isAuthenticated) {
    next('/clients');
  } else if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login');
  } else {
    next();
  }
});

export default router;
