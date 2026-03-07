(async function(){
  const byId=(id)=>document.getElementById(id);
  const fields=['group_id','confirmation_token','secret','access_token','api_version','public_base_url','routerai_api_key'];

  async function loadCfg(){
    const res=await fetch('/api/vk/config/'); const j=await res.json();
    const c=j.config||{}; byId('enabled').checked=!!c.enabled;
    fields.forEach(k=>byId(k).value=String(c[k]||''));
    byId('cbUrl').textContent='Callback URL: '+(c.public_base_url? (c.public_base_url+'/api/vk/callback/'):'/api/vk/callback/');
  }

  byId('saveCfg').onclick=async()=>{
    const payload={enabled:byId('enabled').checked};
    fields.forEach(k=>payload[k]=byId(k).value.trim());
    await fetch('/api/vk/config/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    await loadCfg(); alert('Сохранено');
  };

  async function loadApps(){
    const res=await fetch('/api/vk/applications/'); const j=await res.json();
    const rows=Array.isArray(j.items)?j.items:[];
    const tb=byId('appsBody'); tb.innerHTML='';
    rows.forEach((a)=>{
      const tr=document.createElement('tr');
      const f=a.form||{};
      const coa = (f.coa_svg||'').trim();
      const coaInfo = coa.startsWith('<svg') ? 'inline SVG' : (coa ? `<a href="${coa}" target="_blank" rel="noreferrer">SVG файл</a>` : '—');
      const mapLink = a.territory_image_path ? `<a href="${a.territory_image_path}" target="_blank" rel="noreferrer">Карта выбора</a>` : '—';
      const details = (a.registration_mode === 'existing')
        ? `Режим: existing<br>Сущность: ${a.selected_entity_type||''}:${a.selected_entity_id||''}<br>Имя: ${a.selected_entity_name||'—'}`
        : `Тип: ${a.state_type||''}<br>PID: ${a.chosen_pid||''}<br>Название: ${f.state_name||''}<br>Столица: ${f.capital_name||''}<br>Правитель: ${f.ruler_name||''}<br>Род: ${f.ruler_house||''}<br>Лор: ${(f.lore||'').slice(0,120)}<br>Герб: ${coaInfo}<br>${mapLink}`;
      tr.innerHTML=`<td>${a.id||''}</td><td>${a.status||''}</td><td>${a.vk_user_id||''}</td><td>${details}</td><td></td>`;
      const td=tr.lastElementChild;
      const edit=document.createElement('button'); edit.textContent='Редактировать'; edit.style.marginRight='6px';
      edit.onclick=async()=>{
        const patch={...a, form:{...(a.form||{})}};
        const set=(title,val)=>window.prompt(title, String(val||''));
        const stateName=set('Название государства', patch.form.state_name||''); if (stateName===null) return;
        const capitalName=set('Название столицы', patch.form.capital_name||''); if (capitalName===null) return;
        const rulerName=set('Полное имя правителя', patch.form.ruler_name||''); if (rulerName===null) return;
        const rulerHouse=set('Род правителя', patch.form.ruler_house||''); if (rulerHouse===null) return;
        const lore=set('Краткий лор', patch.form.lore||''); if (lore===null) return;
        const coaSvg=set('Герб (SVG-текст или URL)', patch.form.coa_svg||''); if (coaSvg===null) return;
        patch.form.state_name=stateName.trim();
        patch.form.capital_name=capitalName.trim();
        patch.form.ruler_name=rulerName.trim();
        patch.form.ruler_house=rulerHouse.trim();
        patch.form.lore=lore.trim();
        patch.form.coa_svg=coaSvg.trim();
        await fetch('/api/vk/applications/patch/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:a.id,action:'update',patch:{form:patch.form}})});
        await loadApps();
      };
      const approve=document.createElement('button'); approve.textContent='Одобрить';
      approve.onclick=async()=>{await fetch('/api/vk/applications/patch/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:a.id,action:'approve'})});loadApps();};
      const reject=document.createElement('button'); reject.textContent='Отклонить'; reject.style.marginLeft='6px';
      reject.onclick=async()=>{await fetch('/api/vk/applications/patch/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:a.id,action:'reject'})});loadApps();};
      td.appendChild(edit); td.appendChild(approve); td.appendChild(reject);
      tb.appendChild(tr);
    });
  }

  async function loadCharacterApps(){
    const body = byId('charAppsBody');
    if (!body) return;
    const res=await fetch('/api/vk/character_applications/'); const j=await res.json();
    const rows=Array.isArray(j.items)?j.items:[];
    body.innerHTML='';
    rows.forEach((a)=>{
      const tr=document.createElement('tr');
      const f=a.form||{};
      const relatives = Array.isArray(f.relatives)?f.relatives:[];
      const relText = relatives.slice(0,4).map((r)=>`${r.status||''}: ${r.name||''} (${r.birth_year||'?'})`).join('<br>') || '—';
      const details=`Сущность: ${a.approved_entity_type||''}:${a.approved_entity_id||''}<br>Год рожд.: ${f.birth_year||''}<br>Характер: ${(f.personality||'').slice(0,90)}<br>Биография: ${(f.biography||'').slice(0,90)}<br>Навыки: ${(f.skills||'').slice(0,90)}<br>Родственники:<br>${relText}`;
      tr.innerHTML=`<td>${a.id||''}</td><td>${a.status||''}</td><td>${a.vk_user_id||''}</td><td>${details}</td><td></td>`;
      const td=tr.lastElementChild;
      const approve=document.createElement('button'); approve.textContent='Одобрить';
      approve.onclick=async()=>{await fetch('/api/vk/character_applications/patch/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:a.id,action:'approve'})});loadCharacterApps();};
      const reject=document.createElement('button'); reject.textContent='Отклонить'; reject.style.marginLeft='6px';
      reject.onclick=async()=>{await fetch('/api/vk/character_applications/patch/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:a.id,action:'reject'})});loadCharacterApps();};
      td.appendChild(approve); td.appendChild(reject);
      body.appendChild(tr);
    });
  }

  function escapeHtml(v){
    return String(v ?? '').replace(/[&<>"']/g, (ch)=>({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch] || ch));
  }

  async function loadImageUsage(){
    const body=byId('imageUsageBody');
    if (!body) return;
    const logBody=byId('imageGenerationLogBody');
    const res=await fetch('/api/vk/image_usage/');
    const j=await res.json();
    const rows=Array.isArray(j.items)?j.items:[];
    body.innerHTML='';
    rows.forEach((row)=>{
      const tr=document.createElement('tr');
      const uid=String(row.vk_user_id||'');
      const count=Number(row.count||0);
      const ts=Number(row.updated_at||0);
      const updated=ts>0?new Date(ts*1000).toLocaleString():'—';
      tr.innerHTML=`<td>${uid}</td><td>${count}</td><td>${updated}</td><td></td>`;
      const td=tr.lastElementChild;
      const btn=document.createElement('button');
      btn.textContent='Сбросить';
      btn.onclick=async()=>{
        await fetch('/api/vk/image_usage/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_user',vk_user_id:Number(uid)})});
        await loadImageUsage();
      };
      td.appendChild(btn);
      body.appendChild(tr);
    });

    if (logBody) {
      const logRows=Array.isArray(j.generation_log)?j.generation_log:[];
      logBody.innerHTML='';
      logRows.forEach((row)=>{
        const tr=document.createElement('tr');
        const ts=Number(row.ts||0);
        const at=ts>0?new Date(ts*1000).toLocaleString():'—';
        const uid=String(row.vk_user_id||'');
        const ok=!!row.ok;
        const status=ok?'OK':('Ошибка: '+String(row.error||'unknown'));
        const httpCode=Number(row.http_code||0);
        const prompt=escapeHtml(String(row.prompt||''));
        const routerResponse=escapeHtml(String(row.router_response||''));
        tr.innerHTML=`<td>${at}</td><td>${uid}</td><td>${escapeHtml(status)}</td><td>${httpCode||'—'}</td><td><pre style="margin:0;white-space:pre-wrap;max-width:280px">${prompt}</pre></td><td><pre style="margin:0;white-space:pre-wrap;max-width:520px">${routerResponse||'—'}</pre></td>`;
        logBody.appendChild(tr);
      });
    }
  }

  const resetAllBtn=byId('resetAllImageUsage');
  if (resetAllBtn){
    resetAllBtn.onclick=async()=>{
      if (!window.confirm('Сбросить лимиты генерации всем пользователям?')) return;
      await fetch('/api/vk/image_usage/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_all'})});
      await loadImageUsage();
    };
  }

  await loadCfg();
  await loadApps();
  await loadCharacterApps();
  await loadImageUsage();
})();
