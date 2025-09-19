/* =========================
   Βασικές Ρυθμίσεις
   (Προσαρμόζεις μόνο το BASE αν ο φάκελος διαφέρει)
   ========================= */
const BASE  = '/thesis-web';
const API   = `${BASE}/api/prof`;
const PUB   = `${BASE}/public`;
const API_COMMITTEE = `${BASE}/api/committee`;
const API_THESES_ASSIGN = `${BASE}/api/theses/assign.php`;

/* =========================
   Βοηθητικά
   ========================= */
function esc(s) {
  return String(s).replace(/[&<>"']/g, (m) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[m]));
}

// Διαβάζει ως text και μετά δοκιμάζει JSON.parse ώστε να μη «σκάει» το fetch
async function safeJson(res) {
  const txt = await res.text();
  try { return txt ? JSON.parse(txt) : null; }
  catch (e) {
    console.error('[safeJson] non-JSON από', res.url || '(unknown url)', '\n', txt);
    return null;
  }
}

// ----- API helpers -----
async function apiGet(url) {
  const r = await fetch(`/thesis-web/api${url}`, { credentials: 'same-origin', headers: { 'Accept':'application/json' } });
  return r.json();
}
async function apiPost(url, data) {
  const body = (data instanceof FormData) ? data : Object.entries(data).map(([k,v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
  const r = await fetch(`/thesis-web/api${url}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: data instanceof FormData ? {} : { 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
    body
  });
  return r.json();
}

// Πάρε τον τρέχοντα χρήστη (χρειαζόμαστε person_id για τον έλεγχο επιβλέποντα)
async function getMe() {
  const r = await apiGet('/auth/me.php'); // αν έχεις άλλο endpoint προσαρμόσ’ το
  // αναμένουμε { ok:true, user:{ id, person_id, name, ... } }
  return r?.user || r?.data?.user || {};
}

// Φέρε *τον δικό μου* βαθμό (για prefill)
async function fetchMyGrade(thesis_id) {
  const u = new URL('/thesis-web/api/grades/record.php', location.origin);
  u.searchParams.set('thesis_id', thesis_id);
  const r = await fetch(u, { credentials: 'same-origin', headers: { 'Accept':'application/json' } });
  const j = await r.json();
  // αναμένουμε { ok:true, grade:{ total, notes } } ή κάτι παρόμοιο
  return j?.grade || j?.data?.grade || null;
}

// === Βοηθητικά POST + Resources ===
async function postForm(url, dataObj) {
  const fd = new FormData();
  Object.entries(dataObj || {}).forEach(([k, v]) => fd.append(k, v));
  const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
  const j = await safeJson(res);
  if (!j || j.ok !== true) throw new Error((j && j.error) || 'Αποτυχία');
  return j;
}
async function fetchResources(thesis_id, kind) {
  const u = new URL(`${BASE}/api/resources/list.php`, location.origin);
  u.searchParams.set('thesis_id', thesis_id);
  if (kind) u.searchParams.set('kind', kind); // 'draft' | 'link'
  const res = await fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
  const j = await safeJson(res);
  if (!j || j.ok !== true) return { items: [] };
  const items = (j.data?.items ?? j.items ?? []);
  return { items };
}

// Μετάφραση καταστάσεων διπλωματικής (για badges/labels)
const GR_STATUS = {
  under_assignment: 'Υπό ανάθεση',
  active: 'Ενεργή',
  under_review: 'Υπό εξέταση',
  completed: 'Περατωμένη',
  canceled: 'Ακυρωμένη',
};

/* =========================
   ΘΕΜΑΤΑ (Topics)
   ========================= */
export async function loadTeacherTopics() {
  const box = document.getElementById('topics');
  if (!box) return;

  box.textContent = 'Φόρτωση…';
  try {
    const res = await fetch(`${API}/topics.php?action=list`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const j = await safeJson(res);
    if (!j || j.ok !== true) {
      box.textContent = (j && j.error) || 'Σφάλμα';
      return;
    }

    const items = j.data || [];
    if (!items.length) { box.textContent = 'Δεν υπάρχουν θέματα.'; return; }

    box.innerHTML = items.map(t => {
      // Διόρθωση path για PDF
      let pdfHref = t.spec_pdf_path || null;
      if (pdfHref && pdfHref.startsWith('/uploads/')) {
        pdfHref = `${PUB}${pdfHref}`;
      }

      // Προσωρινή ανάθεση — δείξε στοιχεία & δράσεις
      const provUserId = t.provisional_student_user_id ?? t.provisional_student_id ?? null;
      const hasProvisional = !!(t.provisional_student_user_id || t.provisional_student_id);

      const provBlock = hasProvisional ? `
        <div style="margin-top:.6rem;font-size:.9rem;color:#9CA3AF">
          <b>Προσωρινή ανάθεση σε:</b>
          ${esc(t.provisional_student_name || '—')}
          ${t.provisional_student_number ? `(${esc(t.provisional_student_number)})` : ''}
          <button class="btn btn-sm danger unassignStudent" style="margin-left:.5rem">Αφαίρεση</button>
          ${
            provUserId
              ? `<button class="btn btn-sm finalAssign" data-topic-id="${esc(t.id)}" data-student-user-id="${esc(provUserId)}" style="margin-left:.25rem">Οριστική ανάθεση</button>`
              : ''
          }
        </div>
      ` : `
        <div style="margin-top:.6rem;font-size:.9rem;">
          <button class="btn btn-sm assignStudent">Ανάθεση σε φοιτητή</button>
        </div>
      `;

      return `
        <article class="card" data-id="${esc(t.id)}" style="margin:.5rem 0;padding:1rem">
          <h4 style="margin:0 0 .35rem">${esc(t.title || '—')}</h4>
          <p style="margin:.25rem 0 .75rem 0">${esc(t.summary || '')}</p>

          <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            ${pdfHref ? `<a href="${esc(pdfHref)}" target="_blank" rel="noopener">Προδιαγραφή (PDF)</a>` : '<span class="muted">— κανένα PDF —</span>'}
            <button class="btn btn-sm outline editPdf">Επεξεργασία PDF</button>

            <label style="margin-left:auto;display:flex;gap:.35rem;align-items:center">
              <input type="checkbox" class="toggleAvail" ${t.is_available ? 'checked':''}>
              Διαθέσιμο
            </label>
            <button class="btn btn-sm editTopic">Επεξεργασία</button>
            <button class="btn btn-sm danger deleteTopic">Διαγραφή</button>
          </div>

          <!-- Inline PDF controls -->
          <div class="pdfControls" style="display:none;margin-top:.75rem;padding:.75rem;border:1px dashed #374151;border-radius:8px;">
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
              <input type="file" class="pdfFile" accept="application/pdf">
              <button class="btn btn-sm saveNewPdf">Αποθήκευση νέου PDF</button>
              ${t.spec_pdf_path ? `<button class="btn btn-sm danger removePdf">Διαγραφή PDF</button>` : ''}
              <button class="btn btn-sm secondary cancelPdf">Άκυρο</button>
              <small class="pdfHint muted">Μόνο PDF έως 10MB.</small>
            </div>
          </div>

          ${provBlock}
        </article>
      `;
    }).join('');
  } catch (err) {
    console.error(err);
    box.textContent = 'Σφάλμα.';
  }
}

/* =========================
   ΛΙΣΤΑ ΔΙΠΛΩΜΑΤΙΚΩΝ (Teacher view)
   ========================= */
async function loadThesesList() {
  const box = document.getElementById('thesesList');
  if (!box) return;

  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');
  const role   = roleSel?.value || '';
  const status = statusSel?.value || '';

  const url = new URL(`${API}/theses.php`, window.location.origin);
  url.searchParams.set('action', 'list');
  if (role)   url.searchParams.set('role', role);
  if (status) url.searchParams.set('status', status);

  box.textContent = 'Φόρτωση...';

  try {
    const res = await fetch(url.toString(), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const j = await safeJson(res);
    if (!j || j.ok !== true) {
      box.textContent = (j && j.error) || 'Σφάλμα';
      return;
    }

    const items = j.data || [];
    if (!items.length) { box.textContent = 'Δεν βρέθηκαν αποτελέσματα.'; return; }

    box.innerHTML = items.map(t => {
      const title = t.topic_title || t.title || '—';
      const roleLabel = (t.my_role === 'supervisor') ? 'Επιβλέπων' :
                        (t.my_role === 'member')     ? 'Μέλος τριμελούς' : '';

      return `
        <article class="card" data-thesis="${esc(t.id)}" style="margin:.5rem 0;padding:1rem">
          <div style="display:flex;gap:1rem;align-items:baseline;flex-wrap:wrap">
            <h4 style="margin:0">${esc(title)}</h4>
            ${roleLabel ? `<span class="muted">(${esc(roleLabel)})</span>` : ''}
            <span style="margin-left:auto" class="muted">Κατάσταση: ${esc(t.status || '')}</span>
          </div>
          <div style="margin:.4rem 0">
            Φοιτητής: ${esc(t.student_name ?? '—')} ${t.student_number ? `(${esc(t.student_number)})` : ''}
          </div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button class="btn btn-sm seeDetails">Λεπτομέρειες</button>
          </div>
          <div class="thesisDetails" style="display:none;margin-top:.75rem"></div>
        </article>
      `;
    }).join('');
  } catch (err) {
    console.error('Fetch/list failed:', err);
    box.textContent = 'Σφάλμα.';
  }
}

/* =========================
   ΠΡΟΣΚΛΗΣΕΙΣ ΤΡΙΜΕΛΟΥΣ (Teacher mode)
   ========================= */
async function loadInvitations() {
  const box = document.getElementById('invitationsList');
  if (!box) return;

  box.textContent = 'Φόρτωση...';
  try {
    const response = await fetch(`${API_COMMITTEE}/invitations_list.php`, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    if (!response.ok) {
      const txt = await response.text().catch(() => '');
      console.error('HTTP', response.status, txt);
      box.textContent = 'Σφάλμα.';
      return;
    }

    const payload = await safeJson(response);
    if (!payload || payload.ok !== true) {
      box.textContent = (payload && payload.error) || 'Σφάλμα';
      return;
    }

    const items = payload?.data?.items || payload?.items || [];
    if (!items.length) { box.textContent = 'Δεν υπάρχουν προσκλήσεις.'; return; }

    box.innerHTML = items.map(i => {
      const thesisStatus = i.thesis_status || i.thesisStatus || i.thesis_status_text || '';
      const invStatus    = i.invitation_status || i.status || 'pending';
      const thesisStatusLabel = GR_STATUS[thesisStatus] || thesisStatus || '—';
      const isPending = (invStatus === 'pending');

      return `
        <article class="card" data-inv="${esc(i.id || i.invitation_id)}" style="margin:.5rem 0;padding:1rem">
          <div style="display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap">
            <h4 style="margin:0">${esc(i.topic_title || '—')}</h4>
            <span class="muted">(${esc(i.supervisor_name || '—')})</span>
            <span class="badge" style="margin-left:auto">${esc(thesisStatusLabel)}</span>
          </div>
          <div style="margin:.35rem 0">
            Φοιτητής: ${esc(i.student_name || '—')} ${i.student_number ? `(${esc(i.student_number)})` : ''}
          </div>
          <div class="muted" style="margin:.25rem 0">Πρόσκληση: ${esc(i.invited_at || '—')}</div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            ${
              isPending
                ? `<button class="btn btn-sm acceptInv">Αποδοχή</button>
                   <button class="btn btn-sm danger rejectInv">Απόρριψη</button>`
                : `<span class="muted">Κατάσταση πρόσκλησης: ${esc(invStatus)}</span>`
            }
          </div>
        </article>
      `;
    }).join('');
  } catch (err) {
    console.error(err);
    box.textContent = 'Σφάλμα.';
  }
}

/* =========================
   Νέο Θέμα (με προαιρετικό PDF)
   ========================= */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('newTopicForm');
  const fileInput = document.getElementById('spec_pdf');

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files?.[0];
      if (!f) return;
      if (f.type !== 'application/pdf') { alert('Μόνο PDF επιτρέπεται.'); fileInput.value = ''; return; }
      const max = 10 * 1024 * 1024;
      if (f.size > max) { alert('Το αρχείο ξεπερνά τα 10MB.'); fileInput.value = ''; }
    });
  }
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const fd = new FormData(form);
      fd.append('action', 'create');

      const titleEl   = form.querySelector('#title,[name="title"]');
      const summaryEl = form.querySelector('#summary,[name="summary"]');
      if (!fd.get('title')   && titleEl)   fd.set('title',   titleEl.value.trim());
      if (!fd.get('summary') && summaryEl) fd.set('summary', summaryEl.value.trim());
      if (!fd.get('is_available')) fd.append('is_available', '1');

      if (fileInput && !fd.get('spec_pdf') && fileInput.files?.[0]) {
        fd.set('spec_pdf', fileInput.files[0]);
      }

      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) throw new Error((j && j.error) || 'Σφάλμα καταχώρισης.');

      form.reset();
      await loadTeacherTopics();
      alert('Το θέμα καταχωρήθηκε.');
    } catch (err) {
      alert(err.message || 'Κάτι πήγε στραβά.');
    }
  });
});

/* =========================
   Ενέργειες στη Λίστα Θεμάτων (edit/delete/toggle/pdf/assign/final-assign)
   ========================= */
(function bindTopicActions(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindTopicActions);
    return;
  }
  const box = document.getElementById('topics');
  if (!box) return;

  // Helper: ενημέρωση διαθεσιμότητας στον server
  async function setAvailability(topicId, val /* '0' | '1' */) {
    try {
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', topicId);
      fd.append('is_available', String(val));
      await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' }).then(safeJson);
    } catch (_) {}
  }

  box.addEventListener('click', async (e) => {
    const art = e.target.closest('article[data-id]');
    if (!art) return;
    const id = art.dataset.id;
    if (!id) return;

    // Διαγραφή
    if (e.target.classList.contains('deleteTopic')) {
      if (!confirm('Σίγουρα θέλεις να διαγράψεις αυτό το θέμα;')) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', id);
      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία διαγραφής'); return; }
      await loadTeacherTopics();
      return;
    }

    // Επεξεργασία τίτλου/σύνοψης
    if (e.target.classList.contains('editTopic')) {
      const curTitle   = art.querySelector('h4')?.textContent.trim() || '';
      const curSummary = art.querySelector('p')?.textContent.trim()  || '';
      const newTitle   = prompt('Νέος τίτλος:', curTitle);
      if (newTitle === null) return;
      const newSummary = prompt('Νέα περιγραφή:', curSummary ?? '');
      if (newSummary === null) return;

      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('title', newTitle.trim());
      fd.append('summary', newSummary.trim());
      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία ενημέρωσης'); return; }
      await loadTeacherTopics();
      return;
    }

    // Άνοιγμα/κλείσιμο panel PDF
    if (e.target.classList.contains('editPdf')) {
      const panel = art.querySelector('.pdfControls');
      if (panel) panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
      return;
    }

    // Άκυρο panel PDF
    if (e.target.classList.contains('cancelPdf')) {
      const panel = art.querySelector('.pdfControls');
      if (panel) {
        const file = panel.querySelector('.pdfFile');
        if (file) file.value = '';
        panel.style.display = 'none';
      }
      return;
    }

    // Αποθήκευση νέου PDF
    if (e.target.classList.contains('saveNewPdf')) {
      const panel = art.querySelector('.pdfControls');
      const fileInput = panel?.querySelector('.pdfFile');
      const f = fileInput?.files?.[0];
      if (!f) { alert('Επίλεξε PDF πρώτα.'); return; }

      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('spec_pdf', f);
      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία αποθήκευσης PDF'); return; }
      await loadTeacherTopics();
      alert('Το PDF ενημερώθηκε.');
      return;
    }

    // Διαγραφή υπάρχοντος PDF
    if (e.target.classList.contains('removePdf')) {
      if (!confirm('Να αφαιρεθεί το PDF από αυτό το θέμα;')) return;
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('remove_pdf', '1');
      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία διαγραφής PDF'); return; }
      await loadTeacherTopics();
      alert('Το PDF αφαιρέθηκε.');
      return;
    }

    // Προσωρινή ανάθεση (με αναζήτηση)
    if (e.target.classList.contains('assignStudent')) {
      const q = prompt('Δώσε ΑΜ ή όνομα φοιτητή:');
      if (!q) return;

      // 1) προσωρινή ανάθεση
      const fd = new FormData();
      fd.append('action', 'assign_student');
      fd.append('id', id);
      fd.append('student_query', q);

      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία ανάθεσης'); return; }

      // 2) κράτα διαθέσιμο (server + UI)
      await setAvailability(id, '1');
      const chk = art.querySelector('.toggleAvail'); if (chk) chk.checked = true;

      // 3) refresh
      await loadTeacherTopics();
      return;
    }

    // Αφαίρεση προσωρινής ανάθεσης
    if (e.target.classList.contains('unassignStudent')) {
      if (!confirm('Να αφαιρεθεί η προσωρινή ανάθεση;')) return;

      // 1) unassign
      const fd = new FormData();
      fd.append('action', 'unassign_student');
      fd.append('id', id);

      const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
      const j = await safeJson(res);
      if (!j || j.ok !== true) { alert((j && j.error) || 'Αποτυχία αφαίρεσης'); return; }

      // 2) διαθέσιμο = 1
      await setAvailability(id, '1');
      const chk = art.querySelector('.toggleAvail'); if (chk) chk.checked = true;

      // 3) ανανέωση
      await Promise.allSettled([ loadTeacherTopics(), loadThesesList?.() ]);
      return;
    }

    // Οριστική ανάθεση
    if (e.target.classList.contains('finalAssign')) {
      const btn = e.target;
      const topicId = btn.dataset.topicId || id;
      const studentUserId = btn.dataset.studentUserId;
      if (!studentUserId) { alert('Δεν βρέθηκε user_id φοιτητή για οριστική ανάθεση.'); return; }

      if (!confirm('Να γίνει οριστική ανάθεση στον/στην φοιτητή/τρια;')) return;

      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('topic_id', topicId);
        fd.append('student_user_id', studentUserId);

        const res = await fetch(API_THESES_ASSIGN, { method:'POST', body: fd, credentials:'same-origin' });
        const j = await safeJson(res);
        if (!j || j.ok !== true) {
          alert((j && j.error) || 'Αποτυχία οριστικής ανάθεσης');
          btn.disabled = false;
          return;
        }

        const block = btn.closest('div');
        const label = block?.querySelector('b');
        if (label) label.textContent = 'Οριστική ανάθεση:';
        block?.querySelectorAll('.finalAssign, .unassignStudent')?.forEach(el => el.remove());

        await setAvailability(topicId, '0');
        const chk = art.querySelector('.toggleAvail'); if (chk) chk.checked = false;

        alert('Η οριστική ανάθεση ολοκληρώθηκε.');
      } catch (err) {
        console.error(err);
        alert('Σφάλμα δικτύου.');
        btn.disabled = false;
      }
      return;
    }
  });

  // Toggle "Διαθέσιμο"
  box.addEventListener('change', async (e) => {
    if (!e.target.classList.contains('toggleAvail')) return;
    const art = e.target.closest('article[data-id]');
    if (!art) return;
    const id = art.dataset.id;
    const checked = e.target.checked ? '1' : '0';

    const fd = new FormData();
    fd.append('action', 'update');
    fd.append('id', id);
    fd.append('is_available', checked);

    const res = await fetch(`${API}/topics.php`, { method:'POST', body: fd, credentials:'same-origin' });
    const j = await safeJson(res);
    if (!j || j.ok !== true) {
      alert((j && j.error) || 'Αποτυχία ενημέρωσης');
      e.target.checked = !e.target.checked; // rollback
    }
  });
})();

// ---------- Presentation helpers ----------
function fmtDatetime(iso) {
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T'));
  if (isNaN(d)) return iso;
  const pad = (n) => String(n).padStart(2,'0');
  return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function presentModeLabel(mode) {
  const m = String(mode || '').toLowerCase();
  if (m.includes('online')) return 'Εξ αποστάσεως';
  if (m.includes('hybrid')) return 'Υβριδική';
  if (m.includes('person') || m.includes('ζώης') || m.includes('ζώσης')) return 'Δια ζώσης';
  return mode || '—';
}

/* =========================
   ΛΕΠΤΟΜΕΡΕΙΕΣ διπλωματικής + νέες ενέργειες
   ========================= */
(function bindThesisDetails(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindThesisDetails);
    return;
  }
  const list = document.getElementById('thesesList');
  if (!list) return;

  // helper για να στήσεις πάνελ βαθμού
  function mountGradePanel(det, gradesBox, tid, supervisorLabel) {
    gradesBox.classList.remove('muted');
    gradesBox.innerHTML = `
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <button class="btn btn-sm outline openGradeForm" data-thesis="${esc(tid)}">Καταχώριση βαθμού${supervisorLabel ? ' (Επιβλέπων)' : ''}</button>
        <span class="muted hint">Ο καθένας βλέπει/ενημερώνει τον δικό του βαθμό.</span>
      </div>
      <div class="gradeForm" style="display:none;margin-top:.6rem;padding:.6rem;border:1px dashed #374151;border-radius:.6rem">
        <form class="gradeUpsertF" data-thesis="${esc(tid)}" style="display:grid;gap:.5rem;max-width:420px">
          <label>Συνολικός βαθμός (0–10)
            <input type="number" name="total" min="0" max="10" step="0.5" required />
          </label>
          <label>Σημειώσεις (προαιρετικό)
            <textarea name="notes" rows="3" maxlength="500"></textarea>
          </label>
          <div style="display:flex;gap:.5rem;align-items:center">
            <button class="btn btn-sm">Αποθήκευση</button>
            <button type="button" class="btn btn-sm secondary cancelGrade">Άκυρο</button>
            <small class="muted saveMsg"></small>
          </div>
        </form>

      </div>
    `;

    // bind submit
// μέσα στο bindThesisDetails(), εκεί που έχεις:
const gradeForm = det.querySelector('.gradeUpsertF');
if (gradeForm) {
 gradeForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const saveMsg = gradeForm.querySelector('.saveMsg');
  if (saveMsg) saveMsg.textContent = 'Αποθήκευση...';

  const data = Object.fromEntries(new FormData(gradeForm).entries());

  // 1) thesis_id από το container / dataset
  const thesis_id = gradeForm.dataset.thesis || // π.χ. από το ίδιο το form
                    det.closest('article[data-thesis]')?.dataset.thesis || // ή από το card
                    null;

  if (!thesis_id) {
    alert('Δεν βρέθηκε διπλωματική (thesis_id).');
    if (saveMsg) saveMsg.textContent = 'Σφάλμα';
    return;
  }

  data.thesis_id = thesis_id;

  // 2) κριτήρια: στείλε έστω ένα κενό object για να ικανοποιήσεις το NOT NULL
  if (!data.criteria_scores_json) {
    data.criteria_scores_json = '{}';
  }

  // 3) (προαιρετικό) rubric_id αν το έχεις κάπου διαθέσιμο
  if (window.ACTIVE_RUBRIC_ID) {
    data.rubric_id = window.ACTIVE_RUBRIC_ID;
  }

  try {
    const res = await postForm('/thesis-web/api/grades/upsert.php', data);
    if (saveMsg) saveMsg.textContent = 'Αποθηκεύτηκε ✔';
  } catch (err) {
    alert(err.message || 'Σφάλμα.');
    if (saveMsg) saveMsg.textContent = 'Σφάλμα';
  }
});

}



  }

  list.addEventListener('click', async (e) => {
    const btn = e.target.closest('button.seeDetails');
    if (!btn) return;

    const art = btn.closest('article[data-thesis]');
    if (!art) return;

    const det = art.querySelector('.thesisDetails');
    if (!det) return;

    if (det.style.display === 'block') { det.style.display = 'none'; return; }
    det.style.display = 'block';
    det.textContent = 'Φόρτωση…';

    const id = art.dataset.thesis || '';
    if (!id) { det.textContent = 'Λείπει id'; return; }

    const url = new URL(`${API}/theses.php`, location.origin);
    url.searchParams.set('action', 'details');
    url.searchParams.set('id', id);
    url.searchParams.set('_', Date.now());

    try {
      const res = await fetch(url.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      const j = await safeJson(res);
      if (!j || j.ok !== true) {
        det.innerHTML = `<span style="color:#ef4444">${esc((j && j.error) || 'Σφάλμα')}</span>`;
        return;
      }

      const thesis    = (j.data && j.data.thesis) || {};
      const committee = Array.isArray(j.data && j.data.committee) ? j.data.committee : [];
      const tid       = thesis.id || id;

      // -------- helpers για κουμπιά --------
      const status = String(thesis.status || '').trim().toLowerCase();
      const myRole = String(thesis.my_role || '').trim().toLowerCase();
      const headerRoleText = art.querySelector('span.muted')?.textContent?.toLowerCase() || '';
      const looksSupervisorFromHeader = /επιβλέπ/.test(headerRoleText);

      const me = await getMe();
      const isSupervisorByIds = (me?.person_id && thesis?.supervisor_id && me.person_id === thesis.supervisor_id);
      const isSupervisor = myRole === 'supervisor' || looksSupervisorFromHeader || isSupervisorByIds;

      const gradingEnabled = !!thesis.grading_enabled_at;

      const canSetUnderReview     = (status === 'active');
      const showCancelUnderAssign = isSupervisor && (status === 'under_assignment');
      const showCancelGeneral     = (status === 'active');

      const statusId   = `statusLabel-${tid}`;
      const resDraftId = `resDrafts-${tid}`;
      const resLinksId = `resLinks-${tid}`;
      const notesBoxId = `notesBox-${tid}`;
      const notesFormId= `notesForm-${tid}`;
      const gradesBoxId= `gradesBox-${tid}`;

      const actionsHtml = `
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem">
          ${canSetUnderReview
            ? `<button class="btn btn-sm setUnderReview" data-thesis="${esc(tid)}">Μετάβαση σε «Υπό εξέταση»</button>`
            : ``}
          ${showCancelUnderAssign
            ? `<button class="btn btn-sm danger cancelUnderAssign" data-thesis="${esc(tid)}">Ακύρωση ανάθεσης</button>`
            : ``}
          ${showCancelGeneral
            ? `<button class="btn btn-sm danger cancelAssignment" data-thesis="${esc(tid)}">Ακύρωση ανάθεσης</button>`
            : ``}
          ${ (status === 'under_review' && !gradingEnabled && isSupervisor)
              ? `<button class="btn btn-sm enableGrading" data-thesis="${esc(tid)}">Ενεργοποίηση βαθμολόγησης</button>`
              : `` }
          <button class="btn btn-sm outline genAnnouncement" data-thesis="${esc(tid)}">Παραγωγή ανακοίνωσης</button>
          <button class="btn btn-sm secondary refreshResources" data-thesis="${esc(tid)}">Ανανέωση πόρων</button>
        </div>
      `;

      const presBoxId = `presBox-${tid}`;

      det.innerHTML = `
        <div class="card" style="padding:.75rem">
          <div><b>Φοιτητής:</b> ${esc(thesis.student_name || '—')} ${thesis.student_number ? `(${esc(thesis.student_number)})` : ''}</div>
          <div><b>Επιβλέπων:</b> ${esc(thesis.supervisor_name || '—')}</div>
          <div style="margin:.5rem 0"><b>Μέλη τριμελούς:</b>
            <ul style="margin:.25rem 0 0 1rem">
              ${
                committee.length
                  ? committee.map(c => `<li>${esc((c && c.name) || '—')}${c && c.role_in_committee ? ' — ' + esc(c.role_in_committee) : ''}</li>`).join('')
                  : '<li>—</li>'
              }
            </ul>
          </div>
          <div class="muted" id="${statusId}">Κατάσταση: ${esc(thesis.status || '—')}</div>
          ${actionsHtml}

          <!-- Resources -->
          <div style="margin-top:1rem;display:grid;gap:.6rem">
            <section class="card" style="padding:.75rem">
              <h4 style="margin:.2rem 0">Πρόχειρο κείμενο (Draft)</h4>
              <div id="${resDraftId}">Φόρτωση...</div>
            </section>
            <section class="card" style="padding:.75rem">
              <h4 style="margin:.2rem 0">Σύνδεσμοι/Πόροι</h4>
              <div id="${resLinksId}">Φόρτωση...</div>
            </section>
          </div>

          <!-- Presentation -->
          <section class="card" style="padding:.75rem;margin-top:1rem">
            <h4 style="margin:.2rem 0">Παρουσίαση</h4>
            <div id="${presBoxId}" class="muted">Φόρτωση...</div>
          </section>

          <!-- Notes -->
          <section class="card" style="padding:.75rem;margin-top:1rem">
            <h4 style="margin:.2rem 0">Σημειώσεις (ιδιωτικές)</h4>
            <form id="${notesFormId}" data-thesis="${esc(tid)}" style="display:grid;gap:.5rem;max-width:680px">
              <textarea name="text" maxlength="300" rows="3" placeholder="Έως 300 χαρακτήρες"></textarea>
              <div style="display:flex;gap:.5rem;align-items:center">
                <button class="btn btn-sm addNote">Προσθήκη σημείωσης</button>
                <small class="muted">Μόνο ο δημιουργός βλέπει τις σημειώσεις του.</small>
              </div>
            </form>
            <div id="${notesBoxId}" style="margin-top:.5rem">—</div>
          </section>

                    <!-- Grades -->
          <section class="card" style="padding:.75rem;margin-top:1rem">
            <h4 style="margin:.2rem 0">Καταχώρηση Βαθμού</h4>
            <form class="gradeForm" data-thesis="${esc(tid)}" style="display:grid;gap:.5rem;max-width:400px">
              <div class="row" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <label>Στόχοι
                  <input type="number" step="0.1" min="0" max="10" name="crit_goals">
                </label>
                <label>Διάρκεια
                  <input type="number" step="0.1" min="0" max="10" name="crit_duration">
                </label>
                <label>Κείμενο
                  <input type="number" step="0.1" min="0" max="10" name="crit_text">
                </label>
                <label>Παρουσίαση
                  <input type="number" step="0.1" min="0" max="10" name="crit_presentation">
                </label>
              </div>

              <label>Συνολικός (προαιρετικά)
                <input type="number" step="0.1" min="0" max="10" name="total">
              </label>

              <label>Σημειώσεις (προαιρετικό)
                <textarea name="notes" rows="3" maxlength="500" placeholder="π.χ. παρατηρήσεις προς το πρακτικό"></textarea>
              </label>

              <div style="display:flex;gap:.5rem;align-items:center">
                <button class="btn btn-sm">Αποθήκευση</button>
                <small class="muted saveMsg"></small>
              </div>
            </form>
          </section>

      `;
      
// Submit της inline φόρμας βαθμού
const gradeFormEl = det.querySelector('form.gradeForm');
if (gradeFormEl) {
  gradeFormEl.addEventListener('submit', async (e) => {
    e.preventDefault();
    const saveMsg = gradeFormEl.querySelector('.saveMsg');
    if (saveMsg) saveMsg.textContent = 'Αποθήκευση...';

    const thesis_id = gradeFormEl.dataset.thesis ||
                      det.closest('article[data-thesis]')?.dataset.thesis;
    if (!thesis_id) {
      alert('Δεν βρέθηκε διπλωματική (thesis_id).');
      if (saveMsg) saveMsg.textContent = 'Σφάλμα';
      return;
    }

    const fd = new FormData(gradeFormEl);
    fd.append('thesis_id', thesis_id);

    // Φτιάξε criteria_scores_json από τα πεδία των κριτηρίων
    const crit = {};
    ['crit_goals','crit_duration','crit_text','crit_presentation'].forEach(k => {
      const v = parseFloat(fd.get(k));
      if (!Number.isNaN(v)) crit[k.replace('crit_','')] = v;
      fd.delete(k); // καθάρισε τα raw πεδία
    });
    if (Object.keys(crit).length) {
      fd.append('criteria_scores_json', JSON.stringify(crit));
    }

    // Αν total είναι κενό, μην το στείλεις
    const total = String(fd.get('total') ?? '').trim();
    if (total === '') fd.delete('total');

    // Προαιρετικό rubric_id από global
    if (window.ACTIVE_RUBRIC_ID) fd.append('rubric_id', window.ACTIVE_RUBRIC_ID);

    try {
      const res = await fetch(`${BASE}/api/grades/upsert.php`, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });
      const j = await res.json().catch(()=>null);
      if (!j || j.ok !== true) throw new Error(j?.error || 'Αποτυχία');

      if (saveMsg) saveMsg.textContent = 'Καταχωρήθηκε ✔';
      gradeFormEl.reset();
      // Ανανέωση λίστας/καρτέλας (αν έχεις κουμπί ανανέωσης)
      document.getElementById('btnThesisReload')?.click();
    } catch (err) {
      if (saveMsg) saveMsg.textContent = err.message || 'Σφάλμα.';
      alert(err.message || 'Σφάλμα.');
    }
  });
}


      // --- Grades UI ---
      const gradesBox = det.querySelector('#' + gradesBoxId);
      if (gradesBox) {
        if (status !== 'under_review') {
          gradesBox.textContent = 'Η βαθμολόγηση είναι διαθέσιμη μόνο όταν η διπλωματική είναι «Υπό εξέταση».';
        } else if (!gradingEnabled) {
          if (isSupervisor) {
            gradesBox.innerHTML = `
              <div class="muted">Η βαθμολόγηση δεν είναι ενεργή. Ως επιβλέπων μπορείς να την ενεργοποιήσεις.</div>
              <div style="margin-top:.5rem">
                <button class="btn btn-sm enableGrading" data-thesis="${esc(tid)}">Ενεργοποίηση βαθμολόγησης</button>
              </div>
            `;
          } else {
            gradesBox.innerHTML = `<div class="muted">Η βαθμολόγηση δεν είναι ενεργή ακόμη. Θα ενεργοποιηθεί από τον επιβλέποντα.</div>`;
          }
        } else {
          // ενεργή → φόρμα βαθμού για όλους
          mountGradePanel(det, gradesBox, tid, isSupervisor);
        }
      }

      // Presentation
      const presBox = det.querySelector('#' + presBoxId);
      loadPresentation(tid, presBox);

      // Resources
      async function loadPresentation(thesisId, mountEl) {
        function presentModeLabel(mode) {
          if (mode === 'in_person') return 'Δια ζώσης';
          if (mode === 'online') return 'Εξ αποστάσεως';
          return mode || '—';
        }
        if (!mountEl) return;

        const toDateLabel = (val) => {
          if (!val) return '—';
          const d = new Date(String(val).replace(' ', 'T'));
          if (isNaN(d)) return String(val);
          const pad = (n) => String(n).padStart(2, '0');
          return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        };
        const modeLabel = (m) => {
          const s = String(m || '').toLowerCase();
          if (s.includes('online')) return 'Εξ αποστάσεως';
          if (s.includes('hybrid')) return 'Υβριδική';
          if (s.includes('person') || s.includes('ζώσης') || s.includes('ζωσης')) return 'Δια ζώσης';
          return m || '—';
        };

        try {
          mountEl.textContent = 'Φόρτωση...';

          const url = new URL(`${BASE}/api/presentation/get.php`, location.origin);
          url.searchParams.set('thesis_id', thesisId);

          const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
          });

          const txt = await res.text();
          let j = null;
          try { j = txt ? JSON.parse(txt) : null; } catch { j = null; }

          if (!res.ok || !j || j.ok !== true) {
            mountEl.innerHTML = '<em>Δεν έχει οριστεί παρουσίαση ακόμη.</em>';
            return;
          }

          const raw = j.data || j.presentation || j;
          const item = Array.isArray(raw?.items) ? raw.items[0] : raw;

          if (!item || (!item.when_dt && !item.mode && !item.room_or_link)) {
            mountEl.innerHTML = '<em>Δεν έχει οριστεί παρουσίαση ακόμη.</em>';
            return;
          }

          const when  = item.when_dt    ?? item.when    ?? item.date_time ?? '';
          const mode  = item.mode       ?? item.kind    ?? '';
          const place = item.room_or_link ?? item.place ?? item.location ?? '';

          const whenOut  = toDateLabel(when);
          const modeOut  = modeLabel(mode);
          const placeOut = place
            ? (String(place).startsWith('http')
                ? `<a href="${esc(place)}" target="_blank" rel="noopener">${esc(place)}</a>`
                : esc(place))
            : '—';

          mountEl.classList.remove('muted');
          mountEl.innerHTML = `
            <div style="display:grid;gap:.25rem">
              <div><b>Ημ/νία & ώρα:</b> ${esc(whenOut)}</div>
              <div><b>Τρόπος:</b> ${esc(modeOut)}</div>
              <div><b>Αίθουσα/Σύνδεσμος:</b> ${placeOut}</div>
            </div>
          `;
        } catch (err) {
          console.error('[loadPresentation]', err);
          mountEl.innerHTML = '<span style="color:#ef4444">Σφάλμα φόρτωσης παρουσίασης.</span>';
        }
      }

      async function renderResources() {
        const draftBox = det.querySelector('#' + resDraftId);
        const linkBox  = det.querySelector('#' + resLinksId);
        if (draftBox) draftBox.textContent = 'Φόρτωση...';
        if (linkBox)  linkBox.textContent  = 'Φόρτωση...';

        const pickUrl   = (r) => r?.url ?? r?.url_or_path ?? r?.path ?? '';
        const pickWhen  = (r) => r?.created_at || r?.uploaded_at || '';
        const toHref    = (u) => (u && u.startsWith('/uploads/')) ? `${BASE}${u}` : u;
        const pickName  = (r) => {
          if (r?.filename) return String(r.filename);
          const u = pickUrl(r);
          if (!u) return 'resource';
          try { return decodeURIComponent(u.split('/').pop() || 'resource'); }
          catch { return u.split('/').pop() || 'resource'; }
        };

        try {
          const [draftsRes, linksRes] = await Promise.all([
            fetchResources(tid, 'draft'),
            fetchResources(tid, 'link'),
          ]);

          const draftItems = (draftsRes?.data?.items ?? draftsRes?.items ?? []);
          const linkItems  = (linksRes?.data?.items  ?? linksRes?.items  ?? []);

          if (draftBox) {
            if (!draftItems.length) {
              draftBox.innerHTML = '<em>Δεν έχει ανέβει draft ακόμη.</em>';
            } else {
              draftBox.innerHTML = draftItems.map((d) => {
                const url  = pickUrl(d);
                const href = toHref(url);
                const name = pickName(d);
                const when = pickWhen(d);
                return `
                  <div style="margin:.25rem 0">
                    ${href ? `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(name)}</a>` : esc(name)}
                    ${when ? `<small class="muted"> · ${esc(when)}</small>` : ''}
                  </div>
                `;
              }).join('');
            }
          }

          if (linkBox) {
            if (!linkItems.length) {
              linkBox.innerHTML = '<em>Δεν υπάρχουν σύνδεσμοι ακόμη.</em>';
            } else {
              linkBox.innerHTML = linkItems.map((l) => {
                const url   = pickUrl(l);
                const href  = toHref(url);
                const title = l?.title || url || 'link';
                const when  = pickWhen(l);
                return `
                  <div style="margin:.25rem 0">
                    ${href ? `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(title)}</a>` : esc(title)}
                    ${when ? `<small class="muted"> · ${esc(when)}</small>` : ''}
                  </div>
                `;
              }).join('');
            }
          }
        } catch (err) {
          console.error('renderResources failed:', err);
          if (draftBox) draftBox.textContent = 'Σφάλμα.';
          if (linkBox)  linkBox.textContent  = 'Σφάλμα.';
        }
      }
      renderResources();

      // --- Handlers ---
      det.addEventListener('click', async (ev) => {
        const tbtn = ev.target.closest('button'); if (!tbtn) return;
        const thesis_id = tbtn.dataset.thesis || tid;

        // Υπό εξέταση
        if (tbtn.classList.contains('setUnderReview')) {
          if (!confirm('Να αλλάξει η κατάσταση σε «Υπό εξέταση»;')) return;
          try {
            await postForm(`${BASE}/api/theses/set_under_review.php`, { thesis_id });
            const b = det.querySelector('.setUnderReview'); if (b) b.remove();
            const s = det.querySelector('#' + statusId);    if (s) s.textContent = 'Κατάσταση: Υπό εξέταση';
            alert('Η κατάσταση άλλαξε σε «Υπό εξέταση».');
            document.getElementById('btnThesisReload')?.click();
          } catch (e) { alert(e.message || 'Σφάλμα.'); }
          return;
        }

        // Ακύρωση για under_assignment
        if (tbtn.classList.contains('cancelUnderAssign')) {
          if (!confirm('Να ακυρωθεί η ανάθεση; Θα διαγραφούν τυχόν προσκλήσεις και αναθέσεις συμμετοχής σε τριμελείς.')) return;
          try {
            await postForm(`${BASE}/api/theses/cancel_under_assignment.php`, { thesis_id });
            const b = det.querySelector('.cancelUnderAssign'); if (b) b.remove();
            const s = det.querySelector('#' + statusId);      if (s) s.textContent = 'Κατάσταση: canceled';
            alert('Η ανάθεση ακυρώθηκε.');
            document.getElementById('btnThesisReload')?.click();
            if (typeof loadTeacherTopics === 'function') loadTeacherTopics();
          } catch (e) { alert(e.message || 'Σφάλμα.'); }
          return;
        }

        // Ακύρωση για active
        if (tbtn.classList.contains('cancelAssignment')) {
          const council_number = prompt('Αριθμός Γ.Σ.:'); if (council_number === null) return;
          const council_year   = prompt('Έτος Γ.Σ.:'   ); if (council_year   === null) return;
          if (!confirm('Επιβεβαίωση ακύρωσης ανάθεσης;')) return;
          try {
            await postForm(`${BASE}/api/theses/cancel.php`, { thesis_id, council_number, council_year });
            const s = det.querySelector('#' + statusId); if (s) s.textContent = 'Κατάσταση: canceled';
            const b = det.querySelector('.cancelAssignment'); if (b) b.remove();
            alert('Η ανάθεση ακυρώθηκε.');
            document.getElementById('btnThesisReload')?.click();
            if (typeof loadTeacherTopics === 'function') loadTeacherTopics();
          } catch (e) { alert(e.message || 'Σφάλμα.'); }
          return;
        }

        // Ενεργοποίηση βαθμολόγησης (μόνο supervisor το βλέπει, ο server ξανα-ελέγχει)
        if (tbtn.classList.contains('enableGrading')) {
          try {
            await postForm(`${BASE}/api/grades/enable.php`, { thesis_id, enabled: 1 });
            const g = det.querySelector('#' + `${gradesBoxId}`);
            if (g) {
              mountGradePanel(det, g, tid, true); // μετά το enable, δείξε απευθείας τη φόρμα
            }
            alert('Η βαθμολόγηση ενεργοποιήθηκε.');
          } catch (e) {
            alert(e.message || 'Σφάλμα.');
          }
          return;
        }

        // --- Άνοιγμα φόρμας βαθμού & prefill αν υπάρχει ---
        if (tbtn.classList.contains('openGradeForm')) {
          const wrap = det.querySelector('.gradeForm');
          const form = det.querySelector('.gradeUpsertF');
          if (!wrap || !form) return;
          wrap.style.display = 'block';
          try {
            const g = await fetchMyGrade(thesis_id);
            if (g) {
              form.querySelector('[name="total"]').value = g.total ?? '';
              const notesEl = form.querySelector('[name="notes"]');
              if (notesEl) notesEl.value = g.notes ?? '';
            }
          } catch {}
          return;
        }

        // --- Άκυρο φόρμας ---
        if (tbtn.classList.contains('cancelGrade')) {
          const wrap = det.querySelector('.gradeForm');
          if (wrap) wrap.style.display = 'none';
          return;
        }

        if (tbtn.classList.contains('genAnnouncement')) {
          try {
            const rsp = await postForm(`${BASE}/api/presentation/announcement_preview.php`, { thesis_id });
            const html = (rsp.data?.html ?? rsp.html ?? '');
            if (!html) { alert('Δεν εστάλη HTML ανακοίνωσης.'); return; }
            const w = window.open('', '_blank'); w.document.write(html); w.document.close();
          } catch (e) { alert(e.message || 'Σφάλμα.'); }
          return;
        }

        if (tbtn.classList.contains('refreshResources')) {
          renderResources(); return;
        }
      });

      // Notes
      async function loadNotes() {
        const nb = det.querySelector('#' + `${notesBoxId}`);
        if (!nb) return;
        nb.textContent = 'Φόρτωση...';
        try {
          const u = new URL(`${BASE}/api/notes/list.php`, location.origin);
          u.searchParams.set('thesis_id', tid);
          const res = await fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const jj = await safeJson(res);
          if (!jj || jj.ok !== true) throw new Error(jj?.error || 'Σφάλμα');
          const rows = (jj.data?.items ?? jj.items ?? []);
          if (!rows.length) { nb.innerHTML = '<em>Δεν υπάρχουν σημειώσεις ακόμη.</em>'; return; }
          nb.innerHTML = rows.map(n => `
            <div class="card" style="padding:.5rem;margin:.35rem 0">
              <div style="white-space:pre-wrap">${esc(n.text || '')}</div>
              <small class="muted">${esc(n.created_at || '')}</small>
            </div>
          `).join('');
        } catch {
          nb.textContent = 'Σφάλμα φόρτωσης σημειώσεων.';
        }
      }
      const nf = det.querySelector('#' + `${notesFormId}`);
      if (nf) {
        nf.addEventListener('submit', async (e) => {
          e.preventDefault();
          const text = nf.querySelector('textarea[name="text"]')?.value?.trim() || '';
          if (!text) return;
          if (text.length > 300) { alert('Μέγιστο 300 χαρακτήρες.'); return; }
          try {
            await postForm(`${BASE}/api/notes/create.php`, { thesis_id: tid, text });
            nf.reset();
            loadNotes();
          } catch (err) {
            alert(err.message || 'Σφάλμα.');
          }
        });
        loadNotes();
      }

    } catch (err) {
      console.error('[details] fetch threw', err);
      det.innerHTML = `<span style="color:#ef4444">Σφάλμα φόρτωσης: ${esc(err?.message || String(err))}</span>`;
    }
  });
})();

/* =========================
   Αποδοχή / Απόρριψη Προσκλήσεων
   ========================= */
(function bindInvitationActions(){
  if (window.__invActionsBound) return;
  window.__invActionsBound = true;

  document.addEventListener('click', async (e) => {
    const acc = e.target.closest('.acceptInv');
    const rej = e.target.closest('.rejectInv');
    if (!acc && !rej) return;

    const art = e.target.closest('article[data-inv]');
    if (!art) return;
    const id = art.dataset.inv;
    if (!id) return;

    if (rej && !confirm('Σίγουρα θέλεις να απορρίψεις;')) return;

    try {
      const fd = new FormData();
      fd.append('invitation_id', id);
      fd.append('action', acc ? 'accept' : 'decline');

      const res = await fetch(`${API_COMMITTEE}/respond.php`, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const j = await safeJson(res);
      if (!res.ok || !j || j.ok !== true) {
        alert((j && j.error) || `HTTP ${res.status}`);
        return;
      }

      // UI update
      art.remove();
      const box = document.getElementById('invitationsList');
      if (box && !box.querySelector('article[data-inv]')) {
        box.textContent = 'Δεν υπάρχουν προσκλήσεις.';
      }
      if (acc) loadThesesList();

    } catch (err) {
      console.error(err);
      alert('Σφάλμα δικτύου.');
    }
  });
})();

/* =========================
   Εξαγωγές (CSV / JSON) της λίστας διπλωματικών
   ========================= */
(function bindThesesExports(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindThesesExports);
    return;
  }
  if (window.__thesesExportsBound) return;
  window.__thesesExportsBound = true;

  const btnCsv  = document.getElementById('exportCsv');
  const btnJson = document.getElementById('exportJson');

  if (btnCsv) {
    btnCsv.addEventListener('click', async () => {
      const data = await currentThesesData();
      downloadCSV(data, 'theses.csv');
    });
  }

  if (btnJson) {
    btnJson.addEventListener('click', async () => {
      const data = await currentThesesData();
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'theses.json';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }
})();

async function currentThesesData(){
  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');
  const role   = roleSel?.value || '';
  const status = statusSel?.value || '';
  const url = new URL(`${API}/theses.php`, window.location.origin);
  url.searchParams.set('action', 'list');
  if (role)   url.searchParams.set('role', role);
  if (status) url.searchParams.set('status', status);
  try {
    const res = await fetch(url, { credentials:'same-origin', headers:{ 'Accept':'application/json' } });
    const j = await safeJson(res);
    return (j && j.ok) ? (j.data || []) : [];
  } catch {
    return [];
  }
}

function downloadCSV(rows, filename) {
  if (!rows?.length) { alert('Δεν υπάρχουν δεδομένα.'); return; }
  const cols = ['id','title','topic_title','status','student_name','student_number','my_role','created_at','updated_at'];
  const lines = [
    cols.join(','),
    ...rows.map(r => cols.map(k => `"${String(r[k] ?? '').replace(/"/g,'""')}"`).join(',')),
  ];
  const blob = new Blob([lines.join('\n')], { type:'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* =========================
   Αρχικό Load + Φίλτρα/Ανανέωση
   ========================= */
document.addEventListener('DOMContentLoaded', () => {
  loadTeacherTopics();     // αν έχεις card "Τα θέματα μου"
  loadThesesList();
  loadInvitations();
});

(function bindThesesFiltersReload(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindThesesFiltersReload);
    return;
  }
  if (window.__thesesFiltersBound) return;
  window.__thesesFiltersBound = true;

  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');
  const reloadBtn = document.getElementById('btnThesisReload');

  roleSel?.addEventListener('change', () => loadThesesList());
  statusSel?.addEventListener('change', () => loadThesesList());
  reloadBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    loadThesesList();
    loadInvitations();
  });
})();
