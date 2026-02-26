/**
 * Каталог товаров и рецептов производств для экономического симулятора.
 * Основано на "Биржа.txt" + добавлены сельхозресурсы: мутакурицы, мутабрюква.
 *
 * Единицы измерения — как в списке, но движок оперирует числами без размерности.
 */

/** @typedef {"raw"|"component"|"product"|"animal"} CommodityTier */
/** @typedef {{id:string,name:string,unit:string,tier:CommodityTier,basePrice:number,bulk:number,decayPerDay:number,rarity:number,harvestable?:boolean}} Commodity */

/**
 * bulk: относительная "крупность/тяжесть" для стоимости перевозки (1 = базовая).
 * decayPerDay: доля потери в пути/на складе (0..1). Для большинства 0.
 * rarity: насколько ресурс "редок" при генерации потенциала (0..1). Чем больше — тем меньше средний выпуск.
 */

/** @type {Commodity[]} */
export const COMMODITIES = [
  // RAW
  { id:"wood_raw", name:"Древесина", unit:"м³", tier:"raw", basePrice:8, bulk:1.2, decayPerDay:0.00, rarity:0.05 },
  { id:"fiber_raw", name:"Прядильное волокно", unit:"кг", tier:"raw", basePrice:6, bulk:0.8, decayPerDay:0.01, rarity:0.08 },
  { id:"hides_raw", name:"Необработанные шкуры", unit:"шт", tier:"raw", basePrice:10, bulk:0.9, decayPerDay:0.00, rarity:0.10 , harvestable:false },
  { id:"rubber_raw", name:"Сырьевой каучук", unit:"кг", tier:"raw", basePrice:14, bulk:0.9, decayPerDay:0.00, rarity:0.20 },
  { id:"petrochem_raw", name:"Нефтехимическое сырьё", unit:"кг", tier:"raw", basePrice:18, bulk:1.1, decayPerDay:0.00, rarity:0.25 },
  { id:"stone", name:"Камень", unit:"т", tier:"raw", basePrice: 30, bulk:2.0, decayPerDay:0.00, rarity:0.20 },
  { id:"iron_ore", name:"Железная руда", unit:"т", tier:"raw", basePrice:20, bulk:1.6, decayPerDay:0.00, rarity:0.18 },
  { id:"coke", name:"Кокс", unit:"т", tier:"raw", basePrice:16, bulk:1.4, decayPerDay:0.00, rarity:0.15 },
  { id:"meat", name:"Мясо", unit:"кг", tier:"raw", basePrice: 10, bulk:1.0, decayPerDay:0.03, rarity:0.10 , harvestable:false },
  { id:"gold", name:"Золото", unit:"г", tier:"raw", basePrice: 70, bulk:0.1, decayPerDay:0.00, rarity:0.90 },
  { id:"silver", name:"Серебро", unit:"кг", tier:"raw", basePrice: 25, bulk:0.2, decayPerDay:0.00, rarity:0.75 },
  { id:"copper", name:"Медь", unit:"т", tier:"raw", basePrice: 2000, bulk:1.4, decayPerDay:0.00, rarity:0.45 },
  { id:"iron", name:"Железо", unit:"т", tier:"raw", basePrice: 500, bulk:1.5, decayPerDay:0.00, rarity:0.20 },
  { id:"lead", name:"Свинец", unit:"т", tier:"raw", basePrice: 250, bulk:1.5, decayPerDay:0.00, rarity:0.35 },
  { id:"tin", name:"Олово", unit:"т", tier:"raw", basePrice: 300, bulk:1.3, decayPerDay:0.00, rarity:0.40 },
  { id:"villadium", name:"Вилладиум", unit:"г", tier:"raw", basePrice: 50, bulk:0.05, decayPerDay:0.00, rarity:0.98 },

  // Добавлено пользователем: сельхозресурсы
  { id:"mutabryukva", name:"Мутабрюква", unit:"кг", tier:"raw", basePrice: 0.35, bulk:1.0, decayPerDay:0.02, rarity:0.03 , harvestable:false },
  { id:"mutachicken", name:"Мутакурицы", unit:"шт", tier:"raw", basePrice:9, bulk:1.0, decayPerDay:0.01, rarity:0.06 , harvestable:false },

  // COMPONENTS
  { id:"e_parts", name:"Электронные компоненты", unit:"наб.", tier:"component", basePrice:85, bulk:0.2, decayPerDay:0.00, rarity:0.70 },
  { id:"rolled_steel", name:"Прокатная сталь", unit:"лист", tier:"component", basePrice:70, bulk:0.9, decayPerDay:0.00, rarity:0.35 },
  { id:"precision_steel", name:"Высокоточная сталь", unit:"лист", tier:"component", basePrice:120, bulk:0.8, decayPerDay:0.00, rarity:0.55 },
  { id:"mcu_set", name:"Микропроцессорный набор", unit:"шт", tier:"component", basePrice:240, bulk:0.1, decayPerDay:0.00, rarity:0.92 },
  { id:"filter_substrate", name:"Фильтрующий субстрат", unit:"кг", tier:"component", basePrice:45, bulk:0.6, decayPerDay:0.00, rarity:0.35 },
  { id:"electrolyte_chems", name:"Химикаты для электролита", unit:"л", tier:"component", basePrice:55, bulk:0.7, decayPerDay:0.00, rarity:0.40 },
  { id:"distilled_water", name:"Дистиллированная вода", unit:"л", tier:"component", basePrice:12, bulk:1.0, decayPerDay:0.00, rarity:0.10 },
  { id:"engine_kit", name:"Комплект движка", unit:"шт", tier:"component", basePrice:260, bulk:1.2, decayPerDay:0.00, rarity:0.55 },
  { id:"life_core", name:"Ядро жизнеобеспечения", unit:"шт", tier:"component", basePrice:320, bulk:0.8, decayPerDay:0.00, rarity:0.75 },
  { id:"bread", name:"Хлеб", unit:"кг", tier:"component", basePrice: 1, bulk:1.0, decayPerDay:0.02, rarity:0.05 },
  { id:"meat_cans", name:"Мясные консервы", unit:"кг", tier:"component", basePrice: 20, bulk:1.0, decayPerDay:0.00, rarity:0.08 },
  { id:"steel", name:"Сталь", unit:"т", tier:"component", basePrice: 1000, bulk:1.5, decayPerDay:0.00, rarity:0.30 },
  { id:"wood_processed", name:"Обработанная древесина", unit:"м³", tier:"component", basePrice:18, bulk:1.2, decayPerDay:0.00, rarity:0.06 },
  { id:"armotextile", name:"Бронетекстиль", unit:"рулон", tier:"component", basePrice:95, bulk:0.6, decayPerDay:0.00, rarity:0.30 },
  { id:"leather_reinforced", name:"Усиленная кожа", unit:"компл.", tier:"component", basePrice:80, bulk:0.7, decayPerDay:0.00, rarity:0.20 },
  { id:"rubber_industrial", name:"Промышленная резина", unit:"компл.", tier:"component", basePrice:75, bulk:0.9, decayPerDay:0.00, rarity:0.25 },
  { id:"poly_housings", name:"Полимерные корпуса", unit:"компл.", tier:"component", basePrice:68, bulk:0.8, decayPerDay:0.00, rarity:0.22 },
  { id:"steel_ingot", name:"Стальной слиток", unit:"шт", tier:"component", basePrice:58, bulk:1.1, decayPerDay:0.00, rarity:0.30 },
  { id:"villadium_alloy", name:"Вилладиумный сплав", unit:"шт", tier:"component", basePrice:420, bulk:0.2, decayPerDay:0.00, rarity:0.92 },
  { id:"forged_parts", name:"Кованые детали", unit:"компл.", tier:"component", basePrice:60, bulk:0.9, decayPerDay:0.00, rarity:0.20 },
  { id:"precision_blanks", name:"Высокоточные заготовки", unit:"компл.", tier:"component", basePrice:135, bulk:0.6, decayPerDay:0.00, rarity:0.55 },
  { id:"stamped_frames", name:"Штампованные рамы", unit:"компл.", tier:"component", basePrice:88, bulk:1.0, decayPerDay:0.00, rarity:0.32 },
  { id:"power_module", name:"Силовой модуль", unit:"шт", tier:"component", basePrice:210, bulk:0.5, decayPerDay:0.00, rarity:0.70 },
  { id:"basic_electronics", name:"Комплект базовой электроники", unit:"шт", tier:"component", basePrice:140, bulk:0.3, decayPerDay:0.00, rarity:0.55 },
  { id:"adv_controller", name:"Контроллер продвинутой электроники", unit:"шт", tier:"component", basePrice:290, bulk:0.25, decayPerDay:0.00, rarity:0.85 },
  { id:"villadium_filter_ind", name:"Вилладиумный фильтр промышленный", unit:"шт", tier:"component", basePrice:520, bulk:0.4, decayPerDay:0.00, rarity:0.95 },
  { id:"electrolyte_ind", name:"Промышленный электролит", unit:"л", tier:"component", basePrice:45, bulk:1.0, decayPerDay:0.00, rarity:0.35 },
  { id:"life_module", name:"Модуль жизнеобеспечения", unit:"шт", tier:"component", basePrice:680, bulk:0.9, decayPerDay:0.00, rarity:0.95 },
  { id:"motor_small", name:"Малый электродвигатель", unit:"шт", tier:"component", basePrice: 100, bulk:0.9, decayPerDay:0.00, rarity:0.60 },
  { id:"motor_medium", name:"Средний электродвигатель", unit:"шт", tier:"component", basePrice: 1500, bulk:1.2, decayPerDay:0.00, rarity:0.72 },
  { id:"motor_heavy", name:"Тяжёлый электродвигатель", unit:"шт", tier:"component", basePrice: 2000, bulk:1.7, decayPerDay:0.00, rarity:0.82 },
  { id:"villadium_filter_personal", name:"Вилладиумный фильтр личный", unit:"шт", tier:"component", basePrice: 2, bulk:0.2, decayPerDay:0.00, rarity:0.80 },
  { id:"villadium_life_system", name:"Вилладиумная система жизнеобеспечения", unit:"шт", tier:"component", basePrice: 600, bulk:1.0, decayPerDay:0.00, rarity:0.98 },
  { id:"leather_tanned", name:"Кожа выделанная", unit:"м", tier:"component", basePrice: 3, bulk:0.6, decayPerDay:0.00, rarity:0.12 },
  { id:"cloth_peasant", name:"Ткань простецкая", unit:"м", tier:"component", basePrice: 0.2, bulk:0.5, decayPerDay:0.00, rarity:0.10 },
  { id:"cloth_city", name:"Ткань городская", unit:"м", tier:"component", basePrice: 2, bulk:0.5, decayPerDay:0.00, rarity:0.18 },
  { id:"cloth_noble", name:"Ткань дворянская", unit:"м", tier:"component", basePrice: 8, bulk:0.5, decayPerDay:0.00, rarity:0.35 },
  { id:"wheel_moto", name:"Колесо для мототехники", unit:"шт", tier:"component", basePrice: 20, bulk:1.0, decayPerDay:0.00, rarity:0.30 },
  { id:"wheel_car", name:"Колесо для легковой техники", unit:"шт", tier:"component", basePrice: 150, bulk:1.2, decayPerDay:0.00, rarity:0.35 },
  { id:"wheel_truck", name:"Колесо для грузовой техники", unit:"шт", tier:"component", basePrice: 200, bulk:1.6, decayPerDay:0.00, rarity:0.45 },

  // PRODUCTS
  { id:"armor_militia", name:"Броня ополчения", unit:"шт", tier:"product", basePrice: 10.3, bulk:1.6, decayPerDay:0.00, rarity:0.00 },
  { id:"armor_aux", name:"Броня Ауксилии", unit:"шт", tier:"product", basePrice: 105, bulk:1.8, decayPerDay:0.00, rarity:0.00 },
  { id:"armor_preventor", name:"Броня Превентора", unit:"шт", tier:"product", basePrice: 1620, bulk:2.2, decayPerDay:0.00, rarity:0.00 },

  { id:"rifle_ep", name:"Электропневматическая винтовка", unit:"шт", tier:"product", basePrice: 11.1, bulk:1.1, decayPerDay:0.00, rarity:0.00 },
  { id:"rifle_gauss", name:"Гаусс-винтовка", unit:"шт", tier:"product", basePrice: 2625, bulk:1.2, decayPerDay:0.00, rarity:0.00 },
  { id:"spear_e", name:"Электрокопьё", unit:"шт", tier:"product", basePrice: 5.5, bulk:1.1, decayPerDay:0.00, rarity:0.00 },
  { id:"railgun", name:"Рельсотрон", unit:"шт", tier:"product", basePrice:3200, bulk:2.0, decayPerDay:0.00, rarity:0.00 },
  { id:"assault_gun", name:"Штурмовое орудие", unit:"шт", tier:"product", basePrice:5200, bulk:3.0, decayPerDay:0.00, rarity:0.00 },
  { id:"pistol_e", name:"Электропистолет", unit:"шт", tier:"product", basePrice:420, bulk:0.5, decayPerDay:0.00, rarity:0.00 },
  { id:"sword_tac", name:"Тактический меч", unit:"шт", tier:"product", basePrice:260, bulk:0.9, decayPerDay:0.00, rarity:0.00 },
  { id:"gasmask", name:"Противогаз", unit:"шт", tier:"product", basePrice: 10, bulk:0.4, decayPerDay:0.00, rarity:0.00 },

  { id:"motorcycle", name:"Лёгкий электробайк", unit:"шт", tier:"product", basePrice: 550, bulk:2.2, decayPerDay:0.00, rarity:0.00 },
  { id:"jeep_raider", name:"Джип-рейдер", unit:"шт", tier:"product", basePrice: 10700, bulk:3.2, decayPerDay:0.00, rarity:0.00 },
  { id:"boat_river", name:"Речной катер", unit:"шт", tier:"product", basePrice:3400, bulk:4.0, decayPerDay:0.00, rarity:0.00 },

  { id:"batt_ind", name:"Промышленные аккумуляторы", unit:"шт", tier:"product", basePrice:520, bulk:1.6, decayPerDay:0.00, rarity:0.00 },

  { id:"clothes_peasant", name:"Крестьянская одежда", unit:"шт", tier:"product", basePrice: 2, bulk:0.6, decayPerDay:0.00, rarity:0.00 },
  { id:"clothes_city", name:"Городская одежда", unit:"шт", tier:"product", basePrice: 10, bulk:0.6, decayPerDay:0.00, rarity:0.00 },
  { id:"clothes_noble", name:"Дворянская одежда", unit:"шт", tier:"product", basePrice: 50, bulk:0.6, decayPerDay:0.00, rarity:0.00 },

  { id:"dishes_set", name:"Посуда (10 компл.)", unit:"компл.", tier:"product", basePrice: 1, bulk:1.2, decayPerDay:0.00, rarity:0.00 },
  { id:"agri_tools", name:"Сельскохозяйственный инструмент", unit:"компл.", tier:"product", basePrice: 1, bulk:1.4, decayPerDay:0.00, rarity:0.00 },
  { id:"motoblock", name:"Мотоблок", unit:"шт", tier:"product", basePrice: 200, bulk:2.3, decayPerDay:0.00, rarity:0.00 },
  { id:"tricycle_truck", name:"Грузовой трицикл", unit:"шт", tier:"product", basePrice: 400, bulk:2.8, decayPerDay:0.00, rarity:0.00 },
  { id:"jeep_civil", name:"Гражданский джип", unit:"шт", tier:"product", basePrice: 4000, bulk:3.0, decayPerDay:0.00, rarity:0.00 },
  { id:"truck_civil", name:"Гражданский грузовик", unit:"шт", tier:"product", basePrice: 7000, bulk:4.2, decayPerDay:0.00, rarity:0.00 },
  { id:"barge_river", name:"Речная баржа", unit:"шт", tier:"product", basePrice: 12000, bulk:7.0, decayPerDay:0.00, rarity:0.00 },

  { id:"fertilizer_mineral", name:"Минеральные удобрения (100 кг)", unit:"парт.", tier:"product", basePrice: 20, bulk:2.0, decayPerDay:0.00, rarity:0.00 },
  { id:"air_purifier_home", name:"Личный домашний очиститель воздуха", unit:"шт", tier:"product", basePrice: 800, bulk:1.6, decayPerDay:0.00, rarity:0.00 },

  // ANIMALS
  { id:"field_cat", name:"Полевой кот", unit:"шт", tier:"animal", basePrice: 2560, bulk:0.8, decayPerDay:0.00, rarity:0.15 },
];

/** Быстрый доступ: id -> индекс */
export const COM_INDEX = Object.fromEntries(COMMODITIES.map((c, i) => [c.id, i]));

/** Утилита: список индексов сырья */
export const RAW_ALL_IDX = COMMODITIES.map((c, i) => [c.tier, i]).filter(([t]) => t === "raw").map(([,i]) => i);

// Сырьё, которое добывается из природного потенциала (руда/металлы/древесина/нефтехимия и т.п.).
// Агро/животные (мясо, мутабрюква, мутакурицы, шкуры) добываются ТОЛЬКО через здания-цепочки.
export const HARVEST_RAW_IDX = RAW_ALL_IDX.filter((i) => COMMODITIES[i].harvestable !== false);


/**
 * Описание производственных зданий.
 * output/input — словари commodityId -> qtyPerDay при базовой мощности.
 * labor: требуемая рабочая сила (условные единицы) на 1 здание при 100% загрузке.
 * cap: множитель мощности (можно потом расширять уровнями).
 */
export const BUILDINGS = {
  // Манууфактуры (переработка)
  sawmill: {
    name: "Деревообрабатывающая мануфактура",
    labor: 80,
    cap: 1.0,
    input: { wood_raw: 12 },
    output: { wood_processed: 10 },
  },
  textile: {
    name: "Текстильная мануфактура",
    labor: 90,
    cap: 1.0,
    input: { fiber_raw: 20 },
    output: { cloth_peasant: 18, cloth_city: 3 },
  },
  tannery: {
    name: "Кожевенная мануфактура",
    labor: 70,
    cap: 1.0,
    input: { hides_raw: 14 },
    output: { leather_tanned: 18, leather_reinforced: 4 },
  },
  rubber_works: {
    name: "Мануфактура резины",
    labor: 95,
    cap: 1.0,
    input: { rubber_raw: 10, petrochem_raw: 6 },
    output: { rubber_industrial: 12 },
  },
  plastics: {
    name: "Мануфактура пластиков",
    labor: 110,
    cap: 1.0,
    input: { petrochem_raw: 16 },
    output: { poly_housings: 14 },
  },
  smithy: {
    name: "Кузница",
    labor: 85,
    cap: 1.0,
    input: { iron: 6 },
    output: { forged_parts: 8 },
  },
  adv_smithy: {
    name: "Продвинутая кузница",
    labor: 120,
    cap: 1.0,
    input: { steel: 5, forged_parts: 2 },
    output: { precision_blanks: 4 },
  },
  stamping: {
    name: "Штамповочная мануфактура",
    labor: 130,
    cap: 1.0,
    input: { rolled_steel: 10 },
    output: { stamped_frames: 8 },
  },
  engine_assembly: {
    name: "Мануфактура по сборке движков",
    labor: 160,
    cap: 1.0,
    input: { forged_parts: 4, stamped_frames: 4, rubber_industrial: 2, poly_housings: 2, basic_electronics: 1 },
    output: { engine_kit: 2 },
  },
  smelter: {
    name: "Металлургический цех",
    labor: 140,
    cap: 1.0,
    input: { iron_ore: 12, coke: 8 },
    output: { iron: 10, steel_ingot: 2 },
  },
  adv_smelter: {
    name: "Продвинутый металлургический цех",
    labor: 190,
    cap: 1.0,
    input: { iron: 8, tin: 1, copper: 1, lead: 1, coke: 4 },
    output: { steel: 8, rolled_steel: 4, precision_steel: 1 },
  },
  filters: {
    name: "Мануфактура по производству фильтров",
    labor: 120,
    cap: 1.0,
    input: { filter_substrate: 8, villadium: 120 },
    output: { villadium_filter_personal: 18, villadium_filter_ind: 2 },
  },
  electrolyte: {
    name: "Мануфактура по производству электролита",
    labor: 90,
    cap: 1.0,
    input: { electrolyte_chems: 10, distilled_water: 20 },
    output: { electrolyte_ind: 24 },
  },
  electronics_basic: {
    name: "Мануфактура базовой электроники",
    labor: 150,
    cap: 1.0,
    input: { copper: 2, lead: 1, tin: 1, poly_housings: 2 },
    output: { e_parts: 6, basic_electronics: 2 },
  },
  electronics_adv: {
    name: "Мануфактура продвинутой электроники",
    labor: 190,
    cap: 1.0,
    input: { e_parts: 4, mcu_set: 1, precision_blanks: 1 },
    output: { adv_controller: 1, power_module: 1 },
  },
  life_support: {
    name: "Производство систем жизнеобеспечения",
    labor: 220,
    cap: 1.0,
    input: { life_core: 1, villadium_filter_ind: 1, electrolyte_ind: 10, poly_housings: 3, adv_controller: 1 },
    output: { life_module: 1, villadium_life_system: 0.1 },
  },

  // Агро-узлы
  farm_mutabryukva: {
    name: "Тепличные поля (мутабрюква)",
    labor: 120,
    cap: 1.0,
    input: { fertilizer_mineral: 0.08 },
    output: { mutabryukva: 220 },
  },
  poultry_mutachicken: {
    name: "Птичники (мутакурицы)",
    labor: 90,
    cap: 1.0,
    input: { mutabryukva: 45 },
    output: { mutachicken: 25, meat: 45, hides_raw: 1 },
  },
  bakery: {
    name: "Пекарни",
    labor: 60,
    cap: 1.0,
    input: { mutabryukva: 70, distilled_water: 18 },
    output: { bread: 90 },
  },
  canning: {
    name: "Консервные цеха",
    labor: 70,
    cap: 1.0,
    input: { meat: 60, steel: 1 },
    output: { meat_cans: 55 },
  },

  fertilizer_plant: {
    name: "Химзавод удобрений",
    labor: 70,
    cap: 1.0,
    input: { petrochem_raw: 2, distilled_water: 8 },
    output: { fertilizer_mineral: 2.2 },
  },

water_distillery: {
  name: "Дистиллятор воды",
  labor: 55,
  cap: 1.0,
  input: { wood_raw: 1.5 },
  output: { distilled_water: 110 },
},

chem_filter_substrate: {
  name: "Химцех: фильтрующий субстрат",
  labor: 95,
  cap: 1.0,
  input: { wood_processed: 6, petrochem_raw: 6 },
  output: { filter_substrate: 18 },
},

chem_electrolyte_chems: {
  name: "Химцех: реагенты электролита",
  labor: 100,
  cap: 1.0,
  input: { petrochem_raw: 8, distilled_water: 10 },
  output: { electrolyte_chems: 22 },
},

alloy_villadium: {
  name: "Лаборатория сплавов вилладиума",
  labor: 180,
  cap: 1.0,
  input: { villadium: 240, steel: 1, copper: 0.3 },
  output: { villadium_alloy: 2.0 },
},

microfab_mcu: {
  name: "Микрофабрика (микропроцессоры)",
  labor: 230,
  cap: 1.0,
  input: { e_parts: 8, gold: 1.2, silver: 0.2, villadium: 18, poly_housings: 2 },
  output: { mcu_set: 1.0 },
},

life_core_fab: {
  name: "Фабрика ядер жизнеобеспечения",
  labor: 260,
  cap: 1.0,
  input: { villadium_alloy: 1, precision_steel: 1, adv_controller: 1, power_module: 1 },
  output: { life_core: 0.6 },
},

wheel_works: {
  name: "Производство колёс",
  labor: 120,
  cap: 1.0,
  input: { rubber_industrial: 5, steel: 1 },
  output: { wheel_moto: 4, wheel_car: 1.2, wheel_truck: 0.6 },
},

  // Производственные линии (итоговые изделия)
  line_gasmasks: {
    name: "Линия противогазов",
    labor: 120,
    cap: 1.0,
    input: { villadium_filter_personal: 6, poly_housings: 2, cloth_peasant: 12, rubber_industrial: 1 },
    output: { gasmask: 6 },
  },
  line_swords: {
    name: "Линия мечей",
    labor: 80,
    cap: 1.0,
    input: { forged_parts: 3, steel: 1, leather_tanned: 4 },
    output: { sword_tac: 5 },
  },
  line_spears: {
    name: "Линия электрокопий",
    labor: 90,
    cap: 1.0,
    input: { forged_parts: 3, basic_electronics: 1, power_module: 1, rubber_industrial: 1 },
    output: { spear_e: 4 },
  },
  line_rifle_ep: {
    name: "Линия электропневматических винтовок",
    labor: 140,
    cap: 1.0,
    input: { forged_parts: 3, basic_electronics: 1, rolled_steel: 2, rubber_industrial: 1 },
    output: { rifle_ep: 2 },
  },
  line_rifle_gauss: {
    name: "Линия винтовок Гаусса",
    labor: 180,
    cap: 1.0,
    input: { precision_steel: 2, adv_controller: 1, power_module: 1, batt_ind: 1, precision_blanks: 2 },
    output: { rifle_gauss: 1 },
  },
  line_pistols: {
    name: "Линия электропистолетов",
    labor: 110,
    cap: 1.0,
    input: { forged_parts: 2, basic_electronics: 1, rubber_industrial: 1 },
    output: { pistol_e: 3 },
  },
  line_batteries: {
    name: "Линия аккумуляторов",
    labor: 120,
    cap: 1.0,
    input: { electrolyte_ind: 20, poly_housings: 2, lead: 1, copper: 1 },
    output: { batt_ind: 2 },
  },
  line_motor_small: {
    name: "Линия малых электродвигателей",
    labor: 120,
    cap: 1.0,
    input: { forged_parts: 2, rolled_steel: 2, basic_electronics: 1 },
    output: { motor_small: 2 },
  },
  line_motor_medium: {
    name: "Линия средних электродвигателей",
    labor: 160,
    cap: 1.0,
    input: { forged_parts: 3, rolled_steel: 3, basic_electronics: 1, power_module: 1 },
    output: { motor_medium: 1 },
  },
  line_motor_heavy: {
    name: "Линия тяжёлых электродвигателей",
    labor: 200,
    cap: 1.0,
    input: { forged_parts: 4, precision_steel: 3, adv_controller: 1, power_module: 2 },
    output: { motor_heavy: 0.6 },
  },
  line_motorcycles: {
    name: "Линия мотоциклов",
    labor: 180,
    cap: 1.0,
    input: { engine_kit: 1, stamped_frames: 1, wheel_moto: 2, rubber_industrial: 1, basic_electronics: 1, batt_ind: 1 },
    output: { motorcycle: 0.8 },
  },
  line_jeeps: {
    name: "Линия джипов-рейдеров",
    labor: 260,
    cap: 1.0,
    input: { engine_kit: 2, stamped_frames: 2, wheel_car: 4, rubber_industrial: 2, adv_controller: 1, batt_ind: 2, steel: 2 },
    output: { jeep_raider: 0.25 },
  },

  // Одежда
  line_clothes_peasant: {
    name: "Линия крестьянской одежды",
    labor: 70,
    cap: 1.0,
    input: { cloth_peasant: 30, leather_tanned: 5 },
    output: { clothes_peasant: 25 },
  },
  line_clothes_city: {
    name: "Линия городской одежды",
    labor: 90,
    cap: 1.0,
    input: { cloth_city: 25, leather_tanned: 6 },
    output: { clothes_city: 12 },
  },
  line_clothes_noble: {
    name: "Линия дворянской одежды",
    labor: 120,
    cap: 1.0,
    input: { cloth_noble: 20, leather_reinforced: 3, armotextile: 2 },
    output: { clothes_noble: 4 },
  },
  // Гражданские изделия / транспорт / утварь (закрываем "вечные нули" по тем товарам, которые были без цепочек)
  line_agri_tools: {
    name: "Мастерские сельхозинструмента",
    labor: 80,
    cap: 1.0,
    input: { forged_parts: 2, wood_processed: 2 },
    output: { agri_tools: 4 },
  },
  line_dishes: {
    name: "Гончарно-кузнечная утварь",
    labor: 70,
    cap: 1.0,
    input: { stone: 1.2, iron: 0.6 },
    output: { dishes_set: 6 },
  },
  line_motoblock: {
    name: "Линия мотоблоков",
    labor: 140,
    cap: 1.0,
    input: { engine_kit: 0.6, wheel_moto: 2, stamped_frames: 1, steel: 0.8, basic_electronics: 0.6, batt_ind: 0.4 },
    output: { motoblock: 0.35 },
  },
  line_tricycle_truck: {
    name: "Линия грузовых трициклов",
    labor: 170,
    cap: 1.0,
    input: { engine_kit: 0.8, wheel_car: 3, stamped_frames: 2, steel: 1.4, basic_electronics: 0.8, batt_ind: 0.6 },
    output: { tricycle_truck: 0.25 },
  },
  line_jeep_civil: {
    name: "Линия гражданских джипов",
    labor: 230,
    cap: 1.0,
    input: { engine_kit: 1.6, wheel_car: 4, stamped_frames: 3, steel: 3.0, adv_controller: 0.8, batt_ind: 1.6, rubber_industrial: 2 },
    output: { jeep_civil: 0.18 },
  },
  line_truck_civil: {
    name: "Линия гражданских грузовиков",
    labor: 320,
    cap: 1.0,
    input: { engine_kit: 2.4, wheel_truck: 6, stamped_frames: 5, steel: 6.0, adv_controller: 1.0, batt_ind: 2.4, rubber_industrial: 3 },
    output: { truck_civil: 0.08 },
  },
  line_boat_river: {
    name: "Верфь речных катеров",
    labor: 260,
    cap: 1.0,
    input: { engine_kit: 1.3, wood_processed: 10, steel: 2.0, basic_electronics: 1, rubber_industrial: 2 },
    output: { boat_river: 0.12 },
  },
  line_barge_river: {
    name: "Верфь речных барж",
    labor: 420,
    cap: 1.0,
    input: { engine_kit: 2.0, wood_processed: 18, steel: 10.0, stamped_frames: 6, basic_electronics: 1.2, rubber_industrial: 3 },
    output: { barge_river: 0.03 },
  },
  line_air_purifier_home: {
    name: "Линия домашних очистителей воздуха",
    labor: 160,
    cap: 1.0,
    input: { villadium_filter_personal: 3, basic_electronics: 1, poly_housings: 2, electrolyte_ind: 2 },
    output: { air_purifier_home: 1 },
  },

  // Военная броня (в исходной версии товары были, а цепочек не было)
  line_armor_militia: {
    name: "Мануфактура доспехов ополчения",
    labor: 160,
    cap: 1.0,
    input: { leather_reinforced: 4, armotextile: 3, steel_ingot: 1, cloth_peasant: 6 },
    output: { armor_militia: 3 },
  },
  line_armor_aux: {
    name: "Мануфактура доспехов ауксилии",
    labor: 210,
    cap: 1.0,
    input: { rolled_steel: 2, armotextile: 4, leather_reinforced: 3, forged_parts: 2, cloth_city: 4 },
    output: { armor_aux: 1 },
  },
  line_armor_preventor: {
    name: "Мануфактура доспехов превенторов",
    labor: 280,
    cap: 1.0,
    input: { precision_steel: 3, villadium_alloy: 1, power_module: 1, life_module: 0.25, armotextile: 3, forged_parts: 3, cloth_noble: 1 },
    output: { armor_preventor: 0.18 },
  },

};

const scaledBuilding = (baseType, name, mul, laborMul = 1) => {
  const base = BUILDINGS[baseType];
  if (!base) return null;
  const scale = (obj) => Object.fromEntries(Object.entries(obj || {}).map(([k, v]) => [k, +(v * mul).toFixed(4)]));
  return {
    name,
    labor: Math.round((base.labor || 0) * laborMul),
    cap: +(base.cap || 1),
    input: scale(base.input),
    output: scale(base.output),
  };
};

const EXTRA_BUILDINGS = {
  // Закрываем товары, которые раньше никогда не производились.
  armotextile_mill: {
    name: "Мануфактура бронетекстиля",
    labor: 140,
    cap: 1.0,
    input: { fiber_raw: 22, petrochem_raw: 4, rubber_industrial: 2 },
    output: { armotextile: 12 },
  },
  noble_textile_atelier: {
    name: "Ателье дворянской ткани",
    labor: 130,
    cap: 1.0,
    input: { fiber_raw: 16, leather_reinforced: 1, silver: 0.02 },
    output: { cloth_noble: 8 },
  },
  line_railgun: {
    name: "Линия рельсотронов",
    labor: 320,
    cap: 1.0,
    input: { precision_steel: 4, villadium_alloy: 1, adv_controller: 1, power_module: 2, batt_ind: 2 },
    output: { railgun: 0.2 },
  },
  line_assault_gun: {
    name: "Линия штурмовых орудий",
    labor: 280,
    cap: 1.0,
    input: { rolled_steel: 5, forged_parts: 4, basic_electronics: 2, batt_ind: 1 },
    output: { assault_gun: 0.5 },
  },
  field_cat_breeding: {
    name: "Питомник полевых котов",
    labor: 60,
    cap: 1.0,
    input: { meat: 18, mutabryukva: 12, distilled_water: 12 },
    output: { field_cat: 0.35 },
  },

  // Нефункциональные/инфраструктурные здания как устойчивые потребители.
  outpost_guard: {
    name: "Застава",
    labor: 120,
    cap: 1.0,
    input: { bread: 20, meat_cans: 8, distilled_water: 30, sword_tac: 0.2, villadium_filter_personal: 2 },
    output: {},
  },
  barracks: {
    name: "Казармы",
    labor: 180,
    cap: 1.0,
    input: { bread: 36, meat_cans: 16, distilled_water: 55, rifle_ep: 0.25, armor_militia: 0.15, villadium_filter_personal: 4 },
    output: {},
  },
  trade_route: {
    name: "Торговый путь",
    labor: 90,
    cap: 1.0,
    input: { jeep_civil: 0.02, truck_civil: 0.01, distilled_water: 16, air_purifier_home: 0.03 },
    output: {},
  },
  hydro_power: {
    name: "Гидроэлектростанция",
    labor: 220,
    cap: 1.0,
    input: { steel: 1.8, adv_controller: 0.15, power_module: 0.2, distilled_water: 40 },
    output: {},
  },
  wind_farm: {
    name: "Ветряки",
    labor: 140,
    cap: 1.0,
    input: { steel: 0.9, basic_electronics: 0.4, distilled_water: 10 },
    output: {},
  },
  bunker_habited: {
    name: "Обжитый бункер",
    labor: 110,
    cap: 1.0,
    input: { bread: 22, meat_cans: 12, villadium_filter_personal: 5, air_purifier_home: 0.2, distilled_water: 38 },
    output: {},
  },
  order_fortress: {
    name: "Крепость ордена",
    labor: 360,
    cap: 1.0,
    input: { armor_preventor: 0.05, rifle_gauss: 0.06, villadium_filter_ind: 0.3, life_module: 0.05, distilled_water: 80, meat_cans: 24 },
    output: {},
  },
  trade_court: {
    name: "Торговый двор",
    labor: 100,
    cap: 1.0,
    input: { clothes_city: 0.8, dishes_set: 1.4, jeep_civil: 0.01, bread: 12, distilled_water: 18 },
    output: {},
  },
};

[
  ["smelter", "Шахта угля малая", 0.5],
  ["smelter", "Шахта угля средняя", 1.0],
  ["smelter", "Шахта угля большая", 1.8],
  ["filters", "Шахта вилладиума малая", 0.35],
  ["filters", "Шахта вилладиума средняя", 0.65],
  ["filters", "Шахта вилладиума большая", 1.1],
  ["smelter", "Шахта железа малая", 0.6],
  ["smelter", "Шахта железа средняя", 1.2],
  ["smelter", "Шахта железа большая", 2.0],
  ["electronics_basic", "Шахта меди малая", 0.35],
  ["electronics_basic", "Шахта меди средняя", 0.65],
  ["electronics_basic", "Шахта меди большая", 1.0],
  ["line_dishes", "Шахта камня малая", 0.7],
  ["line_dishes", "Шахта камня средняя", 1.3],
  ["line_dishes", "Шахта камня большая", 2.1],
  ["bakery", "Деревня малая", 0.7],
  ["bakery", "Деревня средняя", 1.2],
  ["bakery", "Деревня большая", 1.9],
  ["outpost_guard", "Фортпост малый", 0.7],
  ["outpost_guard", "Фортпост средний", 1.2],
  ["outpost_guard", "Фортпост большой", 1.9],
  ["barracks", "Крепость малая", 0.8],
  ["barracks", "Крепость средняя", 1.4],
  ["barracks", "Крепость большая", 2.2],
  ["line_boat_river", "Порт речной малый", 0.8],
  ["line_boat_river", "Порт речной средний", 1.4],
  ["line_barge_river", "Порт речной большой", 1.0],
  ["line_boat_river", "Порт морской малый", 1.0],
  ["line_barge_river", "Порт морской средний", 1.1],
  ["line_barge_river", "Порт морской большой", 1.6],
  ["filters", "Мануфактура фильтров малая", 0.6],
  ["filters", "Мануфактура фильтров средняя", 1.0],
  ["filters", "Мануфактура фильтров большая", 1.7],
  ["rubber_works", "Мануфактура резины малая", 0.6],
  ["rubber_works", "Мануфактура резины средняя", 1.0],
  ["rubber_works", "Мануфактура резины большая", 1.7],
  ["chem_electrolyte_chems", "Мануфактура химическая малая", 0.6],
  ["chem_electrolyte_chems", "Мануфактура химическая средняя", 1.0],
  ["chem_electrolyte_chems", "Мануфактура химическая большая", 1.8],
  ["tannery", "Мануфактура кожевенная малая", 0.6],
  ["tannery", "Мануфактура кожевенная средняя", 1.0],
  ["tannery", "Мануфактура кожевенная большая", 1.8],
  ["electronics_basic", "Мануфактура электроники малая", 0.6],
  ["electronics_basic", "Мануфактура электроники средняя", 1.0],
  ["electronics_adv", "Мануфактура электроники большая", 1.4],
  ["sawmill", "Мануфактура древесины малая", 0.6],
  ["sawmill", "Мануфактура древесины средняя", 1.0],
  ["sawmill", "Мануфактура древесины большая", 1.8],
  ["line_spears", "Мануфактура копий малая", 0.6],
  ["line_spears", "Мануфактура копий средняя", 1.0],
  ["line_spears", "Мануфактура копий большая", 1.7],
  ["line_motorcycles", "Мануфактура мотоциклов малая", 0.6],
  ["line_motorcycles", "Мануфактура мотоциклов средняя", 1.0],
  ["line_motorcycles", "Мануфактура мотоциклов большая", 1.6],
  ["line_jeeps", "Мануфактура джипов малая", 0.6],
  ["line_jeeps", "Мануфактура джипов средняя", 1.0],
  ["line_jeeps", "Мануфактура джипов большая", 1.6],
  ["line_armor_militia", "Мануфактура доспехов ополчения малая", 0.6],
  ["line_armor_militia", "Мануфактура доспехов ополчения средняя", 1.0],
  ["line_armor_militia", "Мануфактура доспехов ополчения большая", 1.6],
  ["line_armor_aux", "Мануфактура доспехов баталий малая", 0.6],
  ["line_armor_aux", "Мануфактура доспехов баталий средняя", 1.0],
  ["line_armor_aux", "Мануфактура доспехов баталий большая", 1.6],
  ["line_armor_preventor", "Мануфактура доспехов превенторов малая", 0.6],
  ["line_armor_preventor", "Мануфактура доспехов превенторов средняя", 1.0],
  ["line_armor_preventor", "Мануфактура доспехов превенторов большая", 1.6],
  ["line_assault_gun", "Мануфактура осадных орудий малая", 0.6],
  ["line_assault_gun", "Мануфактура осадных орудий средняя", 1.0],
  ["line_assault_gun", "Мануфактура осадных орудий большая", 1.6],
  ["line_rifle_ep", "Мануфактура винтовок малая", 0.6],
  ["line_rifle_ep", "Мануфактура винтовок средняя", 1.0],
  ["line_rifle_ep", "Мануфактура винтовок большая", 1.6],
  ["line_swords", "Мануфактура мечей малая", 0.6],
  ["line_swords", "Мануфактура мечей средняя", 1.0],
  ["line_swords", "Мануфактура мечей большая", 1.6],
  ["line_rifle_gauss", "Мануфактура гаусс-винтовок малая", 0.6],
  ["line_rifle_gauss", "Мануфактура гаусс-винтовок средняя", 1.0],
  ["line_rifle_gauss", "Мануфактура гаусс-винтовок большая", 1.6],
  ["engine_assembly", "Мануфактура движков малая", 0.6],
  ["engine_assembly", "Мануфактура движков средняя", 1.0],
  ["engine_assembly", "Мануфактура движков большая", 1.6],
  ["smithy", "Кузня малая", 0.6],
  ["smithy", "Кузня средняя", 1.0],
  ["adv_smithy", "Кузня большая", 1.4],
  ["barracks", "Замок малый", 0.9],
  ["barracks", "Замок средний", 1.5],
  ["barracks", "Замок большой", 2.4],
  ["bakery", "Монастырь малый", 0.5],
  ["bakery", "Монастырь средний", 0.9],
  ["bakery", "Монастырь большой", 1.4],
  ["line_jeep_civil", "Линия производства джипов", 1.0],
  ["line_motorcycles", "Линия производства мотоциклов", 1.0],
  ["line_armor_preventor", "Линия производства доспехов превенторов", 1.0],
  ["line_rifle_gauss", "Линия производства гаусс-винтовок", 1.0],
  ["line_assault_gun", "Линия производства осадных орудий", 1.0],
  ["line_jeeps", "Линия производства джипов-рейдеров", 1.0],
].forEach(([baseType, name, mul]) => {
  const key = `extra_${name.toLowerCase().replace(/[^a-zа-я0-9]+/gi, "_").replace(/^_+|_+$/g, "")}`;
  const built = scaledBuilding(baseType, name, mul, 0.75 + mul * 0.2);
  if (built) EXTRA_BUILDINGS[key] = built;
});

Object.assign(BUILDINGS, EXTRA_BUILDINGS);

/** Простейшие улучшения (пока только мультипликаторы эффективности) */
export const UPGRADES = {
  "Точная сборка": { efficiencyMul: 1.1, laborMul: 0.95 },
};
