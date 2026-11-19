DENZOUR DAILYDESK - setup (about 5 minutes, cPanel)

Needs: PHP 8.1 or newer with SQLite (pdo_sqlite). Almost all shared hosting has it.

1. Make a subdomain, e.g. work.denzournutrition.com (cPanel > Domains), and turn on the free SSL (https).
2. Open File Manager, go to the subdomain's folder, and Upload the zip. Right click > Extract.
   You should see index.php, config.php, app, assets, data in that folder.
3. Open https://work.denzournutrition.com/index.php
4. First-time setup screen: enter the install key from config.php ("install_key"), your name, email and password.
   This creates the Admin account.
5. Log in > People > add the CEO (role "CEO (view only)") and each employee. A temporary password is shown once; send it to them.
   They must set their own password on first login.
6. Holidays > add company holidays so they are not counted as missing reports (Sundays are already off).

Who sees what
- Employee: fills daily report (can edit last 7 days), sees own history.
- CEO (you can add as many as needed): Dashboard only, view only, plus CSV download. Any other person can be given dashboard access with the "Can view dashboard" tick.
- Admin: everything. People page: add, edit, set any password, give dashboard access, deactivate or delete. All reports page: view, add, edit or delete any employee report on any date.

Backup: copy the data/dailydesk.sqlite file now and then (File Manager > Download).
Not on Apache (nginx hosting)? Ask the host to block access to the app/ and data/ folders and config.php.
Settings (designations, categories, weekly off, edit window): config.php.
