@ECHO OFF
setlocal ENABLEDELAYEDEXPANSION
for %%i in (%0) do (set "name=%%~ni")
title %0
set FPW_URL=http://xxx.28820.com/
set FPW_HOST=xxx.28820.com
set FPW_TOKEN=21a018194adc44e7
set FPW_FRAMEWORK=tp
set FPW_AUTOLOAD=tp/vendor/autoload.php
rem set FPW_PUBLIC=public
:1
start /b /wait php -c "%~dp0php.ini" "%~dp0fpw/worker.php"
timeout 10
goto 1
PAUSE
