
-- Create device_tasks table for storing pending configuration tasks
CREATE TABLE IF NOT EXISTS `device_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `task_type` varchar(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
  `task_data` text DEFAULT NULL COMMENT 'JSON encoded parameters',
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `status` (`status`),
  CONSTRAINT `device_tasks_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
