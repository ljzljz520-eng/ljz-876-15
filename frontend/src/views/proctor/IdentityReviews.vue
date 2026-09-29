<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center flex-wrap gap-4">
      <h1 class="text-2xl font-bold text-gray-900">身份核验 · 人工确认</h1>
      <div class="flex items-center gap-2">
        <div class="flex rounded-lg border border-gray-200 overflow-hidden">
          <button
            v-for="tab in tabs"
            :key="tab.value"
            @click="switchTab(tab.value)"
            class="px-4 py-2 text-sm"
            :class="activeTab === tab.value ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
          >
            {{ tab.label }}
            <span v-if="tab.value === 'pending'" class="ml-1 text-xs">({{ list.length }})</span>
          </button>
        </div>
        <button @click="fetchList" class="px-3 py-2 text-sm rounded-lg border border-gray-200 hover:bg-gray-50">刷新</button>
      </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
      核验材料仅用于本次考试；每次打开证件照或人脸照片都会被记录到审计日志，材料到期后将自动删除。
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="list.length === 0" class="text-center py-12 text-gray-500 bg-white rounded-lg shadow">
      {{ activeTab === 'pending' ? '暂无待人工确认的疑似记录' : '暂无记录' }}
    </div>

    <div v-else class="bg-white rounded-lg shadow overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">学生</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">考试</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">机器判定</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">相似度</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">复核状态</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">提交时间</th>
            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
          <tr v-for="item in list" :key="item.id" class="hover:bg-gray-50">
            <td class="px-4 py-3 text-sm text-gray-800">{{ item.student_name }}<div class="text-xs text-gray-400">{{ item.id_card_name || '—' }}</div></td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ item.exam_title }}</td>
            <td class="px-4 py-3 text-sm">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="machineBadge(item.status)">{{ machineLabel(item.status) }}</span>
            </td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ item.match_score ?? '—' }}</td>
            <td class="px-4 py-3 text-sm">
              <span v-if="item.review_result === 'approved'" class="text-green-600">已确认通过</span>
              <span v-else-if="item.review_result === 'rejected'" class="text-red-600">已确认拒绝</span>
              <span v-else-if="item.status === 'suspected'" class="text-yellow-600">待确认</span>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td class="px-4 py-3 text-sm text-gray-500">{{ item.created_at }}</td>
            <td class="px-4 py-3 text-right">
              <button @click="openDetail(item)" class="text-indigo-600 hover:underline text-sm">
                {{ item.status === 'suspected' && !item.review_result ? '处理' : '详情' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 详情/处理 弹层 -->
    <div v-if="detail" class="fixed inset-0 z-[95] flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-600/75" @click="detail = null"></div>
      <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white">
          <h2 class="text-lg font-bold text-gray-900">核验详情 #{{ detail.id }}</h2>
          <button @click="detail = null" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-6 space-y-5">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div><span class="text-gray-400">学生：</span>{{ detail.user?.real_name || detail.user?.username }}</div>
            <div><span class="text-gray-400">账号：</span>{{ detail.user?.username }}</div>
            <div><span class="text-gray-400">考试：</span>{{ detail.exam_paper?.title }}</div>
            <div><span class="text-gray-400">证件姓名：</span>{{ detail.id_card_name || '—' }}</div>
            <div><span class="text-gray-400">证件号：</span>{{ detail.id_card_no_masked || '—' }}</div>
            <div><span class="text-gray-400">机器相似度：</span>{{ detail.match_score ?? '—' }}</div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm font-medium text-gray-700 mb-2">证件照 <span class="text-xs text-gray-400 font-normal">（本次查看将记录审计日志）</span></p>
              <ProtectedImage :url="detail.media?.id_card_url" :available="detail.media?.available" />
            </div>
            <div>
              <p class="text-sm font-medium text-gray-700 mb-2">摄像头人脸照</p>
              <ProtectedImage :url="detail.media?.live_photo_url" :available="detail.media?.available" />
            </div>
          </div>

          <div v-if="detail.review_result" class="text-sm rounded-lg p-3" :class="detail.review_result === 'approved' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
            {{ detail.review_result === 'approved' ? '已由监考老师确认通过' : '已由监考老师确认拒绝' }}
            <span v-if="detail.reviewer">（{{ detail.reviewer.real_name || detail.reviewer.username }}，{{ detail.reviewed_at }}）</span>
            <div v-if="detail.review_remark" class="mt-1 text-gray-600">备注：{{ detail.review_remark }}</div>
          </div>

          <div v-if="detail.status === 'suspected' && !detail.review_result" class="space-y-3 border-t border-gray-100 pt-4">
            <label class="block text-sm font-medium text-gray-700">复核备注（可选）</label>
            <textarea v-model="remark" rows="2" maxlength="500" class="w-full border border-gray-300 rounded-md p-2.5" placeholder="如：与证件为同一人，光线较暗"></textarea>
            <div class="flex justify-end gap-3">
              <button @click="submitReview('rejected')" :disabled="submitting" class="px-5 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50">确认拒绝</button>
              <button @click="submitReview('approved')" :disabled="submitting" class="px-5 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">确认通过</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, h, onMounted } from 'vue'
import api from '../../api'

// 带 Authorization 头的受保护图片：点击时才真正发起请求（浏览即留痕）
const ProtectedImage = {
  props: ['url', 'available'],
  setup(props) {
    const src = ref('')
    const loadingImg = ref(false)
    const failed = ref(false)

    const load = async () => {
      if (!props.available) return
      loadingImg.value = true
      failed.value = false
      try {
        const token = localStorage.getItem('token')
        const res = await fetch(props.url, { headers: { Authorization: `Bearer ${token}` } })
        if (!res.ok) throw new Error(String(res.status))
        const blob = await res.blob()
        if (src.value) URL.revokeObjectURL(src.value)
        src.value = URL.createObjectURL(blob)
      } catch (e) {
        failed.value = true
      } finally {
        loadingImg.value = false
      }
    }

    return () => {
      if (props.available === false) {
        return h('div', { class: 'h-44 flex items-center justify-center bg-gray-50 rounded-lg text-xs text-gray-400' }, '材料已过保留期并清理')
      }
      if (src.value) {
        return h('img', { src: src.value, class: 'w-full h-44 object-contain border border-gray-200 rounded-lg bg-gray-50' })
      }
      return h('button', {
        type: 'button',
        onClick: load,
        class: 'w-full h-44 rounded-lg border border-dashed border-gray-300 text-sm text-gray-500 hover:bg-gray-50 hover:text-indigo-600'
      }, loadingImg.value ? '加载中...' : failed.value ? '加载失败，点击重试' : '点击查看（将记录审计日志）')
    }
  }
}

const tabs = [
  { value: 'pending', label: '待确认' },
  { value: 'suspected', label: '全部疑似' },
  { value: 'passed', label: '通过' },
  { value: 'failed', label: '失败' }
]

const activeTab = ref('pending')
const list = ref([])
const loading = ref(true)
const detail = ref(null)
const remark = ref('')
const submitting = ref(false)

const machineLabel = (s) => ({ passed: '通过', suspected: '疑似', failed: '失败' }[s] || s)
const machineBadge = (s) => ({
  passed: 'bg-green-100 text-green-700',
  suspected: 'bg-yellow-100 text-yellow-700',
  failed: 'bg-red-100 text-red-700'
}[s] || 'bg-gray-100 text-gray-700')

const fetchList = async () => {
  loading.value = true
  try {
    const status = activeTab.value === 'pending' ? 'pending' : activeTab.value
    const { data } = await api.get('/proctor/identity-verifications', { params: { status } })
    list.value = data.verifications.data
  } finally {
    loading.value = false
  }
}

const switchTab = (v) => {
  activeTab.value = v
  fetchList()
}

const openDetail = async (item) => {
  const { data } = await api.get(`/proctor/identity-verifications/${item.id}`)
  detail.value = data.verification
  remark.value = ''
}

const submitReview = async (result) => {
  submitting.value = true
  try {
    await api.post(`/proctor/identity-verifications/${detail.value.id}/review`, { result, remark: remark.value })
    detail.value = null
    await fetchList()
  } finally {
    submitting.value = false
  }
}

onMounted(fetchList)
</script>
