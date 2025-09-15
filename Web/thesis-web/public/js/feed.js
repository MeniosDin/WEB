export async function loadPublicFeedJSON(targetId){
const box = document.getElementById(targetId);
const r = await fetch('/public/feeds/presentations.json.php');
const data = await r.json();
box.innerHTML = data.items.map(p=>`
<li>
<strong>${p.topic_title}</strong> — ${p.student_name}<br>
${new Date(p.when_dt).toLocaleString()} · ${p.mode==='in_person' ? p.room_or_link : 'Online'}
</li>
`).join('');
}