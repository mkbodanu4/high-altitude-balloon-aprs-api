<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "telegram_api.class.php";

$Telegram_API = new Telegram_API(getenv('TELEGRAM_API_KEY'));

header("Content-type:text/plain");

$ranges = array(
    array(
        'min' => 0,
        'max' => 80
    ),
    array(
        'min' => 80,
        'max' => 300
    ),
);

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
    `h2`.`daodatumbyte`,
    `h2`.`comment`,
    `h2`.`raw`
FROM (SELECT `h`.`call_sign`, MAX(`h`.`date`) AS `max_date` FROM `history` `h` WHERE `h`.`date` >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE GROUP BY `h`.`call_sign`) `max_h`
LEFT JOIN `history` `h2` ON `max_h`.`max_date` = `h2`.`date` AND `max_h`.`call_sign` = `h2`.`call_sign`;";
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
        foreach ($ranges as $range) {
            $users = array();

            $users_query = "SELECT
                *,
                (ST_Distance_Sphere(POINT(?, ?), POINT(`longitude`, `latitude`)) / 1000) AS `distance`
            FROM
                `users`
            WHERE
                `enabled` = TRUE AND
                `longitude` IS NOT NULL AND
                `latitude` IS NOT NULL AND
                " . ($range['min'] ? "(ST_Distance_Sphere(POINT(?, ?), POINT(`longitude`, `latitude`)) / 1000) > ? AND" : "") . "
                (ST_Distance_Sphere(POINT(?, ?), POINT(`longitude`, `latitude`)) / 1000) <= ?
            ;";
            $users_stmt = $db->prepare($users_query);
            if ($range['min'])
                $users_stmt->bind_param("ddddiddi", $balloon->longitude, $balloon->latitude, $balloon->longitude, $balloon->latitude, $range['min'], $balloon->longitude, $balloon->latitude, $range['max']);
            else
                $users_stmt->bind_param("ddddi", $balloon->longitude, $balloon->latitude, $balloon->longitude, $balloon->latitude, $range['max']);
            $users_stmt->execute();
            $users_result = $users_stmt->get_result();
            while ($row = $users_result->fetch_object()) {
                $users[] = $row;
            }
            $users_stmt->close();

            if (count($users) > 0) {
                log_event("Loaded " . count($users) . " users within " . $balloon->call_sign . " balloon " . ($range['min'] ? $range['min'] . "-" : "") . $range['max'] . " km range");
                foreach ($users as $user) {
                    $is_sent = 0;
                    $is_sent_stmt = $db->prepare("SELECT
                        COUNT(*)
                    FROM
                        `notifications`
                    WHERE
                        `date` > (UTC_TIMESTAMP() - INTERVAL 3 HOUR) AND
                        `user_id` = ? AND
                        `call_sign` = ? AND
                        `range` = ?
                    LIMIT 1
                    ;");
                    $is_sent_stmt->bind_param('isi', $user->user_id, $balloon->call_sign, $range['max']);
                    $is_sent_stmt->execute();
                    $is_sent_stmt->bind_result($is_sent);
                    $is_sent_stmt->fetch();
                    $is_sent_stmt->close();

                    if (!$is_sent) {
                        log_event("User " . $user->username . " NOT received notification about " . $balloon->call_sign . " balloon within " . ($range['min'] ? $range['min'] . "-" : "") . $range['max'] . " km range - " . $user->distance);
                        $telegram_message = __("New balloon nearby!", $user->language_code) . "\n" .
                            __("Call sign", $user->language_code) . ": " . $balloon->call_sign . "\n" .
                            __("Distance to you", $user->language_code) . ": " . round(floatval($user->distance), 2) . " " . __("km", $user->language_code) . "\n" .
                            (!is_null($balloon->altitude) ? __("Altitude", $user->language_code) . ": " . round(floatval($balloon->altitude), 2) . " " . __("m", $user->language_code) . "\n" : "") .
                            (!is_null($balloon->speed) ? __("Speed", $user->language_code) . ": " . round(floatval($balloon->speed), 2) . " " . __("km/h", $user->language_code) . "\n" : "") .
                            (!is_null($balloon->course) ? __("Course", $user->language_code) . ": " . intval($balloon->course) . " " . __("°", $user->language_code) . "\n" : "") .
                            __("Comment", $user->language_code) . ": " . htmlspecialchars($balloon->comment) . "\n" . "\n" .
                            "https://aprs.fi/?call=" . $balloon->call_sign;
                        $sent = $Telegram_API->sendMessage($user->active_chat_id, $telegram_message);
                        if ($sent->ok && $sent->result) {
                            log_event("Message to user " . $user->username . " successfully sent");
                            $message_sent_stmt = $db->prepare("INSERT INTO
                                `notifications`
                            SET
                                `date` = UTC_TIMESTAMP(),
                                `user_id` = ?,
                                `call_sign` = ?,
                                `range` = ?
                            ;");
                            $message_sent_stmt->bind_param('isi',
                                $user->user_id,
                                $balloon->call_sign,
                                $range['max']);
                            if ($message_sent_stmt->execute()) {
                                log_event("Event about sending message to user " . $user->username . " successfully saved to database");
                            }
                            $message_sent_stmt->close();
                        }
                    } else {
                        log_event("User " . $user->username . " ALREADY received notification about " . $balloon->call_sign . " balloon within" . ($range['min'] ? $range['min'] . "-" : "") . $range['max'] . " km range - " . $user->distance);
                    }
                }
            }
        }
    }
}