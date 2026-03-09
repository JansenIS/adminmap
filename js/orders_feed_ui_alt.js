(async function(){
const q=s=>document.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>\"']/g,ch=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[ch]));
const nl2br=s=>esc(s).replace(/\r\n|\r|\n/g,'<br>');

async function load(){
  const url='/api/orders/feed/index.php?entity_id='+encodeURIComponent(q('#entity').value)+'&turn='+encodeURIComponent(q('#turn').value)+'&category='+encodeURIComponent(q('#category').value);
  const r=await fetch(url);
  const d=await r.json();
  const f=q('#feed');
  f.innerHTML='';
  (d.items||[]).forEach(it=>{
    const c=document.createElement('article');
    c.className='card';
    const imgs=(it.images||[]).map(a=>{
      const u=(typeof a==='string')?a:(a?.url||'');
      return u?`<a href='${u}' target='_blank'><img src='${u}' style='max-width:220px;max-height:160px;border:1px solid #2f4760;border-radius:8px;margin:4px'></a>`:'';
    }).join('');
    c.innerHTML=`<h3>${esc(it.title||'')}</h3><div class='meta'>${esc(it.turn_year||'')} · ${esc(it.entity_id||'')} · ${esc((it.categories||[]).join(', '))}</div><p style='white-space:pre-wrap'>${nl2br(it.rp_post||'')}</p><div>${imgs}</div><h4>Вердикт</h4><p style='white-space:pre-wrap'>${nl2br(it.public_verdict_text||'')}</p>`;
    f.appendChild(c);
  });
}
q('#reload').onclick=load; q('#entity').oninput=()=>{}; q('#turn').oninput=()=>{}; q('#category').onchange=()=>{}; await load();
})();
