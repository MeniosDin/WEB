import { apiGet } from './auth.js';
export async function loadTeacherTopics(){
  const box = document.getElementById('topics');
  if (!box) return;

  const res = await fetch('/THESIS_WEB/api/prof/topics.php?action=list');
  const j = await res.json();
  if (!j.ok) { 
    box.textContent = 'Σφάλμα'; 
    return; 
  }

  const items = j.data || [];
  box.innerHTML = items.map(t => {
    // Διόρθωση URL για PDF
    let pdfHref = t.spec_pdf_path || null;
    if (pdfHref && pdfHref.startsWith('/uploads/')) {
      pdfHref = '/THESIS_WEB/public' + pdfHref;
    }

    // Φοιτητής αν έχει προσωρινή ανάθεση
    let studentInfo = '';
    if (t.provisional_student_id) {
      studentInfo = `
        <div style="margin-top:.5rem; font-size:.9rem; color:#9ca3af">
          <b>Προσωρινή ανάθεση σε:</b> ${t.provisional_student_name || ''} 
          (${t.provisional_student_number || ''})
          <button class="btn btn-sm danger unassignStudent" style="margin-left:.5rem">
            Αφαίρεση
          </button>
        </div>`;
    } else {
      studentInfo = `
        <div style="margin-top:.5rem; font-size:.9rem;">
          <button class="btn btn-sm assignStudent">Ανάθεση σε φοιτητή</button>
        </div>`;
    }

    return `
      <article class="card" data-id="${t.id}" style="margin:.5rem 0;padding:1rem">
        <h4 style="margin:0 0 .5rem 0">${t.title}</h4>
        <p style="margin:.25rem 0 .75rem 0">${t.summary || ''}</p>

        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          ${pdfHref ? `<a href="${pdfHref}" target="_blank" rel="noopener">Προδιαγραφή (PDF)</a>` : '<span style="opacity:.7">— κανένα PDF —</span>'}
          <button class="btn btn-sm outline editPdf">Επεξεργασία PDF</button>

          <label style="margin-left:auto;display:flex;gap:.35rem;align-items:center">
            <input type="checkbox" class="toggleAvail" ${t.is_available ? 'checked':''}>
            Διαθέσιμο
          </label>
          <button class="btn btn-sm editTopic">Επεξεργασία</button>
          <button class="btn btn-sm danger deleteTopic">Διαγραφή</button>
        </div>

        ${studentInfo}

        <!-- Inline PDF controls -->
        <div class="pdfControls" style="display:none; margin-top:.75rem; padding:.75rem; border:1px dashed #374151; border-radius:8px;">
          <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap">
            <input type="file" class="pdfFile" accept="application/pdf">
            <button class="btn btn-sm saveNewPdf">Αποθήκευση νέου PDF</button>
            ${t.spec_pdf_path ? `<button class="btn btn-sm danger removePdf">Διαγραφή PDF</button>` : ''}
            <button class="btn btn-sm secondary cancelPdf">Άκυρο</button>
            <small class="pdfHint" style="opacity:.75">Μόνο PDF έως 10MB.</small>
          </div>
        </div>
      </article>
    `;
  }).join('');
}

async function loadThesesList() {
  const box = document.getElementById('thesesList');
  if (!box) return;

  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');

  const role = roleSel?.value || '';
  const status = statusSel?.value || '';

  const url = new URL('/THESIS_WEB/api/prof/theses.php', window.location.origin);
  url.searchParams.set('action', 'list');
  if (role)   url.searchParams.set('role', role);
  if (status) url.searchParams.set('status', status);

  box.textContent = 'Φόρτωση...';
  const res = await fetch(url.toString());
  const j = await res.json();
  if (!j.ok) { box.textContent = j.error || 'Σφάλμα'; return; }

  const items = j.data || [];
  if (!items.length) { box.textContent = 'Δεν βρέθηκαν αποτελέσματα.'; return; }

  box.innerHTML = items.map(t => `
    <article class="card" data-thesis="${t.id}" style="margin:.5rem 0; padding:1rem">
      <div style="display:flex; gap:1rem; align-items:baseline; flex-wrap:wrap">
        <h4 style="margin:0">${t.title}</h4>
        <span style="opacity:.8">(${t.my_role === 'supervisor' ? 'Επιβλέπων' : 'Μέλος τριμελούς'})</span>
        <span style="margin-left:auto; opacity:.8">Κατάσταση: ${t.status}</span>
      </div>
      <div style="margin:.5rem 0; opacity:.9">
        Φοιτητής: ${t.student_name ?? '—'} ${t.student_number ? `(${t.student_number})` : ''}
      </div>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap">
        <button class="btn btn-sm seeDetails">Λεπτομέρειες</button>
        <!-- εδώ αργότερα κουμπιά ενεργειών ανά ρόλο/κατάσταση -->
      </div>
      <div class="thesisDetails" style="display:none; margin-top:.75rem"></div>
    </article>
  `).join('');
}

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("logoutBtn");
  if (btn) {
    btn.addEventListener("click", async () => {
      await fetch("/THESIS_WEB/api/auth/logout.php");
      window.location.href = "/THESIS_WEB/public/login.html";
    });
  }
});

// === Create New Topic (με προαιρετικό PDF) ===
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('newTopicForm');
  const fileInput = document.getElementById('spec_pdf');

  // προαιρετικός έλεγχος αρχείου (τύπος/μέγεθος)
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files?.[0];
      if (!f) return;
      if (f.type !== 'application/pdf') {
        alert('Μόνο PDF επιτρέπεται.');
        fileInput.value = '';
        return;
      }
      const max = 10 * 1024 * 1024; // 10MB
      if (f.size > max) {
        alert('Το αρχείο ξεπερνά τα 10MB.');
        fileInput.value = '';
      }
    });
}

  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    try {
      // Μαζεύουμε τα δεδομένα της φόρμας
      const fd = new FormData(form);
      fd.append('action', 'create');

      // Αν τα inputs δεν έχουν name, φροντίζουμε εδώ:
      const titleEl = form.querySelector('#title,[name="title"]');
      const summaryEl = form.querySelector('#summary,[name="summary"]');
      if (!fd.get('title') && titleEl) fd.set('title', titleEl.value.trim());
      if (!fd.get('summary') && summaryEl) fd.set('summary', summaryEl.value.trim());

      // Αν δεν έχεις checkbox για διαθεσιμότητα, δώσε default 1 (διαθέσιμο)
      if (!fd.get('is_available')) fd.append('is_available', '1');

      // Αν για κάποιο λόγο λείπει name στο file input, πρόσθεσέ το χειροκίνητα
      if (fileInput && !fd.get('spec_pdf') && fileInput.files?.[0]) {
        fd.set('spec_pdf', fileInput.files[0]);
      }

      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method: 'POST', body: fd });
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Σφάλμα καταχώρισης.');

      // καθάρισμα + ανανέωση
      form.reset();
      if (typeof loadTeacherTopics === 'function') await loadTeacherTopics();
      alert('Το θέμα καταχωρήθηκε.');
    } catch (err) {
      alert(err.message || 'Κάτι πήγε στραβά.');
    }
  });
});

// === Ενέργειες στη λίστα θεμάτων (edit / delete / toggle) ===
(function bindTopicActions(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindTopicActions);
    return;
  }
  const box = document.getElementById('topics');
  if (!box) return;

  // Clicks (edit / delete)
  box.addEventListener('click', async (e) => {
    const art = e.target.closest('article[data-id]');
    if (!art) return;
    const id = art.dataset.id;

    // Διαγραφή
    if (e.target.classList.contains('deleteTopic')) {
      if (!confirm('Σίγουρα θέλεις να διαγράψεις αυτό το θέμα;')) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', id);
      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) { alert(j.error || 'Αποτυχία διαγραφής'); return; }
      await loadTeacherTopics();
      return;
    }

    // Επεξεργασία (γρήγορα με prompt — μετά το κάνουμε modal)
    if (e.target.classList.contains('editTopic')) {
      const curTitle = art.querySelector('h4')?.textContent.trim() || '';
      const curSummary = art.querySelector('p')?.textContent.trim() || '';
      const newTitle = prompt('Νέος τίτλος:', curTitle);
      if (newTitle === null) return;
      const newSummary = prompt('Νέα περιγραφή:', curSummary ?? '');
      if (newSummary === null) return;

      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('title', newTitle.trim());
      fd.append('summary', newSummary.trim());
      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) { alert(j.error || 'Αποτυχία ενημέρωσης'); return; }
      await loadTeacherTopics();
      return;
    }

        // Ανάθεση σε φοιτητή
    if (e.target.classList.contains('assignStudent')) {
      const am = prompt('Δώσε ΑΜ ή όνομα φοιτητή:');
      if (!am) return;

      const fd = new FormData();
      fd.append('action', 'assign_student');
      fd.append('id', id);
      fd.append('student_query', am);

      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) { alert(j.error || 'Αποτυχία ανάθεσης'); return; }

      await loadTeacherTopics();
      return;
    }

    // Αφαίρεση φοιτητή
    if (e.target.classList.contains('unassignStudent')) {
      if (!confirm('Να αφαιρεθεί η προσωρινή ανάθεση;')) return;

      const fd = new FormData();
      fd.append('action', 'unassign_student');
      fd.append('id', id);

      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) { alert(j.error || 'Αποτυχία αφαίρεσης'); return; }

      await loadTeacherTopics();
      return;
    }
  });

  // Αλλαγή διαθέσιμου (toggle)
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

    const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
    const j = await res.json();
    if (!j.ok) {
      alert(j.error || 'Αποτυχία ενημέρωσης');
      e.target.checked = !e.target.checked; // rollback αν αποτύχει
    }
  });
})();

// details toggle
(function bindThesesUI(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindThesesUI);
    return;
  }
  const list = document.getElementById('thesesList');
  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');
  const reloadBtn = document.getElementById('btnThesisReload');

  roleSel?.addEventListener('change', loadThesesList);
  statusSel?.addEventListener('change', loadThesesList);
  reloadBtn?.addEventListener('click', loadThesesList);

  // export
  document.getElementById('exportCsv')?.addEventListener('click', async () => {
    const data = await currentThesesData();
    downloadCSV(data, 'theses.csv');
  });
  document.getElementById('exportJson')?.addEventListener('click', async () => {
    const data = await currentThesesData();
    const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'theses.json';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // click for details
  list?.addEventListener('click', async (e) => {
    const art = e.target.closest('article[data-thesis]');
    if (!art) return;
    if (!e.target.classList.contains('seeDetails')) return;

    const det = art.querySelector('.thesisDetails');
    if (!det) return;

    if (det.style.display === 'block') { det.style.display = 'none'; return; }

    // fetch details
    const id = art.dataset.thesis;
    const url = new URL('/THESIS_WEB/api/prof/theses.php', window.location.origin);
    url.searchParams.set('action','details');
    url.searchParams.set('id', id);
    det.textContent = 'Φόρτωση...';
    const res = await fetch(url);
    const j = await res.json();
    if (!j.ok) { det.textContent = j.error || 'Σφάλμα'; return; }

    const { thesis, committee } = j.data;
    det.innerHTML = `
      <div class="card" style="padding:.75rem">
        <div><b>Φοιτητής:</b> ${thesis.student_name ?? '—'} ${thesis.student_number ? `(${thesis.student_number})` : ''}</div>
        <div><b>Επιβλέπων:</b> ${thesis.supervisor_name ?? '—'}</div>
        <div style="margin:.5rem 0"><b>Μέλη τριμελούς:</b>
          <ul style="margin:.25rem 0 0 1rem">
            ${committee.map(c => `<li>${c.name} — ${c.role_in_committee ?? ''}</li>`).join('')}
          </ul>
        </div>
        <!-- εδώ αργότερα: χρονικό ιστορικό, βαθμοί, αρχεία, κ.λπ. -->
      </div>
    `;
    det.style.display = 'block';
  });
})();

// helper: φέρε τα τρέχοντα δεδομένα για export (χωρίς re-render)
async function currentThesesData(){
  const roleSel   = document.getElementById('thesisRole');
  const statusSel = document.getElementById('thesisStatus');
  const role = roleSel?.value || '';
  const status = statusSel?.value || '';
  const url = new URL('/THESIS_WEB/api/prof/theses.php', window.location.origin);
  url.searchParams.set('action', 'list');
  if (role)   url.searchParams.set('role', role);
  if (status) url.searchParams.set('status', status);
  const res = await fetch(url);
  const j = await res.json();
  return j.ok ? (j.data || []) : [];
}

// very small CSV builder
function downloadCSV(rows, filename) {
  if (!rows?.length) { alert('Δεν υπάρχουν δεδομένα.'); return; }
  const cols = ['id','title','status','student_name','student_number','my_role','created_at','updated_at'];
  const lines = [
    cols.join(','),
    ...rows.map(r => cols.map(k => `"${String(r[k] ?? '').replace(/"/g,'""')}"`).join(','))
  ];
  const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

// αρχικό load
document.addEventListener('DOMContentLoaded', loadThesesList);

// === PDF controls: toggle panel / upload νέο / διαγραφή υπάρχοντος ===
(function bindPdfControls(){
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindPdfControls);
    return;
  }
  const box = document.getElementById('topics');
  if (!box) return;

  // Άνοιγμα/κλείσιμο panel
  box.addEventListener('click', (e) => {
    const art = e.target.closest('article[data-id]');
    if (!art) return;

    // toggle
    if (e.target.classList.contains('editPdf')) {
      const panel = art.querySelector('.pdfControls');
      if (panel) panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
      return;
    }
    // cancel
    if (e.target.classList.contains('cancelPdf')) {
      const panel = art.querySelector('.pdfControls');
      if (panel) {
        const file = panel.querySelector('.pdfFile');
        if (file) file.value = '';
        panel.style.display = 'none';
      }
      return;
    }
  });

  // Έλεγχος αρχείου κατά την επιλογή
  box.addEventListener('change', (e) => {
    if (!e.target.classList.contains('pdfFile')) return;
    const fileInput = e.target;
    const hint = fileInput.closest('.pdfControls')?.querySelector('.pdfHint');
    const f = fileInput.files?.[0];
    if (!f) { if (hint) hint.textContent = 'Μόνο PDF έως 10MB.'; return; }
    if (f.type !== 'application/pdf') {
      alert('Μόνο PDF επιτρέπεται.');
      fileInput.value = '';
      if (hint) hint.textContent = 'Μόνο PDF έως 10MB.';
      return;
    }
    const max = 10 * 1024 * 1024;
    if (f.size > max) {
      alert('Το αρχείο ξεπερνά τα 10MB.');
      fileInput.value = '';
      if (hint) hint.textContent = 'Μόνο PDF έως 10MB.';
      return;
    }
    if (hint) hint.textContent = `Επιλέχθηκε: ${f.name} (${(f.size/1024/1024).toFixed(2)}MB)`;
  });

  // Αποθήκευση νέου PDF
  box.addEventListener('click', async (e) => {
    if (!e.target.classList.contains('saveNewPdf')) return;
    const art = e.target.closest('article[data-id]');
    if (!art) return;
    const id = art.dataset.id;
    const panel = art.querySelector('.pdfControls');
    const fileInput = panel?.querySelector('.pdfFile');
    const f = fileInput?.files?.[0];
    if (!f) { alert('Επίλεξε PDF πρώτα.'); return; }

    try {
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('spec_pdf', f);

      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Αποτυχία αποθήκευσης PDF');

      await loadTeacherTopics();
      alert('Το PDF ενημερώθηκε.');
    } catch(err) {
      alert(err.message || 'Κάτι πήγε στραβά.');
    }
  });

  // Διαγραφή υπάρχοντος PDF
  box.addEventListener('click', async (e) => {
    if (!e.target.classList.contains('removePdf')) return;
    const art = e.target.closest('article[data-id]');
    if (!art) return;
    const id = art.dataset.id;
    if (!confirm('Να αφαιρεθεί το PDF από αυτό το θέμα;')) return;

    try {
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', id);
      fd.append('remove_pdf', '1');

      const res = await fetch('/THESIS_WEB/api/prof/topics.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Αποτυχία διαγραφής PDF');

      await loadTeacherTopics();
      alert('Το PDF αφαιρέθηκε.');
    } catch(err) {
      alert(err.message || 'Κάτι πήγε στραβά.');
    }
  });
})


();
