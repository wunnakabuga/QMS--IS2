<?php
/**
 * FILE: auth/google_callback.php
 * WHAT IT DOES: "Sign in with Google", step 2 - checks the state value,
 * swaps Google's one-time code for the verified email, and logs in the
 * staff/admin account with that email. Unknown emails are refused.
 * FIND: Google sign in, OAuth callback, Google login
 *
 * Google OAuth — step 2.
 * Google redirects the staff member back here with a code. We swap the code
 * for the user's verified email, match it to a staff/admin account, and log
 * them in. No password or OTP is needed — Google has already verified them.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

auth_start();

/** POST form fields to a Google endpoint, return the decoded JSON. */
function google_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$body, true) ?: [];
}

/** GET a Google endpoint with a bearer token, return the decoded JSON. */
function google_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$body, true) ?: [];
}

/** Abandon the sign-in, log the reason for the developer, send the user back. */
function google_fail(string $why): void
{
    error_log('[google-oauth] ' . $why);
    redirect('../login.php?google=failed');
}

if (!GOOGLE_OAUTH_ENABLED) {
    redirect('../login.php?google=unavailable');
}

// Verify the round-trip state (guards against forged callbacks).
$state = $_GET['state'] ?? '';
if ($state === '' || empty($_SESSION['google_oauth_state'])
    || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    google_fail('State mismatch');
}
unset($_SESSION['google_oauth_state']);

if (!empty($_GET['error'])) {
    google_fail('Consent denied: ' . $_GET['error']);
}
$code = $_GET['code'] ?? '';
if ($code === '') {
    google_fail('No authorisation code returned');
}

// Exchange the code for an access token.
$tok = google_post('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);
$access = $tok['access_token'] ?? null;
if (!$access) {
    google_fail('Token exchange failed: ' . json_encode($tok));
}

// Fetch the verified profile (email).
$info  = google_get('https://openidconnect.googleapis.com/v1/userinfo', $access);
$email = strtolower(trim($info['email'] ?? ''));
if ($email === '' || empty($info['email_verified'])) {
    google_fail('No verified email on the Google account');
}

// Match a staff or admin account by email.
$user = q_one(
    'SELECT user_id, full_name, email, phone_number, role
       FROM user WHERE email = :e AND role IN ("staff","admin") LIMIT 1',
    [':e' => $email]
);
if (!$user) {
    // Signed in with Google, but that address is not a registered staff account.
    redirect('../login.php?google=unknown');
}

auth_finalise($user);   // opens the session; Google login skips password + OTP
redirect('../' . ($user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/counter.php'));
