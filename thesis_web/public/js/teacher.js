import { apiGet } from './auth.js';
export async function loadTeacherTopics(){
const box = document.getElementById('topics');
const data = await apiGet('/api/topics/list_mine.php');
if(!data.ok){ box.textContent='Σφάλμα'; return; }
box.innerHTML = data.items.map(t=>`
<article class="card" style="margin:.5rem 0;padding:1rem">
<h4>${t.title}</h4>
<p>${t.summary||''}</p>
<small>Επιβλέπων: ${t.supervisor}</small>
</article>
`).join('');
}