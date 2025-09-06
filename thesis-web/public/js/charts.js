import { apiGet } from './auth.js';
import Chart from 'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/+esm';
export async function drawTeacherStats(){
const res = await apiGet('/api/stats/teacher.php');
if(!res.ok) return;
const ctx = document.getElementById('statsChart');
new Chart(ctx, {
type:'bar',
data:{ labels:['Επίβλεψη','Μέλος'], datasets:[{ label:'Πλήθος', data:[res.count_supervised||0, res.count_as_member||0] }]},
options:{ responsive:true, scales:{ y:{ beginAtZero:true } } }
});
}