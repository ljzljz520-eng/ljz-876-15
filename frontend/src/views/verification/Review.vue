<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">身份核验人工确认</h1>
      <button @click="loadData" class="text-sm text-indigo-600 hover:text-indigo-800">刷新</button>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
      核验材料（证件照、人脸抓拍）仅在<strong>待人工确认</strong>期间可查看，确认完成或超过保留期后系统自动清理，不可再浏览。
    </div>

    <!-- Tab -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-6">
        <button @click="switchTab('pending')" class="py-2 px-1 border-b-2 text-sm font-medium transition-colors"
          :class="tab === 'pending' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
          待人工确认
          <span v-if="pendingCount > 0" class="ml-1 bg-amber-100 text-amber-700 text-xs px-2 py-0.5 rounded-full">{{ pendingCount }}</span>
        </button>
        <button @click="switchTab('history')" class="py-2 px-1 border-b-2 text-sm font-medium transition-colors"
          :class="tab === 'history' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'">
          全部记录
        </button>
      </nav>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <div v-else-if="items.length === 0" class="text-center py-12 text-gray-500">
      {{ tab === 'pending' ? '暂无待人工确认的核验记录' : '暂无核验记录' }}
    </div>

    <div v-else class="space-y-4">
      <div v-for="item in items" :key="item.id" class="bg-white rounded-lg shadow p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="space-y-1">
            <div class="flex items-center space-x-3">
              <span class="font-semibold text-gray-900">{{ item.user?.real_name || item.user?.username }}</span>
              <span class="text-sm text-gray-500">{{ item.user?.email }}</span>
            </div>
            <div class="text-sm text-gray-500">
              试卷：{{ item.exam_paper?.title }} ｜ 提交时间：{{ item.created_at }}
              <template v-if="item.similarity_score !== null"> ｜ 相似度：<span class="font-medium" :class="scoreColor(item.similarity_score)">{{ Math.round(item.similarity_score * 100) }}%</span></template>
            </div>
          </div>
          <div class="flex items-center space-x-3">
            <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="statusBadge(item)">{{ statusText(item) }}</span>
            <button v-if="tab === 'pending'" @click="toggleDetail(item)" class="bg-indigo-600 text-white text-sm py-1.5 px-4 rounded-lg hover:bg-indigo-700 transition-colors">
              {{ expandedId === item.id ? '收起' : '查看材料并确认' }}
            </button>
          </div>
        </div>

        <!-- 复核结果（历史） -->
        <div v-if="tab === 'history' && item.review_status" class="mt-3 text-sm text-gray-600 bg-gray-50 rounded-lg p-3">
          人工确认：{{ item.review_status === 'approved' ? '通过' : '拒绝' }}
          <template v-if="item.reviewer">（{{ item.reviewer.username }}）</template>
          <template v-if="item.reviewed_at"> · {{ item.reviewed_at }}</template>
          <template v-if="item.review_note"> · 备注：{{ item.review_note }}</template>
        </div>
        <div v-if="tab === 'history'" class="mt-2 text-xs text-gray-400">
          核验材料：{{ item.purged ? '已按保留期清理' : '保留中（确认后或到期自动清理）' }}
        </div>

        <!-- 待确认详情：材料对比 + 操作 -->
        <div v-if="tab === 'pending' && expandedId === item.id" class="mt-4 border-t border-gray-100 pt-4">
          <div v-if="imageLoading" class="text-center py-6 text-gray-400 text-sm">材料加载中...</div>
          <div v-else-if="imageErrors[item.id]" class="text-center py-6 text-amber-600 text-sm">{{ imageErrors[item.id] }}</div>
          <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <p class="text-sm font-medium text-gray-700 mb-2">证件照片</p>
              <img v-if="images[item.id]?.id_card" :src="images[item.id].id_card" alt="证件照片" class="w-full h-56 object-contain bg-gray-50 rounded-lg border border-gray-200" />
            </div>
            <div>
              <p class="text-sm font-medium text-gray-700 mb-2">人脸抓拍</p>
              <img v-if="images[item.id]?.face" :src="images[item.id].face" alt="人脸抓拍" class="w-full h-56 object-contain bg-gray-50 rounded-lg border border-gray-200" />
            </div>
          </div>

          <div class="mt-4 flex flex-col md:flex-row md:items-center gap-3">
            <input v-model="reviewNotes[item.id]" type="text" maxlength="500" placeholder="复核备注（可选）"
              class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            <div class="flex space-x-3">
              <button @click="submitReview(item, 'approved')" :disabled="reviewing"
                class="bg-green-600 text-white text-sm py-2 px-5 rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors">
                确认通过
              </button>
              <button @click="submitReview(item, 'rejected')" :disabled="reviewing"
                class="bg-red-600 text-white text-sm py-2 px-5 rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors">
                拒绝
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 分页 -->
    <div v-if="pagination.last_page > 1" class="flex justify-center items-center space-x-3 text-sm">
      <button @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1"
        class="px-3 py-1 border rounded-lg disabled:opacity-40 hover:bg-gray-50">上一页</button>
      <span class="text-gray-500">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
      <button @click="changePage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page"
        class="px-3 py-1 border rounded-lg disabled:opacity-40 hover:bg-gray-50">下一页</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import { useModal } from '../../composables/useModal'

const { success, error: toastError } = useToast()
const { confirm } = useModal()

const tab = ref('pending')
const items = ref([])
const loading = ref(true)
const pendingCount = ref(0)
const pagination = ref({ current_page: 1, last_page: 1 })
const expandedId = ref(null)
const images = ref({})
const imageErrors = ref({})
const imageLoading = ref(false)
const reviewNotes = ref({})
const reviewing = ref(false)

onMounted(() => loadData())
onUnmounted(() => revokeAllImages())

const switchTab = (t) => {
  if (tab.value === t) return
  tab.value = t
  expandedId.value = null
  revokeAllImages()
  loadData(1)
}

const loadData = async (page = 1) => {
  loading.value = true
  try {
    const url = tab.value === 'pending' ? '/verifications/pending' : '/verifications/history'
    const res = await api.get(url, { params: { page, per_page: 10 } })
    const data = res.data.verifications
    items.value = data.data
    pagination.value = { current_page: data.current_page, last_page: data.last_page }
    if (tab.value === 'pending') {
      pendingCount.value = data.total
    } else {
      // 同步刷新待确认角标
      const pendingRes = await api.get('/verifications/pending', { params: { page: 1, per_page: 1 } })
      pendingCount.value = pendingRes.data.verifications.total
    }
  } catch (e) {
    console.error('加载核验记录失败:', e)
  } finally {
    loading.value = false
  }
}

const changePage = (page) => {
  if (page < 1 || page > pagination.value.last_page) return
  loadData(page)
}

const fetchImage = async (id, type) => {
  const res = await api.get(`/verifications/${id}/image/${type}`, { responseType: 'blob' })
  return URL.createObjectURL(res.data)
}

const toggleDetail = async (item) => {
  if (expandedId.value === item.id) {
    expandedId.value = null
    return
  }
  expandedId.value = item.id
  if (images.value[item.id] || imageErrors.value[item.id]) return

  imageLoading.value = true
  try {
    const [idCardUrl, faceUrl] = await Promise.all([
      fetchImage(item.id, 'id_card'),
      fetchImage(item.id, 'face')
    ])
    images.value[item.id] = { id_card: idCardUrl, face: faceUrl }
  } catch (e) {
    imageErrors.value[item.id] = e.response?.data?.message || '核验材料加载失败（可能已清理）'
  } finally {
    imageLoading.value = false
  }
}

const submitReview = async (item, action) => {
  const actionText = action === 'approved' ? '确认通过' : '拒绝'
  const ok = await confirm(`确定对该考生的核验结果执行"${actionText}"吗？确认后材料将不可再查看。`, '人工确认')
  if (!ok) return

  reviewing.value = true
  try {
    const res = await api.post(`/verifications/${item.id}/review`, {
      action,
      note: reviewNotes.value[item.id] || null
    })
    success(res.data.message || '操作成功')
    expandedId.value = null
    revokeImages(item.id)
    await loadData(pagination.value.current_page)
  } catch (e) {
    toastError(e.response?.data?.message || '操作失败')
  } finally {
    reviewing.value = false
  }
}

const revokeImages = (id) => {
  const entry = images.value[id]
  if (entry) {
    URL.revokeObjectURL(entry.id_card)
    URL.revokeObjectURL(entry.face)
    delete images.value[id]
  }
  delete imageErrors.value[id]
}

const revokeAllImages = () => {
  Object.keys(images.value).forEach((id) => revokeImages(Number(id)))
}

const statusText = (item) => {
  if (item.status === 'passed') return '系统通过'
  if (item.status === 'failed') return '系统未通过'
  if (item.review_status === 'approved') return '人工确认通过'
  if (item.review_status === 'rejected') return '人工确认拒绝'
  return '疑似 · 待确认'
}

const statusBadge = (item) => {
  if (item.status === 'passed') return 'bg-green-100 text-green-700'
  if (item.status === 'failed') return 'bg-red-100 text-red-700'
  if (item.review_status === 'approved') return 'bg-green-100 text-green-700'
  if (item.review_status === 'rejected') return 'bg-red-100 text-red-700'
  return 'bg-amber-100 text-amber-700'
}

const scoreColor = (score) => (score >= 0.85 ? 'text-green-600' : score >= 0.6 ? 'text-amber-600' : 'text-red-600')
</script>
