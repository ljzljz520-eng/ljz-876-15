<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center flex-wrap gap-4">
      <h1 class="text-2xl font-bold text-gray-900">核验审计日志</h1>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model="filters.action" @change="fetchLogs" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="">全部操作</option>
          <option v-for="(label, value) in actionLabels" :key="value" :value="value">{{ label }}</option>
        </select>
        <button @click="fetchLogs" class="px-3 py-2 text-sm rounded-lg border border-gray-200 hover:bg-gray-50">刷新</button>
      </div>
    </div>

    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm text-gray-600">
      记录学生提交、监考老师每次查看证件/人脸照片、人工确认与到期清理等全部操作，用于合规追溯；日志不含图片与证件号码明文。
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="logs.length === 0" class="text-center py-12 text-gray-500 bg-white rounded-lg shadow">暂无日志</div>

    <div v-else class="bg-white rounded-lg shadow overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">时间</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作人</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">核验记录</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">详情</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="log in logs" :key="log.id" class="hover:bg-gray-50 text-sm">
            <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ log.created_at }}</td>
            <td class="px-4 py-3 text-gray-800">
              {{ log.operator ? (log.operator.real_name || log.operator.username) : '系统' }}
              <span v-if="log.operator" class="ml-1 text-xs text-gray-400">{{ roleLabel(log.operator.role) }}</span>
            </td>
            <td class="px-4 py-3">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="actionBadge(log.action)">{{ actionLabels[log.action] || log.action }}</span>
            </td>
            <td class="px-4 py-3 text-gray-600">#{{ log.identity_verification_id }}</td>
            <td class="px-4 py-3 text-gray-500 max-w-xs truncate" :title="log.detail">{{ log.detail || '—' }}</td>
            <td class="px-4 py-3 text-gray-500">{{ log.ip || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const actionLabels = {
  submit: '学生提交',
  view_id_card: '查看证件照',
  view_live_photo: '查看人脸照',
  approve: '确认通过',
  reject: '确认拒绝',
  purge: '到期清理'
}

const filters = ref({ action: '' })
const logs = ref([])
const loading = ref(true)

const roleLabel = (r) => ({ admin: '管理员', teacher: '监考老师', student: '学生' }[r] || r)
const actionBadge = (a) => ({
  submit: 'bg-indigo-100 text-indigo-700',
  view_id_card: 'bg-yellow-100 text-yellow-700',
  view_live_photo: 'bg-yellow-100 text-yellow-700',
  approve: 'bg-green-100 text-green-700',
  reject: 'bg-red-100 text-red-700',
  purge: 'bg-gray-100 text-gray-600'
}[a] || 'bg-gray-100 text-gray-700')

const fetchLogs = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/identity-audit-logs', { params: filters.value })
    logs.value = data.logs.data
  } finally {
    loading.value = false
  }
}

onMounted(fetchLogs)
</script>
