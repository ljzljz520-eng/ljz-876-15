<template>
  <div class="space-y-6 max-w-3xl mx-auto">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">入场核验</h1>
      <router-link to="/exams" class="text-sm text-indigo-600 hover:underline">返回考试列表</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else>
      <!-- 本场考试未开启核验 -->
      <div v-if="identityCheckEnabled === false" class="bg-white rounded-lg shadow p-6 text-center space-y-4">
        <p class="text-gray-700">本场考试未开启证件与人脸核验，可直接进入考试。</p>
        <button @click="goToExam" class="bg-indigo-600 text-white py-2 px-8 rounded-lg hover:bg-indigo-700 transition-colors">
          进入考试
        </button>
      </div>

      <template v-else>
      <!-- 隐私提示 -->
      <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        <p class="font-medium mb-1">隐私说明</p>
        <p>{{ retentionNotice || '证件与人脸照片仅用于本次考试的身份核验，考试结束后按保留期自动清理删除，监考端每次查看均会记录审计日志。' }}</p>
      </div>

      <!-- 当前状态卡片 -->
      <div v-if="verification" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium" :class="statusBadgeClass">
            {{ statusLabel }}
          </span>
          <span class="text-sm text-gray-500">第 {{ verification.attempt_number }} 次提交 · 相似度 {{ verification.match_score ?? '—' }}</span>
        </div>

        <!-- 通过 -->
        <div v-if="verification.is_admitted" class="mt-4">
          <p class="text-gray-700">核验已通过，可以开始考试。</p>
          <button @click="goToExam" class="mt-4 bg-indigo-600 text-white py-2 px-6 rounded hover:bg-indigo-700 transition-colors">
            进入考试
          </button>
        </div>

        <!-- 疑似待人工确认 -->
        <div v-else-if="verification.status === 'suspected' && !verification.review_result" class="mt-4">
          <p class="text-gray-700">系统判定结果为<b>疑似</b>，已提交监考老师人工确认，请耐心等待。</p>
          <p class="mt-2 text-sm text-gray-500">本页将自动刷新核验结果，你也可以
            <button class="text-indigo-600 hover:underline" @click="fetchStatus">手动刷新</button>。
          </p>
        </div>

        <!-- 人工拒绝 -->
        <div v-else-if="verification.review_result === 'rejected'" class="mt-4">
          <p class="text-red-600">监考老师未通过本次核验。{{ verification.review_remark ? `备注：${verification.review_remark}` : '' }}</p>
          <p class="mt-1 text-sm text-gray-500">如确认为本人，可在下方重新提交核验；如仍有异议请联系监考老师。</p>
        </div>

        <!-- 失败可重新提交 -->
        <div v-else-if="verification.status === 'failed'" class="mt-4">
          <p class="text-gray-700">人脸比对未通过，请确认光线充足、正对摄像头后重新提交。</p>
        </div>
      </div>

      <!-- 提交表单（无记录 / 失败 / 人工拒绝后可重新提交，达到上限则禁用） -->
      <div v-if="canSubmit" class="bg-white rounded-lg shadow p-6 space-y-6">
        <h2 class="text-lg font-semibold text-gray-900">
          {{ verification ? '重新提交核验' : '证件与摄像头比对' }}
          <span class="ml-2 text-sm font-normal text-gray-400">剩余提交次数：{{ maxAttempts - attempts }}/{{ maxAttempts }}</span>
        </h2>

        <!-- 证件信息 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">证件姓名</label>
            <input v-model="form.id_card_name" type="text" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-indigo-500 focus:border-indigo-500" placeholder="与证件一致的姓名">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">证件号码</label>
            <input v-model="form.id_card_no" type="text" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-indigo-500 focus:border-indigo-500" placeholder="仅用于本次核验，加密存储">
          </div>
        </div>

        <!-- 证件照片 -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">证件照片</label>
          <div class="flex items-center gap-4">
            <label class="cursor-pointer inline-flex items-center px-4 py-2 rounded-lg border border-indigo-300 text-indigo-600 hover:bg-indigo-50 text-sm">
              选择证件照片
              <input type="file" accept="image/jpeg,image/png" class="hidden" @change="onIdCardPicked">
            </label>
            <div v-if="idCardPreview" class="flex items-center gap-3">
              <img :src="idCardPreview" alt="证件照预览" class="h-16 w-24 object-cover rounded border border-gray-200">
              <button type="button" class="text-xs text-red-500 hover:underline" @click="clearIdCard">移除</button>
            </div>
          </div>
        </div>

        <!-- 摄像头拍照 -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">摄像头人脸采集</label>
          <div class="space-y-3">
            <div class="relative w-full max-w-sm bg-gray-900 rounded-lg overflow-hidden" style="aspect-ratio: 4/3;">
              <video ref="videoEl" autoplay playsinline class="w-full h-full object-cover"></video>
              <div v-if="!cameraReady && !livePhoto" class="absolute inset-0 flex items-center justify-center text-gray-300 text-sm text-center px-4">
                摄像头未开启
              </div>
              <img v-if="livePhoto && !cameraActive" :src="livePreview" alt="拍摄预览" class="absolute inset-0 w-full h-full object-cover">
            </div>
            <div class="flex flex-wrap gap-3">
              <button type="button" v-if="!cameraActive" @click="startCamera" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm">开启摄像头</button>
              <button type="button" v-else @click="capture" class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-sm">拍照</button>
              <button type="button" v-if="cameraActive" @click="stopCamera" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm">关闭摄像头</button>
              <label v-if="!cameraActive" class="cursor-pointer px-4 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">
                无法使用摄像头？上传照片
                <input type="file" accept="image/jpeg,image/png" class="hidden" @change="onLivePicked">
              </label>
              <button type="button" v-if="livePhoto && !cameraActive" @click="livePhoto = null; livePreview = ''" class="px-4 py-2 text-sm text-red-500 hover:underline">重拍</button>
            </div>
            <p v-if="cameraError" class="text-sm text-red-500">{{ cameraError }}</p>
          </div>
        </div>

        <div class="flex justify-end">
          <button @click="submit" :disabled="submitting" class="bg-indigo-600 text-white py-2.5 px-8 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
            {{ submitting ? '比对中...' : '提交核验' }}
          </button>
        </div>
      </div>

      <div v-if="!canSubmit" class="bg-white rounded-lg shadow p-6 text-gray-600 text-sm">
        <template v-if="verification && verification.status === 'suspected' && !verification.review_result">
          正在等待监考老师人工确认，请稍后在本页查看结果。
        </template>
        <template v-else-if="verification?.is_admitted">
          核验已通过，可直接进入考试。
        </template>
        <template v-else>
          核验提交次数已达上限（{{ maxAttempts }} 次），请联系监考老师处理。
        </template>
      </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const { error: toastError } = useToast()

const examPaperId = route.params.id
const loading = ref(true)
const submitting = ref(false)
const verification = ref(null)
const identityCheckEnabled = ref(true)
const attempts = ref(0)
const maxAttempts = ref(5)
const retentionNotice = ref('')

const form = ref({ id_card_name: '', id_card_no: '' })
const idCardFile = ref(null)
const idCardPreview = ref('')
const livePhoto = ref(null)
const livePreview = ref('')

const videoEl = ref(null)
const cameraReady = ref(false)
const cameraActive = ref(false)
const cameraError = ref('')
let stream = null
let pollTimer = null

const statusText = {
  passed: '通过',
  suspected: '疑似',
  failed: '失败'
}

const statusLabel = computed(() => {
  if (!verification.value) return ''
  if (verification.value.is_admitted) return '核验通过'
  if (verification.value.review_result === 'rejected') return '人工复核未通过'
  if (verification.value.status === 'suspected' && !verification.value.review_result) return '疑似 · 待人工确认'
  return statusText[verification.value.status] || verification.value.status
})

const statusBadgeClass = computed(() => {
  if (verification.value?.is_admitted) return 'bg-green-100 text-green-700'
  if (verification.value?.review_result === 'rejected') return 'bg-red-100 text-red-700'
  if (verification.value?.status === 'suspected') return 'bg-yellow-100 text-yellow-700'
  return 'bg-red-100 text-red-700'
})

const canSubmit = computed(() => {
  if (attempts.value >= maxAttempts.value) return false
  if (!verification.value) return true
  if (verification.value.is_admitted) return false
  if (verification.value.status === 'suspected' && !verification.value.review_result) return false
  return true
})

const fetchStatus = async () => {
  try {
    const { data } = await api.get(`/exams/${examPaperId}/identity`)
    verification.value = data.verification
    identityCheckEnabled.value = data.exam_paper?.identity_check_enabled !== false
    attempts.value = data.attempts
    maxAttempts.value = data.max_attempts
    retentionNotice.value = data.retention_notice
  } finally {
    loading.value = false
  }
}

const onIdCardPicked = (e) => {
  const file = e.target.files[0]
  if (!file) return
  idCardFile.value = file
  idCardPreview.value = URL.createObjectURL(file)
}

const clearIdCard = () => {
  idCardFile.value = null
  if (idCardPreview.value) URL.revokeObjectURL(idCardPreview.value)
  idCardPreview.value = ''
}

const onLivePicked = (e) => {
  const file = e.target.files[0]
  if (!file) return
  livePhoto.value = file
  livePreview.value = URL.createObjectURL(file)
}

const startCamera = async () => {
  cameraError.value = ''
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    if (videoEl.value) {
      videoEl.value.srcObject = stream
      cameraReady.value = true
      cameraActive.value = true
    }
  } catch (err) {
    cameraError.value = '无法访问摄像头：' + (err.message || '权限被拒绝') + '，可改用"上传照片"。'
  }
}

const capture = () => {
  const video = videoEl.value
  if (!video || !stream) return
  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth || 640
  canvas.height = video.videoHeight || 480
  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
  canvas.toBlob((blob) => {
    if (!blob) return
    livePhoto.value = new File([blob], 'live.jpg', { type: 'image/jpeg' })
    livePreview.value = URL.createObjectURL(livePhoto.value)
    stopCamera()
  }, 'image/jpeg', 0.9)
}

const stopCamera = () => {
  if (stream) {
    stream.getTracks().forEach(t => t.stop())
    stream = null
  }
  cameraActive.value = false
  cameraReady.value = false
}

const submit = async () => {
  if (!form.value.id_card_name.trim()) {
    toastError('请填写证件姓名')
    return
  }
  if (!form.value.id_card_no.trim()) {
    toastError('请填写证件号码')
    return
  }
  if (!idCardFile.value) {
    toastError('请上传证件照片')
    return
  }
  if (!livePhoto.value) {
    toastError('请通过摄像头拍照或上传人脸照片')
    return
  }

  const fd = new FormData()
  fd.append('id_card_name', form.value.id_card_name.trim())
  fd.append('id_card_no', form.value.id_card_no.trim())
  fd.append('id_card_photo', idCardFile.value)
  fd.append('live_photo', livePhoto.value)

  submitting.value = true
  try {
    const { data } = await api.post(`/exams/${examPaperId}/identity`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    verification.value = data.verification
    attempts.value += 1
    clearIdCard()
    livePhoto.value = null
    if (livePreview.value) URL.revokeObjectURL(livePreview.value)
    livePreview.value = ''
    form.value.id_card_no = ''
  } catch (e) {
    // 422/409 等错误已由全局拦截器弹出
  } finally {
    submitting.value = false
  }
}

const goToExam = async () => {
  try {
    await api.post(`/exams/${examPaperId}/start`)
    router.push(`/exams/${examPaperId}`)
  } catch (e) {
    await fetchStatus()
    toastError(e.response?.data?.message || '暂时无法进入考试')
  }
}

onMounted(fetchStatus)

// 疑似等待人工确认时每 10 秒轮询一次
const startPolling = () => {
  pollTimer = setInterval(async () => {
    if (verification.value?.status === 'suspected' && !verification.value?.review_result) {
      await fetchStatus()
    }
  }, 10000)
}
startPolling()

onUnmounted(() => {
  stopCamera()
  if (pollTimer) clearInterval(pollTimer)
  if (idCardPreview.value) URL.revokeObjectURL(idCardPreview.value)
  if (livePreview.value) URL.revokeObjectURL(livePreview.value)
})
</script>
