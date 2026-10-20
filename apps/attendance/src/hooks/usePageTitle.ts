import { useEffect } from 'react'

export function usePageTitle(title: string) {
  useEffect(() => {
    document.title = `${title} - IBE Reality`
    return () => { document.title = 'IBE Reality - Portal Karyawan' }
  }, [title])
}
