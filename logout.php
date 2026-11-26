<?php
/**
 * FILE: logout.php
 * WHAT IT DOES: Signs the staff member out (destroys the session) and
 * returns to the login page.
 * FIND: logout, sign out
 */
require_once __DIR__ . '/includes/auth.php';
auth_logout();
flash('You have been signed out.', 'info');
redirect('login.php');
