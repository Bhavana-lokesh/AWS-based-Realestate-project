ALTER TABLE `user`
    ADD COLUMN `firebase_uid` VARCHAR(128) DEFAULT NULL,
    ADD COLUMN `phone_verified_at` DATETIME DEFAULT NULL;

ALTER TABLE `user`
    ADD COLUMN `verified_phone_normalized` VARCHAR(32)
        GENERATED ALWAYS AS (CASE
            WHEN `phone_verified_at` IS NULL THEN NULL
            ELSE `phone_normalized`
        END) STORED,
    ADD UNIQUE KEY `unique_user_firebase_uid` (`firebase_uid`),
    ADD UNIQUE KEY `unique_verified_user_phone` (`verified_phone_normalized`);
