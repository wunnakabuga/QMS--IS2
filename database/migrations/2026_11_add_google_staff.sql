-- Register your Google account as a staff member so Google sign-in logs you in.
-- Run once in phpMyAdmin (qms database -> SQL tab).
INSERT INTO user (full_name, phone_number, email, password_hash, role)
VALUES ('Charles Kabuga', '+254720776046', 'charleskabugamuriuki@gmail.com', NULL, 'staff')
ON DUPLICATE KEY UPDATE role = 'staff', full_name = 'Charles Kabuga';
