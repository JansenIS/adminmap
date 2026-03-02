@echo off
setlocal
cd /d %~dp0

REM Expected layout:
REM   C:\isotope\province_routing_data.json
REM   C:\isotope\economy_sim_ui\(this folder)

set DATA=..\province_routing_data.json
set PORT=8787
set ADMIN_API_BASE=
set REQUIRE_ADMIN_API=false

echo Starting Economy UI server...
echo Data: %DATA%
if "%ADMIN_API_BASE%"=="" (
  echo API:  ^<disabled^> (fallback to local provinces.json)
) else (
  echo API:  %ADMIN_API_BASE%
)
echo Strict backend mode: %REQUIRE_ADMIN_API%
echo URL:  http://localhost:%PORT%

start "" http://localhost:%PORT%
if "%ADMIN_API_BASE%"=="" (
  node server.js %DATA% --port %PORT% --requireAdminApi %REQUIRE_ADMIN_API%
) else (
  node server.js %DATA% --port %PORT% --adminApiBase %ADMIN_API_BASE% --requireAdminApi %REQUIRE_ADMIN_API%
)

endlocal
