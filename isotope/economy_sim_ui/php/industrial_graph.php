<?php

declare(strict_types=1);

return [
  'sawmill' => [
    'name' => "Деревообрабатывающая мануфактура",
    'labor' => 80,
    'cap' => 1,
    'input' => [
      'wood_raw' => 12
    ],
    'output' => [
      'wood_processed' => 10
    ]
  ],
  'textile' => [
    'name' => "Текстильная мануфактура",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'fiber_raw' => 20
    ],
    'output' => [
      'cloth_peasant' => 18,
      'cloth_city' => 3
    ]
  ],
  'tannery' => [
    'name' => "Кожевенная мануфактура",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'hides_raw' => 14
    ],
    'output' => [
      'leather_tanned' => 18,
      'leather_reinforced' => 4
    ]
  ],
  'rubber_works' => [
    'name' => "Мануфактура резины",
    'labor' => 95,
    'cap' => 1,
    'input' => [
      'rubber_raw' => 10,
      'petrochem_raw' => 6
    ],
    'output' => [
      'rubber_industrial' => 12
    ]
  ],
  'plastics' => [
    'name' => "Мануфактура пластиков",
    'labor' => 110,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 16
    ],
    'output' => [
      'poly_housings' => 14
    ]
  ],
  'smithy' => [
    'name' => "Кузница",
    'labor' => 85,
    'cap' => 1,
    'input' => [
      'iron' => 6
    ],
    'output' => [
      'forged_parts' => 8
    ]
  ],
  'adv_smithy' => [
    'name' => "Продвинутая кузница",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'steel' => 5,
      'forged_parts' => 2
    ],
    'output' => [
      'precision_blanks' => 4
    ]
  ],
  'stamping' => [
    'name' => "Штамповочная мануфактура",
    'labor' => 130,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 10
    ],
    'output' => [
      'stamped_frames' => 8
    ]
  ],
  'engine_assembly' => [
    'name' => "Мануфактура по сборке движков",
    'labor' => 160,
    'cap' => 1,
    'input' => [
      'forged_parts' => 4,
      'stamped_frames' => 4,
      'rubber_industrial' => 2,
      'poly_housings' => 2,
      'basic_electronics' => 1
    ],
    'output' => [
      'engine_kit' => 2
    ]
  ],
  'smelter' => [
    'name' => "Металлургический цех",
    'labor' => 140,
    'cap' => 1,
    'input' => [
      'iron_ore' => 12,
      'coke' => 8
    ],
    'output' => [
      'iron' => 10,
      'steel_ingot' => 2
    ]
  ],
  'adv_smelter' => [
    'name' => "Продвинутый металлургический цех",
    'labor' => 190,
    'cap' => 1,
    'input' => [
      'iron' => 8,
      'tin' => 1,
      'copper' => 1,
      'lead' => 1,
      'coke' => 4
    ],
    'output' => [
      'steel' => 8,
      'rolled_steel' => 4,
      'precision_steel' => 1
    ]
  ],
  'filters' => [
    'name' => "Мануфактура по производству фильтров",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 8,
      'villadium' => 120
    ],
    'output' => [
      'villadium_filter_personal' => 18,
      'villadium_filter_ind' => 2
    ]
  ],
  'electrolyte' => [
    'name' => "Мануфактура по производству электролита",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'electrolyte_chems' => 10,
      'distilled_water' => 20
    ],
    'output' => [
      'electrolyte_ind' => 24
    ]
  ],
  'electronics_basic' => [
    'name' => "Мануфактура базовой электроники",
    'labor' => 150,
    'cap' => 1,
    'input' => [
      'copper' => 2,
      'lead' => 1,
      'tin' => 1,
      'poly_housings' => 2
    ],
    'output' => [
      'e_parts' => 6,
      'basic_electronics' => 2
    ]
  ],
  'electronics_adv' => [
    'name' => "Мануфактура продвинутой электроники",
    'labor' => 190,
    'cap' => 1,
    'input' => [
      'e_parts' => 4,
      'mcu_set' => 1,
      'precision_blanks' => 1
    ],
    'output' => [
      'adv_controller' => 1,
      'power_module' => 1
    ]
  ],
  'life_support' => [
    'name' => "Производство систем жизнеобеспечения",
    'labor' => 220,
    'cap' => 1,
    'input' => [
      'life_core' => 1,
      'villadium_filter_ind' => 1,
      'electrolyte_ind' => 10,
      'poly_housings' => 3,
      'adv_controller' => 1
    ],
    'output' => [
      'life_module' => 1,
      'villadium_life_system' => 0.1
    ]
  ],
  'farm_mutabryukva' => [
    'name' => "Тепличные поля (мутабрюква)",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'fertilizer_mineral' => 0.08
    ],
    'output' => [
      'mutabryukva' => 220
    ]
  ],
  'poultry_mutachicken' => [
    'name' => "Птичники (мутакурицы)",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 45
    ],
    'output' => [
      'mutachicken' => 25,
      'meat' => 45,
      'hides_raw' => 1
    ]
  ],
  'bakery' => [
    'name' => "Пекарни",
    'labor' => 60,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 70,
      'distilled_water' => 18
    ],
    'output' => [
      'bread' => 90
    ]
  ],
  'canning' => [
    'name' => "Консервные цеха",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'meat' => 60,
      'steel' => 1
    ],
    'output' => [
      'meat_cans' => 55
    ]
  ],
  'fertilizer_plant' => [
    'name' => "Химзавод удобрений",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 2,
      'distilled_water' => 8
    ],
    'output' => [
      'fertilizer_mineral' => 2.2
    ]
  ],
  'water_distillery' => [
    'name' => "Дистиллятор воды",
    'labor' => 55,
    'cap' => 1,
    'input' => [
      'wood_raw' => 1.5
    ],
    'output' => [
      'distilled_water' => 110
    ]
  ],
  'chem_filter_substrate' => [
    'name' => "Химцех: фильтрующий субстрат",
    'labor' => 95,
    'cap' => 1,
    'input' => [
      'wood_processed' => 6,
      'petrochem_raw' => 6
    ],
    'output' => [
      'filter_substrate' => 18
    ]
  ],
  'chem_electrolyte_chems' => [
    'name' => "Химцех: реагенты электролита",
    'labor' => 100,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 8,
      'distilled_water' => 10
    ],
    'output' => [
      'electrolyte_chems' => 22
    ]
  ],
  'alloy_villadium' => [
    'name' => "Лаборатория сплавов вилладиума",
    'labor' => 180,
    'cap' => 1,
    'input' => [
      'villadium' => 240,
      'steel' => 1,
      'copper' => 0.3
    ],
    'output' => [
      'villadium_alloy' => 2
    ]
  ],
  'microfab_mcu' => [
    'name' => "Микрофабрика (микропроцессоры)",
    'labor' => 230,
    'cap' => 1,
    'input' => [
      'e_parts' => 8,
      'gold' => 1.2,
      'silver' => 0.2,
      'villadium' => 18,
      'poly_housings' => 2
    ],
    'output' => [
      'mcu_set' => 1
    ]
  ],
  'life_core_fab' => [
    'name' => "Фабрика ядер жизнеобеспечения",
    'labor' => 260,
    'cap' => 1,
    'input' => [
      'villadium_alloy' => 1,
      'precision_steel' => 1,
      'adv_controller' => 1,
      'power_module' => 1
    ],
    'output' => [
      'life_core' => 0.6
    ]
  ],
  'wheel_works' => [
    'name' => "Производство колёс",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'rubber_industrial' => 5,
      'steel' => 1
    ],
    'output' => [
      'wheel_moto' => 4,
      'wheel_car' => 1.2,
      'wheel_truck' => 0.6
    ]
  ],
  'line_gasmasks' => [
    'name' => "Линия противогазов",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'villadium_filter_personal' => 6,
      'poly_housings' => 2,
      'cloth_peasant' => 12,
      'rubber_industrial' => 1
    ],
    'output' => [
      'gasmask' => 6
    ]
  ],
  'line_swords' => [
    'name' => "Линия мечей",
    'labor' => 80,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'steel' => 1,
      'leather_tanned' => 4
    ],
    'output' => [
      'sword_tac' => 5
    ]
  ],
  'line_spears' => [
    'name' => "Линия электрокопий",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'basic_electronics' => 1,
      'power_module' => 1,
      'rubber_industrial' => 1
    ],
    'output' => [
      'spear_e' => 4
    ]
  ],
  'line_rifle_ep' => [
    'name' => "Линия электропневматических винтовок",
    'labor' => 140,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'basic_electronics' => 1,
      'rolled_steel' => 2,
      'rubber_industrial' => 1
    ],
    'output' => [
      'rifle_ep' => 2
    ]
  ],
  'line_rifle_gauss' => [
    'name' => "Линия винтовок Гаусса",
    'labor' => 180,
    'cap' => 1,
    'input' => [
      'precision_steel' => 2,
      'adv_controller' => 1,
      'power_module' => 1,
      'batt_ind' => 1,
      'precision_blanks' => 2
    ],
    'output' => [
      'rifle_gauss' => 1
    ]
  ],
  'line_pistols' => [
    'name' => "Линия электропистолетов",
    'labor' => 110,
    'cap' => 1,
    'input' => [
      'forged_parts' => 2,
      'basic_electronics' => 1,
      'rubber_industrial' => 1
    ],
    'output' => [
      'pistol_e' => 3
    ]
  ],
  'line_batteries' => [
    'name' => "Линия аккумуляторов",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'electrolyte_ind' => 20,
      'poly_housings' => 2,
      'lead' => 1,
      'copper' => 1
    ],
    'output' => [
      'batt_ind' => 2
    ]
  ],
  'line_motor_small' => [
    'name' => "Линия малых электродвигателей",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'forged_parts' => 2,
      'rolled_steel' => 2,
      'basic_electronics' => 1
    ],
    'output' => [
      'motor_small' => 2
    ]
  ],
  'line_motor_medium' => [
    'name' => "Линия средних электродвигателей",
    'labor' => 160,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'rolled_steel' => 3,
      'basic_electronics' => 1,
      'power_module' => 1
    ],
    'output' => [
      'motor_medium' => 1
    ]
  ],
  'line_motor_heavy' => [
    'name' => "Линия тяжёлых электродвигателей",
    'labor' => 200,
    'cap' => 1,
    'input' => [
      'forged_parts' => 4,
      'precision_steel' => 3,
      'adv_controller' => 1,
      'power_module' => 2
    ],
    'output' => [
      'motor_heavy' => 0.6
    ]
  ],
  'line_motorcycles' => [
    'name' => "Линия мотоциклов",
    'labor' => 180,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1,
      'stamped_frames' => 1,
      'wheel_moto' => 2,
      'rubber_industrial' => 1,
      'basic_electronics' => 1,
      'batt_ind' => 1
    ],
    'output' => [
      'motorcycle' => 0.8
    ]
  ],
  'line_jeeps' => [
    'name' => "Линия джипов-рейдеров",
    'labor' => 260,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2,
      'stamped_frames' => 2,
      'wheel_car' => 4,
      'rubber_industrial' => 2,
      'adv_controller' => 1,
      'batt_ind' => 2,
      'steel' => 2
    ],
    'output' => [
      'jeep_raider' => 0.25
    ]
  ],
  'line_clothes_peasant' => [
    'name' => "Линия крестьянской одежды",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'cloth_peasant' => 30,
      'leather_tanned' => 5
    ],
    'output' => [
      'clothes_peasant' => 25
    ]
  ],
  'line_clothes_city' => [
    'name' => "Линия городской одежды",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'cloth_city' => 25,
      'leather_tanned' => 6
    ],
    'output' => [
      'clothes_city' => 12
    ]
  ],
  'line_clothes_noble' => [
    'name' => "Линия дворянской одежды",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'cloth_noble' => 20,
      'leather_reinforced' => 3,
      'armotextile' => 2
    ],
    'output' => [
      'clothes_noble' => 4
    ]
  ],
  'line_agri_tools' => [
    'name' => "Мастерские сельхозинструмента",
    'labor' => 80,
    'cap' => 1,
    'input' => [
      'forged_parts' => 2,
      'wood_processed' => 2
    ],
    'output' => [
      'agri_tools' => 4
    ]
  ],
  'line_dishes' => [
    'name' => "Гончарно-кузнечная утварь",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'stone' => 1.2,
      'iron' => 0.6
    ],
    'output' => [
      'dishes_set' => 6
    ]
  ],
  'line_motoblock' => [
    'name' => "Линия мотоблоков",
    'labor' => 140,
    'cap' => 1,
    'input' => [
      'engine_kit' => 0.6,
      'wheel_moto' => 2,
      'stamped_frames' => 1,
      'steel' => 0.8,
      'basic_electronics' => 0.6,
      'batt_ind' => 0.4
    ],
    'output' => [
      'motoblock' => 0.35
    ]
  ],
  'line_tricycle_truck' => [
    'name' => "Линия грузовых трициклов",
    'labor' => 170,
    'cap' => 1,
    'input' => [
      'engine_kit' => 0.8,
      'wheel_car' => 3,
      'stamped_frames' => 2,
      'steel' => 1.4,
      'basic_electronics' => 0.8,
      'batt_ind' => 0.6
    ],
    'output' => [
      'tricycle_truck' => 0.25
    ]
  ],
  'line_jeep_civil' => [
    'name' => "Линия гражданских джипов",
    'labor' => 230,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.6,
      'wheel_car' => 4,
      'stamped_frames' => 3,
      'steel' => 3,
      'adv_controller' => 0.8,
      'batt_ind' => 1.6,
      'rubber_industrial' => 2
    ],
    'output' => [
      'jeep_civil' => 0.18
    ]
  ],
  'line_truck_civil' => [
    'name' => "Линия гражданских грузовиков",
    'labor' => 320,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2.4,
      'wheel_truck' => 6,
      'stamped_frames' => 5,
      'steel' => 6,
      'adv_controller' => 1,
      'batt_ind' => 2.4,
      'rubber_industrial' => 3
    ],
    'output' => [
      'truck_civil' => 0.08
    ]
  ],
  'line_boat_river' => [
    'name' => "Верфь речных катеров",
    'labor' => 260,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.3,
      'wood_processed' => 10,
      'steel' => 2,
      'basic_electronics' => 1,
      'rubber_industrial' => 2
    ],
    'output' => [
      'boat_river' => 0.12
    ]
  ],
  'line_barge_river' => [
    'name' => "Верфь речных барж",
    'labor' => 420,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2,
      'wood_processed' => 18,
      'steel' => 10,
      'stamped_frames' => 6,
      'basic_electronics' => 1.2,
      'rubber_industrial' => 3
    ],
    'output' => [
      'barge_river' => 0.03
    ]
  ],
  'line_air_purifier_home' => [
    'name' => "Линия домашних очистителей воздуха",
    'labor' => 160,
    'cap' => 1,
    'input' => [
      'villadium_filter_personal' => 3,
      'basic_electronics' => 1,
      'poly_housings' => 2,
      'electrolyte_ind' => 2
    ],
    'output' => [
      'air_purifier_home' => 1
    ]
  ],
  'line_armor_militia' => [
    'name' => "Мануфактура доспехов ополчения",
    'labor' => 160,
    'cap' => 1,
    'input' => [
      'leather_reinforced' => 4,
      'armotextile' => 3,
      'steel_ingot' => 1,
      'cloth_peasant' => 6
    ],
    'output' => [
      'armor_militia' => 3
    ]
  ],
  'line_armor_aux' => [
    'name' => "Мануфактура доспехов ауксилии",
    'labor' => 210,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 2,
      'armotextile' => 4,
      'leather_reinforced' => 3,
      'forged_parts' => 2,
      'cloth_city' => 4
    ],
    'output' => [
      'armor_aux' => 1
    ]
  ],
  'line_armor_preventor' => [
    'name' => "Мануфактура доспехов превенторов",
    'labor' => 280,
    'cap' => 1,
    'input' => [
      'precision_steel' => 3,
      'villadium_alloy' => 1,
      'power_module' => 1,
      'life_module' => 0.25,
      'armotextile' => 3,
      'forged_parts' => 3,
      'cloth_noble' => 1
    ],
    'output' => [
      'armor_preventor' => 0.18
    ]
  ],
  'armotextile_mill' => [
    'name' => "Мануфактура бронетекстиля",
    'labor' => 140,
    'cap' => 1,
    'input' => [
      'fiber_raw' => 22,
      'petrochem_raw' => 4,
      'rubber_industrial' => 2
    ],
    'output' => [
      'armotextile' => 12
    ]
  ],
  'noble_textile_atelier' => [
    'name' => "Ателье дворянской ткани",
    'labor' => 130,
    'cap' => 1,
    'input' => [
      'fiber_raw' => 16,
      'leather_reinforced' => 1,
      'silver' => 0.02
    ],
    'output' => [
      'cloth_noble' => 8
    ]
  ],
  'line_railgun' => [
    'name' => "Линия рельсотронов",
    'labor' => 320,
    'cap' => 1,
    'input' => [
      'precision_steel' => 4,
      'villadium_alloy' => 1,
      'adv_controller' => 1,
      'power_module' => 2,
      'batt_ind' => 2
    ],
    'output' => [
      'railgun' => 0.2
    ]
  ],
  'line_assault_gun' => [
    'name' => "Линия штурмовых орудий",
    'labor' => 280,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 5,
      'forged_parts' => 4,
      'basic_electronics' => 2,
      'batt_ind' => 1
    ],
    'output' => [
      'assault_gun' => 0.5
    ]
  ],
  'field_cat_breeding' => [
    'name' => "Питомник полевых котов",
    'labor' => 60,
    'cap' => 1,
    'input' => [
      'meat' => 18,
      'mutabryukva' => 12,
      'distilled_water' => 12
    ],
    'output' => [
      'field_cat' => 0.35
    ]
  ],
  'outpost_guard' => [
    'name' => "Застава",
    'labor' => 120,
    'cap' => 1,
    'input' => [
      'bread' => 20,
      'meat_cans' => 8,
      'distilled_water' => 30,
      'sword_tac' => 0.2,
      'villadium_filter_personal' => 2
    ],
    'output' => []
  ],
  'barracks' => [
    'name' => "Казармы",
    'labor' => 180,
    'cap' => 1,
    'input' => [
      'bread' => 36,
      'meat_cans' => 16,
      'distilled_water' => 55,
      'rifle_ep' => 0.25,
      'armor_militia' => 0.15,
      'villadium_filter_personal' => 4
    ],
    'output' => []
  ],
  'trade_route' => [
    'name' => "Торговый путь",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'jeep_civil' => 0.02,
      'truck_civil' => 0.01,
      'distilled_water' => 16,
      'air_purifier_home' => 0.03
    ],
    'output' => []
  ],
  'hydro_power' => [
    'name' => "Гидроэлектростанция",
    'labor' => 220,
    'cap' => 1,
    'input' => [
      'steel' => 1.8,
      'adv_controller' => 0.15,
      'power_module' => 0.2,
      'distilled_water' => 40
    ],
    'output' => []
  ],
  'wind_farm' => [
    'name' => "Ветряки",
    'labor' => 140,
    'cap' => 1,
    'input' => [
      'steel' => 0.9,
      'basic_electronics' => 0.4,
      'distilled_water' => 10
    ],
    'output' => []
  ],
  'bunker_habited' => [
    'name' => "Обжитый бункер",
    'labor' => 110,
    'cap' => 1,
    'input' => [
      'bread' => 22,
      'meat_cans' => 12,
      'villadium_filter_personal' => 5,
      'air_purifier_home' => 0.2,
      'distilled_water' => 38
    ],
    'output' => []
  ],
  'order_fortress' => [
    'name' => "Крепость ордена",
    'labor' => 360,
    'cap' => 1,
    'input' => [
      'armor_preventor' => 0.05,
      'rifle_gauss' => 0.06,
      'villadium_filter_ind' => 0.3,
      'life_module' => 0.05,
      'distilled_water' => 80,
      'meat_cans' => 24
    ],
    'output' => []
  ],
  'trade_court' => [
    'name' => "Торговый двор",
    'labor' => 100,
    'cap' => 1,
    'input' => [
      'clothes_city' => 0.8,
      'dishes_set' => 1.4,
      'jeep_civil' => 0.01,
      'bread' => 12,
      'distilled_water' => 18
    ],
    'output' => []
  ],
  'extra_шахта_угля_малая' => [
    'name' => "Шахта угля малая",
    'labor' => 119,
    'cap' => 1,
    'input' => [
      'iron_ore' => 6,
      'coke' => 4
    ],
    'output' => [
      'iron' => 5,
      'steel_ingot' => 1
    ]
  ],
  'extra_шахта_угля_средняя' => [
    'name' => "Шахта угля средняя",
    'labor' => 133,
    'cap' => 1,
    'input' => [
      'iron_ore' => 12,
      'coke' => 8
    ],
    'output' => [
      'iron' => 10,
      'steel_ingot' => 2
    ]
  ],
  'extra_шахта_угля_большая' => [
    'name' => "Шахта угля большая",
    'labor' => 155,
    'cap' => 1,
    'input' => [
      'iron_ore' => 21.6,
      'coke' => 14.4
    ],
    'output' => [
      'iron' => 18,
      'steel_ingot' => 3.6
    ]
  ],
  'extra_шахта_вилладиума_малая' => [
    'name' => "Шахта вилладиума малая",
    'labor' => 98,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 2.8,
      'villadium' => 42
    ],
    'output' => [
      'villadium_filter_personal' => 6.3,
      'villadium_filter_ind' => 0.7
    ]
  ],
  'extra_шахта_вилладиума_средняя' => [
    'name' => "Шахта вилладиума средняя",
    'labor' => 106,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 5.2,
      'villadium' => 78
    ],
    'output' => [
      'villadium_filter_personal' => 11.7,
      'villadium_filter_ind' => 1.3
    ]
  ],
  'extra_шахта_вилладиума_большая' => [
    'name' => "Шахта вилладиума большая",
    'labor' => 116,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 8.8,
      'villadium' => 132
    ],
    'output' => [
      'villadium_filter_personal' => 19.8,
      'villadium_filter_ind' => 2.2
    ]
  ],
  'extra_шахта_железа_малая' => [
    'name' => "Шахта железа малая",
    'labor' => 122,
    'cap' => 1,
    'input' => [
      'iron_ore' => 7.2,
      'coke' => 4.8
    ],
    'output' => [
      'iron' => 6,
      'steel_ingot' => 1.2
    ]
  ],
  'extra_шахта_железа_средняя' => [
    'name' => "Шахта железа средняя",
    'labor' => 139,
    'cap' => 1,
    'input' => [
      'iron_ore' => 14.4,
      'coke' => 9.6
    ],
    'output' => [
      'iron' => 12,
      'steel_ingot' => 2.4
    ]
  ],
  'extra_шахта_железа_большая' => [
    'name' => "Шахта железа большая",
    'labor' => 161,
    'cap' => 1,
    'input' => [
      'iron_ore' => 24,
      'coke' => 16
    ],
    'output' => [
      'iron' => 20,
      'steel_ingot' => 4
    ]
  ],
  'extra_шахта_меди_малая' => [
    'name' => "Шахта меди малая",
    'labor' => 123,
    'cap' => 1,
    'input' => [
      'copper' => 0.7,
      'lead' => 0.35,
      'tin' => 0.35,
      'poly_housings' => 0.7
    ],
    'output' => [
      'e_parts' => 2.1,
      'basic_electronics' => 0.7
    ]
  ],
  'extra_шахта_меди_средняя' => [
    'name' => "Шахта меди средняя",
    'labor' => 132,
    'cap' => 1,
    'input' => [
      'copper' => 1.3,
      'lead' => 0.65,
      'tin' => 0.65,
      'poly_housings' => 1.3
    ],
    'output' => [
      'e_parts' => 3.9,
      'basic_electronics' => 1.3
    ]
  ],
  'extra_шахта_меди_большая' => [
    'name' => "Шахта меди большая",
    'labor' => 143,
    'cap' => 1,
    'input' => [
      'copper' => 2,
      'lead' => 1,
      'tin' => 1,
      'poly_housings' => 2
    ],
    'output' => [
      'e_parts' => 6,
      'basic_electronics' => 2
    ]
  ],
  'extra_шахта_камня_малая' => [
    'name' => "Шахта камня малая",
    'labor' => 62,
    'cap' => 1,
    'input' => [
      'stone' => 0.84,
      'iron' => 0.42
    ],
    'output' => [
      'dishes_set' => 4.2
    ]
  ],
  'extra_шахта_камня_средняя' => [
    'name' => "Шахта камня средняя",
    'labor' => 71,
    'cap' => 1,
    'input' => [
      'stone' => 1.56,
      'iron' => 0.78
    ],
    'output' => [
      'dishes_set' => 7.8
    ]
  ],
  'extra_шахта_камня_большая' => [
    'name' => "Шахта камня большая",
    'labor' => 82,
    'cap' => 1,
    'input' => [
      'stone' => 2.52,
      'iron' => 1.26
    ],
    'output' => [
      'dishes_set' => 12.6
    ]
  ],
  'extra_деревня_малая' => [
    'name' => "Деревня малая",
    'labor' => 53,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 49,
      'distilled_water' => 12.6
    ],
    'output' => [
      'bread' => 63
    ]
  ],
  'extra_деревня_средняя' => [
    'name' => "Деревня средняя",
    'labor' => 59,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 84,
      'distilled_water' => 21.6
    ],
    'output' => [
      'bread' => 108
    ]
  ],
  'extra_деревня_большая' => [
    'name' => "Деревня большая",
    'labor' => 68,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 133,
      'distilled_water' => 34.2
    ],
    'output' => [
      'bread' => 171
    ]
  ],
  'extra_порт_речной_малый' => [
    'name' => "Порт речной малый",
    'labor' => 237,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.04,
      'wood_processed' => 8,
      'steel' => 1.6,
      'basic_electronics' => 0.8,
      'rubber_industrial' => 1.6
    ],
    'output' => [
      'boat_river' => 0.096
    ]
  ],
  'extra_порт_речной_средний' => [
    'name' => "Порт речной средний",
    'labor' => 268,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.82,
      'wood_processed' => 14,
      'steel' => 2.8,
      'basic_electronics' => 1.4,
      'rubber_industrial' => 2.8
    ],
    'output' => [
      'boat_river' => 0.168
    ]
  ],
  'extra_порт_речной_большой' => [
    'name' => "Порт речной большой",
    'labor' => 399,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2,
      'wood_processed' => 18,
      'steel' => 10,
      'stamped_frames' => 6,
      'basic_electronics' => 1.2,
      'rubber_industrial' => 3
    ],
    'output' => [
      'barge_river' => 0.03
    ]
  ],
  'extra_порт_морской_малый' => [
    'name' => "Порт морской малый",
    'labor' => 247,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.3,
      'wood_processed' => 10,
      'steel' => 2,
      'basic_electronics' => 1,
      'rubber_industrial' => 2
    ],
    'output' => [
      'boat_river' => 0.12
    ]
  ],
  'extra_порт_морской_средний' => [
    'name' => "Порт морской средний",
    'labor' => 407,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2.2,
      'wood_processed' => 19.8,
      'steel' => 11,
      'stamped_frames' => 6.6,
      'basic_electronics' => 1.32,
      'rubber_industrial' => 3.3
    ],
    'output' => [
      'barge_river' => 0.033
    ]
  ],
  'extra_порт_морской_большой' => [
    'name' => "Порт морской большой",
    'labor' => 449,
    'cap' => 1,
    'input' => [
      'engine_kit' => 3.2,
      'wood_processed' => 28.8,
      'steel' => 16,
      'stamped_frames' => 9.6,
      'basic_electronics' => 1.92,
      'rubber_industrial' => 4.8
    ],
    'output' => [
      'barge_river' => 0.048
    ]
  ],
  'extra_мануфактура_фильтров_малая' => [
    'name' => "Мануфактура фильтров малая",
    'labor' => 104,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 4.8,
      'villadium' => 72
    ],
    'output' => [
      'villadium_filter_personal' => 10.8,
      'villadium_filter_ind' => 1.2
    ]
  ],
  'extra_мануфактура_фильтров_средняя' => [
    'name' => "Мануфактура фильтров средняя",
    'labor' => 114,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 8,
      'villadium' => 120
    ],
    'output' => [
      'villadium_filter_personal' => 18,
      'villadium_filter_ind' => 2
    ]
  ],
  'extra_мануфактура_фильтров_большая' => [
    'name' => "Мануфактура фильтров большая",
    'labor' => 131,
    'cap' => 1,
    'input' => [
      'filter_substrate' => 13.6,
      'villadium' => 204
    ],
    'output' => [
      'villadium_filter_personal' => 30.6,
      'villadium_filter_ind' => 3.4
    ]
  ],
  'extra_мануфактура_резины_малая' => [
    'name' => "Мануфактура резины малая",
    'labor' => 83,
    'cap' => 1,
    'input' => [
      'rubber_raw' => 6,
      'petrochem_raw' => 3.6
    ],
    'output' => [
      'rubber_industrial' => 7.2
    ]
  ],
  'extra_мануфактура_резины_средняя' => [
    'name' => "Мануфактура резины средняя",
    'labor' => 90,
    'cap' => 1,
    'input' => [
      'rubber_raw' => 10,
      'petrochem_raw' => 6
    ],
    'output' => [
      'rubber_industrial' => 12
    ]
  ],
  'extra_мануфактура_резины_большая' => [
    'name' => "Мануфактура резины большая",
    'labor' => 104,
    'cap' => 1,
    'input' => [
      'rubber_raw' => 17,
      'petrochem_raw' => 10.2
    ],
    'output' => [
      'rubber_industrial' => 20.4
    ]
  ],
  'extra_мануфактура_химическая_малая' => [
    'name' => "Мануфактура химическая малая",
    'labor' => 87,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 4.8,
      'distilled_water' => 6
    ],
    'output' => [
      'electrolyte_chems' => 13.2
    ]
  ],
  'extra_мануфактура_химическая_средняя' => [
    'name' => "Мануфактура химическая средняя",
    'labor' => 95,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 8,
      'distilled_water' => 10
    ],
    'output' => [
      'electrolyte_chems' => 22
    ]
  ],
  'extra_мануфактура_химическая_большая' => [
    'name' => "Мануфактура химическая большая",
    'labor' => 111,
    'cap' => 1,
    'input' => [
      'petrochem_raw' => 14.4,
      'distilled_water' => 18
    ],
    'output' => [
      'electrolyte_chems' => 39.6
    ]
  ],
  'extra_мануфактура_кожевенная_малая' => [
    'name' => "Мануфактура кожевенная малая",
    'labor' => 61,
    'cap' => 1,
    'input' => [
      'hides_raw' => 8.4
    ],
    'output' => [
      'leather_tanned' => 10.8,
      'leather_reinforced' => 2.4
    ]
  ],
  'extra_мануфактура_кожевенная_средняя' => [
    'name' => "Мануфактура кожевенная средняя",
    'labor' => 67,
    'cap' => 1,
    'input' => [
      'hides_raw' => 14
    ],
    'output' => [
      'leather_tanned' => 18,
      'leather_reinforced' => 4
    ]
  ],
  'extra_мануфактура_кожевенная_большая' => [
    'name' => "Мануфактура кожевенная большая",
    'labor' => 78,
    'cap' => 1,
    'input' => [
      'hides_raw' => 25.2
    ],
    'output' => [
      'leather_tanned' => 32.4,
      'leather_reinforced' => 7.2
    ]
  ],
  'extra_мануфактура_электроники_малая' => [
    'name' => "Мануфактура электроники малая",
    'labor' => 131,
    'cap' => 1,
    'input' => [
      'copper' => 1.2,
      'lead' => 0.6,
      'tin' => 0.6,
      'poly_housings' => 1.2
    ],
    'output' => [
      'e_parts' => 3.6,
      'basic_electronics' => 1.2
    ]
  ],
  'extra_мануфактура_электроники_средняя' => [
    'name' => "Мануфактура электроники средняя",
    'labor' => 143,
    'cap' => 1,
    'input' => [
      'copper' => 2,
      'lead' => 1,
      'tin' => 1,
      'poly_housings' => 2
    ],
    'output' => [
      'e_parts' => 6,
      'basic_electronics' => 2
    ]
  ],
  'extra_мануфактура_электроники_большая' => [
    'name' => "Мануфактура электроники большая",
    'labor' => 196,
    'cap' => 1,
    'input' => [
      'e_parts' => 5.6,
      'mcu_set' => 1.4,
      'precision_blanks' => 1.4
    ],
    'output' => [
      'adv_controller' => 1.4,
      'power_module' => 1.4
    ]
  ],
  'extra_мануфактура_древесины_малая' => [
    'name' => "Мануфактура древесины малая",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'wood_raw' => 7.2
    ],
    'output' => [
      'wood_processed' => 6
    ]
  ],
  'extra_мануфактура_древесины_средняя' => [
    'name' => "Мануфактура древесины средняя",
    'labor' => 76,
    'cap' => 1,
    'input' => [
      'wood_raw' => 12
    ],
    'output' => [
      'wood_processed' => 10
    ]
  ],
  'extra_мануфактура_древесины_большая' => [
    'name' => "Мануфактура древесины большая",
    'labor' => 89,
    'cap' => 1,
    'input' => [
      'wood_raw' => 21.6
    ],
    'output' => [
      'wood_processed' => 18
    ]
  ],
  'extra_мануфактура_копий_малая' => [
    'name' => "Мануфактура копий малая",
    'labor' => 78,
    'cap' => 1,
    'input' => [
      'forged_parts' => 1.8,
      'basic_electronics' => 0.6,
      'power_module' => 0.6,
      'rubber_industrial' => 0.6
    ],
    'output' => [
      'spear_e' => 2.4
    ]
  ],
  'extra_мануфактура_копий_средняя' => [
    'name' => "Мануфактура копий средняя",
    'labor' => 86,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'basic_electronics' => 1,
      'power_module' => 1,
      'rubber_industrial' => 1
    ],
    'output' => [
      'spear_e' => 4
    ]
  ],
  'extra_мануфактура_копий_большая' => [
    'name' => "Мануфактура копий большая",
    'labor' => 98,
    'cap' => 1,
    'input' => [
      'forged_parts' => 5.1,
      'basic_electronics' => 1.7,
      'power_module' => 1.7,
      'rubber_industrial' => 1.7
    ],
    'output' => [
      'spear_e' => 6.8
    ]
  ],
  'extra_мануфактура_мотоциклов_малая' => [
    'name' => "Мануфактура мотоциклов малая",
    'labor' => 157,
    'cap' => 1,
    'input' => [
      'engine_kit' => 0.6,
      'stamped_frames' => 0.6,
      'wheel_moto' => 1.2,
      'rubber_industrial' => 0.6,
      'basic_electronics' => 0.6,
      'batt_ind' => 0.6
    ],
    'output' => [
      'motorcycle' => 0.48
    ]
  ],
  'extra_мануфактура_мотоциклов_средняя' => [
    'name' => "Мануфактура мотоциклов средняя",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1,
      'stamped_frames' => 1,
      'wheel_moto' => 2,
      'rubber_industrial' => 1,
      'basic_electronics' => 1,
      'batt_ind' => 1
    ],
    'output' => [
      'motorcycle' => 0.8
    ]
  ],
  'extra_мануфактура_мотоциклов_большая' => [
    'name' => "Мануфактура мотоциклов большая",
    'labor' => 193,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.6,
      'stamped_frames' => 1.6,
      'wheel_moto' => 3.2,
      'rubber_industrial' => 1.6,
      'basic_electronics' => 1.6,
      'batt_ind' => 1.6
    ],
    'output' => [
      'motorcycle' => 1.28
    ]
  ],
  'extra_мануфактура_джипов_малая' => [
    'name' => "Мануфактура джипов малая",
    'labor' => 226,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.2,
      'stamped_frames' => 1.2,
      'wheel_car' => 2.4,
      'rubber_industrial' => 1.2,
      'adv_controller' => 0.6,
      'batt_ind' => 1.2,
      'steel' => 1.2
    ],
    'output' => [
      'jeep_raider' => 0.15
    ]
  ],
  'extra_мануфактура_джипов_средняя' => [
    'name' => "Мануфактура джипов средняя",
    'labor' => 247,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2,
      'stamped_frames' => 2,
      'wheel_car' => 4,
      'rubber_industrial' => 2,
      'adv_controller' => 1,
      'batt_ind' => 2,
      'steel' => 2
    ],
    'output' => [
      'jeep_raider' => 0.25
    ]
  ],
  'extra_мануфактура_джипов_большая' => [
    'name' => "Мануфактура джипов большая",
    'labor' => 278,
    'cap' => 1,
    'input' => [
      'engine_kit' => 3.2,
      'stamped_frames' => 3.2,
      'wheel_car' => 6.4,
      'rubber_industrial' => 3.2,
      'adv_controller' => 1.6,
      'batt_ind' => 3.2,
      'steel' => 3.2
    ],
    'output' => [
      'jeep_raider' => 0.4
    ]
  ],
  'extra_мануфактура_доспехов_ополчения_малая' => [
    'name' => "Мануфактура доспехов ополчения малая",
    'labor' => 139,
    'cap' => 1,
    'input' => [
      'leather_reinforced' => 2.4,
      'armotextile' => 1.8,
      'steel_ingot' => 0.6,
      'cloth_peasant' => 3.6
    ],
    'output' => [
      'armor_militia' => 1.8
    ]
  ],
  'extra_мануфактура_доспехов_ополчения_средняя' => [
    'name' => "Мануфактура доспехов ополчения средняя",
    'labor' => 152,
    'cap' => 1,
    'input' => [
      'leather_reinforced' => 4,
      'armotextile' => 3,
      'steel_ingot' => 1,
      'cloth_peasant' => 6
    ],
    'output' => [
      'armor_militia' => 3
    ]
  ],
  'extra_мануфактура_доспехов_ополчения_большая' => [
    'name' => "Мануфактура доспехов ополчения большая",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'leather_reinforced' => 6.4,
      'armotextile' => 4.8,
      'steel_ingot' => 1.6,
      'cloth_peasant' => 9.6
    ],
    'output' => [
      'armor_militia' => 4.8
    ]
  ],
  'extra_мануфактура_доспехов_баталий_малая' => [
    'name' => "Мануфактура доспехов баталий малая",
    'labor' => 183,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 1.2,
      'armotextile' => 2.4,
      'leather_reinforced' => 1.8,
      'forged_parts' => 1.2,
      'cloth_city' => 2.4
    ],
    'output' => [
      'armor_aux' => 0.6
    ]
  ],
  'extra_мануфактура_доспехов_баталий_средняя' => [
    'name' => "Мануфактура доспехов баталий средняя",
    'labor' => 200,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 2,
      'armotextile' => 4,
      'leather_reinforced' => 3,
      'forged_parts' => 2,
      'cloth_city' => 4
    ],
    'output' => [
      'armor_aux' => 1
    ]
  ],
  'extra_мануфактура_доспехов_баталий_большая' => [
    'name' => "Мануфактура доспехов баталий большая",
    'labor' => 225,
    'cap' => 1,
    'input' => [
      'rolled_steel' => 3.2,
      'armotextile' => 6.4,
      'leather_reinforced' => 4.8,
      'forged_parts' => 3.2,
      'cloth_city' => 6.4
    ],
    'output' => [
      'armor_aux' => 1.6
    ]
  ],
  'extra_мануфактура_доспехов_превенторов_малая' => [
    'name' => "Мануфактура доспехов превенторов малая",
    'labor' => 244,
    'cap' => 1,
    'input' => [
      'precision_steel' => 1.8,
      'villadium_alloy' => 0.6,
      'power_module' => 0.6,
      'life_module' => 0.15,
      'armotextile' => 1.8,
      'forged_parts' => 1.8,
      'cloth_noble' => 0.6
    ],
    'output' => [
      'armor_preventor' => 0.108
    ]
  ],
  'extra_мануфактура_доспехов_превенторов_средняя' => [
    'name' => "Мануфактура доспехов превенторов средняя",
    'labor' => 266,
    'cap' => 1,
    'input' => [
      'precision_steel' => 3,
      'villadium_alloy' => 1,
      'power_module' => 1,
      'life_module' => 0.25,
      'armotextile' => 3,
      'forged_parts' => 3,
      'cloth_noble' => 1
    ],
    'output' => [
      'armor_preventor' => 0.18
    ]
  ],
  'extra_мануфактура_доспехов_превенторов_большая' => [
    'name' => "Мануфактура доспехов превенторов большая",
    'labor' => 300,
    'cap' => 1,
    'input' => [
      'precision_steel' => 4.8,
      'villadium_alloy' => 1.6,
      'power_module' => 1.6,
      'life_module' => 0.4,
      'armotextile' => 4.8,
      'forged_parts' => 4.8,
      'cloth_noble' => 1.6
    ],
    'output' => [
      'armor_preventor' => 0.288
    ]
  ],
  'extra_мануфактура_винтовок_малая' => [
    'name' => "Мануфактура винтовок малая",
    'labor' => 122,
    'cap' => 1,
    'input' => [
      'forged_parts' => 1.8,
      'basic_electronics' => 0.6,
      'rolled_steel' => 1.2,
      'rubber_industrial' => 0.6
    ],
    'output' => [
      'rifle_ep' => 1.2
    ]
  ],
  'extra_мануфактура_винтовок_средняя' => [
    'name' => "Мануфактура винтовок средняя",
    'labor' => 133,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'basic_electronics' => 1,
      'rolled_steel' => 2,
      'rubber_industrial' => 1
    ],
    'output' => [
      'rifle_ep' => 2
    ]
  ],
  'extra_мануфактура_винтовок_большая' => [
    'name' => "Мануфактура винтовок большая",
    'labor' => 150,
    'cap' => 1,
    'input' => [
      'forged_parts' => 4.8,
      'basic_electronics' => 1.6,
      'rolled_steel' => 3.2,
      'rubber_industrial' => 1.6
    ],
    'output' => [
      'rifle_ep' => 3.2
    ]
  ],
  'extra_мануфактура_мечей_малая' => [
    'name' => "Мануфактура мечей малая",
    'labor' => 70,
    'cap' => 1,
    'input' => [
      'forged_parts' => 1.8,
      'steel' => 0.6,
      'leather_tanned' => 2.4
    ],
    'output' => [
      'sword_tac' => 3
    ]
  ],
  'extra_мануфактура_мечей_средняя' => [
    'name' => "Мануфактура мечей средняя",
    'labor' => 76,
    'cap' => 1,
    'input' => [
      'forged_parts' => 3,
      'steel' => 1,
      'leather_tanned' => 4
    ],
    'output' => [
      'sword_tac' => 5
    ]
  ],
  'extra_мануфактура_мечей_большая' => [
    'name' => "Мануфактура мечей большая",
    'labor' => 86,
    'cap' => 1,
    'input' => [
      'forged_parts' => 4.8,
      'steel' => 1.6,
      'leather_tanned' => 6.4
    ],
    'output' => [
      'sword_tac' => 8
    ]
  ],
  'extra_мануфактура_гаусс_винтовок_малая' => [
    'name' => "Мануфактура гаусс-винтовок малая",
    'labor' => 157,
    'cap' => 1,
    'input' => [
      'precision_steel' => 1.2,
      'adv_controller' => 0.6,
      'power_module' => 0.6,
      'batt_ind' => 0.6,
      'precision_blanks' => 1.2
    ],
    'output' => [
      'rifle_gauss' => 0.6
    ]
  ],
  'extra_мануфактура_гаусс_винтовок_средняя' => [
    'name' => "Мануфактура гаусс-винтовок средняя",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'precision_steel' => 2,
      'adv_controller' => 1,
      'power_module' => 1,
      'batt_ind' => 1,
      'precision_blanks' => 2
    ],
    'output' => [
      'rifle_gauss' => 1
    ]
  ],
  'extra_мануфактура_гаусс_винтовок_большая' => [
    'name' => "Мануфактура гаусс-винтовок большая",
    'labor' => 193,
    'cap' => 1,
    'input' => [
      'precision_steel' => 3.2,
      'adv_controller' => 1.6,
      'power_module' => 1.6,
      'batt_ind' => 1.6,
      'precision_blanks' => 3.2
    ],
    'output' => [
      'rifle_gauss' => 1.6
    ]
  ],
  'extra_мануфактура_движков_малая' => [
    'name' => "Мануфактура движков малая",
    'labor' => 139,
    'cap' => 1,
    'input' => [
      'forged_parts' => 2.4,
      'stamped_frames' => 2.4,
      'rubber_industrial' => 1.2,
      'poly_housings' => 1.2,
      'basic_electronics' => 0.6
    ],
    'output' => [
      'engine_kit' => 1.2
    ]
  ],
  'extra_мануфактура_движков_средняя' => [
    'name' => "Мануфактура движков средняя",
    'labor' => 152,
    'cap' => 1,
    'input' => [
      'forged_parts' => 4,
      'stamped_frames' => 4,
      'rubber_industrial' => 2,
      'poly_housings' => 2,
      'basic_electronics' => 1
    ],
    'output' => [
      'engine_kit' => 2
    ]
  ],
  'extra_мануфактура_движков_большая' => [
    'name' => "Мануфактура движков большая",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'forged_parts' => 6.4,
      'stamped_frames' => 6.4,
      'rubber_industrial' => 3.2,
      'poly_housings' => 3.2,
      'basic_electronics' => 1.6
    ],
    'output' => [
      'engine_kit' => 3.2
    ]
  ],
  'extra_кузня_малая' => [
    'name' => "Кузня малая",
    'labor' => 74,
    'cap' => 1,
    'input' => [
      'iron' => 3.6
    ],
    'output' => [
      'forged_parts' => 4.8
    ]
  ],
  'extra_кузня_средняя' => [
    'name' => "Кузня средняя",
    'labor' => 81,
    'cap' => 1,
    'input' => [
      'iron' => 6
    ],
    'output' => [
      'forged_parts' => 8
    ]
  ],
  'extra_кузня_большая' => [
    'name' => "Кузня большая",
    'labor' => 124,
    'cap' => 1,
    'input' => [
      'steel' => 7,
      'forged_parts' => 2.8
    ],
    'output' => [
      'precision_blanks' => 5.6
    ]
  ],
  'extra_монастырь_малый' => [
    'name' => "Монастырь малый",
    'labor' => 51,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 35,
      'distilled_water' => 9
    ],
    'output' => [
      'bread' => 45
    ]
  ],
  'extra_монастырь_средний' => [
    'name' => "Монастырь средний",
    'labor' => 56,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 63,
      'distilled_water' => 16.2
    ],
    'output' => [
      'bread' => 81
    ]
  ],
  'extra_монастырь_большой' => [
    'name' => "Монастырь большой",
    'labor' => 62,
    'cap' => 1,
    'input' => [
      'mutabryukva' => 98,
      'distilled_water' => 25.2
    ],
    'output' => [
      'bread' => 126
    ]
  ],
  'extra_линия_производства_джипов' => [
    'name' => "Линия производства джипов",
    'labor' => 219,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1.6,
      'wheel_car' => 4,
      'stamped_frames' => 3,
      'steel' => 3,
      'adv_controller' => 0.8,
      'batt_ind' => 1.6,
      'rubber_industrial' => 2
    ],
    'output' => [
      'jeep_civil' => 0.18
    ]
  ],
  'extra_линия_производства_мотоциклов' => [
    'name' => "Линия производства мотоциклов",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'engine_kit' => 1,
      'stamped_frames' => 1,
      'wheel_moto' => 2,
      'rubber_industrial' => 1,
      'basic_electronics' => 1,
      'batt_ind' => 1
    ],
    'output' => [
      'motorcycle' => 0.8
    ]
  ],
  'extra_линия_производства_доспехов_превенторов' => [
    'name' => "Линия производства доспехов превенторов",
    'labor' => 266,
    'cap' => 1,
    'input' => [
      'precision_steel' => 3,
      'villadium_alloy' => 1,
      'power_module' => 1,
      'life_module' => 0.25,
      'armotextile' => 3,
      'forged_parts' => 3,
      'cloth_noble' => 1
    ],
    'output' => [
      'armor_preventor' => 0.18
    ]
  ],
  'extra_линия_производства_гаусс_винтовок' => [
    'name' => "Линия производства гаусс-винтовок",
    'labor' => 171,
    'cap' => 1,
    'input' => [
      'precision_steel' => 2,
      'adv_controller' => 1,
      'power_module' => 1,
      'batt_ind' => 1,
      'precision_blanks' => 2
    ],
    'output' => [
      'rifle_gauss' => 1
    ]
  ],
  'extra_линия_производства_джипов_рейдеров' => [
    'name' => "Линия производства джипов-рейдеров",
    'labor' => 247,
    'cap' => 1,
    'input' => [
      'engine_kit' => 2,
      'stamped_frames' => 2,
      'wheel_car' => 4,
      'rubber_industrial' => 2,
      'adv_controller' => 1,
      'batt_ind' => 2,
      'steel' => 2
    ],
    'output' => [
      'jeep_raider' => 0.25
    ]
  ]
];
