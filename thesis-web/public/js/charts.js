// THESIS_WEB/public/js/charts.js
export async function drawTeacherStats() {
  const canvas = document.getElementById('teacherStats');
  if (!canvas) return;

  if (typeof window.Chart !== 'function') {
    console.error('[charts] Το Chart.js δεν φορτώθηκε. Έλεγξε τη σειρά των <script>.');
    return;
  }

  // default counts (για ασφάλεια)
  const counts = {
    under_assignment: 0,
    active: 0,
    under_review: 0,
    completed: 0,
    canceled: 0
  };

  // φέρε λίστα διπλωματικών & μάζεψε counts ανά status
  try {
    const url = new URL('/thesis-web/api/prof/theses.php', location.origin);
    url.searchParams.set('action', 'list');

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const txt = await res.text();              // Πρώτα raw text
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; }  // Μετά JSON.parse
    catch (e) {
      console.error('[charts] API returned non-JSON:\n', txt);
    }

    if (j && j.ok && Array.isArray(j.data)) {
      for (const r of j.data) {
        const k = r?.status;
        if (k && Object.prototype.hasOwnProperty.call(counts, k)) counts[k]++;
      }
    }
  } catch (e) {
    console.error('[charts] fetch/list failed:', e);
  }

  const labels = ['Υπό ανάθεση','Ενεργή','Υπό εξέταση','Περατωμένη','Ακυρωμένη'];
  const data = [
    counts.under_assignment,
    counts.active,
    counts.under_review,
    counts.completed,
    counts.canceled
  ];

  // Ζωγράφισε το chart
  try {
    const ctx = canvas.getContext('2d');
    new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Διπλωματικές ανά κατάσταση',
          data
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, precision: 0 } }
      }
    });
  } catch (err) {
    console.error('[charts] Αποτυχία αρχικοποίησης Chart', err);
  }
}
