export async function generateFingerprint() {
  const components = []

  try {
    // 浏览器信息
    components.push(`ua:${navigator.userAgent}`)
    components.push(`platform:${navigator.platform}`)
    components.push(`language:${navigator.language}`)

    // 屏幕信息
    components.push(`screen:${screen.width}x${screen.height}x${screen.colorDepth}`)

    // 时区
    components.push(`timezone:${Intl.DateTimeFormat().resolvedOptions().timeZone}`)

    // 硬件并发数
    if (navigator.hardwareConcurrency) {
      components.push(`cores:${navigator.hardwareConcurrency}`)
    }

    // 设备内存（如果可用）
    if (navigator.deviceMemory) {
      components.push(`memory:${navigator.deviceMemory}`)
    }

    // Canvas指纹
    try {
      const canvas = document.createElement('canvas')
      const ctx = canvas.getContext('2d')
      ctx.textBaseline = 'top'
      ctx.font = '14px Arial'
      ctx.fillText('Fingerprint', 2, 2)
      const canvasDataUrl = canvas.toDataURL()
      components.push(`canvas:${canvasDataUrl.substring(0, 50)}`)
    } catch (e) {
      components.push('canvas:unavailable')
    }

  } catch (error) {
    console.error('生成设备指纹时出错:', error)
  }

  // 生成哈希值
  const fingerprintString = components.join('|')
  
  // 简单的字符串哈希函数
  let hash = 0
  for (let i = 0; i < fingerprintString.length; i++) {
    const char = fingerprintString.charCodeAt(i)
    hash = ((hash << 5) - hash) + char
    hash = hash & hash // Convert to 32bit integer
  }
  
  return Math.abs(hash).toString(16).padStart(16, '0') + '_' + Date.now().toString(36)
}
