# Adminmap admin desktop client

`client-admin/` — отдельный админский Tauri/Vite-проект. Он не смешивается с пользовательским `client/` и предназначен для будущей отдельной сборки админского desktop-приложения.

## Что входит

Админский launcher содержит все админские точки входа и пользовательские экраны для проверки:

- все админки карты (`admin.html`, `admin_ui_alt.html`, `admin_v2.html`);
- админку ходов;
- админку приказов/вердиктов;
- войну и battle simulator;
- админку wiki;
- genealogy admin;
- Sidecar Control Center;
- VK Bot Admin;
- админку фигур SVG;
- sidecar-модули marine/river/raster logistics и enterprise overlay;
- пользовательские публичные экраны для контроля результата.

Список админских и shared точек входа хранится в `src/modules.js`.

## Выбор удалённого сервера

В UI можно выбрать IP/host удалённого сервера, схему, порт и base path. Например:

- `http://127.0.0.1:8080` для локального PHP-сервера;
- `http://192.168.1.10:8080` для сервера в LAN;
- `https://example.org/adminmap` для production-публикации в подпапке.

Итоговый URL сохраняется в IndexedDB. Общий query string можно использовать для токенов или служебных параметров.

## Tauri-проект

В этой папке есть собственный `src-tauri/` с отдельным `Cargo.toml`, `tauri.conf.json`, window label и bundle identifier. Это самостоятельный админский Tauri-проект; он не использует HTML entrypoint второго клиента. Иконки и другие бинарные assets намеренно не добавлены в git — их можно скачать или создать в runtime/CI.

## Runtime-cache и бинарные ассеты

Runtime-cache, скачанные ассеты и созданные клиентом файлы разрешены в runtime. В git не коммитятся только build outputs и бинарные артефакты (`.exe`, изображения, иконки, архивы и т.п.).

## Запуск разработки

Поднимите основной сервер из корня проекта:

```bash
php -S 127.0.0.1:8080
```

Запустите админский клиент:

```bash
cd client-admin
npm install
npm run dev
```

## Проверка без установки зависимостей

```bash
cd client-admin
npm run check
```
