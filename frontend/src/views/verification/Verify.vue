<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">考前身份核验</h1>
      <router-link to="/exams" class="text-sm text-indigo-600 hover:text-indigo-800">返回考试列表</router-link>
    </div>

    <!-- 隐私说明 -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800 flex items-start">
      <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
      </svg>
      <div>
        <p class="font-medium mb-1">核验材料隐私说明</p>
        <p>您上传的证件照片与人脸抓拍<strong>仅用于本次考试的身份核验</strong>，考试结束后将在保留期内自动删除，不会用于其他用途，后台不提供长期浏览。</p>
      </div>
    </div>

    <div v-if="pageLoading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <!-- 已通过 -->
    <div v-else-if="verification && verification.effective_passed" class="bg-white rounded-lg shadow p-8 text-center space-y-4">
      <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto">
        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <h2 class="text-xl font-semibold text-gray-900">身份核验已通过</h2>
      <p class="text-gray-500 text-sm">
        {{ verification.status === 'suspicious' ? '监考老师已人工确认通过' : '系统核验通过' }}
        <template v-if="verification.similarity_score !== null">（相似度 {{ scorePercent }}%）</template>
      </p>
      <button @click="enterExam" :disabled="entering" class="bg-indigo-600 text-white py-2 px-8 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
        {{ entering ? '正在进入...' : '进入考试' }}
      </button>
    </div>

    <!-- 等待人工确认 -->
    <div v-else-if="verification && verification.status === 'suspicious' && !verification.review_status" class="bg-white rounded-lg shadow p-8 text-center space-y-4">
      <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto">
        <svg class="w-8 h-8 text-amber-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h2 class="text-xl font-semibold text-gray-900">等待监考老师人工确认</h2>
      <p class="text-gray-500 text-sm">您的核验结果为"疑似"，已提交监考老师人工确认，请稍候（页面将自动刷新结果）...</p>
    </div>

    <!-- 核验表单 -->
    <div v-else class="space-y-6">
      <!-- 上次结果提示 -->
      <div v-if="verification" class="rounded-lg p-4 text-sm border"
        :class="verification.status === 'failed' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800'">
        <p class="font-medium">
          {{ verification.status === 'failed' ? '上次核验未通过' : '上次人工确认未通过' }}
          <template v-if="verification.similarity_score !== null && verification.status === 'failed'">（相似度 {{ scorePercent }}%）</template>
        </p>
        <p v-if="verification.review_note" class="mt-1">备注：{{ verification.review_note }}</p>
        <p class="mt-1">请调整光线、确保证件照片清晰且为本人后重新核验。</p>
      </div>

      <!-- 步骤 1：证件照 -->
      <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-1 flex items-center">
          <span class="w-6 h-6 bg-indigo-600 text-white rounded-full text-sm flex items-center justify-center mr-2">1</span>
          上传证件照片
        </h2>
        <p class="text-sm text-gray-500 mb-4">请上传学生证 / 身份证等有效证件的清晰照片</p>
        <div class="flex items-start space-x-4">
          <label class="flex-1 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/40 transition-colors">
            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onIdCardSelected" />
            <svg class="w-8 h-8 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            <span class="text-sm text-gray-600">{{ idCardFile ? '重新选择' : '点击选择证件照片' }}</span>
          </label>
          <div v-if="idCardPreview" class="w-40 flex-shrink-0">
            <img :src="idCardPreview" alt="证件照预览" class="w-full h-28 object-cover rounded-lg border border-gray-200" />
          </div>
        </div>
      </div>

      <!-- 步骤 2：人脸抓拍 -->
      <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-1 flex items-center">
          <span class="w-6 h-6 bg-indigo-600 text-white rounded-full text-sm flex items-center justify-center mr-2">2</span>
          摄像头人脸抓拍
        </h2>
        <p class="text-sm text-gray-500 mb-4">请正对摄像头，确保光线充足、面部无遮挡</p>

        <div v-if="cameraError" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3 mb-4">
          {{ cameraError }}
          <div class="mt-2">
            <label class="text-indigo-600 hover:text-indigo-800 cursor-pointer text-sm underline">
              摄像头不可用？改为上传本人正面照片
              <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFaceFileSelected" />
            </label>
          </div>
        </div>

        <div class="flex items-start space-x-4">
          <div class="flex-1">
            <div v-show="cameraActive && !facePreview" class="relative bg-black rounded-lg overflow-hidden">
              <video ref="videoRef" autoplay playsinline muted class="w-full h-56 object-cover"></video>
            </div>
            <div v-if="facePreview" class="relative">
              <img :src="facePreview" alt="人脸抓拍预览" class="w-full h-56 object-cover rounded-lg border border-gray-200" />
              <button @click="retakeFace" class="absolute top-2 right-2 bg-white/90 text-gray-700 text-sm px-3 py-1 rounded-lg shadow hover:bg-white">重拍</button>
            </div>
            <div class="mt-3 flex space-x-3">
              <button v-if="!cameraActive && !facePreview" @click="startCamera" class="bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 text-sm transition-colors">
                开启摄像头
              </button>
              <button v-if="cameraActive && !facePreview" @click="captureFace" class="bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 text-sm transition-colors">
                拍照
              </button>
            </div>
          </div>
        </div>
        <canvas ref="canvasRef" class="hidden"></canvas>
      </div>

      <!-- 提交 -->
      <div class="bg-white rounded-lg shadow p-6">
        <button @click="submitVerification" :disabled="!canSubmit || submitting" class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors font-medium">
          {{ submitting ? '核验提交中...' : '提交核验' }}
        </button>
        <p v-if="!canSubmit && !submitting" class="text-center text-sm text-gray-400 mt-2">请先完成证件照上传与人脸抓拍</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../api'
import { useModal } from '../../composables/useModal'

const route = useRoute()
const router = useRouter()
const { alert } = useModal()

const paperId = route.params.id
const verification = ref(null)
const pageLoading = ref(true)
const submitting = ref(false)
const entering = ref(false)

const idCardFile = ref(null)
const idCardPreview = ref(null)
const faceBlob = ref(null)
const facePreview = ref(null)

const videoRef = ref(null)
const canvasRef = ref(null)
const cameraActive = ref(false)
const cameraError = ref('')
let mediaStream = null
let pollTimer = null

const scorePercent = computed(() => {
  const s = verification.value?.similarity_score
  return s === null || s === undefined ? '' : Math.round(s * 100)
})

const canSubmit = computed(() => idCardFile.value && faceBlob.value)

onMounted(async () => {
  await refreshStatus()
  pageLoading.value = false
  startPollingIfNeeded()
})

onUnmounted(() => {
  stopCamera()
  stopPolling()
})

const refreshStatus = async () => {
  try {
    const res = await api.get(`/exams/${paperId}/verification`)
    verification.value = res.data.verification
    return res.data
  } catch (e) {
    console.error('获取核验状态失败:', e)
    return null
  }
}

const startPollingIfNeeded = () => {
  stopPolling()
  const v = verification.value
  if (v && v.status === 'suspicious' && !v.review_status) {
    pollTimer = setInterval(async () => {
      const data = await refreshStatus()
      const latest = data?.verification
      if (latest && latest.review_status) {
        stopPolling()
        if (latest.review_status !== 'approved') {
          alert('人工确认未通过，请重新进行身份核验', '核验结果', 'warning')
        }
      }
    }, 5000)
  }
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

// 压缩图片：最长边 1024，JPEG 0.85，减小上传体积
const compressImage = (file, maxSide = 1024) => {
  return new Promise((resolve, reject) => {
    const img = new Image()
    const url = URL.createObjectURL(file)
    img.onload = () => {
      URL.revokeObjectURL(url)
      let { width, height } = img
      const scale = Math.min(1, maxSide / Math.max(width, height))
      width = Math.round(width * scale)
      height = Math.round(height * scale)
      const canvas = document.createElement('canvas')
      canvas.width = width
      canvas.height = height
      canvas.getContext('2d').drawImage(img, 0, 0, width, height)
      canvas.toBlob(
        (blob) => (blob ? resolve(blob) : reject(new Error('图片处理失败'))),
        'image/jpeg',
        0.85
      )
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('图片读取失败'))
    }
    img.src = url
  })
}

const onIdCardSelected = async (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  try {
    const blob = await compressImage(file)
    idCardFile.value = new File([blob], 'id_card.jpg', { type: 'image/jpeg' })
    if (idCardPreview.value) URL.revokeObjectURL(idCardPreview.value)
    idCardPreview.value = URL.createObjectURL(blob)
  } catch (e) {
    alert('证件照片读取失败，请更换图片', '上传失败', 'error')
  }
}

const onFaceFileSelected = async (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  try {
    const blob = await compressImage(file)
    setFaceBlob(blob)
  } catch (e) {
    alert('照片读取失败，请更换图片', '上传失败', 'error')
  }
}

const startCamera = async () => {
  cameraError.value = ''
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' },
      audio: false
    })
    cameraActive.value = true
    await new Promise((r) => setTimeout(r, 50))
    if (videoRef.value) {
      videoRef.value.srcObject = mediaStream
    }
  } catch (e) {
    cameraActive.value = false
    cameraError.value = '无法访问摄像头，请检查浏览器权限设置。'
  }
}

const captureFace = () => {
  const video = videoRef.value
  const canvas = canvasRef.value
  if (!video || !canvas || !video.videoWidth) return
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  canvas.getContext('2d').drawImage(video, 0, 0)
  canvas.toBlob((blob) => {
    if (blob) {
      setFaceBlob(blob)
      stopCamera()
    }
  }, 'image/jpeg', 0.85)
}

const setFaceBlob = (blob) => {
  faceBlob.value = blob
  if (facePreview.value) URL.revokeObjectURL(facePreview.value)
  facePreview.value = URL.createObjectURL(blob)
}

const retakeFace = () => {
  faceBlob.value = null
  if (facePreview.value) {
    URL.revokeObjectURL(facePreview.value)
    facePreview.value = null
  }
  startCamera()
}

const stopCamera = () => {
  if (mediaStream) {
    mediaStream.getTracks().forEach((t) => t.stop())
    mediaStream = null
  }
  cameraActive.value = false
}

const submitVerification = async () => {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  try {
    const formData = new FormData()
    formData.append('id_card_image', idCardFile.value, 'id_card.jpg')
    formData.append('face_image', faceBlob.value, 'face.jpg')

    const res = await api.post(`/exams/${paperId}/verification`, formData)
    verification.value = res.data.verification

    if (res.data.verification.status === 'failed') {
      alert('身份核验未通过，请调整光线和姿态后重新核验', '核验结果', 'warning')
    }
    startPollingIfNeeded()
  } catch (e) {
    if (e.response?.status !== 409 && e.response?.status !== 422) {
      alert(e.response?.data?.message || '核验提交失败，请稍后重试', '提交失败', 'error')
    }
    await refreshStatus()
    startPollingIfNeeded()
  } finally {
    submitting.value = false
  }
}

const enterExam = async () => {
  if (entering.value) return
  entering.value = true
  try {
    await api.post(`/exams/${paperId}/start`)
    router.push(`/exams/${paperId}`)
  } catch (e) {
    alert(e.response?.data?.message || '进入考试失败', '进入考试', 'error')
  } finally {
    entering.value = false
  }
}
</script>
