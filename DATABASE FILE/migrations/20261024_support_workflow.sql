UPDATE `tickets` SET `status` = 'Open' WHERE `status` IS NULL;

ALTER TABLE `tickets`
    MODIFY COLUMN `status` ENUM('Open', 'In Progress', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open',
    ADD COLUMN `assigned_agent_id` INT(11) DEFAULT NULL,
    ADD KEY `idx_tickets_assigned_status` (`assigned_agent_id`, `status`);

CREATE TABLE `ticket_replies` (
    `reply_id` INT(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reply_id`),
    KEY `idx_ticket_replies_ticket_created` (`ticket_id`, `created_at`),
    KEY `idx_ticket_replies_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `appointment`
    ADD COLUMN `assigned_agent_id` INT(11) DEFAULT NULL,
    ADD KEY `idx_appointment_assigned_agent` (`assigned_agent_id`);
