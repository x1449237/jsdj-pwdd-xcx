import { createRouter, createWebHashHistory } from 'vue-router'
import { getToken } from '@/utils/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/login/index.vue'),
    meta: { title: '登录', noAuth: true }
  },
  {
    path: '/init',
    name: 'Init',
    component: () => import('@/views/init/index.vue'),
    meta: { title: '初始化', noAuth: true }
  },
  {
    path: '/',
    component: () => import('@/layout/Layout.vue'),
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/index.vue'),
        meta: { title: '仪表盘', icon: 'Odometer' }
      },
      {
        path: 'user/list',
        name: 'UserList',
        component: () => import('@/views/user/list.vue'),
        meta: { title: '用户管理', icon: 'User' }
      },
      {
        path: 'user/detail/:id',
        name: 'UserDetail',
        component: () => import('@/views/user/detail.vue'),
        meta: { title: '用户详情', hidden: true }
      },
      {
        path: 'admin/list',
        name: 'AdminList',
        component: () => import('@/views/admin/list.vue'),
        meta: { title: '管理员管理', icon: 'UserFilled' }
      },
      {
        path: 'audit/player',
        name: 'AuditPlayer',
        component: () => import('@/views/audit/player.vue'),
        meta: { title: '打手审核', icon: 'Checked' }
      },
      {
        path: 'audit/distributor',
        name: 'AuditDistributor',
        component: () => import('@/views/audit/distributor.vue'),
        meta: { title: '分销商审核', icon: 'Checked' }
      },
      {
        path: 'audit/dispatcher',
        name: 'AuditDispatcher',
        component: () => import('@/views/audit/dispatcher.vue'),
        meta: { title: '派单员审核', icon: 'Checked' }
      },
      {
        path: 'audit/admin',
        name: 'AuditAdmin',
        component: () => import('@/views/audit/admin.vue'),
        meta: { title: '内置管理员审核', icon: 'Checked' }
      },
      {
        path: 'invite',
        name: 'Invite',
        component: () => import('@/views/invite/index.vue'),
        meta: { title: '邀请码管理', icon: 'Link' }
      },
      {
        path: 'order/list',
        name: 'OrderList',
        component: () => import('@/views/order/list.vue'),
        meta: { title: '订单管理', icon: 'Document' }
      },
      {
        path: 'order/detail/:id',
        name: 'OrderDetail',
        component: () => import('@/views/order/detail.vue'),
        meta: { title: '订单详情', hidden: true }
      },
      {
        path: 'finance/withdraw',
        name: 'FinanceWithdraw',
        component: () => import('@/views/finance/withdraw.vue'),
        meta: { title: '提现管理', icon: 'Money' }
      },
      {
        path: 'finance/config',
        name: 'FinanceConfig',
        component: () => import('@/views/finance/config.vue'),
        meta: { title: '财务配置', icon: 'Setting' }
      },
      {
        path: 'timeout-rule',
        name: 'TimeoutRule',
        component: () => import('@/views/timeout-rule/index.vue'),
        meta: { title: '超时规则', icon: 'Timer' }
      },
      {
        path: 'chat-audit',
        name: 'ChatAudit',
        component: () => import('@/views/chat-audit/index.vue'),
        meta: { title: '聊天审计', icon: 'ChatDotSquare', group: 'chat' }
      },
      {
        path: 'system/config',
        name: 'SystemConfig',
        component: () => import('@/views/system/config.vue'),
        meta: { title: '系统配置', icon: 'Tools' }
      },
      {
        path: 'appeal/list',
        name: 'AppealList',
        component: () => import('@/views/appeal/list.vue'),
        meta: { title: '申诉管理', icon: 'Warning' }
      },
      {
        path: 'backup',
        name: 'Backup',
        component: () => import('@/views/backup/index.vue'),
        meta: { title: '备份恢复', icon: 'FolderOpened' }
      },
      {
        path: 'gray-release',
        name: 'GrayRelease',
        component: () => import('@/views/gray/index.vue'),
        meta: { title: '灰度发布', icon: 'Connection' }
      },
      {
        path: 'platform/accounts',
        name: 'PlatformAccounts',
        component: () => import('@/views/platform/accounts.vue'),
        meta: { title: '平台官方账号', icon: 'UserFilled', group: 'platform' }
      },
      {
        path: 'platform/subscribe',
        name: 'PlatformSubscribe',
        component: () => import('@/views/platform/subscribe.vue'),
        meta: { title: '订阅消息模板', icon: 'Message', group: 'platform' }
      },
      {
        path: 'after-sale/keywords',
        name: 'AfterSaleKeywords',
        component: () => import('@/views/after-sale/keywords.vue'),
        meta: { title: '售后关键词', icon: 'Warning', group: 'platform' }
      },
      {
        path: 'group-monitor',
        name: 'GroupMonitor',
        component: () => import('@/views/group-monitor/index.vue'),
        meta: { title: '群聊监察', icon: 'ChatDotRound', group: 'chat' }
      },
      {
        path: 'after-sale/manage',
        name: 'AfterSaleManage',
        component: () => import('@/views/after-sale/manage.vue'),
        meta: { title: '售后介入', icon: 'Service', group: 'chat' }
      },
      {
        path: 'punishment',
        name: 'PunishmentRecords',
        component: () => import('@/views/punishment/index.vue'),
        meta: { title: '处罚记录', icon: 'Lock', group: 'security' }
      }
    ]
  },
  {
    path: '/404',
    name: 'NotFound',
    component: () => import('@/views/error/404.vue'),
    meta: { title: '404', noAuth: true }
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/404'
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  document.title = to.meta.title ? `${to.meta.title} - 超级管理后台` : '超级管理后台'

  const token = getToken()
  if (token) {
    if (to.path === '/login') {
      next({ path: '/dashboard' })
    } else {
      next()
    }
  } else {
    if (to.meta.noAuth) {
      next()
    } else {
      next({ path: '/login', query: { redirect: to.fullPath } })
    }
  }
})

export default router