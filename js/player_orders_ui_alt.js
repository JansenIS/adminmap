(function(){
const API='/api/orders'; let orders=[]; let current=null; let images=[];
const q=s=>document.querySelector(s);
function authHeaders(){const t=new URLSearchParams(location.search).get('token')||localStorage.getItem('player_admin_token')||''; return t?{'X-Player-Admin-Token':t}:{};}
function renderList(){const sf=q('#statusFilter').value; const tf=Number(q('#turnFilter').value||0); const list=q('#list'); list.innerHTML=''; orders.filter(o=>(!sf||o.status===sf)&&(!tf||Number(o.turn_year)===tf)).forEach(o=>{const d=document.createElement('div'); d.className='order'; d.innerHTML=`<b>${o.title||'Без названия'}</b><div class="muted">${o.status} · ${o.turn_year}</div>`; d.onclick=()=>openOrder(o); list.appendChild(d);});}
function openOrder(o){current=o; images=[...(o.public_images||[])]; q('#title').value=o.title||''; q('#turnYear').value=o.turn_year||''; q('#rpPost').value=o.rp_post||''; drawItems(o.action_items||[]); drawImages(); const v=o.verdict||null; q('#verdictBox').textContent=v?`Публичный вердикт:\n${v.public_verdict_text||''}\n\nУточнение:\n${v.clarification_request_text||''}\n\nБроски:\n${JSON.stringify(v.rolls||[],null,2)}`:''; }
function drawItems(arr){const box=q('#items'); box.innerHTML=''; arr.forEach((it,i)=>{const d=document.createElement('div'); d.className='item'; d.innerHTML=`<div class='row'><select data-k='category'><option>economy</option><option>politics</option><option>laws</option><option>diplomacy</option><option>military</option><option>religion</option><option>intrigue</option><option>other</option></select><input data-k='summary' placeholder='Кратко'></div><textarea data-k='details' placeholder='Детали'></textarea>`; d.querySelector('[data-k=category]').value=it.category||'other'; d.querySelector('[data-k=summary]').value=it.summary||''; d.querySelector('[data-k=details]').value=it.details||''; d.dataset.id=it.id||''; box.appendChild(d);});}
function collectItems(){return [...document.querySelectorAll('#items .item')].map((el,i)=>({id:el.dataset.id||undefined,sort_index:i+1,category:el.querySelector('[data-k=category]').value,summary:el.querySelector('[data-k=summary]').value,details:el.querySelector('[data-k=details]').value}));}
function drawImages(){q('#images').textContent=images.join('\n');}
async function load(){const r=await fetch('/api/orders/my/index.php',{headers:authHeaders()}); const d=await r.json(); orders=d.items||[]; renderList(); if(orders[0]) openOrder(orders[0]);}
async function save(submit){const payload={title:q('#title').value,turn_year:Number(q('#turnYear').value||0),rp_post:q('#rpPost').value,action_items:collectItems(),public_images:images,source:'web'};
 if(!current){const r=await fetch(API+'/index.php',{method:'POST',headers:{...authHeaders(),'Content-Type':'application/json'},body:JSON.stringify(payload)}); const d=await r.json(); current=d.order;} else {const r=await fetch('/api/orders/patch/index.php?id='+encodeURIComponent(current.id),{method:'POST',headers:{...authHeaders(),'Content-Type':'application/json'},body:JSON.stringify({...payload,version:current.version})}); const d=await r.json(); current=d.order;}
 if(submit&&current){await fetch('/api/orders/submit/index.php?id='+encodeURIComponent(current.id),{method:'POST',headers:authHeaders()});}
 await load(); if(current){const found=orders.find(o=>o.id===current.id); if(found) openOrder(found);} }
q('#reloadBtn').onclick=load; q('#statusFilter').onchange=renderList; q('#turnFilter').oninput=renderList;
q('#newBtn').onclick=()=>{current=null; images=[]; q('#title').value=''; q('#turnYear').value=''; q('#rpPost').value=''; drawItems([]); drawImages(); q('#verdictBox').textContent='';};
q('#addItemBtn').onclick=()=>{const arr=collectItems(); arr.push({category:'other',summary:'',details:''}); drawItems(arr);};
q('#addImageBtn').onclick=()=>{const v=q('#publicImage').value.trim(); if(v) images.push(v); q('#publicImage').value=''; drawImages();};
q('#saveBtn').onclick=()=>save(false); q('#submitBtn').onclick=()=>save(true);
setInterval(()=>{if(current&&current.status==='draft') save(false);},45000);
load();
})();
