// ===================== CONFIG =====================
var BASE = '/thesis-web';
var API  = BASE + '/api/secretariat';
var $    = function(s){ return document.querySelector(s); };

// ===================== helpers =====================
function esc(s){ s = String(s || ''); return s.replace(/[&<>\"']/g, function(c){
  return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
});}
function fmt(d){ return d ? new Date(d).toLocaleString() : '—'; }
function humanElapsed(iso){
  if(!iso) return '—';
  var ms = Date.now() - new Date(iso).getTime();
  var d  = Math.floor(ms/86400000); if (d>=30) return Math.floor(d/30)+' μήνες πριν';
  if (d>=1)  return d+' '+(d===1?'ημέρα':'ημέρες')+' πριν';
  var h  = Math.floor(ms/3600000);  if (h>=1) return h+' '+(h===1?'ώρα':'ώρες')+' πριν';
  return Math.max(1, Math.floor(ms/60000))+' λεπτά πριν';
}

function getJson(url){
  return fetch(url, { credentials:'include', headers:{ 'Accept':'application/json' } })
    .then(function(r){ return r.text().then(function(txt){ return { r:r, txt:txt }; }); })
    .then(function(pair){
      var r = pair.r, txt = pair.txt, j = null;
      try { j = txt ? JSON.parse(txt) : null; } catch(e){}
      if (!j) { console.error('Non-JSON response', r.status, txt); throw new Error('HTTP '+r.status); }
      if (j.ok !== true) { console.error('API error', j); throw new Error(j.error || ('HTTP '+r.status)); }
      return j;
    });
}
function postJson(url, body){
  return fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
    body: JSON.stringify(body || {})
  }).then(function(r){ return r.text().then(function(txt){ return { r:r, txt:txt }; }); })
    .then(function(pair){
      var r = pair.r, txt = pair.txt, j = null;
      try { j = txt ? JSON.parse(txt) : null; } catch(e){}
      if (!j) throw new Error('Non-JSON (HTTP '+r.status+')');
      if (!r.ok || j.ok !== true) throw new Error(j.error || ('HTTP '+r.status));
      return j;
    });
}

// ===================== 1) ΛΙΣΤΑ ΔΕ =====================
function loadTheses(){
  var box = $('#thesesList'); if (!box) return;
  box.textContent = 'Φόρτωση…';
  try{
    var u = new URL(API + '/theses_list.php', location.origin);
    var searchQ = $('#searchQ');
    if (searchQ && searchQ.value) u.searchParams.set('q', searchQ.value.trim());

    getJson(u).then(function(j){
      var items = j.data || [];
      if (!items.length) { box.textContent = 'Δεν βρέθηκαν διπλωματικές σε «Ενεργή» ή «Υπό εξέταση».'; return; }

      box.innerHTML = items.map(function(t){
        var title    = (t.topic && t.topic.title) || t.topic_title || t.title || '—';
        var status   = t.status || '—';
        var assigned = t.assigned_at ? (fmt(t.assigned_at)+' ('+humanElapsed(t.assigned_at)+')') : '—';
        var student  = (t.student && t.student.name) || t.student_name || '—';
        var snum     = (t.student && t.student.student_number) || t.student_number || '';

        return ''
          + '<article class="card" data-id="'+esc(t.id)+'" style="margin:.5rem 0;padding:1rem;border:1px solid #ddd;border-radius:10px">'
          + '  <div style="display:flex;gap:1rem;align-items:baseline;flex-wrap:wrap">'
          + '    <h4 style="margin:0">'+esc(title)+'</h4>'
          + '    <span class="muted">Κατάσταση: '+esc(status)+'</span>'
          + '    <button class="btn showDetails" style="margin-left:auto">Λεπτομέρειες</button>'
          + '  </div>'
          + '  <div class="muted">Φοιτητής/τρια: '+esc(student)+(snum?(' ('+esc(snum)+')'):'')+'</div>'
          + '  <div class="muted">Ανάθεση: '+assigned+'</div>'
          + '  <div class="details" style="display:none;margin-top:.6rem"></div>'
          + '</article>';
      }).join('');
    }).catch(function(e){
      console.error('loadTheses:', e);
      box.textContent = e.message || 'Σφάλμα κατά τη φόρτωση.';
    });
  }catch(e){
    console.error('loadTheses(synchronous):', e);
    box.textContent = e.message || 'Σφάλμα.';
  }
}
var btnReload = document.getElementById('btnReload');
if (btnReload) btnReload.addEventListener('click', loadTheses);
var btnSearch = document.getElementById('btnSearch');
if (btnSearch) btnSearch.addEventListener('click', loadTheses);
var inpSearch = document.getElementById('searchQ');
if (inpSearch) inpSearch.addEventListener('keydown', function(e){ if (e.key === 'Enter') loadTheses(); });

// Λεπτομέρειες (προαιρετικό — αν λείπει το endpoint δεν σπάει τίποτα)
document.addEventListener('click', function(e){
  var btn = e.target && e.target.closest ? e.target.closest('.showDetails') : null;
  if(!btn) return;
  var art = btn.closest('article[data-id]'); if(!art) return;
  var det = art.querySelector('.details'); det.style.display='block'; det.textContent='Φόρτωση…';

  try{
    var id = art.getAttribute('data-id');
    var u = new URL(API + '/theses.php', location.origin);
    u.searchParams.set('action','details'); u.searchParams.set('id', id);

    getJson(u).then(function(j){
      var th      = (j.data && j.data.thesis) || {};
      var topic   = (j.data && j.data.topic) || {};
      var members = (j.data && j.data.committee) || [];
      det.innerHTML = ''
        + '<div class="card" style="padding:.75rem;border:1px solid #eee;border-radius:10px">'
        +   '<div><b>Θέμα:</b> '+esc(topic.title||'—')+'</div>'
        +   (topic.summary ? ('<p style="white-space:pre-wrap">'+esc(topic.summary)+'</p>') : '')
        +   '<div><b>Κατάσταση:</b> '+esc(th.status||'—')+'</div>'
        +   '<div class="muted"><small>Ανάθεση: '+(th.assigned_at?fmt(th.assigned_at):'—')+' '+(th.assigned_at?('('+humanElapsed(th.assigned_at)+')'):'')+'</small></div>'
        +   '<h4 style="margin:.6rem 0 .2rem">Μέλη τριμελούς</h4>'
        +   (members.length
              ? members.map(function(m){
                  var nm = ((m.first_name||'')+' '+(m.last_name||'')).trim() || m.name || '—';
                  return '<div>• '+esc(nm)+' — '+(m.role_in_committee==='supervisor'?'Επιβλέπων/ουσα':'Μέλος')+'</div>';
                }).join('')
              : '<em>Δεν έχουν οριστεί ακόμη μέλη.</em>')
        +   (topic.pdf_path ? ('<div style="margin-top:.5rem"><a target="_blank" rel="noopener" href="'+esc(topic.pdf_path)+'">Αρχείο περιγραφής</a></div>') : '')
        + '</div>';
    }).catch(function(e){
      console.error('details:', e);
      det.textContent = e.message || 'Σφάλμα κατά τη φόρτωση λεπτομερειών.';
    });
  }catch(e){
    console.error('details(synchronous):', e);
    det.textContent = e.message || 'Σφάλμα.';
  }
});

// ===================== 2) IMPORT χρηστών (JSON) =====================
(function wireImport(){
  var fileInput = document.getElementById('jsonFile');
  var btnImport = document.getElementById('btnImport');
  var importMsg = document.getElementById('importMsg');
  if (!btnImport) return;

  btnImport.addEventListener('click', function(){
    try{
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        importMsg.textContent = 'Διάλεξε αρχείο JSON.'; return;
      }
      var f = fileInput.files[0];
      importMsg.textContent = 'Έλεγχος JSON…';
      f.text().then(function(text){
        var parsed;
        try { parsed = JSON.parse(text); }
        catch(_){ importMsg.textContent = 'Σφάλμα: μη έγκυρο JSON.'; return; }

        var payload = Array.isArray(parsed) ? { items: parsed } : parsed;
        if (!payload.items || !Array.isArray(payload.items) || payload.items.length === 0) {
          importMsg.textContent = 'Το αρχείο δεν έχει items.'; return;
        }

        importMsg.textContent = 'Αποστολή…';
        postJson(API + '/import_users.php', payload).then(function(j){
          var res = j.result || {};
          var inserted = res.inserted || 0, updated = res.updated || 0, failed = res.failed || 0;
          importMsg.textContent = 'ΟΚ: '+inserted+' νέοι, '+updated+' ενημερώθηκαν, '+failed+' απέτυχαν.';
          if (j.errors && j.errors.length) console.warn('Import errors:', j.errors);
          if (j.new_credentials && j.new_credentials.length) {
            console.log('Προσωρινοί κωδικοί για ΝΕΟΥΣ χρήστες:', j.new_credentials);
          }
        }).catch(function(err){
          console.error(err);
          importMsg.textContent = err.message || 'Σφάλμα κατά την εισαγωγή.';
        });
      });
    }catch(e){
      console.error(e);
      importMsg.textContent = e.message || 'Σφάλμα κατά την εισαγωγή.';
    }
  });
})();

// ===================== 3) ΔΙΑΧΕΙΡΙΣΗ ΔΕ =====================
function loadManageList(){
  var sel = document.getElementById('manageSel'); if (!sel) return;
  sel.innerHTML = '<option value="">— Φόρτωση… —</option>';
  try{
    var u = new URL(API + '/manage.php', location.origin);
    u.searchParams.set('action','list');
    getJson(u).then(function(j){
      var items = j.data || [];
      if (!items.length) { sel.innerHTML = '<option value="">— Δεν υπάρχουν ενεργές/υπό εξέταση —</option>'; return; }
      sel.innerHTML = '<option value="">— επίλεξε —</option>' + items.map(function(t){
        return '<option value="'+esc(t.id)+'" data-status="'+esc(t.status)+'">'+esc(t.topic_title)+' — '+esc(t.status)+'</option>';
      }).join('');
    }).catch(function(e){
      console.error(e);
      sel.innerHTML = '<option value="">Σφάλμα φόρτωσης</option>';
    });
  }catch(e){
    console.error('loadManageList(synchronous):', e);
    sel.innerHTML = '<option value="">Σφάλμα JS</option>';
  }
}

function showManageInfo(id){
  var info = document.getElementById('manageInfo');
  var blockActive = document.getElementById('blockActive');
  var blockReview = document.getElementById('blockReview');
  if (!info) return;

  info.textContent = 'Φόρτωση…';
  blockActive.hidden = true; blockReview.hidden = true;
  if (!id) { info.textContent = 'Επίλεξε μια διπλωματική…'; return; }

  try{
    var u = new URL(API + '/manage.php', location.origin);
    u.searchParams.set('action','info'); u.searchParams.set('id', id);
    getJson(u).then(function(j){
      var d = j.data || {};
      info.innerHTML = ''
        + '<div><b>Θέμα:</b> '+esc(d.topic_title||'—')+'</div>'
        + '<div><b>Κατάσταση:</b> '+esc(d.status||'—')+'</div>'
        + '<div class="muted"><small>Φοιτητής/τρια: '+esc(d.student_name||'—')+(d.student_number?(' ('+esc(d.student_number)+')'):'')+'</small></div>'
        + '<div class="muted"><small>Νημερτής: '+(d.nimeritis_url?('<a href="'+esc(d.nimeritis_url)+'" target="_blank" rel="noopener">link</a>'):'—')+(d.nimeritis_deposit_date?(' ('+esc(d.nimeritis_deposit_date)+')'):'')+'</small></div>';
      if (d.status === 'active') {
        var formGS = document.getElementById('formGS');
        if (formGS){
          var num = formGS.querySelector('[name=approval_gs_number]');
          var yr  = formGS.querySelector('[name=approval_gs_year]');
          if (num) num.value = d.approval_gs_number || '';
          if (yr)  yr.value  = d.approval_gs_year   || new Date().getFullYear();
        }
        blockActive.hidden = false;
      } else if (d.status === 'under_review') {
        blockReview.hidden = false;
      }
    }).catch(function(e){
      console.error(e);
      info.textContent = e.message || 'Σφάλμα.';
    });
  }catch(e){
    console.error('showManageInfo(synchronous):', e);
    info.textContent = e.message || 'Σφάλμα.';
  }
}
var manageSel = document.getElementById('manageSel');
if (manageSel) manageSel.addEventListener('change', function(e){ showManageInfo(e.target.value); });

// ΑΠ ΓΣ (active)
var formGS = document.getElementById('formGS');
if (formGS) formGS.addEventListener('submit', function(e){
  e.preventDefault();
  var gsMsg = document.getElementById('gsMsg');
  var id = (document.getElementById('manageSel')||{}).value;
  var fd = new FormData(e.target);
  var payload = {
    action: 'set_gs',
    id: id,
    approval_gs_number: fd.get('approval_gs_number'),
    approval_gs_year: Number(fd.get('approval_gs_year'))
  };
  gsMsg.textContent = 'Αποθήκευση…';
  postJson(API + '/manage.php', payload).then(function(){
    gsMsg.textContent = 'Αποθηκεύτηκε.';
  }).catch(function(err){
    console.error(err);
    gsMsg.textContent = err.message || 'Σφάλμα.';
  });
});

// Ακύρωση (active -> canceled)
var formCancel = document.getElementById('formCancel');
if (formCancel) formCancel.addEventListener('submit', function(e){
  e.preventDefault();
  var msg = document.getElementById('cancelMsg');
  var id = (document.getElementById('manageSel')||{}).value;
  var fd = new FormData(e.target);
  var payload = {
    action: 'cancel',
    id: id,
    council_number: fd.get('council_number'),
    council_year: Number(fd.get('council_year')),
    reason: fd.get('reason') || 'κατόπιν αίτησης Φοιτητή/τριας'
  };
  if (!confirm('Σίγουρα θέλεις να ακυρώσεις την ανάθεση θέματος;')) return;
  msg.textContent = 'Εκτέλεση…';
  postJson(API + '/manage.php', payload).then(function(){
    msg.textContent = 'Η ανάθεση ακυρώθηκε.';
    loadManageList();
    showManageInfo((document.getElementById('manageSel')||{}).value);
  }).catch(function(err){
    console.error(err);
    msg.textContent = err.message || 'Σφάλμα.';
  });
});

// Ολοκλήρωση (under_review -> completed)
var btnComplete = document.getElementById('btnComplete');
if (btnComplete) btnComplete.addEventListener('click', function(){
  var msg = document.getElementById('completeMsg');
  var id = (document.getElementById('manageSel')||{}).value;
  if (!id) { msg.textContent = 'Επίλεξε διπλωματική.'; return; }
  msg.textContent = 'Μετάβαση σε «Περατωμένη»…';
  postJson(API + '/manage.php', { action:'complete', id:id }).then(function(){
    msg.textContent = 'Ολοκληρώθηκε.';
    loadManageList();
    showManageInfo((document.getElementById('manageSel')||{}).value);
  }).catch(function(err){
    console.error(err);
    msg.textContent = err.message || 'Σφάλμα.';
  });
});

// ===================== ΑΡΧΙΚΟ LOAD =====================
document.addEventListener('DOMContentLoaded', function(){
  loadTheses();     // 1) Προβολή
  loadManageList(); // 3) Διαχείριση
});
