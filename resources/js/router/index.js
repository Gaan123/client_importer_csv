import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import Login from '../views/Login.vue';

const router = createRouter({
  history: createWebHistory('/'),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: Login,
      meta: { guest: true }
    },
    {
      path: '/',
      name: 'home',
      redirect: '/login'
    }
  ]
});

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore();

  if (to.meta.guest && authStore.isAuthenticated) {
    next('/');
  } else if (!to.meta.guest && !authStore.isAuthenticated && to.name !== 'login') {
    next('/login');
  } else {
    next();
  }
});

export default router;
