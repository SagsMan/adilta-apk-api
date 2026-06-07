-- Run this SQL in your cPanel phpMyAdmin
-- Database: adiliqgs_adildata
-- This creates the table that stores each user's FCM push token

CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `user_id`    INT          NOT NULL,
  `email`      VARCHAR(255) NOT NULL DEFAULT '',
  `fcm_token`  TEXT         NOT NULL,
  `platform`   ENUM('android','ios') NOT NULL DEFAULT 'android',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fcm_token` (`fcm_token`(500)),
  INDEX        `idx_user_id`  (`user_id`),
  INDEX        `idx_email`    (`email`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
