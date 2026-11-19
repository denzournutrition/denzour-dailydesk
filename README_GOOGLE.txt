SIGN IN WITH GOOGLE - one-time setup (about 10 minutes)

Only people already added in DailyDesk > People can sign in. Google does not create new accounts.
Their DailyDesk email must be the same as their Google account email.

A. Create the Google keys (free)
1. Open https://console.cloud.google.com and sign in with your company Google account.
2. Top bar > project list > New project. Name: Denzour DailyDesk > Create.
3. Menu > APIs & Services > OAuth consent screen (or "Google Auth Platform").
   - User type: External (or Internal if you use Google Workspace and all staff are on it).
   - App name: Denzour DailyDesk. Support email: yours. Save.
   - If it asks for Publishing status, click "Publish app" (In production), so everyone can sign in.
4. Credentials > Create credentials > OAuth client ID.
   - Application type: Web application. Name: DailyDesk.
   - Authorized redirect URIs > Add URI, paste exactly:
       https://reporting.denzuu.com/google-callback.php
   - Create. Copy the Client ID and the Client secret.

B. Put the keys in DailyDesk
1. cPanel > File Manager > the reporting.denzuu.com folder > right-click config.php > Edit.
2. Find these two lines and paste your keys between the quotes:
       'google_client_id'     => '',
       'google_client_secret' => '',
   If the lines are not there (older install), add them just above 'max_hours_task'.
3. Save. Open the login page: the "Continue with Google" button now shows.

Keep the Client secret private. Never send it in chat or email.
Change the web address? Add the new redirect URI in Google Credentials too.
