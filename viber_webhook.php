<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "viber_api.class.php";

$input = file_get_contents('php://input');

if (!$input) {
    http_response_code(400);
    exit;
}

if (!isset($_GET['sig']) || $_GET['sig'] !== hash_hmac('sha256', $input, getenv('VIBER_API_KEY'))) {
    http_response_code(401);
    exit;
}

$request = json_decode($input);

if ($request === NULL) {
    http_response_code(400);
    exit;
}

if (!isset($request->event)) {
    http_response_code(401);
    exit;
}

$Viber_API = new Viber_API(getenv('VIBER_API_KEY'));

$user = NULL;
$viber_user_id = NULL;
$user_id = NULL;
if (isset($request->user)) {
    $user = $request->user;
    $viber_user_id = $request->user->id;
} elseif (isset($request->sender)) {
    $user = $request->sender;
    $viber_user_id = $request->sender->id;
} elseif (isset($request->user_id)) {
    $viber_user_id = $request->user_id;
}

$language_code = $user ? $user->language : 'en';
if ($language_code > 2) {
    $language_code = explode('-', $language_code)[0];
}

$message_text = NULL;
$message_lat = NULL;
$message_lon = NULL;
if (isset($request->message)) {
    switch ($request->message->type) {
        case 'text':
            $message_text = $request->message->text;
            break;
        case 'location':
            $message_lat = $request->message->location->lat;
            $message_lon = $request->message->location->lon;
            break;
    }
}

$commands = array(
    __('Enable', $language_code),
    __('Disable', $language_code),
    __('Status', $language_code),
    __('Altitude', $language_code),
    __('Range', $language_code),
    __('Commands', $language_code)
);

if ($viber_user_id) {
    $user_id_stmt = $db->prepare("SELECT `user_id` FROM `viber_users` WHERE `viber_user_id` = ? LIMIT 1;");
    $user_id_stmt->bind_param('s', $viber_user_id);
    $user_id_stmt->execute();
    $user_id_stmt->bind_result($user_id);
    $user_id_stmt->fetch();
    $user_id_stmt->close();

    $last_command = $message_text && in_array($message_text, $commands) ? trim(substr(trim($message_text), 0, 300)) : NULL;
    $last_message = $message_text ? trim(substr(trim($message_text), 0, 300)) : NULL;

    if (!$user_id) {
        $add_user_stmt = $db->prepare("INSERT INTO
            `viber_users`
        SET
            `viber_user_id` = ?,
            `language_code` = ?,
            `last_command` = ?,
            `last_message` = ?,
            `enabled` = FALSE,
            `latitude` = NULL,
            `longitude` = NULL,
            `date_created` = UTC_TIMESTAMP(),
            `date_updated` = UTC_TIMESTAMP()
        ;");
        $add_user_stmt->bind_param('ssss',
            $viber_user_id,
            $language_code,
            $last_command,
            $last_message
        );
        $add_user_stmt->execute();
        $user_id = $add_user_stmt->insert_id;
        $add_user_stmt->close();
    } else {
        $update_user_stmt = $db->prepare("UPDATE
            `viber_users`
        SET
            `viber_user_id` = ?,
            `language_code` = ?,
            " . ($last_command ? "`last_command` = ?," : "") . "
            `last_message` = ?,
            `date_updated` = UTC_TIMESTAMP()
        WHERE
            `user_id` = ?
        LIMIT 1
        ;");
        if ($last_command)
            $update_user_stmt->bind_param('ssssi',
                $viber_user_id,
                $language_code,
                $last_command,
                $last_message,
                $user_id
            );
        else
            $update_user_stmt->bind_param('sssi',
                $viber_user_id,
                $language_code,
                $last_message,
                $user_id
            );
        $update_user_stmt->execute();
        $update_user_stmt->close();
    }
}

if (in_array($request->event, array(
        'conversation_started',
        'message',
        'unsubscribed'
    )) && !$user_id) {
    http_response_code(401);
    exit;
}

switch ($request->event) {
    case 'conversation_started':
        http_response_code(200);
        header("Content-type: application/json");
        echo json_encode(array(
            'sender' => array(
                'name' => __('Balloons Bot', $language_code),
                "avatar" => trim(getenv('APP_URL'), "/") . "/balloon.png"
            ),
            'type' => "text",
            "text" => __("Hi there! I'm monitoring amateur radio balloons and can notify you when one will pass nearby.\n\nPlease send any location as attachment (it could be done with your smartphone only), so I could know the place you are interested in.\nInstruction (with screenshots) available here: https://diy.manko.pro/en/high-altitude-balloon-en/#bot\n\nAlso you can send QTH locator (like KN29at) and I will try to decode coordinates from it.\n\nLooking forward for your location attachment or QTH locator.", $language_code)
        ));
        break;
    case 'message':
        http_response_code(200);
        if ($message_lat !== NULL && $message_lon !== NULL) {
            $update_user_stmt = $db->prepare("UPDATE
                `viber_users`
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
                $message_lat,
                $message_lon,
                $user_id
            );
            if ($update_user_stmt->execute()) {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Thanks for providing the location, I successfully saved your coordinates. Please send me message Enable to enable notifications or Disable to disable them.", $language_code));
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
            }
            $update_user_stmt->close();
        } elseif (preg_match("/^\s{0,}([A-R]{2}[0-9]{2}[A-Wa-w]{0,2})\s{0,}$/s", $message_text)) {
            $qth = strtoupper(trim($message_text));

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
                `viber_users`
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
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Thanks for providing QTH location, I successfully decoded and saved your coordinates. Check that coordinates in location I just sent. Notification automatically enabled, send message Disable to disable notifications.", $language_code));
                $Viber_API->send_location($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    $latitude, $longitude);
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
            }
            $update_user_stmt->close();
        } elseif ($message_text === __('Enable', $language_code)) {
            $user_latitude = NULL;
            $user_longitude = NULL;
            $user_stmt = $db->prepare("SELECT `latitude`, `longitude` FROM `viber_users` WHERE `user_id` = ? LIMIT 1;");
            $user_stmt->bind_param('i', $user_id);
            if ($user_stmt->execute()) {
                $user_stmt->bind_result($user_latitude, $user_longitude);
                $user_stmt->fetch();
            }
            $user_stmt->close();

            if ($user_latitude && $user_longitude) {
                $update_user_stmt = $db->prepare("UPDATE
                `viber_users`
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
                    $Viber_API->send_message($viber_user_id,
                        __('Balloons Bot', $language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        __("Notifications enabled. I will drop you a message after detecting the balloon nearby. Feel free to disable notifications with message Disable.", $language_code));
                } else {
                    $Viber_API->send_message($viber_user_id,
                        __('Balloons Bot', $language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
                }
                $update_user_stmt->close();
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("I see you have no location saved, please send one (or QTH locator) before enabling notifications.", $language_code));
            }
        } elseif ($message_text === __('Disable', $language_code)) {
            $update_user_stmt = $db->prepare("UPDATE
                `viber_users`
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
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Notifications were disabled. Send message Enable to bring them back.", $language_code));
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
            }
            $update_user_stmt->close();
        } elseif ($message_text === __('Status', $language_code)) {
            $user_stmt = $db->prepare("SELECT `enabled`, `latitude`, `longitude`, `range`, `altitude` FROM `viber_users` WHERE `user_id` = ? LIMIT 1;");
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
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    $status);
                if ($user_latitude && $user_longitude) {
                    $Viber_API->send_location($viber_user_id,
                        __('Balloons Bot', $language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        $user_latitude, $user_longitude);
                }
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
            }
            $user_stmt->close();
        } elseif ($message_text === __('Altitude', $language_code)) {
            $Viber_API->send_message($viber_user_id,
                __('Balloons Bot', $language_code),
                trim(getenv('APP_URL'), "/") . "/balloon.png",
                __("Please tell me what minimum altitude balloon must have for notifications. Add the latin letter **m** in the end for meters or **ft** for feet, e.g. 500 m or 1600 ft. Number without units will be considered as a value in meters.", $language_code));
        } elseif ($message_text === __('Range', $language_code)) {
            $Viber_API->send_message($viber_user_id,
                __('Balloons Bot', $language_code),
                trim(getenv('APP_URL'), "/") . "/balloon.png",
                __("Please tell me maximum distance between you and balloon. Add the latin letters **km** in the end for kilometers or **mi** for miles, e.g. 300 km or 186 mi. Number without units will be considered as a value in kilometers.", $language_code));
        } elseif ($message_text === __('Commands', $language_code)) {
            $Viber_API->send_message($viber_user_id,
                __('Balloons Bot', $language_code),
                trim(getenv('APP_URL'), "/") . "/balloon.png",
                __("You can ask me to do something with one of the next commands:\n\nEnable - enable notifications;\nDisable - disable notifications;\nStatus - get your configurations;\nAltitude - set minimum altitude that balloon must have to send notification;\nRange - set maximum range between balloon and you;\nCommands - get list of possible commands.", $language_code),
                array(
                    "Type" => "keyboard",
                    "DefaultHeight" => FALSE,
                    "Buttons" => array(
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Enable", $language_code),
                            "Text" => __("Enable", $language_code)
                        ),
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Disable", $language_code),
                            "Text" => __("Disable", $language_code)
                        ),
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Status", $language_code),
                            "Text" => __("Status", $language_code)
                        ),
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Altitude", $language_code),
                            "Text" => __("Altitude", $language_code)
                        ),
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Range", $language_code),
                            "Text" => __("Range", $language_code)
                        ),
                        array(
                            "ActionType" => "reply",
                            "ActionBody" => __("Commands", $language_code),
                            "Text" => __("Commands", $language_code)
                        ),
                    )
                ));
        } else {
            $user_last_command = NULL;
            $user_stmt = $db->prepare("SELECT `last_command` FROM `viber_users` WHERE `user_id` = ? LIMIT 1;");
            $user_stmt->bind_param('i', $user_id);
            if ($user_stmt->execute()) {
                $user_stmt->bind_result($user_last_command);
                $user_stmt->fetch();
            }
            $user_stmt->close();

            if ($user_last_command === __('Altitude', $language_code)) {
                $altitude = NULL;
                if (preg_match("/^([0-9]{1,})[\s]{0,}(m|ft)$/si", strtolower($message_text), $matches)) {
                    if ($matches[2] === 'ft') {
                        $altitude = floatval($message_text) * 0.3048;
                    } else {
                        $altitude = floatval($message_text);
                    }
                } elseif (is_numeric($message_text)) {
                    $altitude = floatval($message_text);
                }

                if ($altitude) {
                    $altitude = round($altitude, 2);

                    $update_user_stmt = $db->prepare("UPDATE
                        `viber_users`
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
                        $Viber_API->send_message($viber_user_id,
                            __('Balloons Bot', $language_code),
                            trim(getenv('APP_URL'), "/") . "/balloon.png",
                            __("New minimum accepted altitude successfully saved.", $language_code));
                    } else {
                        $Viber_API->send_message($viber_user_id,
                            __('Balloons Bot', $language_code),
                            trim(getenv('APP_URL'), "/") . "/balloon.png",
                            __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
                    }
                    $update_user_stmt->close();
                } else {
                    $Viber_API->send_message($viber_user_id,
                        __('Balloons Bot', $language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        __("I can't recognize value, please try again.", $language_code));
                }
            } elseif ($user_last_command === __('Range', $language_code)) {
                $range = NULL;
                if (preg_match("/^([0-9]{1,})[\s]{0,}(km|mi)$/si", strtolower($message_text), $matches)) {
                    if ($matches[2] === 'mi') {
                        $range = floatval($message_text) * 1.609344;
                    } else {
                        $range = floatval($message_text);
                    }
                } elseif (is_numeric($message_text)) {
                    $range = floatval($message_text);
                }

                if ($range) {
                    $range = round($range, 2);

                    $update_user_stmt = $db->prepare("UPDATE
                        `viber_users`
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
                        $Viber_API->send_message($viber_user_id,
                            __('Balloons Bot', $language_code),
                            trim(getenv('APP_URL'), "/") . "/balloon.png",
                            __("New maximum accepted range successfully saved.", $language_code));
                    } else {
                        $Viber_API->send_message($viber_user_id,
                            __('Balloons Bot', $language_code),
                            trim(getenv('APP_URL'), "/") . "/balloon.png",
                            __("Something went wrong. I will do my best to fix this problem ASAP.", $language_code));
                    }
                    $update_user_stmt->close();
                } else {
                    $Viber_API->send_message($viber_user_id,
                        __('Balloons Bot', $language_code),
                        trim(getenv('APP_URL'), "/") . "/balloon.png",
                        __("I can't recognize value, please try again.", $language_code));
                }
            } else {
                $Viber_API->send_message($viber_user_id,
                    __('Balloons Bot', $language_code),
                    trim(getenv('APP_URL'), "/") . "/balloon.png",
                    __("I don't know what to respond. Send me message Commands to get list of possible commands.", $language_code),
                    array(
                        "Type" => "keyboard",
                        "DefaultHeight" => FALSE,
                        "Buttons" => array(
                            array(
                                "ActionType" => "reply",
                                "ActionBody" => __("Commands", $language_code),
                                "Text" => __("Commands", $language_code)
                            ),
                        )
                    ));
            }
        }
        break;
    case 'unsubscribed':
        http_response_code(200);
        $update_user_stmt = $db->prepare("DELETE FROM
            `viber_users`
        WHERE
            `user_id` = ?
        LIMIT 1
        ;");
        $update_user_stmt->bind_param('i',
            $user_id
        );
        $update_user_stmt->execute();
        $update_user_stmt->close();
        break;
    case 'webhook':
        http_response_code(200);
        break;
    default:
        http_response_code(401);
}

exit;