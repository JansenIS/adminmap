(function(){
  "use strict";

  const COLORS = {
    blue: {
      token: "rgba(110,168,255,.95)",
      token2:"rgba(170,210,255,.95)",
      label: "rgba(225,240,255,.95)",
      range: "rgba(110,168,255,.35)",
      move: "rgba(110,168,255,.22)"
    },
    red: {
      token: "rgba(255,110,110,.95)",
      token2:"rgba(255,180,180,.95)",
      label: "rgba(255,235,235,.95)",
      range: "rgba(255,110,110,.35)",
      move: "rgba(255,110,110,.22)"
    }
  };

  // Unit catalog: baseSize + baseXpl define the linear XPL formula
  // XPL(current) = (sizeCurrent / baseSize) * baseXpl
  const UNIT_CATALOG = {
    militia:        {name:"Крестьянское ополчение", kind:"inf",       baseSize:1000, baseXpl:0.5, move:80,  melee:{power:0.020, capPct:0.10}, armor:0.04, morale:55},
    militia_tr:     {name:"Тренированное крестьянское ополчение", kind:"inf", baseSize:1000, baseXpl:1.0, move:85, melee:{power:0.024, capPct:0.12}, armor:0.06, morale:62},
    pikes:          {name:"Ополчение с электрокопьями", kind:"pike",  baseSize:500,  baseXpl:1.0, move:75, melee:{power:0.030, capPct:0.14}, armor:0.08, morale:65, tags:["antiCav"]},
    shot:           {name:"Ополчение с электропневматикой", kind:"shot", baseSize:500, baseXpl:1.0, move:75,
                     ranged:{range:240, power:0.020, capPct:0.06, acc:0.55}, melee:{power:0.014, capPct:0.08}, armor:0.06, morale:63},
    engineers:      {name:"Инженерная команда", kind:"support", baseSize:10, baseXpl:2.0, move:70, melee:{power:0.014, capPct:0.10}, armor:0.10, morale:60},
    city100:        {name:"Городская сотня", kind:"inf", baseSize:100, baseXpl:0.5, move:90, melee:{power:0.022, capPct:0.12}, armor:0.06, morale:60},
    assault150:     {name:"Штурмовая баталия", kind:"inf", baseSize:150, baseXpl:2.0, move:85, melee:{power:0.036, capPct:0.18}, armor:0.12, morale:70},
    grey250:        {name:"Серая баталия", kind:"inf", baseSize:250, baseXpl:3.0, move:85, melee:{power:0.040, capPct:0.18}, armor:0.14, morale:72},
    houseguard150:  {name:"Гвардия Дома", kind:"elite", baseSize:150, baseXpl:3.0, move:90, melee:{power:0.048, capPct:0.20}, armor:0.18, morale:78},
    preventors100:  {name:"Пешие превенторы", kind:"elite", baseSize:100, baseXpl:4.0, move:90, melee:{power:0.056, capPct:0.22}, armor:0.22, morale:85},
    foot_knights:   {name:"Пешие рыцари", kind:"elite", baseSize:100, baseXpl:2.0, move:85, melee:{power:0.028, capPct:0.11}, armor:0.16, morale:74},
    foot_nehts:     {name:"Пешие нехты", kind:"inf", baseSize:100, baseXpl:1.0, move:85, melee:{power:0.014, capPct:0.055}, armor:0.10, morale:66},

    gauss:          {name:"Стационарная винтовка Гаусса", kind:"gun", baseSize:1, baseXpl:1.0, move:0,
                     ranged:{range:520, power:0.030, capPct:0.08, acc:0.66}, melee:{power:0.004, capPct:0.04}, armor:0.20, morale:65},
    bikes:          {name:"Рейтары", kind:"cav", baseSize:50, baseXpl:1.0, move:220,
                     ranged:{range:150, power:0.012, capPct:0.03, acc:0.42}, melee:{power:0.032, capPct:0.14}, armor:0.14, morale:70},
    dragoons:       {name:"Драгуны", kind:"cav", baseSize:50, baseXpl:1.0, move:210,
                     ranged:{range:170, power:0.018, capPct:0.04, acc:0.48}, melee:{power:0.022, capPct:0.10}, armor:0.14, morale:70},
    ulans:          {name:"Уланы", kind:"cav", baseSize:50, baseXpl:0.67, move:215,
                     ranged:{range:160, power:0.012, capPct:0.027, acc:0.44}, melee:{power:0.021, capPct:0.093}, armor:0.12, morale:66},
    catapult:       {name:"Лёгкое осадное орудие", kind:"siege", baseSize:1, baseXpl:2.0, move:35,
                     ranged:{range:380, power:0.020, capPct:0.10, acc:0.48}, melee:{power:0.004, capPct:0.05}, armor:0.20, morale:60},
    gauss_raiders:  {name:"Рейдеры с винтовкой Гаусса", kind:"gun", baseSize:2, baseXpl:2.0, move:160,
                     ranged:{range:420, power:0.026, capPct:0.07, acc:0.62}, melee:{power:0.020, capPct:0.10}, armor:0.16, morale:68},
    trebuchet:      {name:"Требюше", kind:"siege", baseSize:1, baseXpl:3.0, move:25,
                     ranged:{range:560, power:0.022, capPct:0.12, acc:0.44}, melee:{power:0.004, capPct:0.05}, armor:0.22, morale:60},
    assault_gun:    {name:"Штурмовое орудие", kind:"vehicle", baseSize:1, baseXpl:3.0, move:160,
                     ranged:{range:360, power:0.024, capPct:0.10, acc:0.55}, melee:{power:0.028, capPct:0.12}, armor:0.28, morale:75},
    palatines:      {name:"Палатинские всадники", kind:"heavycav", baseSize:20, baseXpl:3.0, move:170,
                     ranged:{range:120, power:0.010, capPct:0.03, acc:0.40}, melee:{power:0.060, capPct:0.22}, armor:0.24, morale:82},
    moto_knights:   {name:"Мотоконные рыцари", kind:"cav", baseSize:20, baseXpl:1.0, move:220,
                     ranged:{range:120, power:0.004, capPct:0.01, acc:0.38}, melee:{power:0.020, capPct:0.073}, armor:0.16, morale:72},
    big_vehicle:    {name:"Большая техника", kind:"vehicle_big", baseSize:1, baseXpl:4.0, move:140,
                     ranged:{range:320, power:0.026, capPct:0.10, acc:0.52}, melee:{power:0.040, capPct:0.16}, armor:0.36, morale:78},
    wagenburg:      {name:"Вагенбург", kind:"wagen", baseSize:1, baseXpl:7.0, move:25,
                     ranged:{range:280, power:0.035, capPct:0.12, acc:0.60}, melee:{power:0.050, capPct:0.16}, armor:0.48, morale:90, noFlanks:true},
  };

  const TOKEN_PROFILES = {
    inf:       {shape:"dot", size: 1.35, spacing: 3},
    pike:      {shape:"dot", size: 1.35, spacing: 3},
    shot:      {shape:"dot", size: 1.35, spacing: 3},
    support:   {shape:"dot", size: 1.35, spacing: 4},
    elite:     {shape:"dot", size: 1.45, spacing: 3},
    cav:       {shape:"tri", size: 2.0, spacing: 5},
    heavycav:  {shape:"tri", size: 2.1, spacing: 6},
    gun:       {shape:"sq",  size: 3.8, spacing: 12},
    siege:     {shape:"rect",size: 4.6, spacing: 14},
    vehicle:   {shape:"rect",size: 4.8, spacing: 14},
    vehicle_big:{shape:"rect",size: 5.6, spacing: 16},
    wagen:     {shape:"rect",size: 6.2, spacing: 18},
  };

  const FORMATIONS = [
    {id:"line", label:"Линия"},
    {id:"block", label:"Блок"},
    {id:"wedge", label:"Клин"},
    {id:"sleeve", label:"Рукава стрелков"},
    {id:"chatillon", label:"Шатильон"},
  ];

  // Terrain zones (polygons in WORLD coordinates)
  // Used for movement/cover modifiers (simple).
  const TERRAIN_ZONES = [
    {id:"forest1", type:"forest", poly:[{x:-840,y:-450},{x:-680,y:-500},{x:-580,y:-450},{x:-560,y:-360},{x:-640,y:-280},{x:-800,y:-310}]},
    {id:"forest2", type:"forest", poly:[{x:360,y:-480},{x:460,y:-520},{x:550,y:-460},{x:540,y:-360},{x:420,y:-340},{x:340,y:-400}]},
    {id:"rock1", type:"rock", poly:[{x:-120,y:-180},{x:0,y:-220},{x:80,y:-140},{x:40,y:-20},{x:-90,y:-10},{x:-170,y:-100}]},
    {id:"marsh1", type:"marsh", poly:[{x:180,y:220},{x:380,y:160},{x:500,y:280},{x:400,y:440},{x:220,y:420},{x:100,y:300}]},
    {id:"rock2", type:"rock", poly:[{x:580,y:-180},{x:660,y:-200},{x:720,y:-140},{x:680,y:-80},{x:590,y:-100}]}
  ];

  const TERRAIN_MODS = {
    // move multiplier by unit category
    move: {
      forest: {inf:0.86,pike:0.86,shot:0.86,elite:0.88,support:0.84,cav:0.70,heavycav:0.70,gun:0.85,siege:0.78,vehicle:0.68,vehicle_big:0.62,wagen:0.70},
      rock:   {inf:0.92,pike:0.92,shot:0.92,elite:0.93,support:0.90,cav:0.82,heavycav:0.82,gun:0.90,siege:0.86,vehicle:0.76,vehicle_big:0.70,wagen:0.78},
      marsh:  {inf:0.78,pike:0.78,shot:0.78,elite:0.80,support:0.76,cav:0.55,heavycav:0.55,gun:0.78,siege:0.70,vehicle:0.55,vehicle_big:0.45,wagen:0.55},
    },
    cover: { forest:0.10, rock:0.15, marsh:0.05 }
  };

  window.DATA = {COLORS, UNIT_CATALOG, TOKEN_PROFILES, FORMATIONS, TERRAIN_ZONES, TERRAIN_MODS};
})();
