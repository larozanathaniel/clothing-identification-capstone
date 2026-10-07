// Intelligent Laundry Management System - Connected to PHP REST API Backend
const $ = s => document.querySelector(s);
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let S = {
  users: [{u:'staff',p:'staff123',role:'staff',on:true},{u:'admin',p:'admin123',role:'admin',on:true}],
  batches: [], thr: 80, seq: 1000, times: []
};
let me = null, cur = null, tab = 'Open', stream = null, draft = {name:'', items:[]}, pend = null, sortId = null, fileFor = null;
const STATUS = ['Open','Washing','Ready to Sort','Completed'];
const B = id => S.batches.find(b => b.id === String(id));
const count = (b, st) => (b?.items || []).filter(i => i.st === st).length;
const unresolved = () => S.batches.flatMap(b => (b.flags || []).map(f => ({b, f})));

/* ---------- API client helpers ---------- */
async function api(endpoint, options = {}) {
  try {
    const res = await fetch(endpoint, {
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      ...options
    });
    const data = await res.json();
    if (!res.ok && data.error) {
      throw new Error(data.error);
    }
    return data;
  } catch (e) {
    console.warn('API error or offline mode:', e.message);
    throw e;
  }
}

async function refreshState() {
  try {
    const data = await api('api/batches.php');
    if (data && data.batches) {
      S.batches = data.batches;
      S.seq = data.seq || S.seq;
      S.thr = data.thr || S.thr;
    }
  } catch (e) {
    // Fallback to local storage if API server unavailable
    const local = JSON.parse(localStorage.getItem('lms') || 'null');
    if (local) S = local;
  }
}

const save = () => {
  try { localStorage.setItem('lms', JSON.stringify(S)); } catch (e) {}
};

/* ---------- UI helpers ---------- */
function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.remove('hidden'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.add('hidden'), 2500); }
function modal(html) { $('#modal-box').innerHTML = html; $('#modal').classList.remove('hidden'); }
function closeModal() { $('#modal').classList.add('hidden'); }
function confirmBox(msg, yes) {
  window._yes = () => { closeModal(); yes(); };
  modal(`<h3>Are you sure?</h3><p>${msg}</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_yes()">YES, CONTINUE</button></div>`);
}
const img = (src, n) => src ? `<img src="${src}" alt="${esc(n)}">` : `<div class="ph">&#128085;</div>`;
const stBadge = st => `<span class="badge ${{pending:'muted',auto:'success',manual:'info'}[st] || ''}">${{pending:'Not matched',auto:'Auto-matched',manual:'Manual match'}[st] || esc(st)}</span>`;

/* ---------- navigation ---------- */
const HOME = () => me && me.role === 'admin' ? 'admin' : 'intake';
const ALLOWED = {
  staff: ['intake','sorting','verification','batches','detail','summary','password'],
  admin: ['admin','batches','detail','summary']
};
function buildNav() {
  const n = unresolved().length;
  const items = me.role === 'admin'
    ? [['admin','Admin Dashboard'],['batches','List']]
    : [['intake','Intake Station'],['sorting','Sorting Station'],
       ['verification','Manual' + (n ? `<span class="pill">${n}</span>` : '')],
       ['batches','List'],['password','Profile']];
  $('#nav').innerHTML = items.map(([id,l]) => `<li data-s="${id}" onclick="navigateTo('${id}')">${l}</li>`).join('') + `<li onclick="logout()" class="logout">Logout</li>`;
}
function toggleMenu(force) {
  const open = force === undefined ? !$('#sidebar').classList.contains('open') : force;
  $('#sidebar').classList.toggle('open', open); $('#scrim').classList.toggle('show', open);
}
async function navigateTo(id) {
  stopCam(); toggleMenu(false);
  if (me && id !== 'login' && !ALLOWED[me.role].includes(id)) id = HOME();
  await refreshState();
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.body.classList.toggle('staff', !!me && me.role === 'staff');
  const hd = document.querySelector(`#${id}-screen .header-bar h2`); $('#topbar-title').textContent = hd ? hd.textContent : 'Laundry Mgmt';
  const t = $('#' + id + '-screen'); if (t) t.classList.add('active');
  if (me) { buildNav(); const li = document.querySelector(`#nav li[data-s="${id === 'detail' ? 'batches' : id}"]`); if (li) li.classList.add('active'); }
  ({intake:renderIntake, batches:renderBatches, detail:renderDetail, sorting:renderSorting,
    verification:renderVerify, summary:renderSummary, admin:renderAdmin, password:() => { $('#pw-role').textContent = me.role; $('#pf-user').value = me.u || ''; ['old','new','new2'].forEach(k => $('#pw-'+k).value=''); $('#pw-err').textContent=''; }}[id] || (()=>{}))();
}

/* ---------- auth ---------- */
async function login() {
  const u = $('#username').value.trim(), p = $('#password').value;
  if (!u || !p) { $('#login-err').textContent = 'Please enter username and password.'; return; }

  try {
    const res = await api('api/auth.php?action=login', {
      method: 'POST',
      body: JSON.stringify({ username: u, password: p })
    });
    if (res.success && res.user) {
      me = res.user;
      $('#login-err').textContent = '';
      $('#sidebar').classList.remove('hidden');
      navigateTo(HOME());
      return;
    }
  } catch (e) {
    // Fallback to local user check if offline
    const a = S.users.find(x => x.u === u && x.p === p);
    if (!a) { $('#login-err').textContent = e.message || 'Invalid username or password.'; return; }
    if (!a.on) { $('#login-err').textContent = 'This account is deactivated. Contact an admin.'; return; }
    me = a; $('#login-err').textContent = ''; $('#sidebar').classList.remove('hidden');
    navigateTo(HOME());
  }
}

async function logout() {
  try { await api('api/auth.php?action=logout', { method: 'POST' }); } catch(e){}
  me = null; $('#sidebar').classList.add('hidden'); navigateTo('login');
  $('#username').value = ''; $('#password').value = '';
}

async function changePassword() {
  const o = $('#pw-old').value, n = $('#pw-new').value, n2 = $('#pw-new2').value, e = $('#pw-err');
  if (!o || !n || !n2) return e.textContent = 'All fields are required.';
  if (n.length < 6) return e.textContent = 'New password must be at least 6 characters.';
  if (n !== n2) return e.textContent = 'New passwords do not match.';

  try {
    await api('api/auth.php?action=change_password', {
      method: 'POST',
      body: JSON.stringify({ old_password: o, new_password: n, confirm_password: n2 })
    });
    me.p = n; save(); navigateTo('password'); toast('Password updated');
  } catch (err) {
    e.textContent = err.message || 'Failed to update password.';
  }
}

/* ---------- camera ---------- */
async function startCam(sec) {
  const box = $(`#${sec}-screen .camera-preview`), v = box.querySelector('video'), m = box.querySelector('.cam-msg');
  box.classList.remove('live'); m.textContent = 'Starting camera...';
  try {
    if (!navigator.mediaDevices?.getUserMedia) throw 0;
    stream = await navigator.mediaDevices.getUserMedia({video: true}); v.srcObject = stream; box.classList.add('live');
  } catch (e) { m.innerHTML = '&#9888; Camera blocked or unavailable.<br>Allow camera access in your browser, or use <b>Upload Photo</b>.'; }
}
function stopCam() { if (stream) stream.getTracks().forEach(t => t.stop()); stream = null; }
function snap(sec) {
  const v = $(`#${sec}-screen video`); if (!stream || !v.videoWidth) return null;
  const c = document.createElement('canvas'), w = 240; c.width = w; c.height = w * v.videoHeight / v.videoWidth;
  c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); return c.toDataURL('image/jpeg', .6);
}
function shrink(file, cb) {
  const r = new FileReader(); r.onload = () => { const im = new Image(); im.onload = () => {
    const c = document.createElement('canvas'), w = 240; c.width = w; c.height = w * im.height / im.width;
    c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); cb(c.toDataURL('image/jpeg', .6)); }; im.src = r.result; }; r.readAsDataURL(file);
}
function pickFile(sec) { fileFor = sec; $('#file').value = ''; $('#file').click(); }
$('#file').addEventListener('change', e => { const f = e.target.files[0]; if (f) shrink(f, d => gotPhoto(fileFor, d)); });
function capture(sec) {
  const d = snap(sec);
  if (d) gotPhoto(sec, d); else toast('Camera not available - use Upload Photo');
}
function gotPhoto(sec, d) { sec === 'intake' ? addItem(d) : runMatch(d); }

/* ---------- intake ---------- */
function renderIntake() { startCam('intake'); drawDraft(); }
function drawDraft() {
  $('#in-name').value = draft.name; $('#in-id').value = 'AUTO-' + (S.seq + 1); $('#in-count').value = draft.items.length;
  $('#in-thumbs').innerHTML = draft.items.length ? draft.items.map((i,k) => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)} <a href="#" onclick="rmItem(${k});return false">&times;</a></div>`).join('') : '<div class="empty-state w-full">No garments scanned yet.</div>';
}
function addItem(d) { draft.items.push({id: 'i' + Date.now() + draft.items.length, name: 'Item ' + (draft.items.length + 1), img: d, st: 'pending'}); drawDraft(); }
function rmItem(k) { draft.items.splice(k, 1); draft.items.forEach((i, n) => i.name = 'Item ' + (n + 1)); drawDraft(); }
function resetDraft() { draft = {name:'', items:[]}; drawDraft(); }

async function saveBatch() {
  if (!draft.name.trim()) return toast('Enter the customer name first');
  if (!draft.items.length) return toast('Scan at least one garment');

  try {
    const res = await api('api/batches.php', {
      method: 'POST',
      body: JSON.stringify({ customer: draft.name.trim(), items: draft.items })
    });
    const id = res.id;
    draft = {name:'', items:[]};
    toast(`Batch #${id} saved`);
    cur = id;
    await refreshState();
    navigateTo('detail');
  } catch (e) {
    // Local fallback
    const id = String(++S.seq);
    S.batches.unshift({id, customer: draft.name.trim(), status: 'Open', items: draft.items, flags: [], created: Date.now()});
    save(); draft = {name:'', items:[]}; toast(`Batch #${id} saved (local)`); cur = id; navigateTo('detail');
  }
}

/* ---------- batches ---------- */
function renderBatches() {
  $('#tabs').innerHTML = STATUS.map(s => `<button class="tab ${s === tab ? 'active' : ''}" onclick="tab='${s}';renderBatches()">${s}</button>`).join('');
  const q = ($('#search').value || '').toLowerCase();
  const list = S.batches.filter(b => b.status === tab && (b.id + b.customer).toLowerCase().includes(q));
  $('#batch-list').innerHTML = list.length ? list.map(b => `<div class="batch-item" onclick="cur='${b.id}';navigateTo('detail')"><div><b>Batch #${b.id}</b> - ${esc(b.customer)}<br><span class="text-sm">${b.items.length} items - tap to view intake details</span></div><span class="badge muted">${b.status}</span></div>`).join('')
    : `<div class="empty-state">${q ? 'No batches match your search.' : `No ${tab.toLowerCase()} batches yet.`}</div>`;
}

function renderDetail() {
  const b = B(cur); if (!b) return navigateTo('batches');
  $('#d-title').textContent = 'BATCH #' + b.id; $('#d-status').textContent = b.status;
  const next = {Open:['Start Washing','Washing','Move this batch to Washing?'], Washing:['Mark Ready to Sort','Ready to Sort','Mark this batch Ready to Sort? Items can then be scanned at the Sorting Station.']}[b.status];
  $('#detail-body').innerHTML = `<div class="form-row"><div class="form-group"><label>Customer</label><input readonly value="${esc(b.customer)}"></div><div class="form-group"><label>Received</label><input readonly value="${new Date(b.created).toLocaleString()}"></div></div>
    <p class="text-sm">Garments (${b.items.length}) - ${count(b,'auto')} auto, ${count(b,'manual')} manual, ${count(b,'pending')} not yet matched</p>
    <div class="garments">${b.items.map(i => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)}<br>${stBadge(i.st)}</div>`).join('')}</div>
    <div class="btn-group mt-2"><button class="btn-secondary" onclick="navigateTo('batches')">&larr; BACK</button>
    ${next ? `<button class="btn-primary" onclick="confirmBox('${next[2]}',()=>setStatus('${b.id}','${next[1]}'))">${next[0].toUpperCase()}</button>` : ''}
    ${b.status === 'Ready to Sort' && me.role === 'staff' ? `<button class="btn-primary" onclick="sortId='${b.id}';navigateTo('sorting')">GO TO SORTING</button>` : ''}
    ${b.status === 'Completed' ? `<button class="btn-primary" onclick="navigateTo('summary')">VIEW SUMMARY</button>` : ''}</div>`;
}

async function setStatus(id, st) {
  try {
    await api('api/batches.php', {
      method: 'PUT',
      body: JSON.stringify({ id, status: st })
    });
    toast(`Batch #${id}: ${st}`);
  } catch (e) {
    if (B(id)) B(id).status = st; save(); toast(`Batch #${id}: ${st}`);
  }
  await refreshState();
  renderDetail();
}

/* ---------- sorting & visual similarity matching ---------- */
function renderSorting() {
  const ready = S.batches.filter(b => b.status === 'Ready to Sort');
  if (!ready.find(b => b.id === sortId)) sortId = ready[0]?.id || null;
  $('#s-batch').innerHTML = ready.length ? ready.map(b => `<option value="${b.id}" ${b.id === sortId ? 'selected' : ''}>#${b.id} - ${esc(b.customer)}</option>`).join('') : '<option>No open batches yet</option>';
  drawSort();
  if (sortId) startCam('sorting'); else { $('#sorting-screen .cam-msg').textContent = 'Mark a batch Ready to Sort to begin.'; $('#sorting-screen .camera-preview').classList.remove('live'); }
}
function pickSortBatch(id) { sortId = id; pend = null; drawSort(); }
function drawSort() {
  const b = B(sortId), r = $('#sort-result');
  $('#s-badge').textContent = 'Batch #' + (b ? b.id : '----');
  $('#s-progress').textContent = b ? `${b.items.length - count(b,'pending')} of ${b.items.length} items sorted` : '';
  $('#s-scan').disabled = !b; $('#s-confirm').disabled = true; r.classList.add('hidden'); r.innerHTML = '';
}

async function runMatch(d) {
  const b = B(sortId); if (!b) return toast('Select a batch first');
  const cand = b.items.filter(i => i.st === 'pending'); if (!cand.length) return toast('Nothing left to match');
  const r = $('#sort-result'); r.classList.remove('hidden'); r.innerHTML = '<div class="loading w-full">Analyzing visual features...</div>'; $('#s-confirm').disabled = true; $('#s-scan').disabled = true;

  try {
    const res = await api('api/match.php', {
      method: 'POST',
      body: JSON.stringify({ batch_id: sortId, scanned_img: d })
    });
    $('#s-scan').disabled = false;
    pend = { bid: b.id, scan: d, list: res.list, top: res.top };
    const top = res.list[0];

    if (res.auto_matched) {
      const it = b.items.find(i => i.id === top.id);
      r.innerHTML = `<div class="scanned-img">${img(d,'scan')}</div><div class="match-info"><p><strong>Match: </strong>${esc(it ? it.name : top.name)} - ${esc(b.customer)}</p><p class="text-sm">PHP GD Visual Similarity: ${top.sim}% (threshold ${res.thr}%)</p><span class="badge success">RECOGNIZED (${res.duration}s)</span></div>${img(it ? it.img : '', top.name)}`;
      $('#s-confirm').disabled = false;
    } else {
      r.classList.add('hidden');
      toast(`Low confidence (${top.sim}%) - manual verification needed`);
      navigateTo('verification');
    }
  } catch (e) {
    // Never fabricate a match result: show the real error so it can be fixed
    $('#s-scan').disabled = false;
    r.classList.add('hidden'); r.innerHTML = '';
    console.error('Match failed:', e);
    toast('Matching failed: ' + e.message);
  }
}

async function confirmSort() {
  if (!pend?.top) return;
  try {
    const res = await api('api/sort_confirm.php', {
      method: 'POST',
      body: JSON.stringify({ batch_id: pend.bid, item_id: pend.top, st: 'auto', sim: (pend.list.find(c => c.id === pend.top) || {}).sim })
    });
    const b = B(pend.bid);
    pend = null;
    await afterMatch(b);
  } catch (e) {
    const b = B(pend.bid); if (b) b.items.find(i => i.id === pend.top).st = 'auto'; pend = null; afterMatch(b);
  }
}

async function afterMatch(b, then) {
  await refreshState();
  const updatedB = B(b.id) || b;
  if (count(updatedB, 'pending') === 0 && updatedB.flags && updatedB.flags.length) {
    save(); toast('All items matched - resolve or dismiss flagged garments to finish');
    return navigateTo('verification');
  }
  if (count(updatedB, 'pending') === 0) {
    save(); cur = updatedB.id;
    return navigateTo('summary');
  }
  save(); toast('Item sorted'); navigateTo(then || 'sorting');
}

/* ---------- manual verification ---------- */
let pick = null;
function flaggedHTML() {
  const u = unresolved();
  if (!u.length) return '';
  return `<p class="text-sm" style="margin-top:20px">Flagged garments (${u.length})</p>` + u.map(({b,f}) => `<div class="row"><div>${img(f.img,'')}<span>Batch #${b.id} - ${esc(b.customer)}<br><span class="text-sm">${b.items.length - count(b,'pending')} of ${b.items.length} sorted</span></span></div><button class="btn-primary" style="width:auto" onclick="openResolve('${b.id}','${f.id}')">Resolve</button></div>`).join('');
}
function renderVerify() {
  const el = $('#verify-body');
  if (!pend) return el.innerHTML = '<div class="empty-state">Nothing to verify. Low-confidence scans from the Sorting Station appear here.</div>' + flaggedHTML() + '<button class="btn-secondary w-full mt-2" onclick="navigateTo(\'sorting\')">&larr; BACK TO SORTING</button>';
  const b = B(pend.bid); pick = null;
  el.innerHTML = `<p class="text-center text-sm">No confident match found - select correct owner</p>
    <div class="camera-preview small-preview">${pend.scan ? `<video hidden></video><img src="${pend.scan}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain">` : ''}</div>
    <p class="text-sm">Closest candidates in Batch #${b.id} (${esc(b.customer)}):</p>
    ${pend.list.slice(0,5).map(c => { const it = b.items.find(i => i.id === c.id); return `<div class="row pick" data-id="${c.id}" onclick="selCand('${c.id}')"><div>${img(it ? it.img : '', it ? it.name : c.name)}<span>${esc(it ? it.name : c.name)}<br><span class="text-sm">Similarity ${c.sim}%</span></span></div><span class="badge muted">select</span></div>`; }).join('')}
    <div class="btn-group mt-2"><button class="btn-primary" id="v-ok" disabled onclick="confirmSel()">&#10003; CONFIRM SELECTED</button>
    <button class="btn-warning" onclick="confirmBox('Flag this garment as unresolved? Staff can resolve it later.',flagUnres)">&#9888; FLAG UNRESOLVED</button></div>` + flaggedHTML();
}
function selCand(id) { pick = id; document.querySelectorAll('#verify-body .row').forEach(r => r.classList.toggle('sel', r.dataset.id === id)); $('#v-ok').disabled = false; }

async function confirmSel() {
  try {
    await api('api/sort_confirm.php', {
      method: 'POST',
      body: JSON.stringify({ batch_id: pend.bid, item_id: pick, st: 'manual', sim: (pend.list.find(c => c.id === pick) || {}).sim })
    });
    const b = B(pend.bid); pend = null; await afterMatch(b);
  } catch (e) {
    const b = B(pend.bid); b.items.find(i => i.id === pick).st = 'manual'; pend = null; afterMatch(b);
  }
}

async function flagUnres() {
  try {
    await api('api/flags.php?action=flag', {
      method: 'POST',
      body: JSON.stringify({ batch_id: pend.bid, img: pend.scan })
    });
    pend = null; toast('Flagged as unresolved'); navigateTo('sorting');
  } catch (e) {
    const b = B(pend.bid); b.flags.push({id: 'f' + Date.now(), img: pend.scan}); pend = null; save(); toast('Flagged as unresolved'); navigateTo('sorting');
  }
}

/* ---------- flagged garments (resolved from the Manual screen) ---------- */
function openResolve(bid, fid) {
  const b = B(bid), f = (b.flags || []).find(x => x.id === fid) || {id: fid, img: ''};
  window._res = async () => {
    const id = $('#res-sel').value; if (!id) return;
    confirmBox('Assign this garment to the selected item?', async () => {
      try {
        await api('api/flags.php?action=assign', {
          method: 'POST',
          body: JSON.stringify({ flag_id: fid, batch_id: bid, item_id: id })
        });
        afterMatch(b, 'verification');
      } catch (e) {
        b.items.find(i => i.id === id).st = 'manual'; b.flags = b.flags.filter(x => x.id !== fid); afterMatch(b, 'verification');
      }
    });
  };
  window._dis = async () => confirmBox('Dismiss this flag? Use this only if the garment is already accounted for.', async () => {
    try {
      await api('api/flags.php?action=dismiss', {
        method: 'POST',
        body: JSON.stringify({ flag_id: fid, batch_id: bid })
      });
      afterMatch(b, 'verification');
    } catch (e) {
      b.flags = b.flags.filter(x => x.id !== fid); afterMatch(b, 'verification');
    }
  });

  const pend_ = b.items.filter(i => i.st === 'pending');
  if (!pend_.length) return modal(`<h3>Batch #${b.id}</h3><p>Every garment in this batch is already matched, so this flag is stale.</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CLOSE</button><button class="btn-primary" onclick="_dis()">DISMISS FLAG</button></div>`);
  modal(`<h3>Resolve garment - Batch #${b.id}</h3><div class="thumbs">${img(f.img,'')}</div><div class="form-group" style="margin-top:14px"><label>Which garment is this?</label><select id="res-sel">${b.items.filter(i => i.st === 'pending').map(i => `<option value="${i.id}">${esc(i.name)}</option>`).join('')}</select></div><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_res()">ASSIGN</button></div>`);
}

/* ---------- batch complete summary ---------- */
function renderSummary() {
  const b = B(cur); if (!b) return;
  const a = count(b,'auto'), m = count(b,'manual');
  $('#summary-body').innerHTML = `<div style="font-size:3rem">&#9989;</div><div class="big">All ${b.items.length} items matched: ${a} auto, ${m} manual</div>
    <p>Batch #${b.id} - ${esc(b.customer)}</p><p class="text-sm">Ready to hand back to the customer.</p>
    <div class="garments" style="justify-content:center">${b.items.map(i => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)}<br>${stBadge(i.st)}</div>`).join('')}</div>
    <div class="btn-group mt-2"><button class="btn-secondary" onclick="tab='Completed';navigateTo('batches')">VIEW COMPLETED</button>${me.role === 'staff' ? `<button class="btn-primary" onclick="navigateTo('sorting')">NEXT BATCH</button>` : ''}</div>`;
}

/* ---------- admin ---------- */
async function renderAdmin() {
  try {
    const data = await api('api/admin.php');
    S.users = data.users || S.users;
    S.thr = data.thr || S.thr;

    const acc = data.accuracy;
    const avg = data.avg_time;
    const statusCounts = data.status_counts || {};

    const mx = Math.max(1, ...STATUS.map(s => statusCounts[s] || 0));
    $('#admin-body').innerHTML = `<div class="stats-grid"><div class="stat-box"><h3>${acc}%</h3><p>Match Accuracy</p></div><div class="stat-box"><h3>${avg}s</h3><p>Avg. Match Time</p></div></div>
      <div class="bars">${STATUS.map(s => { const n = statusCounts[s] || 0; return `<div><i style="height:${n / mx * 100}px"></i>${n}<br>${s}</div>`; }).join('')}</div>
      <div class="mt-2"><p class="text-sm">Match threshold (similarity needed for auto-match)</p>
      <div class="row"><input type="number" id="thr" min="50" max="99" value="${S.thr}" style="max-width:100px"> <span>%</span><button class="btn-primary" style="width:auto" onclick="saveThr()">SAVE</button></div></div>
      <div class="user-management mt-2"><p class="text-sm">Staff / User Management (RBAC)</p>
      ${S.users.map((u,k) => `<div class="row"><div><b>${esc(u.u)}</b> <span class="badge muted">${u.role}</span> <span class="badge ${u.on ? 'success' : 'danger'}">${u.on ? 'Active' : 'Deactivated'}</span></div><button class="btn-secondary" onclick="userForm(${k})">Edit</button></div>`).join('')}
      <button class="btn-secondary w-full mt-2" onclick="userForm(-1)">+ ADD USER</button></div>`;
  } catch (e) {
    const auto = S.batches.reduce((n,b) => n + count(b,'auto'), 0), man = S.batches.reduce((n,b) => n + count(b,'manual'), 0);
    const acc = auto + man ? Math.round(auto / (auto + man) * 100) : 0, avg = S.times.length ? (S.times.reduce((a,c) => a + c, 0) / S.times.length).toFixed(1) : '0.0';
    const mx = Math.max(1, ...STATUS.map(s => S.batches.filter(b => b.status === s).length));
    $('#admin-body').innerHTML = `<div class="stats-grid"><div class="stat-box"><h3>${acc}%</h3><p>Match Accuracy</p></div><div class="stat-box"><h3>${avg}s</h3><p>Avg. Match Time</p></div></div>
      <div class="bars">${STATUS.map(s => { const n = S.batches.filter(b => b.status === s).length; return `<div><i style="height:${n / mx * 100}px"></i>${n}<br>${s}</div>`; }).join('')}</div>
      <div class="mt-2"><p class="text-sm">Match threshold (similarity needed for auto-match)</p>
      <div class="row"><input type="number" id="thr" min="50" max="99" value="${S.thr}" style="max-width:100px"> <span>%</span><button class="btn-primary" style="width:auto" onclick="saveThr()">SAVE</button></div></div>
      <div class="user-management mt-2"><p class="text-sm">Staff / User Management (RBAC)</p>
      ${S.users.map((u,k) => `<div class="row"><div><b>${esc(u.u)}</b> <span class="badge muted">${u.role}</span> <span class="badge ${u.on ? 'success' : 'danger'}">${u.on ? 'Active' : 'Deactivated'}</span></div><button class="btn-secondary" onclick="userForm(${k})">Edit</button></div>`).join('')}
      <button class="btn-secondary w-full mt-2" onclick="userForm(-1)">+ ADD USER</button></div>`;
  }
}

async function saveThr() {
  const v = +$('#thr').value;
  if (!(v >= 50 && v <= 99)) return toast('Enter a value from 50 to 99');
  try {
    await api('api/admin.php?action=threshold', {
      method: 'POST',
      body: JSON.stringify({ thr: v })
    });
    S.thr = v; save(); toast('Threshold saved');
  } catch (e) {
    S.thr = v; save(); toast('Threshold saved');
  }
}

function userForm(k) {
  const u = k < 0 ? {id: -1, u:'', p:'', role:'staff', on:true} : S.users[k];
  window._u = async () => {
    const n = $('#uf-u').value.trim(), p = $('#uf-p').value, e = $('#uf-err');
    if (!n) return e.textContent = 'Username is required.';
    if (k < 0 && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    if (k >= 0 && p && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    const on = $('#uf-on').checked, role = $('#uf-role').value;
    if (k >= 0 && S.users[k]?.u === me?.u && (!on || role !== 'admin')) return e.textContent = "You can't deactivate or demote your own account.";

    try {
      await api('api/admin.php?action=save_user', {
        method: 'POST',
        body: JSON.stringify({ id: u.id, u: n, p, role, on })
      });
      closeModal(); renderAdmin(); toast('User saved');
    } catch (err) {
      e.textContent = err.message || 'Failed to save user.';
    }
  };
  modal(`<h3>${k < 0 ? 'Add User' : 'Edit User'}</h3>
    <div class="form-group"><label>Username</label><input id="uf-u" value="${esc(u.u)}"></div>
    <div class="form-group"><label>Password ${k < 0 ? '' : '(leave blank to keep)'}</label><input type="password" id="uf-p"></div>
    <div class="form-group"><label>Role</label><select id="uf-role"><option value="staff" ${u.role === 'staff' ? 'selected' : ''}>Staff</option><option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option></select></div>
    <label><input type="checkbox" class="check" id="uf-on" ${u.on ? 'checked' : ''}> Account active (untick to deactivate)</label>
    <p class="err" id="uf-err" style="margin-top:8px"></p>
    <div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_u()">SAVE USER</button></div>`);
}

// Initial state load
refreshState();
