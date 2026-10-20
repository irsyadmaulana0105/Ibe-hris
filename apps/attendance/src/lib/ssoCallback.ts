let captured: URLSearchParams | null = null
export function consumeSsoCallbackParams(): URLSearchParams {
  if (captured) return captured
  const query = new URLSearchParams(window.location.search)
  const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''))
  const params = fragment.has('sso_token') ? fragment : query

  if (params.has('sso_token')) {
    window.history.replaceState(null, document.title, window.location.pathname)
  }

  return (captured = params)
}
