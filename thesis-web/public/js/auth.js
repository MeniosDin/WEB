import { apiGet, apiPost } from './api.js';
export function setAuth(v){}
export async function me(){
try { const res = await apiGet('/api/auth/me.php'); return res.ok? res.user : null; }
catch { return null; }
}
export async function guardRole(role){
const u = await me();
if(!u){ location.replace('login.html'); return; }
if(role && u.role!==role){ location.replace('app.html'); }
return u;
}
export async function logout(){ await apiPost('/api/auth/logout.php', {}); location.replace('login.html'); }
export { apiGet, apiPost };