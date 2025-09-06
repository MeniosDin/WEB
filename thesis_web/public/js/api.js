export async function apiGet(url) {
const r = await fetch(url, { credentials:'include' });
return r.json();
}
export async function apiPost(url, body) {
const r = await fetch(url, {
method:'POST', credentials:'include',
headers:{'Content-Type':'application/json'},
body: JSON.stringify(body||{})
});
return r.json();
}
