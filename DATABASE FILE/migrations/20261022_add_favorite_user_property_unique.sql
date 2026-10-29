ALTER TABLE `favorite`
    ADD UNIQUE KEY `unique_user_property` (`user_id`, `property_id`);
