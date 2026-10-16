/* =====================================================================
   Calabrian Formation School - Family Portal + Registrar (front end)
   Talks to the PHP API in /api over fetch(); the session cookie keeps
   people logged in. No framework, no build step.
   ===================================================================== */

// ---------- small helpers ----------
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const cents = v => Math.round(Number(v || 0) * 100);
const peso = v => '₱' + Number(v || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
const pesoShort = v => '₱' + Number(v || 0).toLocaleString('en-PH', {minimumFractionDigits:0, maximumFractionDigits:2});
function parts(s){ const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? {y:+m[1], m:+m[2], d:+m[3]} : null; }
function fmtDate(s){ const p = parts(s); return p ? MONTHS[p.m-1] + ' ' + p.d + ', ' + p.y : '—'; }
function fmtShort(s){ const p = parts(s); return p ? MONTHS[p.m-1] + ' ' + p.d : '—'; }
function todayStr(){ return new Date().toLocaleDateString('en-CA'); }   // YYYY-MM-DD in local time
function digitsOnly(s){ return String(s || '').replace(/\D/g, ''); }

const DOC_LABELS = {
  psa_birth_certificate: 'PSA Birth Certificate',
  report_card: 'Report Card / Form 138',
  id_photo: '2x2 ID Photo',
  good_moral: 'Good Moral Certificate'
};
const REQUIRED_DOCS = ['psa_birth_certificate', 'report_card', 'id_photo'];
const UPLOADABLE = ['draft', 'submitted', 'pending_verification', 'needs_revision'];
const APP_STATUS = {
  draft: 'Draft', submitted: 'Submitted', pending_verification: 'Pending verification',
  needs_revision: 'Needs revision', approved: 'Approved', rejected: 'Rejected'
};
const MAX_BYTES = 5 * 1024 * 1024;

const state = { user: null, guardian: null, apps: [], students: [], current: null, detail: null, history: null };

class ApiError extends Error { constructor(msg, status, data){ super(msg); this.status = status; this.data = data; } }

async function api(path, opts){
  opts = opts || {};
  const init = { method: opts.method || 'GET', credentials: 'same-origin', headers: {} };
  if (opts.json !== undefined){ init.method = opts.method || 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.json); }
  if (opts.form){ init.method = 'POST'; init.body = opts.form; }
  let res;
  try { res = await fetch(path, init); }
  catch(e){ throw new ApiError('Cannot reach the server. Is Apache running?', 0); }
  let data = null;
  try { data = await res.json(); } catch(e){}
  if (!res.ok) throw new ApiError((data && data.error) || ('Something went wrong (' + res.status + ').'), res.status, data);
  return data;
}

function showMsg(id, text){
  const el = $(id); if (!el) return;
  if (!text){ el.classList.remove('show'); el.innerHTML = ''; return; }
  el.textContent = text; el.classList.add('show');
}
let toastTimer;
function toast(text){
  const t = $('toast'); t.textContent = text; t.classList.add('show');
  clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('show'), 3600);
}
function busy(btn, on, label){
  if (!btn) return;
  if (on){ btn.dataset.label = btn.textContent; btn.textContent = label || 'Please wait…'; btn.disabled = true; }
  else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; }
}
function sessionExpired(err){
  if (err && err.status === 401){ state.user = null; showView('auth'); toast('Please sign in again.'); return true; }
  return false;
}
function pickFile(accept, cb){
  const i = document.createElement('input');
  i.type = 'file'; i.accept = accept;
  i.onchange = () => { if (i.files && i.files[0]) cb(i.files[0]); };
  i.click();
}
function checkFile(file, allowed){
  const ext = (file.name.split('.').pop() || '').toLowerCase();
  if (!allowed.includes(ext)) return 'Only ' + allowed.map(e => e.toUpperCase()).join(', ') + ' files are accepted.';
  if (file.size > MAX_BYTES) return 'That file is larger than 5 MB.';
  return '';
}

// ---------- views / navigation ----------
function showView(name){
  document.querySelectorAll('[data-view]').forEach(el => el.classList.remove('active'));
  document.querySelector('[data-view="' + name + '"]').classList.add('active');
  window.scrollTo(0,0);
}
function goPortal(){
  if (state.user && state.user.role === 'guardian') return openPortal();
  if (state.user && (state.user.role === 'registrar' || state.user.role === 'admin')) return openStaff();
  showMsg('auth-error', ''); showView('auth');
}
function selectAuthTab(name){
  $('auth-tab-create').classList.toggle('active', name === 'create');
  $('auth-tab-signin').classList.toggle('active', name === 'signin');
  $('auth-panel-create').style.display = name === 'create' ? 'block' : 'none';
  $('auth-panel-signin').style.display = name === 'signin' ? 'block' : 'none';
  showMsg('auth-error', '');
}
function selectPortalTab(name){
  $('tab-enrollment').classList.toggle('active', name === 'enrollment');
  $('tab-billing').classList.toggle('active', name === 'billing');
  $('portal-panel-enrollment').style.display = name === 'enrollment' ? 'block' : 'none';
  $('portal-panel-billing').style.display = name === 'billing' ? 'block' : 'none';
  window.scrollTo(0,0);
}
function scrollToTypes(){ $('type-select-anchor').scrollIntoView({behavior:'smooth', block:'start'}); }

// ---------- auth ----------
async function doRegister(){
  showMsg('auth-error', '');
  const name = $('reg-name').value.trim(), email = $('reg-email').value.trim();
  const mobile = $('reg-mobile').value.trim(), pw = $('reg-password').value;
  if (!name || !email || !pw) return showMsg('auth-error', 'Full name, email and password are required.');
  if (!/^\S+@\S+\.\S+$/.test(email)) return showMsg('auth-error', 'Please enter a valid email address.');
  if (pw.length < 8) return showMsg('auth-error', 'Password must be at least 8 characters.');
  const btn = $('reg-btn'); busy(btn, true, 'Creating account…');
  try {
    const u = await api('api/auth/register.php', { json: { full_name: name, email: email, password: pw, mobile: mobile || undefined } });
    state.user = u; $('reg-password').value = '';
    await openPortal();
  } catch(e){ showMsg('auth-error', e.message); }
  busy(btn, false);
}
async function doLogin(){
  showMsg('auth-error', '');
  const email = $('login-email').value.trim(), pw = $('login-password').value;
  if (!email || !pw) return showMsg('auth-error', 'Enter your email and password.');
  const btn = $('login-btn'); busy(btn, true, 'Signing in…');
  try {
    const u = await api('api/auth/login.php', { json: { email: email, password: pw } });
    state.user = u; $('login-password').value = '';
    if (u.role === 'guardian') await openPortal(); else await openStaff();
  } catch(e){ showMsg('auth-error', e.message); }
  busy(btn, false);
}
async function doStaffLogin(){
  showMsg('staff-error', '');
  const btn = $('staff-btn'); busy(btn, true, 'Signing in…');
  try {
    const u = await api('api/auth/login.php', { json: { email: $('staff-email').value.trim(), password: $('staff-password').value } });
    if (u.role !== 'registrar' && u.role !== 'admin'){
      await api('api/auth/logout.php', { method: 'POST', json: {} }).catch(() => {});
      state.user = null;
      showMsg('staff-error', 'This account is not a staff account.');
    } else {
      state.user = u; await openStaff();
    }
  } catch(e){ showMsg('staff-error', e.message); }
  busy(btn, false);
}
async function doLogout(){
  try { await api('api/auth/logout.php', { method: 'POST', json: {} }); } catch(e){}
  state.user = null; state.guardian = null; state.apps = []; state.current = null;
  showView('site');
}

// =====================================================================
//  PARENT PORTAL
// =====================================================================
async function openPortal(){
  showView('portal');
  selectPortalTab('enrollment');
  await refreshPortal();
}

async function refreshPortal(){
  try {
    const d = await api('api/enrollment/mine.php');
    state.guardian = d.guardian; state.apps = d.applications; state.students = d.students;
    const first = (d.guardian.full_name || '').split(' ')[0] || 'there';
    $('portal-welcome').textContent = 'Welcome back, ' + first + '.';

    const sel = $('app-select');
    sel.innerHTML = state.apps.map(a => '<option value="' + a.id + '">' + esc(a.first_name + ' ' + a.last_name) + ' — Grade ' + a.grade_level + ' (' + esc(a.application_no) + ')</option>').join('');
    $('app-switch').style.display = state.apps.length > 1 ? 'flex' : 'none';

    if (!state.apps.length){ state.current = null; state.detail = null; state.history = null; renderNoApplication(); return; }
    if (!state.apps.some(a => a.id === state.current)) state.current = state.apps[0].id;
    sel.value = state.current;
    await loadApplication(state.current);
  } catch(e){
    if (!sessionExpired(e)) toast(e.message);
  }
}

function selectApplication(id){ state.current = Number(id); loadApplication(state.current); }

async function loadApplication(id){
  try {
    const [st, hist] = await Promise.all([
      api('api/enrollment/status.php?application_id=' + id),
      api('api/billing/history.php?application_id=' + id)
    ]);
    state.detail = st; state.history = hist;
    renderApplication(); renderBilling();
  } catch(e){ if (!sessionExpired(e)) toast(e.message); }
}

function renderNoApplication(){
  $('app-student').textContent = 'No application yet';
  $('app-meta').textContent = 'Choose an enrollment type below to start your first application.';
  $('app-status').textContent = '—';
  $('app-status-note').textContent = '';
  $('req-block').style.display = 'none';
  $('bill-sub').textContent = 'No application yet';
  $('bill-outstanding').textContent = peso(0);
  $('bill-of').textContent = 'Submit an enrollment application to see your fees.';
  $('bill-next-amt').textContent = '—'; $('bill-next-desc').textContent = '';
  $('bill-paid-label').textContent = '₱0 paid'; $('bill-pct').textContent = '0% complete'; $('bill-fill').style.width = '0%';
  $('bill-pay-btn').style.display = 'none';
  $('stat-total').textContent = '₱0'; $('stat-out').textContent = '₱0'; $('stat-paid').textContent = '₱0';
  $('fee-rows').innerHTML = '<tr><td colspan="5">No fees yet — submit an enrollment application first.</td></tr>';
  $('history-rows').innerHTML = '<tr><td colspan="5">No payments yet.</td></tr>';
}

function currentSummary(){ return state.apps.find(a => a.id === state.current) || {}; }

function renderApplication(){
  const a = state.detail.application, s = currentSummary();
  $('app-student').textContent = a.first_name + ' ' + a.last_name;
  $('app-meta').textContent = 'Grade ' + a.grade_level + ' · Application No. ' + a.application_no + ' · Submitted ' + fmtDate(a.submitted_at);
  $('app-status').textContent = APP_STATUS[a.status] || a.status;
  const notes = {
    approved: 'Enrollment confirmed for SY ' + (s.school_year || '') + '. Your invoice is under Billing & Payments.',
    submitted: 'Waiting for the registrar to review your documents and payment',
    pending_verification: 'The registrar is verifying your documents and payment',
    needs_revision: a.remarks ? 'Follow-up needed: ' + a.remarks : 'The registrar needs something from you',
    rejected: a.remarks ? 'Reason: ' + a.remarks : 'This application was not accepted'
  };
  $('app-status-note').textContent = notes[a.status] || '';

  // requirements checklist (with upload / re-upload where still allowed)
  const docs = {}; state.detail.documents.forEach(d => docs[d.doc_type] = d);
  const canUpload = UPLOADABLE.includes(a.status);
  const keys = REQUIRED_DOCS.concat(['good_moral']);
  const allOk = REQUIRED_DOCS.every(k => docs[k] && docs[k].status === 'verified');
  $('req-sub').textContent = allOk ? "All required documents verified by the registrar's office" : "Documents are checked by the registrar's office";
  $('req-list').innerHTML = keys.map(k => {
    const d = docs[k];
    let badge, remark = '';
    if (!d) badge = '<span class="status-badge upcoming">Not uploaded</span>';
    else if (d.status === 'verified') badge = '<span class="status-badge paid">Verified</span>';
    else if (d.status === 'rejected'){ badge = '<span class="status-badge rejected">Rejected</span>'; if (d.remarks) remark = '<span class="req-remark">' + esc(d.remarks) + '</span>'; }
    else badge = '<span class="status-badge pending">Under review</span>';
    const btn = (canUpload && !(d && d.status === 'verified'))
      ? '<button class="upload-btn" onclick="portalUpload(\'' + k + '\')">' + (d ? 'Replace' : 'Upload') + '</button>' : '';
    return '<div class="upload-row"><span>' + DOC_LABELS[k] + (k === 'good_moral' ? ' <span style="color:var(--ink-400);font-size:11.5px;">(optional)</span>' : '') + remark + '</span>' +
           '<span class="req-actions">' + badge + btn + '</span></div>';
  }).join('');
  $('req-block').style.display = 'block';
}

function portalUpload(docType){
  const appId = state.current;
  pickFile('.jpg,.jpeg,.png,.pdf', async file => {
    const bad = checkFile(file, ['jpg','jpeg','png','pdf']);
    if (bad) return toast(bad);
    const fd = new FormData();
    fd.append('application_id', appId); fd.append('doc_type', docType); fd.append('file', file);
    try {
      await api('api/uploads/document.php', { form: fd });
      toast(DOC_LABELS[docType] + ' uploaded.');
      await loadApplication(appId);
    } catch(e){ if (!sessionExpired(e)) toast(e.message); }
  });
}

// ---------- billing tab ----------
let payTarget = null, payProofFile = null, billingNext = null;

function computeFees(items){
  const today = todayStr();
  const rows = items.map(f => {
    const amount = cents(f.amount), paid = cents(f.paid), pend = cents(f.pending);
    return { id: f.id, name: f.name, due: f.due_date, amount, paid, pend, remaining: Math.max(0, amount - paid - pend) };
  });
  const firstPayable = rows.findIndex(r => r.remaining > 0);
  rows.forEach((r, i) => {
    r.overdue = r.remaining > 0 && r.due && r.due < today;
    if (r.paid >= r.amount) r.status = 'paid';
    else if (r.remaining <= 0) r.status = 'verifying';
    else if (r.overdue) r.status = 'overdue';
    else if (i === firstPayable) r.status = 'due';
    else r.status = 'upcoming';
    r.canPay = r.remaining > 0 && (r.status === 'overdue' || r.status === 'due');
  });
  return { rows: rows, firstPayable: firstPayable };
}

function renderBilling(){
  const a = state.detail.application, b = state.detail.balance, s = currentSummary();
  const sy = s.school_year || '';
  $('bill-sub').textContent = 'School Year ' + sy + ' · ' + a.first_name + ' ' + a.last_name + ', Grade ' + a.grade_level;
  $('bill-outstanding').textContent = peso(b.outstanding);
  $('bill-of').textContent = 'of ' + peso(b.total_assessed) + ' total assessment for SY ' + sy;
  const total = Number(b.total_assessed), paid = Number(b.total_paid);
  const pct = total > 0 ? Math.min(100, Math.round(paid / total * 100)) : 0;
  $('bill-paid-label').textContent = pesoShort(paid) + ' paid';
  $('bill-pct').textContent = pct + '% complete';
  $('bill-fill').style.width = pct + '%';
  $('stat-total').textContent = pesoShort(total);
  $('stat-total-l').textContent = 'Total assessment, SY ' + sy;
  $('stat-out').textContent = pesoShort(b.outstanding);
  $('stat-paid').textContent = pesoShort(paid);

  if (!state.detail.fee_items.length){
    const msg = a.status === 'rejected' ? 'No invoice: this application was not accepted.'
              : 'Your invoice will be available once the registrar approves your application.';
    $('bill-outstanding').textContent = '—';
    $('bill-of').textContent = msg;
    $('bill-next-amt').textContent = '—'; $('bill-next-desc').textContent = '';
    $('bill-paid-label').textContent = ''; $('bill-pct').textContent = ''; $('bill-fill').style.width = '0%';
    $('bill-pay-btn').style.display = 'none'; billingNext = null;
    ['stat-total','stat-out','stat-paid'].forEach(id => $(id).textContent = '—');
    $('fee-rows').innerHTML = '<tr><td colspan="5">' + msg + '</td></tr>';
    $('history-rows').innerHTML = '<tr><td colspan="5">No payments yet.</td></tr>';
    return;
  }
  const fees = computeFees(state.detail.fee_items);
  const next = fees.rows.find(r => r.canPay) || null;
  billingNext = next;
  const anyVerifying = fees.rows.some(r => r.status === 'verifying');
  if (next){
    $('bill-next-amt').textContent = peso(next.remaining / 100);
    $('bill-next-desc').textContent = next.name + ' · ' + fmtDate(next.due);
  } else if (anyVerifying){
    $('bill-next-amt').textContent = 'Verifying';
    $('bill-next-desc').textContent = 'Your payment is awaiting the registrar';
  } else {
    $('bill-next-amt').textContent = total > 0 ? 'All settled' : '—';
    $('bill-next-desc').textContent = total > 0 ? 'No payment due' : '';
  }
  $('bill-pay-btn').style.display = (next && a.status !== 'rejected') ? 'inline-block' : 'none';

  const label = { paid:'Paid', verifying:'Awaiting verification', overdue:'Overdue', due:'Pending', upcoming:'Not yet due' };
  const cls = { paid:'paid', verifying:'pending', overdue:'overdue', due:'pending', upcoming:'upcoming' };
  $('fee-rows').innerHTML = fees.rows.length ? fees.rows.map(r =>
    '<tr><td><div class="fee-name">' + esc(r.name) + '</div>' +
      (/^enrollment/i.test(r.name) ? '<div class="fee-sub">Includes reservation, books &amp; modules, misc. fee</div>' : '') + '</td>' +
    '<td class="fee-amount">' + pesoShort(r.amount / 100) + '</td>' +
    '<td>' + fmtDate(r.due) + '</td>' +
    '<td><span class="status-badge ' + cls[r.status] + '">' + label[r.status] + '</span></td>' +
    '<td>' + (r.canPay && a.status !== 'rejected' ? '<button class="pay-link" onclick="openPay(' + r.id + ')">Pay now →</button>' : '') + '</td></tr>'
  ).join('') : '<tr><td colspan="5">No fees yet.</td></tr>';

  const pays = state.history.payments;
  const pcls = { verified:'paid', pending:'pending', rejected:'rejected' };
  const plabel = { verified:'Verified', pending:'Pending', rejected:'Rejected' };
  $('history-rows').innerHTML = pays.length ? pays.map(p =>
    '<tr><td>' + fmtDate(p.verified_at || p.submitted_at) + '</td>' +
    '<td>' + esc(p.fee_item_name || 'Payment') + '</td>' +
    '<td class="ref-code">' + esc(p.gcash_ref_no) + '</td>' +
    '<td>' + peso(p.amount) + '</td>' +
    '<td><span class="status-badge ' + pcls[p.status] + '">' + plabel[p.status] + '</span>' +
      (p.status === 'rejected' && p.remarks ? '<span class="req-remark">' + esc(p.remarks) + '</span>' : '') +
      ' <a class="receipt-link" href="api/uploads/file.php?type=payment&id=' + p.id + '" target="_blank" rel="noopener">View proof →</a></td></tr>'
  ).join('') : '<tr><td colspan="5">No payments yet.</td></tr>';
}

// ---------- GCash pay modal ----------
function payNext(){ if (billingNext) openPay(billingNext.id); }
function openPay(feeItemId){
  const fees = computeFees(state.detail.fee_items);
  const r = fees.rows.find(x => x.id === feeItemId);
  if (!r || r.remaining <= 0) return;
  payTarget = { feeItemId: feeItemId, amount: (r.remaining / 100).toFixed(2) };
  payProofFile = null;
  $('pay-desc').value = r.name;
  $('pay-amount').textContent = peso(r.remaining / 100);
  $('pay-ref').value = '';
  const ub = document.querySelector('#pay-overlay .upload-btn'); ub.classList.remove('uploaded'); ub.textContent = 'Upload';
  showMsg('pay-error', '');
  $('pay-overlay').classList.add('open');
}
function closePay(){ $('pay-overlay').classList.remove('open'); }
function payPickProof(btn){
  pickFile('.jpg,.jpeg,.png', file => {
    const bad = checkFile(file, ['jpg','jpeg','png']);
    if (bad) return showMsg('pay-error', bad);
    showMsg('pay-error', ''); payProofFile = file; btn.classList.add('uploaded'); btn.textContent = 'Uploaded ✓'; btn.title = file.name;
  });
}
async function submitPay(){
  showMsg('pay-error', '');
  const ref = digitsOnly($('pay-ref').value);
  if (ref.length < 10 || ref.length > 20) return showMsg('pay-error', 'Enter the GCash reference number (10 to 20 digits).');
  if (!payProofFile) return showMsg('pay-error', 'Please upload a screenshot of your GCash payment.');
  const fd = new FormData();
  fd.append('application_id', state.current); fd.append('fee_item_id', payTarget.feeItemId);
  fd.append('amount', payTarget.amount); fd.append('gcash_ref_no', ref); fd.append('proof', payProofFile);
  const btn = $('pay-submit'); busy(btn, true, 'Submitting…');
  try {
    await api('api/billing/pay.php', { form: fd });
    closePay();
    toast("Payment submitted. The registrar's office will verify it within 1–2 working days.");
    await loadApplication(state.current);
  } catch(e){ if (!sessionExpired(e)) showMsg('pay-error', e.message); }
  busy(btn, false);
}

// =====================================================================
//  ENROLLMENT WIZARD
// =====================================================================
const stepMeta = [
  { label: "Student info", sub: "Basic details" },
  { label: "Guardian info", sub: "Contact & billing" },
  { label: "Requirements", sub: "Document upload" },
  { label: "Review", sub: "Submit application" },
];
let currentStep = 1;
let wiz = { type: 'new', files: {} };

function renderTracker(){
  $('step-tracker').innerHTML = stepMeta.map((s,i) => {
    const n = i + 1;
    const cls = n < currentStep ? 'done' : (n === currentStep ? 'active' : '');
    return '<div class="step ' + cls + '">' +
      '<div class="step-num">' + (n < currentStep ? '✓' : n) + '</div>' +
      '<div><div class="step-label">' + s.label + '</div><div class="step-sub">' + s.sub + '</div></div>' +
    '</div>';
  }).join('');
}

function resetUploadButtons(){
  document.querySelectorAll('[data-view="wizard"] .upload-btn').forEach(b => { b.classList.remove('uploaded'); b.textContent = 'Upload'; b.title = ''; });
}
function lockLearnerFields(lock){
  ['w-last','w-first','w-dob','w-lrn'].forEach(id => { $(id).readOnly = lock; });
  $('w-sex').disabled = lock;
}

function startEnrollment(type){
  wiz = { type: type, files: {} };
  currentStep = 1;
  document.querySelectorAll('.wizard-step').forEach(s => s.style.display = 'none');
  document.querySelector('.wizard-step[data-step="1"]').style.display = 'block';
  showMsg('wiz-error', '');
  resetUploadButtons();

  ['w-last','w-first','w-dob','w-address','w-lrn','e-name','e-mobile'].forEach(id => $(id).value = '');
  $('w-sex').value = 'male';
  lockLearnerFields(false);

  const g = state.guardian || {};
  $('g-name').value = g.full_name || ''; $('g-email').value = g.email || ''; $('g-mobile').value = g.mobile || '';
  $('g-rel').value = 'mother';

  const gradeSelect = $('grade-select'), lrnField = $('lrn-field'), sub = $('step1-sub'), wrap = $('w-existing-wrap');
  if (type === 'new'){
    gradeSelect.innerHTML = '<option value="1">Grade 1</option>';
    lrnField.style.display = 'none'; wrap.style.display = 'none';
    sub.textContent = 'Applying as: New Student, Grade 1';
  } else {
    gradeSelect.innerHTML = [2,3,4,5,6].map(n => '<option value="' + n + '">Grade ' + n + '</option>').join('');
    lrnField.style.display = 'block';
    sub.textContent = 'Applying as: Continuing Student';
    if (state.students.length){
      $('w-existing').innerHTML = '<option value="">— Enter a different child —</option>' +
        state.students.map(s => '<option value="' + s.id + '">' + esc(s.first_name + ' ' + s.last_name) + '</option>').join('');
      wrap.style.display = 'block';
    } else wrap.style.display = 'none';
  }
  renderTracker();
  showView('wizard');
}

function pickExistingChild(id){
  if (!id){
    ['w-last','w-first','w-dob','w-lrn'].forEach(i => $(i).value = '');
    lockLearnerFields(false); return;
  }
  const s = state.students.find(x => String(x.id) === String(id));
  if (!s) return;
  $('w-last').value = s.last_name; $('w-first').value = s.first_name; $('w-dob').value = s.birthdate || '';
  $('w-lrn').value = s.lrn || ''; if (s.sex) $('w-sex').value = s.sex;
  lockLearnerFields(true);
}

function wizPickDoc(btn, key){
  pickFile('.jpg,.jpeg,.png,.pdf', file => {
    const bad = checkFile(file, ['jpg','jpeg','png','pdf']);
    if (bad) return showMsg('wiz-error', bad);
    showMsg('wiz-error', ''); wiz.files[key] = file;
    btn.classList.add('uploaded'); btn.textContent = 'Uploaded ✓'; btn.title = file.name;
  });
}
function validateStep(n){
  if (n === 1){
    if (!$('w-last').value.trim() || !$('w-first').value.trim()) return 'Enter the learner\'s first and last name.';
    const dob = $('w-dob').value;
    if (!dob) return 'Enter the learner\'s date of birth.';
    if (dob > todayStr()) return 'Date of birth cannot be in the future.';
    const lrn = digitsOnly($('w-lrn').value);
    if (wiz.type === 'continuing' && $('w-lrn').value.trim() && lrn.length !== 12) return 'LRN must be 12 digits.';
  }
  if (n === 2){
    if (digitsOnly($('g-mobile').value).length < 10) return 'Enter a valid mobile number for the guardian.';
    if ($('e-mobile').value.trim() && digitsOnly($('e-mobile').value).length < 10) return 'The emergency contact mobile number looks incomplete.';
  }
  if (n === 3){
    const missing = REQUIRED_DOCS.filter(k => !wiz.files[k]).map(k => DOC_LABELS[k]);
    if (missing.length) return 'Please upload: ' + missing.join(', ') + '.';
  }
  return '';
}

function showStep(n){
  document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'none';
  currentStep = n;
  document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'block';
  if (n === 4) renderReview();
  renderTracker(); window.scrollTo(0,0);
}
function nextStep(){
  const err = validateStep(currentStep);
  if (err) return showMsg('wiz-error', err);
  showMsg('wiz-error', '');
  if (currentStep < 4) showStep(currentStep + 1);
}
function prevStep(){ showMsg('wiz-error', ''); if (currentStep > 1) showStep(currentStep - 1); }
function goToStep(n){ showMsg('wiz-error', ''); showStep(n); }

function renderReview(){
  $('rv-name').textContent = $('w-first').value.trim() + ' ' + $('w-last').value.trim();
  $('review-grade').textContent = 'Grade ' + $('grade-select').value;
  $('rv-dob').textContent = fmtDate($('w-dob').value);
  $('rv-address').textContent = $('w-address').value.trim() || '—';
  $('rv-gname').textContent = $('g-name').value;
  $('rv-gmobile').textContent = $('g-mobile').value.trim();
  const n = Object.keys(wiz.files).length;
  $('rv-files').textContent = n + ' file' + (n === 1 ? '' : 's') + ' attached';
}

async function submitApplication(){
  showMsg('wiz-error', '');
  const btn = $('submit-app-btn'); busy(btn, true, 'Submitting…');
  const existing = wiz.type === 'continuing' ? $('w-existing').value : '';
  const payload = {
    enrollment_type: wiz.type,
    grade_level: Number($('grade-select').value),
    guardian_mobile: $('g-mobile').value.trim(),
    guardian_relationship: $('g-rel').value,
    emergency_contact_name: $('e-name').value.trim() || undefined,
    emergency_contact_mobile: $('e-mobile').value.trim() || undefined
  };
  if (existing) payload.student_id = Number(existing);
  else {
    payload.first_name = $('w-first').value.trim(); payload.last_name = $('w-last').value.trim();
    payload.birthdate = $('w-dob').value; payload.sex = $('w-sex').value;
    payload.address = $('w-address').value.trim() || undefined;
    if (wiz.type === 'continuing' && $('w-lrn').value.trim()) payload.lrn = $('w-lrn').value.trim();
  }

  let created;
  try { created = await api('api/enrollment/submit.php', { json: payload }); }
  catch(e){ busy(btn, false); if (!sessionExpired(e)) showMsg('wiz-error', e.message); return; }

  // The application exists now. Attach the documents and the payment; report anything that fails.
  const problems = [];
  for (const key of Object.keys(wiz.files)){
    const fd = new FormData();
    fd.append('application_id', created.application_id); fd.append('doc_type', key); fd.append('file', wiz.files[key]);
    try { await api('api/uploads/document.php', { form: fd }); }
    catch(e){ problems.push(DOC_LABELS[key] + ': ' + e.message); }
  }

  busy(btn, false);

  $('confirm-heading').textContent = 'Application received';
  $('confirm-copy').textContent = "Your enrollment application has been submitted. The registrar's office will review your documents within 1–2 working days. Once approved, your invoice and payment options will appear under Billing & Payments.";
  $('confirm-no-label').textContent = 'Application no.';
  $('confirm-appno').textContent = created.application_no;
  $('confirm-student').textContent = $('w-first').value.trim() + ' ' + $('w-last').value.trim();
  $('confirm-grade').textContent = 'Grade ' + payload.grade_level;
  $('confirm-amount-row').style.display = 'none';
  const st = $('confirm-status'); st.textContent = 'Pending Review'; st.className = 'status-pill'; st.removeAttribute('style');
  if (problems.length){
    const el = $('confirm-warn');
    el.innerHTML = '<strong>Your application was saved, but some items need attention:</strong><ul>' + problems.map(p => '<li>' + esc(p) + '</li>').join('') + '</ul>You can fix these from your portal by re-uploading the documents.';
    el.classList.add('show');
  } else showMsg('confirm-warn', '');
  state.current = created.application_id;
  showView('confirm');
}

// =====================================================================
//  SCREENING REQUEST
// =====================================================================
let screenType = 'kinder';
function startScreening(type){
  screenType = type;
  const title = $('screen-title'), sub = $('screen-sub');
  showMsg('screen-error', '');
  ['s-name','s-dob','s-prev'].forEach(id => $(id).value = '');
  $('s-grade').value = '1'; $('s-sched').selectedIndex = 0;
  const g = state.guardian || {};
  $('s-gname').value = g.full_name || ''; $('s-mobile').value = g.mobile || '';
  if (type === 'kinder'){
    title.textContent = 'Request a screening — Kindergarten';
    sub.textContent = "Kindergarten applicants are assessed before enrollment opens. Fill this out and the registrar's office will text you a schedule.";
    $('screen-prev-school').style.display = 'none'; $('screen-target-grade').style.display = 'none';
  } else {
    title.textContent = 'Request a screening — Transferee';
    sub.textContent = "Transferees from other schools need a short assessment first. Fill this out and the registrar's office will text you a schedule.";
    $('screen-prev-school').style.display = 'block'; $('screen-target-grade').style.display = 'block';
  }
  showView('screening');
}

async function submitScreening(){
  showMsg('screen-error', '');
  const name = $('s-name').value.trim(), dob = $('s-dob').value, mobile = $('s-mobile').value.trim();
  if (!name) return showMsg('screen-error', "Enter the child's full name.");
  if (!dob || dob > todayStr()) return showMsg('screen-error', "Enter a valid date of birth for the child.");
  if (digitsOnly(mobile).length < 10) return showMsg('screen-error', 'Enter a valid mobile number so the school can text you.');
  const transferee = screenType !== 'kinder';
  if (transferee && !$('s-prev').value.trim()) return showMsg('screen-error', 'Enter the current or previous school.');
  const payload = {
    screening_type: transferee ? 'transferee' : 'kindergarten',
    child_name: name, birthdate: dob, guardian_mobile: mobile, preferred_schedule: $('s-sched').value
  };
  if (transferee){ payload.previous_school = $('s-prev').value.trim(); payload.target_grade = Number($('s-grade').value); }
  const btn = $('screen-btn'); busy(btn, true, 'Sending…');
  try {
    const r = await api('api/screening/request.php', { json: payload });
    $('confirm-heading').textContent = 'Screening request sent';
    $('confirm-copy').textContent = "Thank you. The registrar's office will text you within 2 working days to schedule your child's assessment. Enrollment and payment will open after screening.";
    showMsg('confirm-warn', '');
    $('confirm-no-label').textContent = 'Request no.';
    $('confirm-appno').textContent = 'SCR-' + String(r.screening_request.id).padStart(5, '0');
    $('confirm-student').textContent = name;
    $('confirm-grade').textContent = transferee ? 'Grade ' + payload.target_grade + ' (transferee)' : 'Kindergarten';
    $('confirm-amount-row').style.display = 'none';
    const st = $('confirm-status'); st.textContent = 'For Screening'; st.className = 'status-pill';
    st.style.background = 'var(--maroon-100)'; st.style.color = 'var(--maroon-600)';
    showView('confirm');
  } catch(e){ if (!sessionExpired(e)) showMsg('screen-error', e.message); }
  busy(btn, false);
}

// =====================================================================
//  REGISTRAR DASHBOARD
// =====================================================================
let staffRows = [], staffFilter = 'all', drawerState = null;
const STATUS_BADGE = {
  submitted: ['pending', 'Pending review'], pending_verification: ['pending', 'Pending review'],
  needs_revision: ['pending', 'Needs revision'], approved: ['approved', 'Approved'],
  rejected: ['screening', 'Rejected'], draft: ['pending', 'Draft'], screening: ['screening', 'For screening']
};
const PAY_LABEL = { paid: 'Paid', pending: 'Pending', unpaid: 'Unpaid', noinv: 'No invoice' };

async function openStaff(){
  showView('staff');
  $('staff-user').textContent = 'Registrar · ' + ((state.user && (state.user.full_name || state.user.email)) || '');
  await loadStaff();
}

async function loadStaff(){
  showMsg('staff-list-error', '');
  try {
    const [apps, scr] = await Promise.all([
      api('api/registrar/list.php?per_page=100'),
      api('api/registrar/screening.php')
    ]);
    const rows = [];
    apps.applications.forEach(a => rows.push({
      kind: 'app', id: a.id, name: a.first_name + ' ' + a.last_name, sub: a.application_no,
      grade: 'Grade ' + a.grade_level,
      type: a.enrollment_type === 'new' ? 'New' : 'Continuing',
      payment: a.status !== 'approved' ? 'noinv'
             : (Number(a.pending_payments) > 0 ? 'pending'
             : (cents(a.outstanding) <= 0 ? 'paid' : (cents(a.total_paid) > 0 ? 'pending' : 'unpaid'))),
      status: a.status, when: a.submitted_at || '', guardian: a.guardian_name + ' ' + a.guardian_email,
      pendingPayments: Number(a.pending_payments)
    }));
    scr.screening_requests.forEach(r => rows.push({
      kind: 'screen', id: r.id, name: r.child_name,
      sub: r.screening_type === 'kindergarten' ? 'Kindergarten screening' : 'Transferee screening',
      grade: r.screening_type === 'kindergarten' ? 'Kinder' : 'Grade ' + r.target_grade,
      type: r.screening_type === 'kindergarten' ? 'Kindergarten' : 'Transferee',
      payment: 'unpaid', status: 'screening', when: r.created_at || '',
      guardian: r.guardian_name + ' ' + r.guardian_email, raw: r
    }));
    rows.sort((x, y) => (y.when || '').localeCompare(x.when || ''));
    staffRows = rows;

    $('st-total').textContent = apps.pagination.total;
    $('st-verify').textContent = rows.filter(r => r.kind === 'app' && r.pendingPayments > 0).length;
    $('st-approved').textContent = rows.filter(r => r.kind === 'app' && r.status === 'approved').length;
    $('st-screen').textContent = scr.screening_requests.filter(r => r.status === 'requested' || r.status === 'scheduled').length;
    renderRecords();
  } catch(e){
    if (e.status === 401 || e.status === 403){ state.user = null; showView('staffLogin'); showMsg('staff-error', 'Please sign in with a staff account.'); }
    else showMsg('staff-list-error', e.message);
  }
}

function setFilter(f){
  staffFilter = f;
  document.querySelectorAll('#filter-pills .filter-pill').forEach(b => b.classList.toggle('active', b.dataset.filter === f));
  renderRecords();
}

function renderRecords(){
  const q = ($('staff-search').value || '').trim().toLowerCase();
  const list = staffRows.filter(r => {
    if (staffFilter === 'unpaid' && !(r.kind === 'app' && r.payment === 'unpaid')) return false;
    if (staffFilter === 'pending' && !(r.kind === 'app' && ['submitted','pending_verification','needs_revision'].includes(r.status))) return false;
    if (staffFilter === 'screening' && r.kind !== 'screen') return false;
    if (q && !(r.name + ' ' + r.sub + ' ' + r.guardian).toLowerCase().includes(q)) return false;
    return true;
  });
  $('record-rows').innerHTML = list.length ? list.map(r => {
    const b = STATUS_BADGE[r.status] || ['pending', r.status];
    return '<tr onclick="openDrawer(\'' + r.kind + '\',' + r.id + ')">' +
      '<td><div class="stud-name">' + esc(r.name) + '</div><div class="stud-lrn">' + esc(r.sub) + '</div></td>' +
      '<td>' + esc(r.grade) + '</td><td>' + esc(r.type) + '</td>' +
      '<td><span class="badge ' + (r.payment === 'noinv' ? 'pending' : r.payment) + '">' + PAY_LABEL[r.payment] + '</span></td>' +
      '<td><span class="badge ' + b[0] + '">' + b[1] + '</span></td>' +
      '<td>' + fmtShort(r.when) + '</td></tr>';
  }).join('') : '<tr class="empty-row"><td colspan="6">No records match.</td></tr>';
}

function closeDrawer(){ $('drawer-overlay').classList.remove('open'); drawerState = null; }

async function openDrawer(kind, id){
  drawerState = { kind: kind, id: id };
  if (kind === 'screen'){
    const r = staffRows.find(x => x.kind === 'screen' && x.id === id).raw;
    $('drawer-body').innerHTML =
      '<h3>' + esc(r.child_name) + '</h3><div class="drawer-lrn">' + (r.screening_type === 'kindergarten' ? 'Kindergarten screening request' : 'Transferee screening request') + '</div>' +
      '<div class="drawer-section"><h5>REQUEST DETAILS</h5><dl class="drawer-grid">' +
        '<div><dt>Date of birth</dt><dd>' + fmtDate(r.birthdate) + '</dd></div>' +
        '<div><dt>Grade applying for</dt><dd>' + (r.target_grade ? 'Grade ' + r.target_grade : 'Kindergarten') + '</dd></div>' +
        (r.previous_school ? '<div><dt>Previous school</dt><dd>' + esc(r.previous_school) + '</dd></div>' : '') +
        '<div><dt>Preferred schedule</dt><dd>' + esc(r.preferred_schedule || '—') + '</dd></div>' +
        '<div><dt>Guardian</dt><dd>' + esc(r.guardian_name) + '</dd></div>' +
        '<div><dt>Contact</dt><dd>' + esc(r.guardian_mobile || r.guardian_email) + '</dd></div>' +
      '</dl></div>' +
      '<p class="drawer-note">Text the guardian a screening schedule. Enrollment and payment open for this child after screening.</p>';
    $('drawer-overlay').classList.add('open');
    return;
  }
  $('drawer-body').innerHTML = '<p class="drawer-note">Loading…</p>';
  $('drawer-overlay').classList.add('open');
  await loadDrawer(id);
}

async function loadDrawer(id){
  try {
    const d = await api('api/registrar/detail.php?application_id=' + id);
    renderDrawer(d);
  } catch(e){
    $('drawer-body').innerHTML = '<div class="form-error show">' + esc(e.message) + '</div>';
  }
}

function renderDrawer(d){
  const a = d.application, s = d.student, g = d.guardian;
  const relLabel = { mother:'Mother', father:'Father', legal_guardian:'Legal guardian' };
  const docs = {}; d.documents.forEach(x => docs[x.doc_type] = x);
  const docKeys = ['psa_birth_certificate','report_card','good_moral','id_photo'];

  const docRows = docKeys.map(k => {
    const x = docs[k];
    if (!x){
      const req = REQUIRED_DOCS.includes(k);
      return '<div class="doc-row"><span>' + DOC_LABELS[k] + '</span><span class="doc-status" style="color:' + (req ? 'var(--maroon-600)' : 'var(--ink-400)') + '">' + (req ? 'Missing' : 'Not provided') + '</span></div>';
    }
    const lbl = { pending:'Uploaded', verified:'Verified', rejected:'Rejected' }[x.status];
    return '<div class="doc-row"><span>' + DOC_LABELS[k] + (x.remarks ? '<span class="req-remark" style="color:var(--ink-400)">' + esc(x.remarks) + '</span>' : '') + '</span>' +
      '<span class="doc-actions"><span class="doc-status">' + lbl + '</span>' +
      '<a class="mini-btn" href="api/uploads/file.php?type=document&id=' + x.id + '" target="_blank" rel="noopener">View</a>' +
      (x.status !== 'verified' ? '<button class="mini-btn ok" onclick="decideDoc(' + x.id + ',\'verified\')">Verify</button>' : '') +
      (x.status !== 'rejected' ? '<button class="mini-btn bad" onclick="decideDoc(' + x.id + ',\'rejected\')">Reject</button>' : '') +
      '</span></div>';
  }).join('');

  const payRows = d.payments.length ? d.payments.map(p => {
    const lbl = { pending:'Awaiting verification', verified:'Verified', rejected:'Rejected' }[p.status];
    return '<div class="doc-row"><span>GCash · Ref# ' + esc(p.gcash_ref_no) + ' · ' + peso(p.amount) +
      (p.remarks ? '<span class="req-remark" style="color:var(--ink-400)">' + esc(p.remarks) + '</span>' : '') + '</span>' +
      '<span class="doc-actions"><span class="doc-status">' + lbl + '</span>' +
      '<a class="mini-btn" href="api/uploads/file.php?type=payment&id=' + p.id + '" target="_blank" rel="noopener">Proof</a>' +
      (p.status === 'pending' ? '<button class="mini-btn ok" onclick="decidePay(' + p.id + ',\'verified\')">Verify</button><button class="mini-btn bad" onclick="decidePay(' + p.id + ',\'rejected\')">Reject</button>' : '') +
      '</span></div>';
  }).join('') : (a.status === 'approved' ? '<div class="doc-row"><span>No payment submitted</span><span class="doc-status" style="color:var(--maroon-600)">Not yet paid</span></div>' : '<div class="doc-row"><span>No invoice yet</span><span class="doc-status">Issued on approval</span></div>');

  const canDecide = d.approval.can_decide;
  const blockers = d.approval.blockers || [];
  const checklist = !canDecide ? '' : (blockers.length
    ? '<div class="form-error show"><strong>Not ready for approval yet:</strong><ul>' + blockers.map(b => '<li>' + esc(b) + '</li>').join('') + '</ul></div>'
    : '<div class="form-ok">All required documents are verified. Approving will send the family their invoice.</div>');

  const badge = STATUS_BADGE[a.status] || ['pending', a.status];
  $('drawer-body').innerHTML =
    '<h3>' + esc(s.first_name + ' ' + s.last_name) + '</h3>' +
    '<div class="drawer-lrn">' + esc(a.application_no) + ' · SY ' + esc(a.school_year) + ' · <span class="badge ' + badge[0] + '">' + badge[1] + '</span></div>' +
    '<div class="drawer-section"><h5>APPLICATION SUMMARY</h5><dl class="drawer-grid">' +
      '<div><dt>Grade level</dt><dd>Grade ' + a.grade_level + '</dd></div>' +
      '<div><dt>Type</dt><dd>' + (a.enrollment_type === 'new' ? 'New student' : 'Continuing student') + '</dd></div>' +
      '<div><dt>Date of birth</dt><dd>' + fmtDate(s.birthdate) + '</dd></div>' +
      '<div><dt>LRN</dt><dd>' + esc(s.lrn || '—') + '</dd></div>' +
      '<div><dt>Guardian</dt><dd>' + esc(g.full_name) + (a.guardian_relationship ? ' (' + relLabel[a.guardian_relationship] + ')' : '') + '</dd></div>' +
      '<div><dt>Contact</dt><dd>' + esc(g.mobile || g.email) + '</dd></div>' +
      (s.address ? '<div><dt>Address</dt><dd>' + esc(s.address) + '</dd></div>' : '') +
      (a.emergency_contact_name ? '<div><dt>Emergency contact</dt><dd>' + esc(a.emergency_contact_name) + (a.emergency_contact_mobile ? ' · ' + esc(a.emergency_contact_mobile) : '') + '</dd></div>' : '') +
    '</dl></div>' +
    '<div class="drawer-section"><h5>PAYMENT</h5>' + payRows +
      '<div class="drawer-note">Verified ' + peso(d.balance ? d.balance.total_paid : 0) + ' of ' + peso(d.balance ? d.balance.total_assessed : 0) + ' assessed.</div></div>' +
    '<div class="drawer-section"><h5>DOCUMENTS</h5>' + docRows + '</div>' +
    (a.remarks ? '<div class="drawer-section"><h5>REGISTRAR REMARKS</h5><p class="drawer-note" style="margin-top:0">' + esc(a.remarks) + '</p></div>' : '') +
    '<div class="form-error" id="drawer-error"></div>' +
    checklist +
    (canDecide
      ? '<div class="field"><label>Remarks (required to flag, reject, or approve with missing items)</label><textarea id="drawer-remarks" rows="2" placeholder="Visible to the family when flagged or rejected"></textarea></div>' +
        '<div class="drawer-actions">' +
          '<button class="btn-approve" onclick="decideApp(\'approve\')">' + (blockers.length ? 'Approve anyway' : 'Approve enrollment') + '</button>' +
          '<button class="btn-flag" onclick="decideApp(\'flag\')">Flag for follow-up</button>' +
        '</div>' +
        '<button class="reject-link" onclick="decideApp(\'reject\')">Reject application</button>'
      : '<p class="drawer-note">This application is already <strong>' + esc(APP_STATUS[a.status] || a.status) + '</strong>' + (a.status === 'rejected' || a.status === 'approved' ? ' and can no longer be changed.' : '.') + '</p>');
  drawerState.detail = d;
}

async function afterDrawerChange(msg){
  toast(msg);
  await Promise.all([loadDrawer(drawerState.id), loadStaff()]);
}
async function decideDoc(docId, decision){
  let remarks = null;
  if (decision === 'rejected'){
    remarks = (window.prompt('Reason for rejecting this document (the family will see it):') || '').trim();
    if (!remarks) return;
  }
  try { await api('api/registrar/verify_document.php', { json: { document_id: docId, decision: decision, remarks: remarks || undefined } }); await afterDrawerChange('Document ' + decision + '.'); }
  catch(e){ showMsg('drawer-error', e.message); }
}
async function decidePay(payId, decision){
  let remarks = null;
  if (decision === 'rejected'){
    remarks = (window.prompt('Reason for rejecting this payment (the family will see it):') || '').trim();
    if (!remarks) return;
  } else if (!window.confirm('Verify this payment? Verified payments are final.')) return;
  try { await api('api/registrar/verify_payment.php', { json: { payment_id: payId, decision: decision, remarks: remarks || undefined } }); await afterDrawerChange('Payment ' + decision + '.'); }
  catch(e){ showMsg('drawer-error', e.message); }
}
async function decideApp(action){
  showMsg('drawer-error', '');
  const remarks = ($('drawer-remarks').value || '').trim();
  const id = drawerState.id, blockers = drawerState.detail.approval.blockers || [];
  try {
    if (action === 'approve'){
      if (blockers.length){
        if (!remarks) return showMsg('drawer-error', 'Approving with missing items needs a remark explaining why. This override is recorded in the audit log.');
        if (!window.confirm('Approve even though the checklist is incomplete? This is recorded in the audit log.')) return;
      }
      await api('api/registrar/approve.php', { json: { application_id: id, remarks: remarks || undefined, override: blockers.length > 0 } });
      await afterDrawerChange('Application approved.');
    } else if (action === 'flag'){
      if (!remarks) return showMsg('drawer-error', 'Add a remark telling the family what to fix.');
      await api('api/registrar/request_revision.php', { json: { application_id: id, remarks: remarks } });
      await afterDrawerChange('Flagged for follow-up. The family can see your remark.');
    } else {
      if (!remarks) return showMsg('drawer-error', 'Add the reason for rejecting this application.');
      if (!window.confirm('Reject this application? Rejection is final.')) return;
      await api('api/registrar/reject.php', { json: { application_id: id, remarks: remarks } });
      await afterDrawerChange('Application rejected.');
    }
  } catch(e){
    const extra = e.data && e.data.blockers ? ' ' + e.data.blockers.join('; ') : '';
    showMsg('drawer-error', e.message + extra);
  }
}

// ---------- boot ----------
renderTracker();
(async function init(){
  try {
    const me = await api('api/auth/me.php');
    if (me && me.logged_in) state.user = { id: me.id, email: me.email, role: me.role };
  } catch(e){ /* not logged in, or server not reachable: the public site still works */ }
})();
