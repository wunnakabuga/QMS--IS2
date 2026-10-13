<?php
/** End the staff session. */
require_once __DIR__ . '/includes/auth.php';
auth_logout();
flash('You have been signed out.', 'info');
redirect('login.php');
