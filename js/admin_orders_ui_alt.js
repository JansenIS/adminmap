(function(){
const ADMIN=localStorage.getItem('admin_token')||'dev-admin-token';
const H={'X-Admin-Token':ADMIN,'Content-Type':'application/json'};
const q=s=>document.querySelector(s);

const esc=s=>String(s??'').replace(/[&<>"']/g,ch=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[ch]));
const nl2br=s=>esc(s).replace(/\r\n|\r|\n/g,'<br>');
let rows=[]; let cur=null; let effectsState=[];

function setBusy(busy){ document.querySelectorAll('button').forEach(b=>b.disabled=busy); }

async function load(){
  const params=new URLSearchParams();
  ['turn','status','entity','category','hasAttachments'].forEach(id=>{ const v=q('#'+id).value.trim(); if(v!=='') params.set(id==='turn'?'turn_year':id, v); });
  const r=await fetch('/api/orders/admin-queue/index.php?'+params.toString(),{headers:H});
  const d=await r.json(); rows=d.items||[]; renderList();
}

function renderList(){
  const list=q('#list'); list.innerHTML='';
  rows.forEach(o=>{ const d=document.createElement('div'); d.className='order'; d.innerHTML=`<b>${o.title||'—'}</b><div class='muted'>${o.status} · ${o.turn_year} · ${o.entity_id}</div>`; d.onclick=()=>openOrder(o); list.appendChild(d); });
}

function renderEffectsPreview(){
  const out=q('#effectsPreview');
  const arr=effectsState;
  if(!arr.length){ out.textContent='Эффекты не добавлены.'; q('#effectsList').textContent=''; q('#effects').value='[]'; return; }
  const lines=arr.map((e,i)=>`${i+1}) ${e.effect_type} :: ${JSON.stringify(e.payload)}`);
  q('#effectsList').textContent=lines.join('\n');
  q('#effects').value=JSON.stringify(arr,null,2);
  out.textContent=lines.join('\n');
}


function renderImages(images){
  const box=q('#images');
  if(!box) return;
  const arr=Array.isArray(images)?images:[];
  if(!arr.length){ box.innerHTML=''; return; }
  box.innerHTML=arr.map(a=>{ const u=(typeof a==='string')?a:(a?.url||''); return u?`<div><a href='${u}' target='_blank' style='color:#9dc8ff'>📎 ${u}</a></div><div><img src='${u}' style='max-width:220px;max-height:160px;border:1px solid #2f4760;border-radius:8px;margin:4px 0'></div>`:''; }).join('');
}

function openOrder(o){
  cur=o;
  q('#title').textContent=o.title||'—';
  q('#rp').innerHTML=nl2br(o.rp_post||'');
  renderImages(o.public_images||[]);
  q('#publicVerdict').value=o.verdict?.public_verdict_text||'';
  q('#privateNotes').value=o.verdict?.private_notes||'';
  effectsState=Array.isArray(o.effects)?o.effects:[];
  renderEffectsPreview();

  const items=q('#items'); items.innerHTML='';
  (o.action_items||[]).forEach(it=>{
    const row=document.createElement('div'); row.className='item';
    const roll=(o.verdict?.rolls||[]).find(r=>r.order_action_item_id===it.id);
    row.innerHTML=`<div><b>${esc(it.category)}</b> — ${esc(it.summary)}</div><div class='muted' style='white-space:pre-wrap'>${nl2br(it.details||'')}</div><div class='row'><input class='mod' type='number' value='0'><button class='roll'>Бросить d20</button><div class='muted'>${roll?('total '+roll.total+' '+roll.outcome_tier):'нет броска'}</div></div>`;
    row.querySelector('.roll').onclick=async()=>{
      if(!cur) return;
      const modifier=Number(row.querySelector('.mod').value||0);
      await mutate('/api/orders/roll/index.php',{order_action_item_id:it.id,modifier});
    };
    items.appendChild(row);
  });
}

function collectEffectFromForm(){
  const t=q('#effType').value;
  const itemId=q('#effItemId').value.trim();
  const pid=q('#effPid').value.trim();
  const delta=Number(q('#effDelta').value||0);
  const reason=q('#effReason').value.trim();
  const payload={};
  if(pid) payload.pid=pid;
  if(['treasury_delta','entity_income_delta','province_income_delta','garrison_change','militia_change'].includes(t)) payload.delta=delta;
  if(reason){
    if(t==='map_event_note') payload.note=reason;
    else payload.reason=reason;
  }
  return {
    id:'ef_ui_'+Math.random().toString(36).slice(2,10),
    order_id:cur?.id||'',
    order_action_item_id:itemId,
    effect_type:t,
    payload,
    is_enabled:true,
    applied:false,
    applied_at:'',
    applied_by:''
  };
}

async function mutate(path,payload){
  if(!cur) return alert('Выберите приказ');
  setBusy(true);
  try{
    const full={...payload,version:cur.version};
    const r=await fetch(path+'?id='+encodeURIComponent(cur.id),{method:'POST',headers:H,body:JSON.stringify(full)});
    const d=await r.json();
    if(d.error){ alert(d.error); return; }
    cur=d.order||cur;
    await load();
    const f=rows.find(x=>x.id===cur.id);
    if(f) openOrder(f);
    if(d.outbox_run){
      alert('Публикация: posted='+d.outbox_run.posted+' failed='+d.outbox_run.failed+(d.outbox_job_status?(' status='+d.outbox_job_status.status+' err='+ (d.outbox_job_status.last_error||'')) : ''));
    }
  } finally { setBusy(false); }
}

q('#reload').onclick=load;
['turn','status','entity','category','hasAttachments'].forEach(id=>q('#'+id).addEventListener('change',load));
q('#addEffect').onclick=()=>{ if(!cur) return alert('Сначала выберите приказ'); effectsState.push(collectEffectFromForm()); renderEffectsPreview(); };
q('#clearEffects').onclick=()=>{ effectsState=[]; renderEffectsPreview(); };
q('#saveVerdict').onclick=()=>mutate('/api/orders/verdict/index.php',{public_verdict_text:q('#publicVerdict').value,private_notes:q('#privateNotes').value,effects:effectsState});
q('#apply').onclick=()=>mutate('/api/orders/apply-effects/index.php',{});
q('#publish').onclick=()=>mutate('/api/orders/publish/index.php',{});
q('#clar').onclick=()=>mutate('/api/orders/clarification/index.php',{text:q('#clarText').value});
q('#reject').onclick=()=>mutate('/api/orders/reject/index.php',{});

load();
})();
