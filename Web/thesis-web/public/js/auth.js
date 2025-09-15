/* Υπολογίζει δυναμικά τη βάση του API ώστε να δουλεύει
   είτε σε /project/public είτε στο document root. */
const API_BASE = (() => {
  const m = location.pathname.match(/^(.*)\/public\//);
  return (m ? m[1] : '') + '/api'; // π.χ. '/thesis_web/api' ή '/api'
})();

/* Κανονικοποιεί όποιο path δώσεις:
   - '/api/xxx'  -> API_BASE + '/xxx'
   - '/xxx'      -> API_BASE + '/xxx'
   - 'xxx'       -> API_BASE + '/xxx'
   - 'http...'   -> όπως είναι */
export const toApi = (path) =>
  path.startsWith('http')
    ? path
    : API_BASE + (path.startsWith('/api')
        ? path.slice(4)
        : (path.startsWith('/') ? path : '/' + path));

async function parseRes(res) {
  const ct = res.headers.get('content-type') || '';
  const isJson = ct.includes('application/json');
  const data = isJson ? await res.json().catch(() => ({})) : await res.text();
  if (!res.ok) {
    const msg = isJson ? (data?.error || 'Request failed') : (data || 'Request failed');
    throw new Error(msg);
  }
  return data;
}

async function apiFetch(path, opts = {}) {
  const init = { credentials: 'include', ...opts };
  init.headers = init.headers || {};

  // Αν δεν είναι FormData, βάλ’ το default JSON header
  const isFormData = (init.body instanceof FormData);
  if (!isFormData && init.method && init.method.toUpperCase() === 'POST') {
    init.headers['Content-Type'] = init.headers['Content-Type'] || 'application/json; charset=utf-8';
  }
  return parseRes(await fetch(toApi(path), init));
}

export async function apiGet(path) {
  try {
    return await apiFetch(path, { method: 'GET' });
  } catch (e) {
    return { ok: false, error: e.message };
  }
}

export async function apiPost(path, body) {
  try {
    const init = (body instanceof FormData)
      ? { method: 'POST', body }
      : { method: 'POST', body: JSON.stringify(body || {}) };
    return await apiFetch(path, init);
  } catch (e) {
    return { ok: false, error: e.message };
  }
}

/* Επιστρέφει τον τρέχοντα χρήστη ή null */
export async function me() {
  const r = await apiGet('/api/auth/me.php');  // θα γίνει API_BASE + '/auth/me.php'
  return r?.ok ? r.user : null;
}

/* Guard που απαιτεί συγκεκριμένο ρόλο, αλλιώς redirect σε login */
export async function guardRole(role) {
  const u = await me();
  if (!u || u.role !== role) {
    location.href = 'login.html';
    return null;
  }
  return u;
}

/* Logout + redirect */
export async function logout() {
  await apiPost('/api/auth/logout.php', {});   // θα γίνει API_BASE + '/auth/logout.php'
  location.replace('login.html');
}

/* Προαιρετικό placeholder */
export function setAuth(_) {}
