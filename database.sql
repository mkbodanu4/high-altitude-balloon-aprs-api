CREATE TABLE `users`
(
    `user_id`          BIGINT      NOT NULL AUTO_INCREMENT,
    `active_chat_id`   BIGINT      NOT NULL,
    `telegram_user_id` BIGINT      NOT NULL,
    `first_name`       VARCHAR(50) NOT NULL,
    `last_name`        VARCHAR(50) NOT NULL,
    `username`         VARCHAR(50) NOT NULL,
    `language_code`    VARCHAR(5)  NOT NULL,
    `enabled`          BOOLEAN     NOT NULL DEFAULT FALSE,
    `latitude`         FLOAT       NULL,
    `longitude`        FLOAT       NULL,
    `date_created`     DATETIME    NOT NULL,
    `date_updated`     DATETIME    NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE = InnoDB;

CREATE TABLE `notifications`
(
    `date`      DATETIME    NOT NULL,
    `user_id`   BIGINT      NOT NULL,
    `call_sign` VARCHAR(30) NOT NULL,
    `range`     INT         NOT NULL,
    PRIMARY KEY (`date`, `user_id`, `call_sign`)
) ENGINE = InnoDB;
