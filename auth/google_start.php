<?php
/**
 * FILE: auth/google_start.php
 * WHAT IT DOES: "Sign in with Google", step 1 - sends the staff member to
 * Google's account chooser, with a random state value for CSRF protection.
 * FIND: Google sign in, OAuth, Google login
 *
 * Google OAuth — step 1.
 * Sends the staff member to Google's consent screen. Google then returns
 * them to google_callback.php with a one-time code.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (!GOOGLE_OAUTH_ENABLED) {
    redirect('../login.php?google=unavailable');
}

auth_start();
$state = bin2hex(random_bytes(16));           // CSRF protection for the round trip
$_SESSION['google_oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]);
redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
