<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "viber_api.class.php";

$Viber_API = new Viber_API(getenv('VIBER_API_KEY'));

header("Content-type:text/plain");

$balloons = array();
$balloons_query = "SELECT
	`max_h`.`call_sign`,
	`max_h`.`max_date` AS `date`,
    `h2`.`timestamp`,
    `h2`.`latitude`,
    `h2`.`longitude`,
    `h2`.`course`,
    `h2`.`speed`,
    `h2`.`altitude`,
    `h2`.`comment`,
    `h2`.`raw`
FROM (
        SELECT
            `h`.`call_sign`,
            MAX(`h`.`date`) AS `max_date`
        FROM
            `history` `h` 
        WHERE
            `h`.`altitude` IS NOT NULL AND
            `h`.`date` >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE
        GROUP BY
            `h`.`call_sign`
    ) `max_h`
LEFT JOIN
    `history` `h2` ON `max_h`.`max_date` = `h2`.`date` AND `max_h`.`call_sign` = `h2`.`call_sign`;";
$balloons_stmt = $db->prepare($balloons_query);
$balloons_stmt->execute();
$balloons_result = $balloons_stmt->get_result();
while ($row = $balloons_result->fetch_object()) {
    $balloons[] = $row;
}
$balloons_stmt->close();

if (count($balloons) > 0) {
    log_event("Loaded " . count($balloons) . " active ballons (within last 5 minutes)");
    foreach ($balloons as $balloon) {
        log_event("Balloon " . $balloon->call_sign);
        $users = array();

        $users_query = "SELECT
                *,
                (ST_Distance_Sphere(POINT(?, ?), POINT(`longitude`, `latitude`)) / 1000) AS `distance`
            FROM
                `viber_users`
            WHERE
                `enabled` = TRUE AND
                `longitude` IS NOT NULL AND
                `latitude` IS NOT NULL AND
                `altitude` <= ? AND
                (ST_Distance_Sphere(POINT(?, ?), POINT(`longitude`, `latitude`)) / 1000) <= `range`
            ;";
        $users_stmt = $db->prepare($users_query);
        $users_stmt->bind_param("ddddd", $balloon->longitude, $balloon->latitude, $balloon->altitude, $balloon->longitude, $balloon->latitude);
        $users_stmt->execute();
        $users_result = $users_stmt->get_result();
        while ($row = $users_result->fetch_object()) {
            $users[] = $row;
        }
        $users_stmt->close();

        if (count($users) > 0) {
            log_event("Loaded " . count($users) . " users within " . $balloon->call_sign . " balloon area.");
            foreach ($users as $user) {
                $is_sent = 0;
                $is_sent_stmt = $db->prepare("SELECT
                        COUNT(*)
                    FROM
                        `viber_notifications`
                    WHERE
                        `date` > (UTC_TIMESTAMP() - INTERVAL 3 HOUR) AND
                        `user_id` = ? AND
                        `call_sign` = ?
                    LIMIT 1
                    ;");
                $is_sent_stmt->bind_param('is', $user->user_id, $balloon->call_sign);
                $is_sent_stmt->execute();
                $is_sent_stmt->bind_result($is_sent);
                $is_sent_stmt->fetch();
                $is_sent_stmt->close();

                if (!$is_sent) {
                    log_event("User " . $user->user_id . " NOT received notification about " . $balloon->call_sign . " balloon - " . $user->distance);
                    $viber_message = __("There is a balloon nearby!", $user->language_code) . "\n" .
                        __("Call sign", $user->language_code) . ": " . $balloon->call_sign . "\n" .
                        __("Distance to you", $user->language_code) . ": " . round(floatval($user->distance), 2) . " " . __("km", $user->language_code) . "\n" .
                        (!is_null($balloon->altitude) ? __("Altitude", $user->language_code) . ": " . round(floatval($balloon->altitude), 2) . " " . __("m", $user->language_code) . "\n" : "") .
                        (!is_null($balloon->speed) ? __("Speed", $user->language_code) . ": " . round(floatval($balloon->speed), 2) . " " . __("km/h", $user->language_code) . "\n" : "") .
                        (!is_null($balloon->course) ? __("Course", $user->language_code) . ": " . intval($balloon->course) . " " . __("°", $user->language_code) . "\n" : "");

                    if ($balloon->comment) {
                        $frequencies = array();
                        $parts = array();

                        if (preg_match('/\s(wspr)\s/i', $balloon->comment, $parts)) {
                            $frequencies[] = '14,097 MHz ' . $parts[1];
                        }

                        if (preg_match('/(aprs)[\-|\s]{0,}(\d{3}\.\d{3}){0,}/i', $balloon->comment, $parts)) {
                            $frequencies[] = (isset($parts[2]) ? $parts[2] . ' MHz ' : '') . $parts[1];
                        }

                        if (preg_match('/(lora)[\-|\s]{0,}(\d{3}\.\d{3}){0,}/i', $balloon->comment, $parts)) {
                            $frequencies[] = (isset($parts[2]) ? $parts[2] . ' MHz ' : '') . $parts[1];
                        }

                        if (preg_match('/(4\-{0,}fsk)[\-|\s]{0,}(\d{3}\.\d{3}){0,}/i', $balloon->comment, $parts)) {
                            $frequencies[] = (isset($parts[2]) ? $parts[2] . ' MHz ' : '437.600 MHz ') . $parts[1];
                        }

                        if (preg_match('/(sstv)[\-|\s]{0,}(\d{3}\.\d{3}){0,}/i', $balloon->comment, $parts)) {
                            $frequencies[] = (isset($parts[2]) ? $parts[2] . ' MHz ' : '144.500 MHz ' . __("or", $user->language_code) . ' 433.400 MHz ') . $parts[1];
                        }

                        $viber_message .= __("Frequencies", $user->language_code) . ": ";
                        if (count($frequencies) > 0) {
                            $viber_message .= implode('; ', $frequencies);
                        }
                        $viber_message .= ' APRS' . "\n" . "\n";

                        $viber_message .= __("Comment", $user->language_code) . ": " . htmlspecialchars($balloon->comment) . "\n" . "\n";
                    }

                    $viber_message .= "https://aprs.fi/?call=" . $balloon->call_sign . "\n" . "\n" .
                        __("https://diy.manko.pro/en/high-altitude-balloon-en/", $user->language_code) . "#call_sign=" . $balloon->call_sign . '&track=1';

                    $Viber_API->send_location($user->viber_user_id,
                        __('Balloons Bot', $user->language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        $balloon->latitude, $balloon->longitude);
                    $sent = $Viber_API->send_message($user->viber_user_id,
                        __('Balloons Bot', $user->language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        $viber_message);
                    if (isset($sent->status) && $sent->status == '0') {
                        log_event("Message to user " . $user->user_id . " successfully sent");
                        $message_sent_stmt = $db->prepare("INSERT INTO
                                `viber_notifications`
                            SET
                                `date` = UTC_TIMESTAMP(),
                                `user_id` = ?,
                                `call_sign` = ?
                            ;");
                        $message_sent_stmt->bind_param('is',
                            $user->user_id,
                            $balloon->call_sign);
                        if ($message_sent_stmt->execute()) {
                            log_event("Event about sending message to user " . $user->user_id . " successfully saved to database");
                        }
                        $message_sent_stmt->close();
                    }
                } else {
                    log_event("User " . $user->user_id . " ALREADY received notification about " . $balloon->call_sign . " balloon.");
                }
            }
        }
    }
}