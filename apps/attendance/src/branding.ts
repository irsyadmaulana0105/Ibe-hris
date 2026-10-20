export async function loadDefaultBranding() {
  const base = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''
  try {
    const response = await fetch(`${base}/api/v1/branding/default`, { headers: { Accept: 'application/json' } })
    if (!response.ok) return
    const payload = await response.json()
    const brand = payload.data ?? payload
    document.documentElement.style.setProperty('--brand-primary', brand.brand_color_primary)
    document.documentElement.style.setProperty('--brand-accent', brand.brand_color_accent)
    const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]') ?? document.head.appendChild(Object.assign(document.createElement('link'), { rel: 'icon' }))
    icon.href = brand.favicon_url
    document.title = document.title.replace(/IBE Reality/g, brand.name)
  } catch { /* bundled brand remains active */ }
}
