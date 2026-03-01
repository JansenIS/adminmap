# Genealogy Simulator (backend-first)

Новая отдельная папка с интерфейсами:
- `genealogy_simulator/admin.html` — админ-редактор дерева;
- `genealogy_simulator/index.html` — публичный просмотр с модальным профилем персонажа.

Работает только через backend API:
- `GET /api/genealogy/`
- `GET /api/genealogy/show/?id=<character_id>`
- `POST /api/genealogy/characters/`
- `POST /api/genealogy/relationships/`

Данные хранятся в `data/genealogy_tree.json`.
