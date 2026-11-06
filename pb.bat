@echo off
REM Fast push script for Lechon System: pushes to both main and lechon-JM
set msg=%*
if "%msg%"=="" set msg=Update and synchronize project changes

git add .
git commit -m "%msg%"
git push origin main
git checkout lechon-JM
git merge main --no-edit
git push origin lechon-JM
git checkout main
echo [OK] Successfully pushed to both main and lechon-JM!
