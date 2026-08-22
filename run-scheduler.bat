@echo off
REM ---------------------------------------------------------------------------
REM Laravel's scheduler heartbeat.
REM
REM Windows Task Scheduler runs this every minute; Laravel decides from it what
REM is actually due - the nightly backup, and anything scheduled later. Nothing
REM happens on the other 1439 runs of the day.
REM
REM Register it once, from an Administrator command prompt:
REM
REM   schtasks /create /tn "VIS Scheduler" /tr "C:\Users\pc\Documents\Amircan student system\run-scheduler.bat" /sc minute /mo 1
REM
REM Remove it with:  schtasks /delete /tn "VIS Scheduler" /f
REM ---------------------------------------------------------------------------
cd /d "%~dp0backend"
C:\xampp\php\php.exe artisan schedule:run >> storage\logs\scheduler.log 2>&1
