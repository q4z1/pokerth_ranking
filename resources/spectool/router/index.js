import { createRouter, createMemoryHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'lobby',
    component: () => import('../components/lobby/LobbyView.vue'),
  },
  {
    path: '/game',
    name: 'game',
    component: () => import('../components/gametable/GameTableView.vue'),
  },
]

const router = createRouter({
  history: createMemoryHistory(),
  routes,
})

export default router
