// public/js/student.js
import { apiGet, apiPost, guardRole } from './auth.js';

const $ = (s) => document.querySelector(s);
const fmt = (d) => d ? new Date(d).toLocaleString() : '—';

let CURRENT_THESIS = null;

export async function initStudent() {
  await guardRole('student');

  // Αρχικό load
  const thesis = await loadMyThesis();
  if (!thesis) return;

  await Promise.allSettled([
    loadInvitations(thesis.id),
    loadMembers(thesis.id),
    loadTimeline(thesis.id),
    loadGrades(thesis.id),
    loadPresentation(thesis.id),
  ]);

  // Wire up invite form (αν υπάρχει στη σελίδα)
  const form = $('#inviteF');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      if (!data.thesis_id && CURRENT_THESIS) data.thesis_id = CURRENT_THESIS.id;

      const res = await apiPost('/api/committee/invite.php', data);
      $('#inviteMsg').textContent = res.ok ? 'Η πρόσκληση στάλθηκε.' : (res.error || 'Σφάλμα');
      loadInvitations(data.thesis_id);
    });
  }
}

/* ---------------- Loaders ---------------- */

async function loadMyThesis() {
  const out = $('#thesis');
  out.innerHTML = 'Φόρτωση...';

  const res = await apiGet('/api/theses/list.php');
  if (!res.ok) { out.textContent = res.error || 'Σφάλμα φόρτωσης'; return null; }

  const items = res.items || [];
  if (!items.length) {
    out.innerHTML = `<div class="card" style="padding:1rem">
      Δεν έχεις ενεργή διπλωματική ακόμη. Ζήτησε ανάθεση από επιβλέποντα.
    </div>`;
    return null;
  }

  items.sort((a,b) => new Date(b.created_at) - new Date(a.created_at));
  const t = items[0];
  CURRENT_THESIS = t;

  let topicTitle = '—';
  try {
    const r = await apiGet(`/api/topics/get.php?id=${encodeURIComponent(t.topic_id)}`);
    topicTitle = r?.item?.title || '—';
  } catch {}

  out.innerHTML = renderThesis(t, topicTitle);
  return t;
}

async function loadInvitations(thesis_id) {
  const box = ensureBox('#invitesBox', 'Προσκλήσεις');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/api/committee/invitations_list.php?thesis_id=${encodeURIComponent(thesis_id)}`);
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
  const res = await apiGet(`/api/committee/members.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const rows = res.items || [];
  if (!rows.length) { box.innerHTML = '<em>Δεν έχουν οριστεί ακόμη μέλη.</em>'; return; }
  box.innerHTML = rows.map(m => `
    <div class="card" style="padding:.6rem;margin:.3rem 0">
      <div><strong>${(m.first_name||'')+' '+(m.last_name||'')}</strong> — ${m.email||'—'}</div>
      <small>Ρόλος: ${m.role_in_committee}</small>
    </div>
  `).join('');
}

async function loadTimeline(thesis_id) {
  const box = ensureBox('#timelineBox', 'Χρονολόγιο');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/api/theses/timeline.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const rows = res.items || [];
  if (!rows.length) { box.innerHTML = '<em>Ακόμη δεν υπάρχουν γεγονότα.</em>'; return; }
  box.innerHTML = rows.map(e => `
    <div class="card" style="padding:.6rem;margin:.3rem 0">
      <div><strong>${e.event_type || 'event'}</strong></div>
      <div>${e.from_status || '—'} → ${e.to_status || '—'}</div>
      <small>${fmt(e.created_at)}</small>
    </div>
  `).join('');
}

async function loadGrades(thesis_id) {
  const box = ensureBox('#gradesBox', 'Βαθμολογία (Σύνοψη)');
  box.innerHTML = 'Φόρτωση...';
  const res = await apiGet(`/api/grades/summary.php?thesis_id=${encodeURIComponent(thesis_id)}`);
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
  const res = await apiGet(`/api/presentation/get.php?thesis_id=${encodeURIComponent(thesis_id)}`);
  if (!res.ok) { box.textContent = res.error || 'Σφάλμα'; return; }
  const p = res.item;
  if (!p) { box.innerHTML = '<em>Δεν έχει προγραμματιστεί ακόμη παρουσίαση.</em>'; return; }
  box.innerHTML = `
    <div class="card" style="padding:.6rem">
      <div><strong>Ημ/νία:</strong> ${fmt(p.when_dt)}</div>
      <div><strong>Τρόπος:</strong> ${p.mode === 'online' ? 'Online' : 'Δια ζώσης'}</div>
      <div><strong>Χώρος/Σύνδεσμος:</strong> ${p.room_or_link}</div>
      <div><small>Δημοσίευση ανακοίνωσης: ${p.published_at ? fmt(p.published_at) : '—'}</small></div>
    </div>
  `;
}

/* ---------------- Helpers ---------------- */

function renderThesis(t, topicTitle) {
  const status = statusBadge(t.status);
  return `
    <article class="card" style="padding:1rem">
      <h4 style="margin:.2rem 0">${topicTitle}</h4>
      <div>${status}</div>
      <div style="margin-top:.3rem"><small><strong>Thesis ID:</strong> ${t.id}</small></div>
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

function ensureBox(sel, title) {
  let el = document.querySelector(sel);
  if (!el) {
    const wrapper = document.createElement('section');
    wrapper.className = 'card';
    wrapper.style.padding = '1rem';
    wrapper.innerHTML = `<h3 style="margin-top:0">${title}</h3><div class="content"></div>`;
    document.querySelector('#thesis')?.appendChild(wrapper);
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

function statusBadge(s) {
  const map = {
    under_assignment: 'Υπό ανάθεση',
    active: 'Ενεργή',
    under_review: 'Υπό αξιολόγηση',
    completed: 'Ολοκληρωμένη',
    canceled: 'Ακυρωμένη'
  };
  const label = map[s] || s || '—';
  return `<span style="display:inline-block;background:#1f2937;color:#e5e7eb;border-radius:.4rem;padding:.15rem .5rem;font-size:.85em">${label}</span>`;
}

initStudent().catch(()=>{});
