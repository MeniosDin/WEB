/* Υπολογίζει δυναμικά τη βάση του API ώστε να δουλεύει
   είτε σε /project/public είτε στο document root. */
const API_BASE = (() => {
  const m = location.pathname.match(/^(.*)\/public\//);
  return (m ? m[1] : '') + '/api';
})();
const toApi = (path) => path.startsWith('http') ? path
                  : API_BASE + (path.startsWith('/api') ? path.slice(4) : (path.startsWith('/') ? path : '/'+path));

export async function apiPost(url, body){
  const r = await fetch(toApi(url), {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'include',              // ΠΑΝΤΑ στείλε cookies
    body: JSON.stringify(body || {})
  });
  return r.json();
}
export async function apiGet(url){
  const r = await fetch(toApi(url), { credentials:'include' });
  return r.json();
}
export async function me(){
  try {
    const res = await apiGet('/api/auth/me.php');
    return res.ok ? res.user : null;
  } catch { return null; }
}
export function setAuth(_){ /* placeholder */ }
export async function logout(){ await apiPost('/api/auth/logout.php', {}); location.replace('login.html'); }
