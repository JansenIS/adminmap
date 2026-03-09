(function(){
const ADMIN=localStorage.getItem('admin_token')||'dev-admin-token'; let queue=[]; let cur=null;
const H={'X-Admin-Token':ADMIN,'Content-Type':'application/json'}; const q=s=>document.querySelector(s);
function render(){
  const turn=Number(q('#turn').value||0), st=q('#status').value, ent=q('#entity').value.trim().toLowerCase();
  const box=q('#list'); box.innerHTML='';
  queue.filter(o=>(!turn||o.turn_year===turn)&&(!st||o.status===st)&&(!ent||(o.entity_id||'').toLowerCase().includes(ent))).forEach(o=>{
    const d=document.createElement('div'); d.className='order';
    d.innerHTML=`<b>${o.title}</b><div class='muted'>${o.entity_id} · ${o.status} · v${o.version||0}</div>`;
    d.onclick=()=>open(o); box.appendChild(d);
  });
}
function open(o){
  cur=o;
  q('#title').textContent=o.title;
  q('#rp').textContent=o.rp_post||'';
  q('#publicVerdict').value=o.verdict?.public_verdict_text||'';
  q('#privateNotes').value=o.verdict?.private_notes||'';
  q('#clarText').value='';
  q('#effects').value=JSON.stringify(o.effects||[],null,2);
  const items=q('#items'); items.innerHTML='';
  (o.action_items||[]).forEach(it=>{
    const row=document.createElement('div'); row.className='item';
    const roll=(o.verdict?.rolls||[]).find(r=>r.order_action_item_id===it.id);
    row.innerHTML=`<b>${it.category}</b> ${it.summary}<div class='muted'>${it.details||''}</div><div class='row'><input data-mid='${it.id}' type='number' min='-9' max='9' value='0'><button data-roll='${it.id}'>Roll d20</button><div class='muted'>${roll?`raw ${roll.roll_raw} / mod ${roll.modifier} / total ${roll.total} / ${roll.outcome_tier}`:'нет броска'}</div></div>`;
    items.appendChild(row);
  });
  items.querySelectorAll('[data-roll]').forEach(b=>b.onclick=async()=>{
    const id=b.dataset.roll;
    const mod=Number(items.querySelector(`[data-mid="${id}"]`).value||0);
    const r=await fetch('/api/orders/roll/index.php?id='+encodeURIComponent(cur.id),{method:'POST',headers:H,body:JSON.stringify({order_action_item_id:id,modifier:mod,version:cur.version})});
    const d=await r.json();
    if(d.error) return alert(d.error + (d.expected?` (ожидалась версия ${d.expected})`:''));
    await load();
    const fresh=queue.find(x=>x.id===cur.id); if(fresh) open(fresh);
  });
}
async function load(){
  const turn=Number(q('#turn').value||0), st=q('#status').value, ent=q('#entity').value.trim();
  const url='/api/orders/admin-queue/index.php?turn='+turn+'&status='+encodeURIComponent(st)+'&entity='+encodeURIComponent(ent);
  const r=await fetch(url,{headers:{'X-Admin-Token':ADMIN}}); const d=await r.json();
  queue=d.items||[]; render();
}
async function act(path,body={}){
  if(!cur) return;
  const payload={...body,version:cur.version};
  const r=await fetch(path+'?id='+encodeURIComponent(cur.id),{method:'POST',headers:H,body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.error) return alert(d.error + (d.expected?` (ожидалась версия ${d.expected})`:''));
  await load();
  if(d.order){ cur=d.order; const fresh=queue.find(x=>x.id===cur.id)||cur; open(fresh); }
}
q('#reload').onclick=load; q('#turn').oninput=render; q('#status').onchange=load; q('#entity').oninput=load;
q('#saveVerdict').onclick=()=>act('/api/orders/verdict/index.php',{public_verdict_text:q('#publicVerdict').value,private_notes:q('#privateNotes').value,effects:JSON.parse(q('#effects').value||'[]')});
q('#apply').onclick=()=>act('/api/orders/apply-effects/index.php',{});
q('#publish').onclick=()=>act('/api/orders/publish/index.php',{});
q('#clar').onclick=()=>act('/api/orders/clarification/index.php',{text:q('#clarText').value});
q('#reject').onclick=()=>act('/api/orders/reject/index.php',{});
load();
})();
