(function(){
const API='/api/orders';
const STEPS=['Сущность','Заголовок','РП-пост','Пункты','Вложения','Предпросмотр','Отправка'];
let orders=[];
let current=null;
let publicImages=[];
let privateAttachments=[];
let step=0;
let sessionInfo=null;
let autosaveTimer=null;

const q=s=>document.querySelector(s);
const qa=s=>[...document.querySelectorAll(s)];

function authHeaders(){
  const t=new URLSearchParams(location.search).get('token')||localStorage.getItem('player_admin_token')||'';
  return t?{'X-Player-Admin-Token':t}:{};
}

function normalizeAttachment(x, fallbackVisibility='private'){
  if(!x) return null;
  if(typeof x==='string') return {url:x,visibility:fallbackVisibility,access_scope:fallbackVisibility,source:'web',kind:'file',title:'',description:'',tags:[],meta:{}};
  if(typeof x!=='object') return null;
  const visibility=(x.visibility==='public')?'public':'private';
  return {
    id:x.id||undefined,
    url:x.url||'',
    visibility,
    access_scope:x.access_scope||visibility,
    mime:x.mime||'',
    size_bytes:Number(x.size_bytes||0),
    file_name:x.file_name||'',
    source:x.source||'web',
    kind:x.kind||'file',
    title:x.title||'',
    description:x.description||'',
    tags:Array.isArray(x.tags)?x.tags:[],
    checksum_sha1:x.checksum_sha1||'',
    meta:(x.meta&&typeof x.meta==='object')?x.meta:{},
    uploaded_at:x.uploaded_at||''
  };
}

function syncCurrentToForm(){
  if(!current) return;
  current.title=q('#title').value.trim();
  current.turn_year=Number(q('#turnYear').value||0);
  current.rp_post=q('#rpPost').value;
  current.action_items=collectItems();
  current.public_images=[...publicImages];
  current.private_attachments=[...privateAttachments];
}

function draftValidationForStep(i){
  if(i===0){
    if(!sessionInfo) return 'Сессия игрока не найдена';
  }
  if(i===1){
    const t=q('#title').value.trim();
    const y=Number(q('#turnYear').value||0);
    if(t.length<5) return 'Заголовок должен быть не короче 5 символов';
    if(y<1) return 'Укажите год/ход';
  }
  if(i===2){
    const rp=q('#rpPost').value.trim();
    if(rp.length<80) return 'РП-пост слишком короткий (минимум 80 символов)';
  }
  if(i===3){
    const items=collectItems().filter(it=>it.summary.trim()!==''||it.details.trim()!=='');
    if(items.length===0) return 'Добавьте хотя бы один пункт намерений';
    const bad=items.find(it=>it.summary.trim().length<3);
    if(bad) return 'У каждого заполненного пункта должен быть краткий summary (минимум 3 символа)';
  }
  return '';
}

function canMoveToStep(target){
  for(let i=0;i<target;i++){
    const err=draftValidationForStep(i);
    if(err) return err;
  }
  return '';
}

function renderStepper(){
  const box=q('#stepper');
  box.innerHTML='';
  STEPS.forEach((title,i)=>{
    const el=document.createElement('button');
    el.type='button';
    el.className='step'+(i===step?' active':'');
    el.textContent=(i+1)+'. '+title;
    el.onclick=()=>{
      if(i===step) return;
      const err = i>step ? canMoveToStep(i) : '';
      if(err) return alert(err);
      step=i;
      renderStepper();
    };
    box.appendChild(el);
  });
  qa('.pane').forEach(p=>p.classList.toggle('active', Number(p.dataset.step)===step));
  q('#prevStep').disabled=step===0;
  q('#nextStep').disabled=step===STEPS.length-1;
  if(step===5) renderPreview();
}

function renderNotices(){
  const c={draft:0,submitted:0,needs_clarification:0,verdict_ready:0,published:0};
  orders.forEach(o=>{ if(c[o.status]!=null) c[o.status]++; });
  const box=q('#statusNotices'); box.innerHTML='';
  Object.entries(c).forEach(([k,v])=>{ if(v>0){ const b=document.createElement('span'); b.className='badge'; b.textContent=`${k}: ${v}`; box.appendChild(b);} });
}

function renderList(){
  const sf=q('#statusFilter').value;
  const tf=Number(q('#turnFilter').value||0);
  const list=q('#list');
  list.innerHTML='';
  orders.filter(o=>(!sf||o.status===sf)&&(!tf||Number(o.turn_year)===tf)).forEach(o=>{
    const d=document.createElement('div');
    d.className='order';
    d.innerHTML=`<b>${o.title||'Без названия'}</b><div class="muted">${o.status} · ${o.turn_year}</div>`;
    d.onclick=()=>openOrder(o);
    list.appendChild(d);
  });
}

function drawItems(arr){
  const box=q('#items');
  box.innerHTML='';
  arr.forEach((it,i)=>{
    const d=document.createElement('div');
    d.className='item';
    d.dataset.id=it.id||'';
    d.innerHTML=`<div class='row'>
      <select data-k='category'><option>economy</option><option>politics</option><option>laws</option><option>diplomacy</option><option>military</option><option>religion</option><option>intrigue</option><option>other</option></select>
      <input data-k='summary' placeholder='Кратко'>
    </div>
    <textarea data-k='details' placeholder='Детали'></textarea>
    <div class='actions' style='margin-top:6px'><button type='button' data-del='1'>Удалить пункт</button></div>`;
    d.querySelector('[data-k=category]').value=it.category||'other';
    d.querySelector('[data-k=summary]').value=it.summary||'';
    d.querySelector('[data-k=details]').value=it.details||'';
    d.querySelector('[data-del=1]').onclick=()=>{
      const items=collectItems();
      items.splice(i,1);
      drawItems(items);
      renderPreview();
      queueAutosave();
    };
    d.querySelectorAll('input,textarea,select').forEach(el=>el.addEventListener('input',()=>{ renderPreview(); queueAutosave(); }));
    box.appendChild(d);
  });
}

function collectItems(){
  return qa('#items .item').map((el,i)=>(
    {id:el.dataset.id||undefined,sort_index:i+1,category:el.querySelector('[data-k=category]').value,summary:el.querySelector('[data-k=summary]').value,details:el.querySelector('[data-k=details]').value}
  ));
}

function drawAttachments(){
  q('#publicImages').textContent=publicImages.map(a=>a.url||'').join('\n') || '—';
  q('#privateAttachments').textContent=privateAttachments.map(a=>`${a.url||''}  [${a.access_scope||'private'}]`).join('\n') || '—';
}

function renderPreview(){
  const lines=[];
  lines.push('Сущность: ' + (sessionInfo ? `${sessionInfo.entity_type}:${sessionInfo.entity_id}` : '—'));
  lines.push('Заголовок: ' + (q('#title').value||'—'));
  lines.push('Ход/год: ' + (q('#turnYear').value||'—'));
  lines.push('\nРП-пост:\n' + (q('#rpPost').value||'—'));
  lines.push('\nПункты:');
  collectItems().forEach((it,i)=>lines.push(`${i+1}. [${it.category}] ${it.summary||'—'}${it.details?`\n   ${it.details}`:''}`));
  lines.push(`\nПубличных вложений: ${publicImages.length}`);
  lines.push(`Приватных вложений: ${privateAttachments.length}`);
  q('#preview').textContent=lines.join('\n');
}

function fillAttachmentForm(att){
  q('#attUrl').value=att?.url||'';
  q('#attVisibility').value=att?.visibility||'public';
  q('#attAccessScope').value=att?.access_scope||((att?.visibility==='public')?'public':'private');
  q('#attKind').value=att?.kind||'file';
  q('#attTitle').value=att?.title||'';
  q('#attTags').value=Array.isArray(att?.tags)?att.tags.join(', '):'';
  q('#attDescription').value=att?.description||'';
}

function appendAttachmentFromFields(){
  const att=normalizeAttachment({
    url:q('#attUrl').value.trim(),
    visibility:q('#attVisibility').value,
    access_scope:q('#attAccessScope').value,
    kind:q('#attKind').value,
    title:q('#attTitle').value.trim(),
    description:q('#attDescription').value.trim(),
    tags:q('#attTags').value.split(',').map(v=>v.trim()).filter(Boolean),
    source:'web'
  }, q('#attVisibility').value);
  if(!att||!att.url) return alert('Укажите URL вложения');
  if(att.visibility==='public') publicImages.push(att); else privateAttachments.push(att);
  fillAttachmentForm(null);
  drawAttachments();
  renderPreview();
  queueAutosave();
}

async function uploadSelectedFile(){
  const file=q('#imageFile').files[0];
  if(!file) return;
  const fd=new FormData();
  fd.append('file',file);
  fd.append('visibility',q('#attVisibility').value);
  fd.append('access_scope',q('#attAccessScope').value);
  fd.append('kind',q('#attKind').value);
  fd.append('title',q('#attTitle').value.trim());
  fd.append('description',q('#attDescription').value.trim());
  fd.append('tags',q('#attTags').value.trim());
  const r=await fetch('/api/orders/upload/index.php',{method:'POST',headers:authHeaders(),body:fd});
  const d=await r.json();
  if(d.error) return alert(d.error);
  const att=normalizeAttachment(d.attachment, q('#attVisibility').value);
  if(att){
    if(att.visibility==='public') publicImages.push(att); else privateAttachments.push(att);
    drawAttachments();
    renderPreview();
    queueAutosave();
  }
  q('#imageFile').value='';
}

function applyOrderToForm(o){
  current=o;
  publicImages=((o.public_images||[]).map(x=>normalizeAttachment(x,'public')).filter(Boolean));
  privateAttachments=((o.private_attachments||[]).map(x=>normalizeAttachment(x,'private')).filter(Boolean));
  q('#title').value=o.title||'';
  q('#turnYear').value=o.turn_year||'';
  q('#rpPost').value=o.rp_post||'';
  drawItems(o.action_items||[]);
  drawAttachments();
  const v=o.verdict||null;
  q('#verdictBox').textContent=v?`Публичный вердикт:\n${v.public_verdict_text||''}\n\nУточнение:\n${v.clarification_request_text||''}\n\nБроски:\n${JSON.stringify(v.rolls||[],null,2)}`:'';
  renderPreview();
}

function newOrder(){
  current=null;
  publicImages=[];
  privateAttachments=[];
  q('#title').value='';
  q('#turnYear').value='';
  q('#rpPost').value='';
  drawItems([]);
  drawAttachments();
  fillAttachmentForm(null);
  q('#verdictBox').textContent='';
  step=0;
  renderStepper();
  renderPreview();
}

async function save(submit){
  syncCurrentToForm();
  const payload={
    title:q('#title').value.trim(),
    turn_year:Number(q('#turnYear').value||0),
    rp_post:q('#rpPost').value,
    action_items:collectItems(),
    public_images:publicImages,
    private_attachments:privateAttachments,
    source:'web'
  };

  if(!current){
    const r=await fetch(API+'/index.php',{method:'POST',headers:{...authHeaders(),'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json();
    if(d.error) return alert(d.error);
    current=d.order;
  }else{
    const r=await fetch('/api/orders/patch/index.php?id='+encodeURIComponent(current.id),{method:'POST',headers:{...authHeaders(),'Content-Type':'application/json'},body:JSON.stringify({...payload,version:current.version})});
    const d=await r.json();
    if(d.error) return alert(d.error);
    current=d.order;
  }

  if(submit && current){
    const rs=await fetch('/api/orders/submit/index.php?id='+encodeURIComponent(current.id),{method:'POST',headers:authHeaders()});
    const ds=await rs.json();
    if(ds.error) return alert(ds.error);
    current=ds.order;
  }

  await load();
  if(current){
    const found=orders.find(o=>o.id===current.id);
    if(found) applyOrderToForm(found);
  }
}

function queueAutosave(){
  if(autosaveTimer) clearTimeout(autosaveTimer);
  autosaveTimer=setTimeout(async()=>{
    if(step < 2) return;
    if(current && current.status!=='draft' && current.status!=='needs_clarification') return;
    const err=canMoveToStep(Math.min(4, step));
    if(err) return;
    try { await save(false); } catch(_e) {}
  }, 1200);
}

async function loadSession(){
  const r=await fetch('/api/player-admin/session/index.php',{headers:authHeaders()});
  const d=await r.json();
  sessionInfo=d.session||null;
  q('#entityInfo').textContent=sessionInfo?`${sessionInfo.entity_name} (${sessionInfo.entity_type}:${sessionInfo.entity_id})`:'Сессия не найдена';
}

async function load(){
  await loadSession();
  const r=await fetch('/api/orders/my/index.php',{headers:authHeaders()});
  const d=await r.json();
  orders=d.items||[];
  renderList();
  renderNotices();
  if(orders[0]) applyOrderToForm(orders[0]); else newOrder();
}

q('#reloadBtn').onclick=load;
q('#newBtn').onclick=newOrder;
q('#statusFilter').onchange=renderList;
q('#turnFilter').oninput=renderList;
q('#addItemBtn').onclick=()=>{ const arr=collectItems(); arr.push({category:'other',summary:'',details:''}); drawItems(arr); renderPreview(); queueAutosave(); };
q('#addAttachmentBtn').onclick=appendAttachmentFromFields;
q('#uploadImageBtn').onclick=uploadSelectedFile;
q('#saveBtn').onclick=()=>save(false);
q('#submitBtn').onclick=()=>{
  const err=canMoveToStep(6);
  if(err) return alert(err);
  save(true);
};
q('#prevStep').onclick=()=>{ step=Math.max(0, step-1); renderStepper(); };
q('#nextStep').onclick=()=>{
  const err=draftValidationForStep(step);
  if(err) return alert(err);
  step=Math.min(STEPS.length-1, step+1);
  renderStepper();
};
q('#title').addEventListener('input', ()=>{ renderPreview(); queueAutosave(); });
q('#turnYear').addEventListener('input', ()=>{ renderPreview(); queueAutosave(); });
q('#rpPost').addEventListener('input', ()=>{ renderPreview(); queueAutosave(); });

renderStepper();
load();
})();
