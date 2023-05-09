@ECHO OFF
setlocal ENABLEDELAYEDEXPANSION
cd /d %~dp0
for %%i in (%0) do (set "name=%%~ni")
title %0
set FPW_URL=https://fpw.feieryun.cn:379/
rem set FPW_PROXY_URL=http://127.0.0.1
set FPW_FRAMEWORK=tp
set FPW_AUTOLOAD=tp/vendor/autoload.php
rem set FPW_PUBLIC=public
set FPW_COOKIE=cookie.txt
:1
start /b /wait php -c "%~dp0php.ini" "%~dp0fpw/worker.php"
timeout 10
goto 1
PAUSE
