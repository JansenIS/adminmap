<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Экономический симулятор — админка провинций</title>
  <link rel="stylesheet" href="./public/admin_sim.css" />
</head>
<body>
<div class="layout">
  <section class="mapCard">
    <h1>Карта провинций (симулятор)</h1>
    <div class="mapTools">
      <label>Покраска
        <select id="paintMode">
          <option value="kingdom">Королевства</option>
          <option value="great_house">ВД (Великие дома)</option>
          <option value="minor_house">МД (Малые дома)</option>
        </select>
      </label>
      <label><input id="showContours" type="checkbox" checked> Контуры провинций</label>
      <label><input id="transparentMode" type="checkbox"> Прозрачный режим</label>
      <span class="hint">Особые территории (free city) всегда подсвечены.</span>
    </div>
    <canvas id="mapCanvas" width="1900" height="2050"></canvas>
  </section>
  <section class="formCard">
    <h2 id="title">Провинция не выбрана</h2>
    <div class="grid">
      <label>PID <input id="pid" readonly></label>
      <label>Название <input id="name" readonly></label>
      <label><input id="isCity" type="checkbox"> Город</label>
      <label>Население <input id="pop" type="number"></label>
      <label>Инфраструктура <input id="infra" type="number" step="0.01"></label>
      <label>Транспорт capacity <input id="transportCap" type="number" step="0.01"></label>
      <label>Транспорт used <input id="transportUsed" type="number" step="0.01"></label>
      <label>GDP turnover <input id="gdpTurnover" type="number"></label>
    </div>
    <h3>Здания</h3>
    <table id="buildingsTbl">
      <thead><tr><th>Тип</th><th>Кол-во</th><th>Эфф.</th><th></th></tr></thead>
      <tbody></tbody>
    </table>
    <button id="addBuilding">+ здание</button>
    <button id="save">Сохранить параметры провинции</button>
    <pre id="status"></pre>
  </section>
</div>
<script src="./public/admin_sim.js"></script>
</body>
</html>
