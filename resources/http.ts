export function request(input: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  headers.set('X-Requested-With', 'XMLHttpRequest');
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  if (token) headers.set('X-CSRF-TOKEN', token);
  return fetch(input, {...init, headers, credentials: 'same-origin'}).then(response => {
    if (response.status === 401 || response.status === 419) {
      throw new Error('Your session expired. Save any unsaved text, then refresh and sign in again.');
    }
    return response;
  });
}
