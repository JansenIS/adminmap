# Windows desktop bootstrap (local, not committed)

Этот шаг запускает стартовый каркас Windows-приложения (Tauri) в **локальной папке**, которую git не отслеживает.

## Почему так

- Вы просили держать Windows-версию в директории, которая не копируется/не версионируется через git.
- Для этого добавлен игнорируемый префикс `local/` в `.gitignore`.

## Быстрый старт

```bash
bash tools/bootstrap_windows_tauri.sh
```

По умолчанию проект создаётся в:

```text
local/windows-adminmap-tauri
```

Можно указать собственную локальную папку:

```bash
bash tools/bootstrap_windows_tauri.sh /absolute/or/relative/path
```

## Что дальше

1. Открыть созданный Tauri-проект.
2. Подключить существующие `index.html`/`admin.html` как основу UI.
3. Добавить слой локального кэша тяжёлых ассетов (геометрия/SVG) в native storage.
