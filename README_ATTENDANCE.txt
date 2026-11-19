DENZOUR DAILYDESK v7 - ATTENDANCE, BREAKS AND DOWNLOADS
=======================================================

WHAT IT DOES
- First login of a working day = start of day. No button to press.
- "My day" page (employees): Start lunch / End lunch, Start tea / End tea, End my day.
  Tea can be taken many times; the total is counted against the tea allowance.
- Late: if the first login is after start time + grace, the person must pick a reason
  (and optionally the real start time) before they can open any other page.
- Missed day: a working day with no login and no leave report asks for a reason on
  the next login. Weekly offs and holidays (Holidays page) are never asked.
- If someone forgets "End my day", the end time is their last activity in DailyDesk
  (shown as "auto").
- Working time = end - start - lunch - tea.

WHO SEES WHAT
- Employees: only their own day and last 14 days.
- CEO and admin: Attendance page (who is in, late, on break, month summary).
- Admin only: accept or flag a reason, change the rules.
- Admin and CEO themselves are not tracked.

DEFAULT RULES (Attendance page > Attendance rules)
- Start 10:00, grace 15 min, lunch 45 min, tea 30 min in total, 8 hours a day.
- Tracking starts from the day you install this update, so old days are never asked.

DOWNLOADS (CEO and admin)
- Menu > Downloads. Choose dates (this month, last month, all time or your own).
- CSV for: attendance per day, every punch, reasons, sales orders, sales calls,
  call minutes, daily reports. Or everything in one zip.
- CSV opens in Excel and Google Sheets.
- Everything is kept in data/dailydesk.sqlite. Copy that file now and then as a backup.

INSTALL
- Upload the UPDATE zip over the existing folder in File Manager and extract.
  config.php, data/ and .htaccess are not in the UPDATE zip, so they stay as they are.
