<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "telegram_api.class.php";

if ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] !== getenv('TELEGRAM_SECRET_TOKEN')) {
    http_response_code(401);
    exit;
}

$input = file_get_contents('php://input');

if (!$input) {
    http_response_code(400);
    exit;
}

$request = json_decode($input);

if ($request === NULL) {
    http_response_code(400);
    exit;
}

if (!isset($request->message) && !isset($request->edited_message)) {
    http_response_code(400);
    exit;
}

$Telegram_API = new Telegram_API(getenv('TELEGRAM_API_KEY'));

$input_message = isset($request->edited_message) ? $request->edited_message : $request->message;
$active_chat_id = $input_message->chat->id;
$telegram_user_id = $input_message->from->id;
$is_group_chat = $active_chat_id !== $telegram_user_id && $active_chat_id < 0;
$message_thread_id = $is_group_chat && isset($input_message->message_thread_id) && $input_message->message_thread_id ? $input_message->message_thread_id : NULL;

if ($is_group_chat) {
    $first_name = isset($input_message->chat->title) ? $input_message->chat->title : NULL;
    $last_name = NULL;
    $username = NULL;
} else {
    $first_name = isset($input_message->from->first_name) ? $input_message->from->first_name : NULL;
    $last_name = isset($input_message->from->last_name) ? $input_message->from->last_name : NULL;
    $username = isset($input_message->from->username) ? $input_message->from->username : NULL;
}
$language_code = isset($input_message->from->language_code) ? $input_message->from->language_code : 'en';
$last_command = isset($input_message->text) && strlen($input_message->text) > 1 && substr($input_message->text, 0, 1) === '/' ? trim(substr(trim($input_message->text), 0, 300)) : NULL;
$last_message = isset($input_message->text) ? trim(substr(trim($input_message->text), 0, 300)) : NULL;

$user_id_query = $is_group_chat ?
    "SELECT `user_id` FROM `users` WHERE `active_chat_id` = ? LIMIT 1;"
    : "SELECT `user_id` FROM `users` WHERE `telegram_user_id` = ? LIMIT 1;";
$user_id_query_param = $is_group_chat ? $active_chat_id : $telegram_user_id;

$user_id_stmt = $db->prepare($user_id_query);
$user_id_stmt->bind_param('d', $user_id_query_param);
$user_id_stmt->execute();
$user_id_stmt->bind_result($user_id);
$user_id_stmt->fetch();
$user_id_stmt->close();

if (!$user_id) {
    $add_telegram_user_id = $is_group_chat ? $active_chat_id : $telegram_user_id;
    $add_user_stmt = $db->prepare("INSERT INTO
        `users`
    SET
        `active_chat_id` = ?,
        `telegram_user_id` = ?,
        `message_thread_id` = ?,
        `first_name` = ?,
        `last_name` = ?,
        `username` = ?,
        `language_code` = ?,
        `last_command` = ?,
        `last_message` = ?,
        `enabled` = FALSE,
        `latitude` = NULL,
        `longitude` = NULL,
        `date_created` = UTC_TIMESTAMP(),
        `date_updated` = UTC_TIMESTAMP()
    ;");
    $add_user_stmt->bind_param('iiissssss',
        $active_chat_id,
        $add_telegram_user_id,
        $message_thread_id,
        $first_name,
        $last_name,
        $username,
        $language_code,
        $last_command,
        $last_message
    );
    $add_user_stmt->execute();
    $user_id = $add_user_stmt->insert_id;
    $add_user_stmt->close();
} else {
    $update_telegram_user_id = $is_group_chat ? $active_chat_id : $telegram_user_id;
    $update_user_stmt = $db->prepare("UPDATE
        `users`
    SET
        `active_chat_id` = ?,
        `telegram_user_id` = ?,
        `message_thread_id` = ?,
        `first_name` = ?,
        `last_name` = ?,
        `username` = ?,
        `language_code` = ?,
        " . ($last_command ? "`last_command` = ?," : "") . "
        `last_message` = ?,
        `date_updated` = UTC_TIMESTAMP()
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    if ($last_command)
        $update_user_stmt->bind_param('iiissssssi',
            $active_chat_id,
            $update_telegram_user_id,
            $message_thread_id,
            $first_name,
            $last_name,
            $username,
            $language_code,
            $last_command,
            $last_message,
            $user_id
        );
    else
        $update_user_stmt->bind_param('iiisssssi',
            $active_chat_id,
            $update_telegram_user_id,
            $message_thread_id,
            $first_name,
            $last_name,
            $username,
            $language_code,
            $last_message,
            $user_id
        );
    $update_user_stmt->execute();
    $update_user_stmt->close();
}

if ($is_group_chat && !strpos($input_message->text, getenv('TELEGRAM_USERNAME'))) {
    // Ignore spam from group chats other bots
    http_response_code(200);
    header("Content-type:text/plain");
    exit;
}

$input_message_text = $is_group_chat ? trim(str_replace(getenv('TELEGRAM_USERNAME'), "", $input_message->text)) : trim($input_message->text);

if (isset($input_message->location) && $input_message->location->latitude && $input_message->location->longitude) {
    $update_user_stmt = $db->prepare("UPDATE
        `users`
    SET
        `latitude` = ?,
        `longitude` = ?,
        `enabled` = TRUE,
        `date_updated` = UTC_TIMESTAMP()
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    $update_user_stmt->bind_param('ddi',
        $input_message->location->latitude,
        $input_message->location->longitude,
        $user_id
    );
    if ($update_user_stmt->execute()) {
        $Telegram_API->sendMessage($input_message->chat->id, __("Thanks for providing the location, I successfully saved your coordinates. Please /enable or /disable notifications.", $language_code), $message_thread_id);
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
    }
    $update_user_stmt->close();
} elseif (preg_match("/^\s{0,}([A-R]{2}[0-9]{2}[A-Wa-w]{0,2})\s{0,}$/s", $input_message_text) ||
    preg_match("/^\/qth\s{1,}([A-R]{2}[0-9]{2}[A-Wa-w]{0,2})\s{0,}$/s", $input_message_text)) {
    $qth = strtoupper(trim(str_replace("/qth ", "", $input_message_text)));

    $chars_mapping = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"; // Constants.
    $int_mapping = "0123456789";

    $latitude = strpos($chars_mapping, substr($qth, 1, 1)) * 10; // 2nd digit: 10deg latitude slot.
    $longitude = strpos($chars_mapping, substr($qth, 0, 1)) * 20; // 1st digit: 20deg longitude slot.
    $latitude += strpos($int_mapping, substr($qth, 3, 1)) * 1; // 4th digit: 1deg latitude slot.
    $longitude += strpos($int_mapping, substr($qth, 2, 1)) * 2; // 3rd digit: 2deg longitude slot.
    if (strlen($qth) == 6) {
        $latitude += strpos($chars_mapping, substr($qth, 5, 1)) * 2.5 / 60; // 6th digit: 2.5min latitude slot.
        $longitude += strpos($chars_mapping, substr($qth, 4, 1)) * 5 / 60; // 5th digit: 5min longitude slot.
    }

    if (strlen($qth) == 4) { // Get coordinates of the center of the square.
        $latitude += 0.5 * 1;
        $longitude += 0.5 * 2;
    } else {
        $latitude += 0.5 * 2.5 / 60;
        $longitude += 0.5 * 5 / 60;
    }

    $latitude -= 90; // Locator lat/lon origin shift.
    $longitude -= 180;

    $update_user_stmt = $db->prepare("UPDATE
        `users`
    SET
        `latitude` = ?,
        `longitude` = ?,
        `enabled` = TRUE,
        `date_updated` = UTC_TIMESTAMP()
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    $update_user_stmt->bind_param('ddi',
        $latitude,
        $longitude,
        $user_id
    );
    if ($update_user_stmt->execute()) {
        $Telegram_API->sendMessage($input_message->chat->id, __("Thanks for providing QTH location, I successfully decoded and saved your coordinates. Check that coordinates in location I just sent. Also please /enable or /disable notifications.", $language_code), $message_thread_id);
        $Telegram_API->sendLocation($input_message->chat->id, $latitude, $longitude, $message_thread_id);
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
    }
    $update_user_stmt->close();
} elseif ($input_message_text === '/enable') {
    $user_latitude = NULL;
    $user_longitude = NULL;
    $user_stmt = $db->prepare("SELECT `latitude`, `longitude` FROM `users` WHERE `user_id` = ? LIMIT 1;");
    $user_stmt->bind_param('i', $user_id);
    if ($user_stmt->execute()) {
        $user_stmt->bind_result($user_latitude, $user_longitude);
        $user_stmt->fetch();
    }
    $user_stmt->close();

    if ($user_latitude && $user_longitude) {
        $update_user_stmt = $db->prepare("UPDATE
                `users`
            SET
                `enabled` = TRUE,
                `date_updated` = UTC_TIMESTAMP()
            WHERE
                `user_id` = ?
            LIMIT 1
            ;");
        $update_user_stmt->bind_param('i',
            $user_id
        );
        if ($update_user_stmt->execute()) {
            $Telegram_API->sendMessage($input_message->chat->id, __("Notifications enabled. I will drop you a message after detecting the balloon nearby. Feel free to disable notifications with command /disable.", $language_code), $message_thread_id);
        } else {
            $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
        }
        $update_user_stmt->close();
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("I see you have no location saved, please send one (or QTH locator) before enabling notifications.", $language_code), $message_thread_id);
    }
} elseif ($input_message_text === '/disable') {
    $update_user_stmt = $db->prepare("UPDATE
        `users`
    SET
        `enabled` = FALSE,
        `date_updated` = UTC_TIMESTAMP()
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    $update_user_stmt->bind_param('i',
        $user_id
    );
    if ($update_user_stmt->execute()) {
        $Telegram_API->sendMessage($input_message->chat->id, __("Notifications were disabled. Use the command /enable to bring them back.", $language_code), $message_thread_id);
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
    }
    $update_user_stmt->close();
} elseif ($input_message_text === '/status') {
    $user_stmt = $db->prepare("SELECT `enabled`, `latitude`, `longitude`, `range`, `altitude` FROM `users` WHERE `user_id` = ? LIMIT 1;");
    $user_stmt->bind_param('i', $user_id);
    if ($user_stmt->execute()) {
        $user_stmt->bind_result($user_enabled, $user_latitude, $user_longitude, $user_range, $user_altitude);
        $user_stmt->fetch();

        $status = __("Notifications", $language_code) . ": " .
            ($user_enabled ? __("Enabled", $language_code) : __("Disabled", $language_code)) . "\n" .
            __("Maximum distance between the balloon and you", $language_code) . ": " . $user_range . " " . __("km", $language_code) . "\n" .
            __("Balloon minimum altitude", $language_code) . ": " . $user_altitude . " " . __("m", $language_code) . "\n" .
            ($user_latitude && $user_longitude ?
                __("Location to monitor", $language_code) . ": " : __("No location saved yet", $language_code));
        $Telegram_API->sendMessage($input_message->chat->id, $status, $message_thread_id);
        if ($user_latitude && $user_longitude) {
            $Telegram_API->sendLocation($input_message->chat->id, $user_latitude, $user_longitude, $message_thread_id);
        }
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
    }
    $user_stmt->close();
} elseif ($input_message_text === '/leave_me_alone') {
    $update_user_stmt = $db->prepare("DELETE FROM
        `users`
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    $update_user_stmt->bind_param('i',
        $user_id
    );
    if ($update_user_stmt->execute()) {
        $Telegram_API->sendMessage($input_message->chat->id, __("All your data removed from my database. You need /start again to continue our cooperation.", $language_code), $message_thread_id);
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
    }
    $update_user_stmt->close();
} elseif ($input_message_text === '/start') {
    $Telegram_API->sendMessage($input_message->chat->id, __("Hi there! I'm monitoring amateur radio balloons and can notify you when one will pass nearby.\n\nPlease send any location as attachment (it could be done with your smartphone only), so I could know the place you are interested in.\nInstruction (with screenshots) available here: https://diy.manko.pro/en/high-altitude-balloon-en/#bot\n\nAlso you can send QTH locator (like KN29at) and I will try to decode coordinates from it.\n\nLooking forward for your location attachment or QTH locator.", $language_code), $message_thread_id, TRUE);
} elseif ($input_message_text === '/altitude') {
    $Telegram_API->sendMessage($input_message->chat->id, __("Please tell me what minimum altitude balloon must have for notifications. Add the latin letter **m** in the end for meters or **ft** for feet, e.g. 500 m or 1600 ft. Number without units will be considered as a value in meters.", $language_code), $message_thread_id);
} elseif ($input_message_text === '/range') {
    $Telegram_API->sendMessage($input_message->chat->id, __("Please tell me maximum distance between you and balloon. Add the latin letters **km** in the end for kilometers or **mi** for miles, e.g. 300 km or 186 mi. Number without units will be considered as a value in kilometers.", $language_code), $message_thread_id);
} else {
    $user_last_command = NULL;
    $user_stmt = $db->prepare("SELECT `last_command` FROM `users` WHERE `user_id` = ? LIMIT 1;");
    $user_stmt->bind_param('i', $user_id);
    if ($user_stmt->execute()) {
        $user_stmt->bind_result($user_last_command);
        $user_stmt->fetch();
    }
    $user_stmt->close();

    if ($user_last_command === '/altitude' ||
        preg_match("/^\/altitude\s{1,}([0-9]{1,})[\s]{0,}(m|ft)\s{0,}$/s", $input_message_text)) {
        $altitude = NULL;
        $input_message_text = str_replace("/altitude ", "", $input_message_text);
        if (preg_match("/^([0-9]{1,})[\s]{0,}(m|ft)$/si", strtolower($input_message_text), $matches)) {
            if ($matches[2] === 'ft') {
                $altitude = floatval($input_message_text) * 0.3048;
            } else {
                $altitude = floatval($input_message_text);
            }
        } elseif (is_numeric($input_message_text)) {
            $altitude = floatval($input_message_text);
        }

        if ($altitude) {
            $altitude = round($altitude, 2);

            $update_user_stmt = $db->prepare("UPDATE
                `users`
            SET
                `altitude` = ?,
                `last_command` = NULL,
                `date_updated` = UTC_TIMESTAMP()
            WHERE
                `user_id` = ?
            LIMIT 1
            ;");
            $update_user_stmt->bind_param('di',
                $altitude,
                $user_id
            );
            if ($update_user_stmt->execute()) {
                $Telegram_API->sendMessage($input_message->chat->id, __("New minimum accepted altitude successfully saved.", $language_code), $message_thread_id);
            } else {
                $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
            }
            $update_user_stmt->close();
        } else {
            $Telegram_API->sendMessage($input_message->chat->id, __("I can't recognize value, please try again.", $language_code), $message_thread_id);
        }
    } elseif ($user_last_command === '/range' ||
        preg_match("/^\/range\s{1,}([0-9]{1,})[\s]{0,}(km|mi)\s{0,}$/s", $input_message_text)) {
        $range = NULL;
        $input_message_text = str_replace("/range ", "", $input_message_text);
        if (preg_match("/^([0-9]{1,})[\s]{0,}(km|mi)$/si", strtolower($input_message_text), $matches)) {
            if ($matches[2] === 'mi') {
                $range = floatval($input_message_text) * 1.609344;
            } else {
                $range = floatval($input_message_text);
            }
        } elseif (is_numeric($input_message_text)) {
            $range = floatval($input_message_text);
        }

        if ($range) {
            $range = round($range, 2);

            $update_user_stmt = $db->prepare("UPDATE
                `users`
            SET
                `range` = ?,
                `last_command` = NULL,
                `date_updated` = UTC_TIMESTAMP()
            WHERE
                `user_id` = ?
            LIMIT 1
            ;");
            $update_user_stmt->bind_param('di',
                $range,
                $user_id
            );
            if ($update_user_stmt->execute()) {
                $Telegram_API->sendMessage($input_message->chat->id, __("New maximum accepted range successfully saved.", $language_code), $message_thread_id);
            } else {
                $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code), $message_thread_id);
            }
            $update_user_stmt->close();
        } else {
            $Telegram_API->sendMessage($input_message->chat->id, __("I can't recognize value, please try again.", $language_code), $message_thread_id);
        }
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("I don't know what to respond, try /start command.", $language_code), $message_thread_id);
    }
}

http_response_code(200);
header("Content-type:text/plain");
exit;
