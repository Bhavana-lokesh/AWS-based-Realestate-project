-- Check for duplicate emails before applying this migration:
-- SELECT email, COUNT(*) FROM user GROUP BY email HAVING COUNT(*) > 1;
-- Resolve any duplicates without deleting user data before continuing.
ALTER TABLE `user`
    ADD UNIQUE KEY `unique_user_email` (`email`);
