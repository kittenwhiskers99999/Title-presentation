const stepMeta = [
    { label: "Student info", sub: "Basic details" },
    { label: "Guardian info", sub: "Contact & billing" },
    { label: "Requirements", sub: "Document upload" },
    { label: "Payment", sub: "GCash only" },
    { label: "Review", sub: "Submit application" },
  ];
  let currentStep = 1;
  let enrollmentType = 'new';

  function renderTracker(){
    const el = document.getElementById('step-tracker');
    el.innerHTML = stepMeta.map((s,i)=>{
      const n = i+1;
      const cls = n < currentStep ? 'done' : (n === currentStep ? 'active' : '');
      return '<div class="step ' + cls + '">' +
        '<div class="step-num">' + (n < currentStep ? '✓' : n) + '</div>' +
        '<div><div class="step-label">' + s.label + '</div><div class="step-sub">' + s.sub + '</div></div>' +
      '</div>';
    }).join('');
  }

  function showView(name){
    document.querySelectorAll('[data-view]').forEach(el => el.classList.remove('active'));
    document.querySelector('[data-view="' + name + '"]').classList.add('active');
    window.scrollTo(0,0);
  }

  function scrollToTypes(){
    document.getElementById('type-select-anchor').scrollIntoView({behavior:'smooth', block:'start'});
  }

  function startEnrollment(type){
    enrollmentType = type;
    currentStep = 1;
    document.querySelectorAll('.wizard-step').forEach(s => s.style.display = 'none');
    document.querySelector('.wizard-step[data-step="1"]').style.display = 'block';

    const gradeSelect = document.getElementById('grade-select');
    const lrnField = document.getElementById('lrn-field');
    const sub = document.getElementById('step1-sub');

    if(type === 'new'){
      gradeSelect.innerHTML = '<option>Grade 1</option>';
      lrnField.style.display = 'none';
      sub.textContent = 'Applying as: New Student, Grade 1';
    } else {
      gradeSelect.innerHTML = '<option>Grade 2</option><option>Grade 3</option><option>Grade 4</option><option>Grade 5</option><option>Grade 6</option>';
      lrnField.style.display = 'block';
      sub.textContent = 'Applying as: Continuing Student';
    }
    renderTracker();
    showView('wizard');
  }

  function startScreening(type){
    const title = document.getElementById('screen-title');
    const sub = document.getElementById('screen-sub');
    const prevSchool = document.getElementById('screen-prev-school');
    const targetGrade = document.getElementById('screen-target-grade');

    if(type === 'kinder'){
      title.textContent = 'Request a screening — Kindergarten';
      sub.textContent = "Kindergarten applicants are assessed before enrollment opens. Fill this out and the registrar's office will text you a schedule.";
      prevSchool.style.display = 'none';
      targetGrade.style.display = 'none';
    } else {
      title.textContent = 'Request a screening — Transferee';
      sub.textContent = "Transferees from other schools need a short assessment first. Fill this out and the registrar's office will text you a schedule.";
      prevSchool.style.display = 'block';
      targetGrade.style.display = 'block';
    }
    showView('screening');
  }

  function nextStep(){
    if(currentStep < 5){
      document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'none';
      currentStep++;
      document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'block';
      renderTracker();
      window.scrollTo(0,0);
    }
  }
  function prevStep(){
    if(currentStep > 1){
      document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'none';
      currentStep--;
      document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'block';
      renderTracker();
      window.scrollTo(0,0);
    }
  }
  function goToStep(n){
    document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'none';
    currentStep = n;
    document.querySelector('.wizard-step[data-step="' + currentStep + '"]').style.display = 'block';
    renderTracker();
    window.scrollTo(0,0);
  }

  function toggleUpload(btn){
    const uploaded = btn.classList.toggle('uploaded');
    btn.textContent = uploaded ? 'Uploaded ✓' : 'Upload';
  }

  function submitApplication(){
    document.getElementById('confirm-heading').textContent = 'Application received';
    document.getElementById('confirm-copy').textContent = "Your enrollment application has been submitted. The registrar's office will verify your payment and documents within 1–2 working days.";
    showView('confirm');
  }

  function submitScreening(){
    document.getElementById('confirm-heading').textContent = 'Screening request sent';
    document.getElementById('confirm-copy').textContent = "Thank you. The registrar's office will text you within 2 working days to schedule your child's assessment. Enrollment and payment will open after screening.";
    document.getElementById('confirm-status').textContent = 'For Screening';
    document.getElementById('confirm-status').className = 'status-pill';
    document.getElementById('confirm-status').style.background = 'var(--maroon-100)';
    document.getElementById('confirm-status').style.color = 'var(--maroon-600)';
    showView('confirm');
  }

  // ---- registrar table (mock data) ----
  const records = [
    { name:"Miguel Dela Cruz", lrn:"App. CFS-2026-04821", grade:"Grade 1", type:"New", payment:"paid", status:"approved", date:"Sep 2" },
    { name:"Andrea Villanueva", lrn:"App. CFS-2026-04822", grade:"Grade 4", type:"Continuing", payment:"pending", status:"pending", date:"Sep 3" },
    { name:"Josh Fernandez", lrn:"App. CFS-2026-04823", grade:"Grade 2", type:"Transferee", payment:"unpaid", status:"screening", date:"Sep 3" },
    { name:"Bea Santos", lrn:"App. CFS-2026-04824", grade:"Grade 6", type:"Continuing", payment:"paid", status:"approved", date:"Sep 4" },
    { name:"Carlo Mendoza", lrn:"App. CFS-2026-04825", grade:"Grade 3", type:"Continuing", payment:"pending", status:"pending", date:"Sep 5" },
    { name:"Kylie Reyes", lrn:"Kindergarten screening", grade:"Kinder", type:"Kindergarten", payment:"unpaid", status:"screening", date:"Sep 5" },
    { name:"Nathan Cruz", lrn:"App. CFS-2026-04827", grade:"Grade 5", type:"Continuing", payment:"paid", status:"approved", date:"Sep 6" },
    { name:"Pia Aguilar", lrn:"App. CFS-2026-04828", grade:"Grade 1", type:"New", payment:"pending", status:"pending", date:"Sep 7" },
  ];

  const statusLabel = { approved:"Approved", pending:"Pending review", screening:"For screening" };
  const paymentLabel = { paid:"Paid", pending:"Pending", unpaid:"Unpaid" };

  function renderRecords(){
    const tbody = document.getElementById('record-rows');
    tbody.innerHTML = records.map((r,i) => `
      <tr onclick="openDrawer(${i})">
        <td><div class="stud-name">${r.name}</div><div class="stud-lrn">${r.lrn}</div></td>
        <td>${r.grade}</td>
        <td>${r.type}</td>
        <td><span class="badge ${r.payment}">${paymentLabel[r.payment]}</span></td>
        <td><span class="badge ${r.status}">${statusLabel[r.status]}</span></td>
        <td>${r.date}</td>
      </tr>
    `).join('');
  }

  function openDrawer(i){
    const r = records[i];
    document.getElementById('drawer-name').textContent = r.name;
    document.getElementById('drawer-lrn').textContent = r.lrn;
    document.getElementById('drawer-grade').textContent = r.grade;
    document.getElementById('drawer-type').textContent = r.type;
    document.getElementById('drawer-payment-status').textContent = r.payment === 'paid' ? 'Verified' : (r.payment === 'pending' ? 'Awaiting verification' : 'Not yet paid');
    document.getElementById('drawer-overlay').classList.add('open');
  }
  function closeDrawer(e){
    document.getElementById('drawer-overlay').classList.remove('open');
  }

  renderTracker();
  renderRecords();

  // ---------- billing / GCash pay modal ----------
function openPay(desc, amount){
  document.getElementById('pay-desc').value = desc;
  document.getElementById('pay-amount').textContent = '₱' + amount;
  document.getElementById('pay-overlay').classList.add('open');
}
function closePay(e){
  document.getElementById('pay-overlay').classList.remove('open');
}
function submitPay(){
  alert('Payment submitted. The registrar\'s office will verify it and update your balance within 1–2 working days.');
  closePay();
}

  // ---------- auth + portal tab switching ----------
  function selectAuthTab(name){
    document.getElementById('auth-tab-create').classList.toggle('active', name === 'create');
    document.getElementById('auth-tab-signin').classList.toggle('active', name === 'signin');
    document.getElementById('auth-panel-create').style.display = name === 'create' ? 'block' : 'none';
    document.getElementById('auth-panel-signin').style.display = name === 'signin' ? 'block' : 'none';
  }
  function selectPortalTab(name){
    document.getElementById('tab-enrollment').classList.toggle('active', name === 'enrollment');
    document.getElementById('tab-billing').classList.toggle('active', name === 'billing');
    document.getElementById('portal-panel-enrollment').style.display = name === 'enrollment' ? 'block' : 'none';
    document.getElementById('portal-panel-billing').style.display = name === 'billing' ? 'block' : 'none';
    window.scrollTo(0,0);
  }
