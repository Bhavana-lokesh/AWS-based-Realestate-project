CREATE TABLE IF NOT EXISTS `property_change_requests` (
    `request_id` BIGINT NOT NULL AUTO_INCREMENT,
    `property_id` INT(11) NOT NULL,
    `agent_id` INT(11) NOT NULL,
    `original_values` LONGTEXT NOT NULL,
    `proposed_values` LONGTEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `reviewed_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`request_id`),
    KEY `idx_property_change_status_submitted` (`status`, `submitted_at`),
    KEY `idx_property_change_property_status` (`property_id`, `status`),
    KEY `idx_property_change_agent_submitted` (`agent_id`, `submitted_at`),
    CONSTRAINT `fk_property_change_property`
        FOREIGN KEY (`property_id`) REFERENCES `property_listings` (`property_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_property_change_agent`
        FOREIGN KEY (`agent_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_property_change_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `user` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
