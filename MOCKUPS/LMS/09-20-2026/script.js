const courses = {
    ap: {
      title: "Araling Panlipunan",
      teacher: "Ms. Domingo",
      grade: "GRADE 6",
      room: "Room 6-A · Mon & Wed, 9:00–9:50",
      pending: [
        { title: "Sanaysay: Kahalagahan ng Wastong Pamamahala", due: "Due tomorrow, 5:00 PM", soon: true, type: "Sanaysay" },
        { title: "Basahin: Yunit 3 — Ang Pamahalaang Lokal", due: "Due Sep 14", type: "Reading" },
      ],
      done: [
        { title: "Pagsusulit sa Yunit 2", score: "90/100" },
        { title: "Graphic organizer: Sangay ng Pamahalaan", score: "Complete" },
      ]
    },
    mapeh: {
      title: "MAPEH",
      teacher: "Mr. Bautista",
      grade: "GRADE 6",
      room: "Covered Court · Tue & Thu, 10:00–10:50",
      pending: [
        { title: "Folk dance performance: Tinikling", due: "Due Sep 15", type: "Performance task" },
      ],
      done: [
        { title: "Music: Note reading quiz", score: "Complete" },
        { title: "Health: Nutrition poster", score: "Complete" },
        { title: "PE: Fitness test", score: "Complete" },
      ]
    },
    math: {
      title: "Math",
      teacher: "Mrs. Santos",
      grade: "GRADE 6",
      room: "Room 6-A · Mon, Wed & Fri, 8:00–8:50",
      pending: [
        { title: "Worksheet: Ratio and Proportion", due: "Due tomorrow, 8:00 AM", soon: true, type: "Worksheet" },
        { title: "Problem set: Operations on Fractions", due: "Due Sep 13", type: "Problem set" },
        { title: "Module 4 quiz: Integers", due: "Due Sep 16", type: "Quiz" },
      ],
      done: [
        { title: "Long test: Decimals", score: "88/100" },
        { title: "Seatwork: Order of operations", score: "Complete" },
      ]
    },
    filipino: {
      title: "Filipino",
      teacher: "Ms. Ramos",
      grade: "GRADE 6",
      room: "Room 6-A · Tue & Thu, 9:00–9:50",
      pending: [
        { title: "Pagbasa: Maikling Kuwento — Unang Bahagi", due: "Due Sep 12", type: "Pagbasa" },
      ],
      done: [
        { title: "Pagsasanay sa Gramatika", score: "Complete" },
        { title: "Pagsusulit: Aralin 5", score: "94/100" },
      ]
    },
    science: {
      title: "Science",
      teacher: "Mr. Cruz",
      grade: "GRADE 6",
      room: "Science Room · Mon & Wed, 10:00–10:50",
      pending: [
        { title: "Lab report: States of Matter", due: "Due Sep 14", type: "Lab report" },
        { title: "Reading: The Water Cycle", due: "Due Sep 17", type: "Reading" },
      ],
      done: [
        { title: "Quiz: Parts of the Ecosystem", score: "91/100" },
        { title: "Diagram: The Solar System", score: "Complete" },
        { title: "Lab safety agreement", score: "Complete" },
      ]
    },
    tle: {
      title: "Technical Livelihood Education",
      teacher: "Mrs. Aquino",
      grade: "GRADE 6",
      room: "TLE Room · Fri, 1:00–2:40",
      pending: [],
      done: [
        { title: "Project: Basic Sewing Stitches", score: "Complete" },
        { title: "Written test: Kitchen Safety", score: "89/100" },
      ]
    },
    computer: {
      title: "Computer",
      teacher: "Mr. Reyes",
      grade: "GRADE 6",
      room: "Computer Lab · Thu, 1:00–1:50",
      pending: [
        { title: "Activity: Basic Spreadsheet Formulas", due: "Due Sep 15", type: "Activity" },
      ],
      done: [
        { title: "Typing speed test", score: "Complete" },
        { title: "Quiz: Parts of a Computer", score: "93/100" },
      ]
    },
    cle: {
      title: "Christian Living Education",
      teacher: "Ms. Villanueva",
      grade: "GRADE 6",
      room: "Room 6-A · Fri, 8:00–8:50",
      pending: [],
      done: [
        { title: "Reflection paper: Fruits of the Spirit", score: "Complete" },
        { title: "Memory verse recitation", score: "Complete" },
      ]
    },
    english: {
      title: "English",
      teacher: "Mrs. Whitfield",
      grade: "GRADE 6",
      room: "Room 6-A · Tue & Thu, 8:00–8:50",
      pending: [
        { title: "Reading response: Short Story Unit", due: "Due Sep 13", type: "Reading" },
      ],
      done: [
        { title: "Grammar quiz: Subject-verb agreement", score: "Complete" },
        { title: "Vocabulary quiz: Unit 4", score: "Complete" },
      ]
    },
  };

  function toggleAuth(which){
    document.getElementById('login-card').style.display = which === 'login' ? 'block' : 'none';
    document.getElementById('register-card').style.display = which === 'register' ? 'block' : 'none';
  }

  function showView(name){
    document.querySelectorAll('[data-view]').forEach(el => el.classList.remove('active'));
    document.querySelector('[data-view="' + name + '"]').classList.add('active');
    window.scrollTo(0,0);
  }

  function openCourse(id){
    const c = courses[id];
    if(!c) return;

    document.getElementById('course-topbar-title').textContent = c.title;
    document.getElementById('course-eyebrow').textContent = c.grade;
    document.getElementById('course-title').textContent = c.title;
    document.getElementById('course-teacher-line').textContent = 'Taught by ' + c.teacher;
    document.getElementById('course-room-line').textContent = c.room;

    const wrap = document.getElementById('work-columns');
    wrap.innerHTML = '';

    const pendingCol = document.createElement('div');
    pendingCol.innerHTML =
      '<div class="work-col-head"><h4>Pending</h4><span class="count-badge">' + c.pending.length + '</span></div>';
    if(c.pending.length === 0){
      pendingCol.innerHTML += '<div class="empty-state">Nothing pending. New work will show up here.</div>';
    } else {
      c.pending.forEach((item, i) => {
        pendingCol.innerHTML +=
          '<div class="work-item" id="pending-' + i + '">' +
            '<button class="work-check" onclick="markDone(\'' + id + '\',' + i + ')" aria-label="Mark as done">' +
              '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>' +
            '</button>' +
            '<div class="work-body">' +
              '<div class="work-title">' + item.title + '</div>' +
              '<div class="work-sub"><span class="' + (item.soon ? 'due-soon' : '') + '">' + item.due + '</span><span class="type-tag">' + item.type + '</span></div>' +
            '</div>' +
          '</div>';
      });
    }

    const doneCol = document.createElement('div');
    doneCol.innerHTML =
      '<div class="work-col-head"><h4>Completed</h4><span class="count-badge">' + c.done.length + '</span></div>';
    if(c.done.length === 0){
      doneCol.innerHTML += '<div class="empty-state">Completed work will appear here.</div>';
    } else {
      c.done.forEach(item => {
        doneCol.innerHTML +=
          '<div class="work-item done">' +
            '<div class="work-check"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg></div>' +
            '<div class="work-body">' +
              '<div class="work-title">' + item.title + '</div>' +
              '<div class="work-sub"><span class="score-chip">' + item.score + '</span></div>' +
            '</div>' +
          '</div>';
      });
    }

    wrap.appendChild(pendingCol);
    wrap.appendChild(doneCol);

    showView('course');
  }

  function markDone(courseId, index){
    const el = document.getElementById('pending-' + index);
    if(el){
      el.classList.add('done');
      setTimeout(() => { el.style.opacity = '0.5'; }, 150);
    }
  }
