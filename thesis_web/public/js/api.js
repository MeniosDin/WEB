export async function api(path, data, method = 'POST'){
const res = await fetch(`/api${path}`, {
method,
headers: { 'Content-Type': 'application/json' },
credentials: 'include',
body: method==='GET' ? null : JSON.stringify(data||{})
});
const j = await res.json().catch(()=>({ ok:false, error:'Bad JSON' }));
if (!j.ok) throw new Error(j.error||'Request failed');
return j.data;
}


export async function me(){ return (await api('/auth/me.php', {}, 'GET')).user; }

