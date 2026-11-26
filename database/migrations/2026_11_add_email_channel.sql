-- Adds 'email' as a notification channel, for e-ticket delivery by email.
-- Run once against an existing database:
--   mysql -u root qms < database/migrations/2026_11_add_email_channel.sql
ALTER TABLE notification
    MODIFY channel ENUM('e-ticket','sms','email') NOT NULL;
