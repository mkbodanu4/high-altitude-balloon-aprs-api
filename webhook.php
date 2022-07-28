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

if (!isset($request->message)) {
    http_response_code(400);
    exit;
}

$Telegram_API = new Telegram_API(getenv('TELEGRAM_API_KEY'));

$input_message = $request->message;

$user_id_stmt = $db->prepare("SELECT `user_id` FROM `users` WHERE `telegram_user_id` = ? LIMIT 1;");
$user_id_stmt->bind_param('d', $input_message->from->id);
$user_id_stmt->execute();
$user_id_stmt->bind_result($user_id);
$user_id_stmt->fetch();
$user_id_stmt->close();

$active_chat_id = $input_message->chat->id;
$telegram_user_id = $input_message->from->id;
$first_name = isset($input_message->from->first_name) ? $input_message->from->first_name : NULL;
$last_name = isset($input_message->from->last_name) ? $input_message->from->last_name : NULL;
$username = isset($input_message->from->username) ? $input_message->from->username : NULL;
$language_code = isset($input_message->from->language_code) ? $input_message->from->language_code : 'en';

if (!$user_id) {
    $add_user_stmt = $db->prepare("INSERT INTO
        `users`
    SET
        `active_chat_id` = ?,
        `telegram_user_id` = ?,
        `first_name` = ?,
        `last_name` = ?,
        `username` = ?,
        `language_code` = ?,
        `enabled` = FALSE,
        `latitude` = NULL,
        `longitude` = NULL,
        `date_created` = UTC_TIMESTAMP(),
        `date_updated` = UTC_TIMESTAMP()
    ;");
    $add_user_stmt->bind_param('iissss',
        $active_chat_id,
        $telegram_user_id,
        $first_name,
        $last_name,
        $username,
        $language_code
    );
    $add_user_stmt->execute();
    $user_id = $add_user_stmt->insert_id;
    $add_user_stmt->close();
} else {
    $update_user_stmt = $db->prepare("UPDATE
        `users`
    SET
        `active_chat_id` = ?,
        `telegram_user_id` = ?,
        `first_name` = ?,
        `last_name` = ?,
        `username` = ?,
        `language_code` = ?,
        `date_updated` = UTC_TIMESTAMP()
    WHERE
        `user_id` = ?
    LIMIT 1
    ;");
    $update_user_stmt->bind_param('iissssi',
        $active_chat_id,
        $telegram_user_id,
        $first_name,
        $last_name,
        $username,
        $language_code,
        $user_id
    );
    $update_user_stmt->execute();
    $update_user_stmt->close();
}

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
        $Telegram_API->sendMessage($input_message->chat->id, __("Thanks for providing the location, I successfully saved your coordinates. Please /enable or /disable notifications.", $language_code));
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
    }
    $update_user_stmt->close();
} elseif (preg_match("/^\s{0,}([A-R]{2}[0-9]{2}[A-Wa-w]{0,2})\s{0,}$/s", $input_message->text)) {
    $qth = strtoupper(trim($input_message->text));

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
        $Telegram_API->sendMessage($input_message->chat->id, __("Thanks for providing QTH location, I successfully decoded and saved your coordinates. Check that coordinates in location I just sent. Also please /enable or /disable notifications.", $language_code));
        $Telegram_API->sendLocation($input_message->chat->id, $latitude, $longitude);
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
    }
    $update_user_stmt->close();
} elseif ($input_message->text === '/enable') {
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
            $Telegram_API->sendMessage($input_message->chat->id, __("Notifications enabled. I will drop you a message after detecting the balloon nearby. Feel free to disable notifications with command /disable.", $language_code));
        } else {
            $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
        }
        $update_user_stmt->close();
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("I see you have no location saved, please send one (or QTH locator) before enabling notifications.", $language_code));
    }
} elseif ($input_message->text === '/disable') {
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
        $Telegram_API->sendMessage($input_message->chat->id, __("Notifications were disabled. Use the command /enable to bring them back.", $language_code));
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
    }
    $update_user_stmt->close();
} elseif ($input_message->text === '/status') {
    $user_stmt = $db->prepare("SELECT `enabled`, `latitude`, `longitude` FROM `users` WHERE `user_id` = ? LIMIT 1;");
    $user_stmt->bind_param('i', $user_id);
    if ($user_stmt->execute()) {
        $user_stmt->bind_result($user_enabled, $user_latitude, $user_longitude);
        $user_stmt->fetch();

        $status = __("Status", $language_code) . ": " .
            ($user_enabled ? __("Enabled", $language_code) : __("Disabled", $language_code)) . "\n" .
            ($user_latitude && $user_longitude ?
                __("Location", $language_code) . ": " : __("No location saved yet", $language_code));
        $Telegram_API->sendMessage($input_message->chat->id, $status);
        if ($user_latitude && $user_longitude) {
            $Telegram_API->sendLocation($input_message->chat->id, $user_latitude, $user_longitude);
        }
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
    }
    $user_stmt->close();
} elseif ($input_message->text === '/leave_me_alone') {
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
        $Telegram_API->sendMessage($input_message->chat->id, __("All your data removed from my database. You need /start again to continue our cooperation.", $language_code));
    } else {
        $Telegram_API->sendMessage($input_message->chat->id, __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
    }
    $update_user_stmt->close();
} elseif ($input_message->text === '/start') {
    $Telegram_API->sendMessage($input_message->chat->id, __("Hi there! I'm monitoring amateur radio balloons and can notify you when one will pass nearby.\n\nPlease send any location as attachment (it could be done with your smartphone only), so I could know the place you are interested in.\nInstruction (with screenshots) available here: https://diy.manko.pro/en/high-altitude-balloon-en/#bot\n\nAlso you can send QTH locator (like KN29at) and I will try to decode coordinates from it.\n\nLooking forward for your location attachment or QTH locator.", $language_code));
} else {
    $Telegram_API->sendMessage($input_message->chat->id, __("I don't know what to respond, try /start command.", $language_code));
}

http_response_code(200);
header("Content-type:text/plain");
exit;