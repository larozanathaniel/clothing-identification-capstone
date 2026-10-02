// Intelligent Laundry Management System - front-end prototype (data kept in localStorage)
const $ = s => document.querySelector(s);
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let S = JSON.parse(localStorage.getItem('lms') || 'null') || {
  users: [{u:'staff',p:'staff123',role:'staff',on:true},{u:'admin',p:'admin123',role:'admin',on:true}],
  batches: [], thr: 80, seq: 1000, times: []
};
const save = () => { try { localStorage.setItem('lms', JSON.stringify(S)); } catch (e) { toast('Storage full - photo not saved'); } };
let me = null, cur = null, tab = 'Open', stream = null, draft = {name:'', items:[]}, pend = null, sortId = null, fileFor = null;
const STATUS = ['Open','Washing','Ready to Sort','Completed'];
const B = id => S.batches.find(b => b.id === id);
const count = (b, st) => b.items.filter(i => i.st === st).length;
const unresolved = () => S.batches.flatMap(b => (b.flags || []).map(f => ({b, f})));

/* ---------- helpers ---------- */
function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.remove('hidden'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.add('hidden'), 2500); }
function modal(html) { $('#modal-box').innerHTML = html; $('#modal').classList.remove('hidden'); }
function closeModal() { $('#modal').classList.add('hidden'); }
function confirmBox(msg, yes) {  // 7. confirmation dialogs
  window._yes = () => { closeModal(); yes(); };
  modal(`<h3>Are you sure?</h3><p>${msg}</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_yes()">YES, CONTINUE</button></div>`);
}
const img = (src, n) => src ? `<img src="${src}" alt="${esc(n)}">` : `<div class="ph">&#128085;</div>`;
const stBadge = st => `<span class="badge ${{pending:'muted',auto:'success',manual:'info'}[st] || ''}">${{pending:'Not matched',auto:'Auto-matched',manual:'Manual match'}[st] || esc(st)}</span>`;

/* ---------- navigation ---------- */
function buildNav() {
  const n = unresolved().length;
  const items = [['dashboard','Dashboard'],['intake','Intake Station'],['batches','Active Batches'],['sorting','Sorting Station'],
    ['unresolved','Unresolved Items' + (n ? `<span class="pill">${n}</span>` : '')]];
  if (me.role === 'admin') items.push(['admin','Admin Dashboard']);
  items.push(['password','Change Password']);
  $('#nav').innerHTML = items.map(([id,l]) => `<li data-s="${id}" onclick="navigateTo('${id}')">${l}</li>`).join('') + `<li onclick="logout()" class="logout">Logout</li>`;
}
function navigateTo(id) {
  stopCam();
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const t = $('#' + id + '-screen'); if (t) t.classList.add('active');
  if (me) { buildNav(); const li = document.querySelector(`#nav li[data-s="${id}"]`); if (li) li.classList.add('active'); }
  ({dashboard:renderDash, intake:renderIntake, batches:renderBatches, detail:renderDetail, sorting:renderSorting,
    verification:renderVerify, unresolved:renderUnres, admin:renderAdmin, password:() => { $('#pw-role').textContent = me.role; ['old','new','new2'].forEach(k => $('#pw-'+k).value=''); $('#pw-err').textContent=''; }}[id] || (()=>{}))();
}

/* ---------- login / logout / password (9) ---------- */
function login() {
  const u = $('#username').value.trim(), p = $('#password').value, a = S.users.find(x => x.u === u && x.p === p);
  if (!a) { $('#login-err').textContent = 'Invalid username or password.'; return; }
  if (!a.on) { $('#login-err').textContent = 'This account is deactivated. Contact an admin.'; return; }
  me = a; $('#login-err').textContent = ''; $('#sidebar').classList.remove('hidden');
  navigateTo('dashboard');
}
function logout() {
  me = null; $('#sidebar').classList.add('hidden'); navigateTo('login');
  $('#username').value = ''; $('#password').value = '';
}
function changePassword() {
  const o = $('#pw-old').value, n = $('#pw-new').value, n2 = $('#pw-new2').value, e = $('#pw-err');
  if (o !== me.p) return e.textContent = 'Current password is incorrect.';
  if (n.length < 6) return e.textContent = 'New password must be at least 6 characters.';
  if (n !== n2) return e.textContent = 'New passwords do not match.';
  me.p = n; save(); navigateTo('password'); toast('Password updated');
}

/* ---------- camera (5) ---------- */
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

/* ---------- staff dashboard ---------- */
function renderDash() {
  const c = s => S.batches.filter(b => b.status === s).length;
  $('#dash-body').innerHTML = `<div class="stats-grid">
    <div class="stat-box"><h3>${c('Open')}</h3><p>Open</p></div>
    <div class="stat-box"><h3>${c('Washing')}</h3><p>Washing</p></div>
    <div class="stat-box"><h3>${c('Ready to Sort')}</h3><p>Ready to Sort</p></div></div>
    <p class="text-sm">Needs attention</p>
    ${unresolved().length ? unresolved().slice(0,5).map(({b,f}) => `<div class="row"><div>${img(f.img,'')}<span>Batch #${b.id} - ${esc(b.customer)}<br><span class="text-sm">Unresolved garment</span></span></div><button class="btn-warning" onclick="openResolve('${b.id}','${f.id}')">Review</button></div>`).join('')
      : '<div class="empty-state">No unresolved items. All clear.</div>'}
    <div class="btn-group mt-2"><button class="btn-secondary" onclick="navigateTo('intake')">+ NEW INTAKE</button><button class="btn-secondary" onclick="navigateTo('batches')">VIEW BATCHES</button><button class="btn-primary" onclick="navigateTo('sorting')">START SORTING</button></div>`;
}

/* ---------- intake ---------- */
function renderIntake() { startCam('intake'); drawDraft(); }
function drawDraft() {
  $('#in-name').value = draft.name; $('#in-id').value = 'AUTO-' + (S.seq + 1); $('#in-count').value = draft.items.length;
  $('#in-thumbs').innerHTML = draft.items.length ? draft.items.map((i,k) => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)} <a href="#" onclick="rmItem(${k});return false">&times;</a></div>`).join('') : '<div class="empty-state w-full">No garments scanned yet.</div>';
}
function addItem(d) { draft.items.push({id: 'i' + Date.now() + draft.items.length, name: 'Item ' + (draft.items.length + 1), img: d, st: 'pending'}); drawDraft(); }
function rmItem(k) { draft.items.splice(k, 1); draft.items.forEach((i, n) => i.name = 'Item ' + (n + 1)); drawDraft(); }
function resetDraft() { draft = {name:'', items:[]}; drawDraft(); }
function saveBatch() {
  if (!draft.name.trim()) return toast('Enter the customer name first');
  if (!draft.items.length) return toast('Scan at least one garment');
  const id = String(++S.seq);
  S.batches.unshift({id, customer: draft.name.trim(), status: 'Open', items: draft.items, flags: [], created: Date.now()});
  save(); draft = {name:'', items:[]}; toast(`Batch #${id} saved`); cur = id; navigateTo('detail');
}

/* ---------- batches (1, 6) ---------- */
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
    ${b.status === 'Ready to Sort' ? `<button class="btn-primary" onclick="sortId='${b.id}';navigateTo('sorting')">GO TO SORTING</button>` : ''}
    ${b.status === 'Completed' ? `<button class="btn-primary" onclick="navigateTo('summary')">VIEW SUMMARY</button>` : ''}</div>`;
}
function setStatus(id, st) { B(id).status = st; save(); toast(`Batch #${id}: ${st}`); renderDetail(); }

/* ---------- sorting & matching (6) ---------- */
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
function runMatch(d) {  // simulated model - replace with real similarity call
  const b = B(sortId); if (!b) return toast('Select a batch first');
  const cand = b.items.filter(i => i.st === 'pending'); if (!cand.length) return toast('Nothing left to match');
  const r = $('#sort-result'); r.classList.remove('hidden'); r.innerHTML = '<div class="loading w-full">Matching...</div>'; $('#s-confirm').disabled = true; $('#s-scan').disabled = true;
  const t0 = performance.now();
  setTimeout(() => {
    $('#s-scan').disabled = false;
    const list = cand.map(i => ({i, sim: .5 + Math.random() * .49})).sort((a, c) => c.sim - a.sim);
    if (Math.random() < .3) list[0].sim = Math.min(list[0].sim, .7);  // demo: occasional low-confidence case
    S.times.push((performance.now() - t0) / 1000);
    pend = {bid: b.id, scan: d, list: list.map(x => ({id: x.i.id, sim: Math.round(x.sim * 100)}))};
    const top = pend.list[0];
    if (top.sim >= S.thr) {
      const it = b.items.find(i => i.id === top.id);
      r.innerHTML = `<div class="scanned-img">${img(d,'scan')}</div><div class="match-info"><p><strong>Match: </strong>${esc(it.name)} - ${esc(b.customer)}</p><p class="text-sm">Similarity: ${top.sim}% (threshold ${S.thr}%)</p><span class="badge success">RECOGNIZED</span></div>${img(it.img,it.name)}`;
      $('#s-confirm').disabled = false; pend.top = top.id;
    } else { save(); r.classList.add('hidden'); toast('Low confidence - manual verification needed'); navigateTo('verification'); }
  }, 900);
}
function confirmSort() {
  if (!pend?.top) return;
  const b = B(pend.bid); b.items.find(i => i.id === pend.top).st = 'auto'; pend = null; afterMatch(b);
}
function afterMatch(b, then) {
  if (count(b, 'pending') === 0 && b.flags.length) { save(); toast('All items matched - resolve or dismiss flagged garments to finish'); return navigateTo('unresolved'); }
  if (count(b, 'pending') === 0) { b.status = 'Completed'; b.done = Date.now(); save(); cur = b.id; return navigateTo('summary'); }  // 8, 10
  save(); toast('Item sorted'); navigateTo(then || 'sorting');
}

/* ---------- manual verification ---------- */
let pick = null;
function renderVerify() {
  const el = $('#verify-body');
  if (!pend) return el.innerHTML = '<div class="empty-state">Nothing to verify. Low-confidence scans from the Sorting Station appear here.</div><button class="btn-secondary w-full mt-2" onclick="navigateTo(\'sorting\')">&larr; BACK TO SORTING</button>';
  const b = B(pend.bid); pick = null;
  el.innerHTML = `<p class="text-center text-sm">No confident match found - select correct owner</p>
    <div class="camera-preview small-preview">${pend.scan ? `<video hidden></video><img src="${pend.scan}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain">` : ''}</div>
    <p class="text-sm">Closest candidates in Batch #${b.id} (${esc(b.customer)}):</p>
    ${pend.list.slice(0,5).map(c => { const it = b.items.find(i => i.id === c.id); return `<div class="row pick" data-id="${c.id}" onclick="selCand('${c.id}')"><div>${img(it.img,it.name)}<span>${esc(it.name)}<br><span class="text-sm">Similarity ${c.sim}%</span></span></div><span class="badge muted">select</span></div>`; }).join('')}
    <div class="btn-group mt-2"><button class="btn-primary" id="v-ok" disabled onclick="confirmSel()">&#10003; CONFIRM SELECTED</button>
    <button class="btn-warning" onclick="confirmBox('Flag this garment as unresolved? Staff can resolve it later.',flagUnres)">&#9888; FLAG UNRESOLVED</button></div>`;
}
function selCand(id) { pick = id; document.querySelectorAll('#verify-body .row').forEach(r => r.classList.toggle('sel', r.dataset.id === id)); $('#v-ok').disabled = false; }
function confirmSel() { const b = B(pend.bid); b.items.find(i => i.id === pick).st = 'manual'; pend = null; afterMatch(b); }
function flagUnres() {
  const b = B(pend.bid); b.flags.push({id: 'f' + Date.now(), img: pend.scan}); pend = null; save(); toast('Flagged as unresolved'); navigateTo('sorting');
}

/* ---------- unresolved items (2) ---------- */
function renderUnres() {
  const u = unresolved(); $('#u-count').textContent = u.length;
  $('#unres-body').innerHTML = u.length ? u.map(({b,f}) => `<div class="row"><div>${img(f.img,'')}<span>Batch #${b.id} - ${esc(b.customer)}<br><span class="text-sm">${b.items.length - count(b,'pending')} of ${b.items.length} sorted</span></span></div><button class="btn-primary" onclick="openResolve('${b.id}','${f.id}')">Resolve</button></div>`).join('')
    : '<div class="empty-state">No unresolved items.</div>';
}
function openResolve(bid, fid) {
  const b = B(bid), f = b.flags.find(x => x.id === fid);
  window._res = () => { const id = $('#res-sel').value; if (!id) return; confirmBox('Assign this garment to the selected item?', () => { b.items.find(i => i.id === id).st = 'manual'; b.flags = b.flags.filter(x => x.id !== fid); afterMatch(b, 'unresolved'); }); };
  window._dis = () => confirmBox('Dismiss this flag? Use this only if the garment is already accounted for.', () => { b.flags = b.flags.filter(x => x.id !== fid); afterMatch(b, 'unresolved'); });
  const pend_ = b.items.filter(i => i.st === 'pending');
  if (!pend_.length) return modal(`<h3>Batch #${b.id}</h3><p>Every garment in this batch is already matched, so this flag is stale.</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CLOSE</button><button class="btn-primary" onclick="_dis()">DISMISS FLAG</button></div>`);
  modal(`<h3>Resolve garment - Batch #${b.id}</h3><div class="thumbs">${img(f.img,'')}</div><div class="form-group" style="margin-top:14px"><label>Which garment is this?</label><select id="res-sel">${b.items.filter(i => i.st === 'pending').map(i => `<option value="${i.id}">${esc(i.name)}</option>`).join('')}</select></div><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_res()">ASSIGN</button></div>`);
}

/* ---------- batch complete summary (8, 10) ---------- */
function renderSummary() {
  const b = B(cur); if (!b) return;
  const a = count(b,'auto'), m = count(b,'manual');
  $('#summary-body').innerHTML = `<div style="font-size:3rem">&#9989;</div><div class="big">All ${b.items.length} items matched: ${a} auto, ${m} manual</div>
    <p>Batch #${b.id} - ${esc(b.customer)}</p><p class="text-sm">Ready to hand back to the customer.</p>
    <div class="garments" style="justify-content:center">${b.items.map(i => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)}<br>${stBadge(i.st)}</div>`).join('')}</div>
    <div class="btn-group mt-2"><button class="btn-secondary" onclick="tab='Completed';navigateTo('batches')">VIEW COMPLETED</button><button class="btn-primary" onclick="navigateTo('sorting')">NEXT BATCH</button></div>`;
}

/* ---------- admin (3, 4) ---------- */
function renderAdmin() {
  const act = S.batches.filter(b => b.status !== 'Completed').length, auto = S.batches.reduce((n,b) => n + count(b,'auto'), 0), man = S.batches.reduce((n,b) => n + count(b,'manual'), 0);
  const acc = auto + man ? Math.round(auto / (auto + man) * 100) : 0, avg = S.times.length ? (S.times.reduce((a,c) => a + c, 0) / S.times.length).toFixed(1) : '0.0';
  const mx = Math.max(1, ...STATUS.map(s => S.batches.filter(b => b.status === s).length));
  $('#admin-body').innerHTML = `<div class="stats-grid"><div class="stat-box"><h3>${act}</h3><p>Active Batches</p></div><div class="stat-box"><h3>${acc}%</h3><p>Match Accuracy</p></div><div class="stat-box"><h3>${avg}s</h3><p>Avg. Match Time</p></div></div>
    <div class="bars">${STATUS.map(s => { const n = S.batches.filter(b => b.status === s).length; return `<div><i style="height:${n / mx * 100}px"></i>${n}<br>${s}</div>`; }).join('')}</div>
    <div class="mt-2"><p class="text-sm">Match threshold (similarity needed for auto-match)</p>
    <div class="row"><input type="number" id="thr" min="50" max="99" value="${S.thr}" style="max-width:100px"> <span>%</span><button class="btn-primary" style="width:auto" onclick="saveThr()">SAVE</button></div></div>
    <div class="user-management mt-2"><p class="text-sm">Staff / User Management (RBAC)</p>
    ${S.users.map((u,k) => `<div class="row"><div><b>${esc(u.u)}</b> <span class="badge muted">${u.role}</span> <span class="badge ${u.on ? 'success' : 'danger'}">${u.on ? 'Active' : 'Deactivated'}</span></div><button class="btn-secondary" onclick="userForm(${k})">Edit</button></div>`).join('')}
    <button class="btn-secondary w-full mt-2" onclick="userForm(-1)">+ ADD USER</button></div>`;
}
function saveThr() { const v = +$('#thr').value; if (!(v >= 50 && v <= 99)) return toast('Enter a value from 50 to 99'); S.thr = v; save(); toast('Threshold saved'); }
function userForm(k) {
  const u = k < 0 ? {u:'', p:'', role:'staff', on:true} : S.users[k];
  window._u = () => {
    const n = $('#uf-u').value.trim(), p = $('#uf-p').value, e = $('#uf-err');
    if (!n) return e.textContent = 'Username is required.';
    if (S.users.some((x,i) => x.u === n && i !== k)) return e.textContent = 'Username already exists.';
    if (k < 0 && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    if (k >= 0 && p && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    const on = $('#uf-on').checked, role = $('#uf-role').value;
    if (k >= 0 && S.users[k] === me && (!on || role !== 'admin')) return e.textContent = "You can't deactivate or demote your own account.";
    const rec = {u:n, p: p || u.p, role, on};
    k < 0 ? S.users.push(rec) : Object.assign(S.users[k], rec); save(); closeModal(); renderAdmin(); toast('User saved');
  };
  modal(`<h3>${k < 0 ? 'Add User' : 'Edit User'}</h3>
    <div class="form-group"><label>Username</label><input id="uf-u" value="${esc(u.u)}"></div>
    <div class="form-group"><label>Password ${k < 0 ? '' : '(leave blank to keep)'}</label><input type="password" id="uf-p"></div>
    <div class="form-group"><label>Role</label><select id="uf-role"><option value="staff" ${u.role === 'staff' ? 'selected' : ''}>Staff</option><option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option></select></div>
    <label><input type="checkbox" class="check" id="uf-on" ${u.on ? 'checked' : ''}> Account active (untick to deactivate)</label>
    <p class="err" id="uf-err" style="margin-top:8px"></p>
    <div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_u()">SAVE USER</button></div>`);
}

// hook the summary renderer into navigation
var _nav = navigateTo;
navigateTo = function (id) { _nav(id); if (id === 'summary') renderSummary(); };
