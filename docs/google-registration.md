# Google connection for senior registration

The registration page includes Continue with Google. Google verifies the email, then the senior completes the existing profile, valid ID, and password fields. Barangay approval is unchanged. This is registration email verification, not Google login for existing accounts.

## Required configuration

Create an OAuth client of type Web application in Google Cloud and configure its consent screen. Register the exact callback URL:
http://localhost:8080/barangay-assistance-system/register_google.php

Use your actual hostname and path if different; use HTTPS in production. Set these environment variables for Apache, then restart Apache:
- GOOGLE_CLIENT_ID
- GOOGLE_CLIENT_SECRET
- GOOGLE_REDIRECT_URI (the exact callback registered above)

Keep the secret outside source control and never put it in browser JavaScript. In Google's testing mode, add the intended Google accounts as test users. Enable PHP cURL and a valid CA trust store for HTTPS calls. Do not disable certificate verification.

Without credentials, the button explains that Google connection is unavailable and offers the email verification alternative. Live Google authorization requires these credentials and cannot be tested with placeholder values.

The server uses authorization code exchange with state, PKCE, a 10-minute request expiry, and Google's authenticated userinfo endpoint. It requires a verified email and checks for existing accounts. Tokens are not stored. The resulting registration permission is bound to the session, lasts one hour, and uses the same server-side email checks as OTP registration.

Reference: https://developers.google.com/identity/protocols/oauth2/web-server


## This local installation
Credentials are loaded from C:/xamppa/barangay-assistance-storage/google-oauth.json, outside the public website and repository. GOOGLE_CREDENTIALS_FILE can override the path; environment variables take precedence. The callback is read from the credentials file only when exactly one redirect URI exists. For multiple callbacks, explicitly set GOOGLE_REDIRECT_URI.
