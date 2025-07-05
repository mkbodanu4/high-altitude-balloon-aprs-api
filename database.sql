CREATE TABLE `users`
(
    `user_id`           BIGINT   NOT NULL AUTO_INCREMENT,
    `active_chat_id`    BIGINT   NOT NULL,
    `telegram_user_id`  BIGINT   NOT NULL,
    `message_thread_id` BIGINT NULL DEFAULT NULL,
    `first_name`        VARCHAR(255) NULL     DEFAULT NULL,
    `last_name`         VARCHAR(50) NULL     DEFAULT NULL,
    `username`          VARCHAR(50) NULL     DEFAULT NULL,
    `language_code`     VARCHAR(5) NULL     DEFAULT NULL,
    `enabled`           BOOLEAN  NOT NULL DEFAULT FALSE,
    `latitude`          DECIMAL(10, 8) NULL     DEFAULT NULL,
    `longitude`         DECIMAL(11, 8) NULL     DEFAULT NULL,
    `range`             INT      NOT NULL DEFAULT 300,
    `altitude`          INT      NOT NULL DEFAULT 500,
    `last_command`      VARCHAR(300) NULL     DEFAULT NULL,
    `last_message`      VARCHAR(300) NULL     DEFAULT NULL,
    `date_created`      DATETIME NOT NULL,
    `date_updated`      DATETIME NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE = InnoDB;

CREATE TABLE `notifications`
(
    `date`      DATETIME    NOT NULL,
    `user_id`   BIGINT      NOT NULL,
    `call_sign` VARCHAR(30) NOT NULL,
    PRIMARY KEY (`date`, `user_id`, `call_sign`)
) ENGINE = InnoDB;

CREATE TABLE `viber_users`
(
    `user_id`       BIGINT       NOT NULL AUTO_INCREMENT,
    `viber_user_id` VARCHAR(100) NOT NULL,
    `language_code` VARCHAR(5) NULL     DEFAULT NULL,
    `enabled`       BOOLEAN      NOT NULL DEFAULT FALSE,
    `latitude`      DECIMAL(10, 8) NULL     DEFAULT NULL,
    `longitude`     DECIMAL(11, 8) NULL     DEFAULT NULL,
    `range`         INT          NOT NULL DEFAULT 300,
    `altitude`      INT          NOT NULL DEFAULT 500,
    `last_command`  VARCHAR(300) NULL     DEFAULT NULL,
    `last_message`  VARCHAR(300) NULL     DEFAULT NULL,
    `date_created`  DATETIME     NOT NULL,
    `date_updated`  DATETIME     NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE = InnoDB;

CREATE TABLE `viber_notifications`
(
    `date`      DATETIME    NOT NULL,
    `user_id`   BIGINT      NOT NULL,
    `call_sign` VARCHAR(30) NOT NULL,
    PRIMARY KEY (`date`, `user_id`, `call_sign`)
) ENGINE = InnoDB;