(async function(){
const q=s=>document.querySelector(s);
async function load(){const url='/api/orders/feed/index.php?entity_id='+encodeURIComponent(q('#entity').value)+'&turn='+encodeURIComponent(q('#turn').value)+'&category='+encodeURIComponent(q('#category').value); const r=await fetch(url); const d=await r.json(); const f=q('#feed'); f.innerHTML=''; (d.items||[]).forEach(it=>{const c=document.createElement('article'); c.className='card'; c.innerHTML=`<h3>${it.title}</h3><div class='meta'>${it.turn_year} · ${it.entity_id} · ${ (it.categories||[]).join(', ') }</div><p>${it.rp_post||''}</p><h4>Вердикт</h4><p>${it.public_verdict_text||''}</p>`; f.appendChild(c);}); }
q('#reload').onclick=load; q('#entity').oninput=()=>{}; q('#turn').oninput=()=>{}; q('#category').onchange=()=>{}; await load();
})();
