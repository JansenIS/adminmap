(function(){
  const q = new URLSearchParams(location.search);
  const token = q.get('token') || '';
  const $ = (id)=>document.getElementById(id);
  const statusEl = $('status');
  const MODE_TO_FIELD = { provinces:null, kingdoms:'kingdom_id', great_houses:'great_house_id', minor_houses:'minor_house_id', free_cities:'free_city_id', special_territories:'special_territory_id' };
  let session = null;
  let provinces = [];
  let realms = {};
  let map = null;
  let selectedPid = 0;

  function setStatus(msg, bad){ statusEl.textContent = msg || ''; statusEl.style.color = bad ? '#ff9f9f' : '#9bd39f'; }
  async function jfetch(url, opts){ const r = await fetch(url, opts); const d = await r.json(); if(!r.ok || d.error) throw new Error(d.error || ('HTTP '+r.status)); return d; }
  function toRgbaFromHex(hex, a){
    const m = /^#?([0-9a-f]{6})$/i.exec(String(hex||''));
    if(!m) return [120,120,120,a];
    const n = parseInt(m[1],16);
    return [(n>>16)&255,(n>>8)&255,n&255,a];
  }

  function showProvinceInfo(p){
    $('infoName').textContent = p ? (p.name || '—') : '—';
    $('infoPid').textContent = p ? String(p.pid || '—') : '—';
    $('infoOwner').textContent = p ? (p.owner || '—') : '—';
    $('infoSuzerain').textContent = p ? (p.suzerain || '—') : '—';
    $('infoSenior').textContent = p ? (p.senior || '—') : '—';
    $('infoTerrain').textContent = p ? (p.terrain || '—') : '—';
  }

  function renderArmies(){
    const sel = $('armySelect'); sel.innerHTML='';
    const arr = (session.entity.player_armies || []);
    for(const a of arr){ const o=document.createElement('option'); o.value=a.army_id; o.textContent=`${a.army_name} (${a.size}) @PID ${a.location_pid}`; sel.appendChild(o); }
  }

  function renderProvinceSelect(){
    const sel = $('provinceSelect'); sel.innerHTML='';
    for(const p of provinces.filter(x=>x.is_owned)){
      const o=document.createElement('option'); o.value=p.pid; o.textContent=`${p.pid}: ${p.name}`; sel.appendChild(o);
    }
    sel.onchange = ()=>{
      const p = provinces.find(x=>String(x.pid)===sel.value); if(!p) return;
      $('provinceDesc').value = p.wiki_description || '';
      $('provinceImage').value = p.province_card_image || '';
      $('provinceEmblem').value = p.emblem_svg || '';
      selectedPid = Number(p.pid||0);
      showProvinceInfo(p);
    };
    if(sel.options.length) sel.dispatchEvent(new Event('change'));
  }

  function paintMap(){
    if(!map) return;
    const mode = $('viewMode').value || 'provinces';
    map.clearAllFills();
    map.clearAllEmblems();
    for (const [key, meta] of map.provincesByKey.entries()) {
      const p = provinces.find(x=>Number(x.pid)===Number(meta.pid));
      if(!p) continue;
      let rgba = [75,75,75,115];
      if (mode === 'provinces') {
        rgba = p.is_owned ? [95,160,255,130] : [80,90,105,95];
      } else {
        const realmId = String(p[MODE_TO_FIELD[mode]] || '').trim();
        const realm = (realms[mode] || {})[realmId] || null;
        rgba = toRgbaFromHex(realm && realm.color, p.is_owned ? 170 : 120);
      }
      map.setFill(key, rgba);
      if (p.is_owned && p.emblem_svg && mode === 'provinces') {
        map.setEmblem(key, `data:image/svg+xml;base64,${MapUtils.toBase64Utf8(p.emblem_svg)}`, null, {margin:0.12});
      }
      if (selectedPid && Number(p.pid) === selectedPid) {
        map.setSelectedHighlight(key, [255,255,255,70]);
      }
    }
  }

  async function load(){
    if(!token){ setStatus('Токен не найден в ссылке.', true); return; }
    const data = await jfetch(`/api/player/session/?token=${encodeURIComponent(token)}`);
    session = data.session;
    provinces = data.provinces || [];
    realms = data.realms || {};
    $('entityName').textContent = `${session.entity.name} (${session.entity.type})`;
    $('treasury').textContent = session.entity.treasury_total;
    $('population').textContent = session.entity.population_total;
    $('musterCap').textContent = `Лимит арьербана: ${session.entity.muster_cap}`;
    $('entityDesc').value = session.entity.wiki_description || '';
    $('entityImage').value = session.entity.image_url || '';
    $('entityEmblem').value = session.entity.emblem_svg || '';
    renderProvinceSelect(); renderArmies();
    await initMap();
  }

  async function initMap(){
    if (!map) {
      map = new RasterProvinceMap({
        baseImgId:'baseMap', fillCanvasId:'fill', emblemCanvasId:'emblems', hoverCanvasId:'hover',
        provincesMetaUrl:'provinces.json', maskUrl:'provinces_id.png',
        onHover: ({key, evt}) => {
          const meta = map.getProvinceMeta(key || 0);
          const p = meta ? provinces.find(x=>Number(x.pid)===Number(meta.pid)) : null;
          if (p) {
            showProvinceInfo(p);
            map.setHoverHighlight(key, [255,255,255,55]);
          } else {
            map.clearHover();
          }
        },
        onClick: ({key}) => {
          const meta = map.getProvinceMeta(key || 0);
          const p = meta ? provinces.find(x=>Number(x.pid)===Number(meta.pid)) : null;
          if (!p) return;
          selectedPid = Number(p.pid || 0);
          showProvinceInfo(p);
          paintMap();
        }
      });
      await map.init();
    }
    paintMap();
  }

  $('viewMode').addEventListener('change', paintMap);

  $('saveEntity').onclick = async ()=>{
    await jfetch('/api/player/session/save/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,entity:{wiki_description:$('entityDesc').value,image_url:$('entityImage').value,emblem_svg:$('entityEmblem').value}})});
    setStatus('Сущность сохранена.');
  };

  $('saveProvince').onclick = async ()=>{
    const pid = Number($('provinceSelect').value||0);
    await jfetch('/api/player/session/save/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,provinces:[{pid,wiki_description:$('provinceDesc').value,province_card_image:$('provinceImage').value,emblem_svg:$('provinceEmblem').value}]})});
    setStatus('Провинция сохранена.');
    await load();
  };

  $('musterBtn').onclick = async ()=>{
    await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'muster',pid:Number($('musterPid').value||0),size:Number($('musterSize').value||0),army_name:$('musterName').value})});
    setStatus('Арьербан созван.'); await load();
  };
  $('moveBtn').onclick = async ()=>{
    await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'move',army_id:$('armySelect').value,to_pid:Number($('movePid').value||0)})});
    setStatus('Армия перемещена.'); await load();
  };
  $('disbandBtn').onclick = async ()=>{
    await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'disband',army_id:$('armySelect').value})});
    setStatus('Армия распущена.'); await load();
  };

  load().catch(e=>setStatus(e.message,true));
})();
