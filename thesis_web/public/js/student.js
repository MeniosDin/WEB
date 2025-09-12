// public/js/student.js
import { apiGet, apiPost, guardRole } from './auth.js';

const $   = (s) => document.querySelector(s);
const fmt = (d) => d ? new Date(d).toLocaleString() : '—';
const esc = (s='') => String(s).replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

let CURRENT_THESIS = null;

/* ---------------- Utils ---------------- */
function timeAgo(dateStr){
  if (!dateStr) return '—';
  const now = new Date(), d = new Date(dateStr);
  const ms = Math.max(0, now - d);
  const m  = Math.floor(ms/60000), h = Math.floor(m/60), dd = Math.floor(h/24);
  if (dd >= 1) return `πριν ${dd} ${dd===1?'ημέρα':'ημέρες'}`;
  if (h  >= 1) return `πριν ${h} ${h===1?'ώρα':'ώρες'}`;
  return `πριν ${m} ${m===1?'λεπτό':'λεπτά'}`;
}

function statusBadge(s) {
  const map = {
    under_assignment: 'Υπό ανάθεση',
    active: 'Ενεργή',
    under_review: 'Υπό εξέταση',
    completed: 'Περατωμένη',
    canceled: 'Ακυρωμένη'
  };
  const label = map[s] || s || '—';
  return `<span style="display:inline-block;background:#1f2937;color:#e5e7eb;border-radius:.4rem;padding:.15rem .5rem;font-size:.85em">${label}</span>`;
}

function ensureBox(sel, title) {
  let el = document.querySelector(sel);
  if (!el) {
    const wrapper = document.createElement('section');
    wrapper.className = 'card';
    wrapper.style.padding = '1rem';
    wrapper.innerHTML = `<h3 style="margin-top:0">${title}</h3><div class="content"></div>`;
    $('#thesis')?.appendChild(wrapper);
    el = wrapper.querySelector('.content');
    el.id = sel.replace('#','');
  } else {
    const parent = el.closest('.card');
    if (parent && !parent.querySelector('h3')) {
      const h = document.createElement('h3');
      h.textContent = title;
      h.style.marginTop = '0';
      parent.prepend(h);
    }
  }
  return el;
}

/* ---------------- 1) Προβολή θέματος ---------------- */
function renderThesis(t, topic) {
  const pdf = topic?.pdf_path
    ? `<div><a href="${esc(topic.pdf_path)}" target="_blank" rel="noopener">Συνημμένο PDF περιγραφής</a></div>`
    : '';
  const assigned = t.assigned_at
    ? `<small><strong>Ανάθεση:</strong> ${fmt(t.assigned_at)} (${timeAgo(t.assigned_at)})</small>`
    : '<small><strong>Ανάθεση:</strong> —</small>';

  return `
    <article class="card" style="padding:1rem">
      <h4 style="margin:.2rem 0">${esc(topic?.title || '—')}</h4>
      <div>${statusBadge(t.status)}</div>
      ${topic?.summary ? `<p style="white-space:pre-wrap">${esc(topic.summary)}</p>` : ''}
      ${pdf}
      <div style="margin-top:.3rem"><small><strong>Thesis ID:</strong> ${t.id}</small> · ${assigned}</div>
      <div style="display:grid;gap:.35rem;margin-top:.8rem">
        <div id="invitesBox"></div>
        <div id="membersBox"></div>
        <div id="timelineBox"></div>
        <div id="gradesBox"></div>
        <div id="presentationBox"></div>
      </div>
    </article>
  `;
}

async function loadMyThesis() {
  const out = $('#thesis');
  out.innerHTML = 'Φόρτωση...';

  // Πρέπει το API να επιστρέφει: id, topic_id, status, created_at, assigned_at
  const res = await apiGet('/theses/list.php');
  if (!res.ok) { out.textContent = res.error || 'Σφάλμα φόρτωσης'; return null; }

  const items = res.items || [];
  if (!items.length) {
    out.innerHTML = `<div class="card" style="padding:1rem">
      Δεν έχεις διπλωματική ακόμη.
    </div>`;
    return null;
  }

  items.sort((a,b) => new Date(b.created_at) - new Date(a.created_at));
  const t = items[0];
  CURRENT_THESIS = t;

  // Θέμα: τίτλος/περιγραφή/pdf
  let topic = { title: '—', summary: '', pdf_path: '' };
  try {
    const r = await apiGet(`/topics/get.php?id=${encodeURIComponent(t.topic_id)}`);
    if (r?.item) topic = r.item;
  } catch {}

  out.innerHTML = renderThesis(t, topic);
  return t;
}

async function loadInvitations(thesis_id) {
  const box = ensureBox('#invitesBox', 'Προσκλήσεις');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/committee/invitations_list.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const rows = res.items || [];
  if (!rows.length) { box.innerHTML = '<em>Δεν υπάρχουν προσκλήσεις ακόμη.</em>'; return; }
  box.innerHTML = rows.map(r => `
    <div class="card" style="padding:.6rem;margin:.3rem 0">
      <div><strong>Invitation:</strong> ${r.id}</div>
      <div><strong>Status:</strong> ${statusBadge(r.status)}</div>
      <div><small>Invited: ${fmt(r.invited_at)} ${r.responded_at ? '· Responded: '+fmt(r.responded_at) : ''}</small></div>
    </div>
  `).join('');
}

async function loadMembers(thesis_id) {
  const box = ensureBox('#membersBox', 'Μέλη Τριμελούς');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/committee/members.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const rows = res.items || [];
  if (!rows.length) { box.innerHTML = '<em>Δεν έχουν οριστεί ακόμη μέλη.</em>'; return; }
  box.innerHTML = rows.map(m => `
    <div class="card" style="padding:.6rem;margin:.3rem 0">
      <div><strong>${esc((m.first_name||'')+' '+(m.last_name||''))}</strong> — ${esc(m.email||'—')}</div>
      <small>Ρόλος: ${m.role_in_committee}</small>
    </div>
  `).join('');
}

async function loadTimeline(thesis_id) {
  const box = ensureBox('#timelineBox', 'Χρονολόγιο');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/theses/timeline.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const rows = res.items || [];
  if (!rows.length) { box.innerHTML = '<em>Ακόμη δεν υπάρχουν γεγονότα.</em>'; return; }
  box.innerHTML = rows.map(e => `
    <div class="card" style="padding:.6rem;margin:.3rem 0">
      <div><strong>${esc(e.event_type || 'event')}</strong></div>
      <div>${esc(e.from_status || '—')} → ${esc(e.to_status || '—')}</div>
      <small>${fmt(e.created_at)}</small>
    </div>
  `).join('');
}

async function loadGrades(thesis_id) {
  const box = ensureBox('#gradesBox', 'Βαθμολογία (Σύνοψη)');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/grades/summary.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const s = res.summary || {};
  if (!s.cnt) { box.innerHTML = '<em>Δεν έχουν καταχωρηθεί βαθμοί ακόμη.</em>'; return; }
  box.innerHTML = `
    <div class="card" style="padding:.6rem">
      <div><strong>Μέσος όρος:</strong> ${Number(s.avg_total || 0).toFixed(2)}</div>
      <div><small>Βαθμολογήσεις: ${s.cnt}</small></div>
    </div>
  `;
}

async function loadPresentation(thesis_id) {
  const box = ensureBox('#presentationBox', 'Παρουσίαση');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/presentation/get.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const p = res.item;
  if (!p) { box.innerHTML = '<em>Δεν έχει προγραμματιστεί ακόμη παρουσίαση.</em>'; return; }
  box.innerHTML = `
    <div class="card" style="padding:.6rem">
      <div><strong>Ημ/νία:</strong> ${fmt(p.when_dt)}</div>
      <div><strong>Τρόπος:</strong> ${p.mode === 'online' ? 'Online' : 'Δια ζώσης'}</div>
      <div><strong>Χώρος/Σύνδεσμος:</strong> ${esc(p.room_or_link)}</div>
      <div><small>Δημοσίευση ανακοίνωσης: ${p.published_at ? fmt(p.published_at) : '—'}</small></div>
    </div>
  `;
}

async function refreshThesisExtras(){
  if (!CURRENT_THESIS) return;
  await Promise.allSettled([
    loadInvitations(CURRENT_THESIS.id),
    loadMembers(CURRENT_THESIS.id),
    loadTimeline(CURRENT_THESIS.id),
    loadGrades(CURRENT_THESIS.id),
    loadPresentation(CURRENT_THESIS.id),
  ]);
}

/* ---------------- 2) Επεξεργασία Προφίλ ---------------- */
async function loadProfile(){
  const f = $('#profileForm'); if (!f) return;
  const msg = $('#profileMsg');
  msg.textContent = 'Φόρτωση...';

  const res = await apiGet('/users/get_profile.php');
  if (!res.ok) { msg.textContent = res.error || 'Σφάλμα φόρτωσης'; return; }

  const u = res.user || {};
  f.email.value          = u.email || '';
  f.phone_mobile.value   = u.phone_mobile || '';
  f.phone_landline.value = u.phone_landline || '';
  f.address.value        = u.address || '';
  msg.textContent = '';
}

function wireProfileForm(){
  const f = $('#profileForm'); if (!f) return;
  const msg = $('#profileMsg');

  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = 'Αποθήκευση...';

    const data = Object.fromEntries(new FormData(f).entries());
    const res = await apiPost('/users/update_profile.php', data);

    if (!res.ok) {
      msg.textContent = res.error || 'Σφάλμα';
      msg.style.color = '#b91c1c';
    } else {
      msg.textContent = 'Αποθηκεύτηκε ✔';
      msg.style.color = '#065f46';
      loadProfile();
    }
  });
}

/* ---------------- 3) Διαχείριση διπλωματικής ---------------- */

// απόκτησε/κράτα thesis id
async function getThesisId(){
  if (CURRENT_THESIS?.id) return CURRENT_THESIS.id;
  const r = await apiGet('/theses/list.php');
  if (r?.ok && Array.isArray(r.items) && r.items.length){
    r.items.sort((a,b)=> new Date(b.created_at)-new Date(a.created_at));
    CURRENT_THESIS = r.items[0];
    return CURRENT_THESIS.id;
  }
  return null;
}

// Πρόσκληση μέλους μέσω απλής φόρμας (person_id)
function wireInviteForm(){
  const form = $('#inviteF'); if (!form) return;
  if (!form.querySelector('[name="thesis_id"]') && CURRENT_THESIS) {
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'thesis_id'; hidden.value = CURRENT_THESIS.id;
    form.appendChild(hidden);
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());
    if (!data.thesis_id) data.thesis_id = await getThesisId();
    const res = await apiPost('/committee/invite.php', data);
    $('#inviteMsg').textContent = res.ok ? 'Η πρόσκληση στάλθηκε.' : (res.error || 'Σφάλμα');
    refreshThesisExtras();
  });
}

// Αναζήτηση διδασκόντων & άμεση πρόσκληση (αν υπάρχει σχετική φόρμα/λίστα στο HTML)
function wireTeacherSearch(){
  const searchF = $('#teacherSearchF');
  const results = $('#teacherResults');
  const msg     = $('#teacherSearchMsg');
  if (!searchF || !results) return;

  searchF.addEventListener('submit', async (e)=>{
    e.preventDefault();
    msg.textContent = 'Αναζήτηση...';
    results.innerHTML = '';
    const q = new FormData(searchF).get('q') || '';
    const res = await apiGet(`/users/list.php?role=teacher&q=${encodeURIComponent(q)}`);
    msg.textContent = res.ok ? '' : (res.error || 'Σφάλμα');
    const items = res.items || [];
    if (!items.length){ results.innerHTML = '<em>Δεν βρέθηκαν διδάσκοντες.</em>'; return; }
    results.innerHTML = items.map(u=>`
      <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px dashed #e5e7eb;padding:.35rem 0">
        <div>
          <strong>${esc(u.name||'—')}</strong>
          <div class="muted"><small>${esc(u.email||'—')}</small></div>
        </div>
        <button class="btn" data-invite-user="${u.id}">Πρόσκληση</button>
      </div>
    `).join('');
  });

  results.addEventListener('click', async (e)=>{
    const btn = e.target.closest('[data-invite-user]');
    if (!btn) return;
    btn.disabled = true;
    const thesis_id = await getThesisId();
    const user_id   = btn.getAttribute('data-invite-user');
    const res = await apiPost('/committee/invite.php', { thesis_id, user_id });
    btn.textContent = res.ok ? 'Στάλθηκε ✔' : (res.error || 'Σφάλμα');
    refreshThesisExtras();
    setTimeout(()=> (btn.disabled=false, btn.textContent='Πρόσκληση'), 1200);
  });
}

// Upload draft (multipart) & δήλωση πόρων ως link
function wireDraftAndResources(){
  const draftF = $('#draftUploadF');
  const draftMsg = $('#draftUploadMsg');
  if (draftF){
    draftF.addEventListener('submit', async (e)=>{
      e.preventDefault();
      if (draftMsg) draftMsg.textContent = 'Μεταφόρτωση...';
      const thesis_id = await getThesisId();
      const fd = new FormData(draftF);
      fd.append('thesis_id', thesis_id);
      fd.append('kind', 'draft');
      const r = await fetch('./api/resources/create.php', { method:'POST', body: fd, credentials:'include' });
      const res = await r.json().catch(()=>({ok:false,error:'Σφάλμα'}));
      if (draftMsg) draftMsg.textContent = res.ok ? 'Καταχωρήθηκε ✔' : (res.error || 'Σφάλμα');
    });
  }

  const resF = $('#resourceF');
  const resMsg = $('#resourceMsg');
  if (resF){
    resF.addEventListener('submit', async (e)=>{
      e.preventDefault();
      if (resMsg) resMsg.textContent = 'Αποστολή...';
      const thesis_id = await getThesisId();
      const data = Object.fromEntries(new FormData(resF).entries());
      data.thesis_id = thesis_id;
      const res = await apiPost('/resources/create.php', data);
      if (resMsg) resMsg.textContent = res.ok ? 'Καταχωρήθηκε ✔' : (res.error || 'Σφάλμα');
    });
  }
}

// Προγραμματισμός παρουσίασης
function wireSchedule(){
  const f = $('#scheduleF'); const msg = $('#scheduleMsg');
  if (!f) return;
  f.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if (msg) msg.textContent = 'Αποστολή...';
    const thesis_id = await getThesisId();
    const data = Object.fromEntries(new FormData(f).entries());
    data.thesis_id = thesis_id;
    const res = await apiPost('/presentation/schedule.php', data);
    if (msg) msg.textContent = res.ok ? 'Καταχωρήθηκε ✔' : (res.error || 'Σφάλμα');
    refreshThesisExtras();
  });
}

// Νημερτής
function wireNimeritis(){
  const f = $('#nimeritisF'); const msg = $('#nimeritisMsg');
  if (!f) return;
  f.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if (msg) msg.textContent = 'Αποθήκευση...';
    const thesis_id = await getThesisId();
    const data = Object.fromEntries(new FormData(f).entries());
    data.thesis_id = thesis_id;
    const res = await apiPost('/theses/set_nimeritis.php', data);
    if (msg) msg.textContent = res.ok ? 'Αποθηκεύτηκε ✔' : (res.error || 'Σφάλμα');
    refreshThesisExtras();
  });
}

/* ---------------- Entry ---------------- */
export async function initStudent() {
  await guardRole('student');

  // Προφίλ (tab 2)
  wireProfileForm();
  await loadProfile().catch(()=>{});

  // Προβολή θέματος (tab 1)
  const t = await loadMyThesis();
  if (t) await refreshThesisExtras();

  // Διαχείριση (tab 3)
  wireInviteForm();
  wireTeacherSearch();
  wireDraftAndResources();
  wireSchedule();
  wireNimeritis();
}

/* αυτο-εκκίνηση */
initStudent().catch(()=>{});
