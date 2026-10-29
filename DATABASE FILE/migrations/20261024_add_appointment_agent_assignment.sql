ALTER TABLE `appointment`
    ADD COLUMN `assigned_agent_id` INT(11) DEFAULT NULL,
    ADD KEY `idx_appointment_assigned_agent` (`assigned_agent_id`);
