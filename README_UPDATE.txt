ONE-CLICK UPDATES (v10)
=======================
1. Log in as admin. Menu > Admin > Update.
2. Choose the UPDATE zip (do not unzip it) and press Update now.
3. Check the pages. If something looks wrong, press "Go back to this" on the safety copy.

Safe by design:
- config.php, data/, any .htaccess are never changed by an update.
- A safety copy of the old version is saved first (last 8 are kept) in data/backups.
- Only code files (php, css, js, images, txt) are accepted. Anything else in the zip is ignored.
- New database fields are added automatically.

The very first time (this v10 zip) upload it in cPanel File Manager as before.
From the next version on, use the Update page.
