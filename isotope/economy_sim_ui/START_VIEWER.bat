@echo off
setlocal
cd /d %~dp0

REM Expected layout:
REM   C:\isotope\province_routing_data.json
REM   C:\isotope\economy_sim_ui\(this folder)

set DATA=..\province_routing_data.json
set PORT=8787
set ADMIN_API_BASE=http://127.0.0.1:8080

echo Starting Economy UI server...
echo Data: %DATA%
echo API:  %ADMIN_API_BASE%
echo URL:  http://localhost:%PORT%

start "" http://localhost:%PORT%
node server.js %DATA% --port %PORT% --adminApiBase %ADMIN_API_BASE% --requireAdminApi true

endlocal
