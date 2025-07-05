<?php
session_start();

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";
include __DIR__ . DIRECTORY_SEPARATOR . "telegram_api.class.php";

if (isset($_GET['logout'])) {
    $_SESSION['logged'] = FALSE;
    session_destroy();
    header("Location: panel.php");
    exit;
}

if (count($_POST) > 0) {
    $action = trim(filter_var($_POST['action'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    switch ($action) {
        case 'login':
            $password = trim(filter_var($_POST['password'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));

            $_SESSION['logged'] = (bool)($password === getenv('MASTER_PASSWORD'));
            header("Location: panel.php");
            exit;
            break;
        case 'send_message':
            if (isset($_SESSION['logged']) && $_SESSION['logged'] === TRUE) {
                $submit = trim(filter_var($_POST['submit'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
                $message = trim($_POST['message']); // To have all special chars in message, this must be not validated

                $messages = array();
                foreach (array_keys($lang) as $lang_code) {
                    $messages[$lang_code] = trim($_POST['message_' . $lang_code]); // To have all special chars in message, this must be not validated
                }

                $result_message = '';
                if ($message && count($messages) > 0) {
                    $Telegram_API = new Telegram_API(getenv('TELEGRAM_API_KEY'));

                    $users = array();
                    $users_query = "SELECT
                    *
                FROM
                    `users`
                WHERE
                    `enabled` = TRUE
                ;";
                    $users_stmt = $db->prepare($users_query);
                    $users_stmt->execute();
                    $users_result = $users_stmt->get_result();
                    while ($row = $users_result->fetch_object()) {
                        $users[] = $row;
                    }
                    $users_stmt->close();

                    foreach ($users as $user) {
                        $telegram_message = isset($messages[$user->language_code]) ? $messages[$user->language_code] : $message;
                        $telegram_message = str_replace('\n', "\n", $telegram_message); // New lines with \n symbol
                        $result_message .= $user->first_name . ' ' . $user->language_code . '>' . substr(filter_var($telegram_message, FILTER_SANITIZE_FULL_SPECIAL_CHARS), 0, 50);
                        if ($submit === "Send") {
                            $sent = $Telegram_API->sendMessage($user->active_chat_id, $telegram_message, $user->message_thread_id,TRUE);
                            if ($sent->ok && $sent->result) {
                                $result_message .= ' sent';
                            } else {
                                $result_message .= ' not sent';
                            }
                        }
                        $result_message .= '<br/>';
                    }

                    $_SESSION['result'] = 'success';
                } else {
                    $_SESSION['result'] = 'danger';
                    $result_message = 'No messages received';
                }

                $_SESSION['message'] = $result_message;
            } else {
                header("Location: panel.php");
                exit;
            }
            break;
        default:
            header("Location: panel.php");
            exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>High Altitude Balloon APRS Tracker API</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.0/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-gH2yIJqKdNHPEq0n4Mqa/HGKIhSkIHeL5AyhkYV8i59U5AR6csBvApHHNl/vI1Bx" crossorigin="anonymous">
</head>
<body>
<?php if (isset($_SESSION['logged']) && $_SESSION['logged'] === TRUE) { ?>
    <header class="navbar navbar-dark sticky-top bg-dark flex-md-nowrap p-0 shadow">
        <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fs-6" href="<?= "panel.php"; ?>">
            High Altitude Balloon APRS Tracker API
        </a>
        <div class="navbar-nav">
            <div class="nav-item text-nowrap">
                <a class="nav-link px-3" href="<?= "panel.php?logout"; ?>">Sign out</a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if (isset($_SESSION['result']) && $_SESSION['result']) { ?>
            <div class="alert my-2 text-center alert-<?= $_SESSION['result']; ?>">
                <?= isset($_SESSION['message']) && $_SESSION['message'] ? $_SESSION['message'] : ucfirst($_SESSION['result']); ?>
            </div>
        <?php } ?>

        <div class="row mt-3">
            <div class="col">
                <div class="card">
                    <form action="<?= "panel.php"; ?>" method="post">
                        <div class="card-header">
                            Send message to users with enabled notifications
                        </div>
                        <div class="card-body">
                            <input type="hidden" name="action" value="send_message">
                            <?php foreach (array_keys($lang) as $lang_code) { ?>
                                <div class="mb-3">
                                    <label for="message_<?= $lang_code; ?>">
                                        Message in <?= $lang_code; ?>:
                                    </label>
                                    <textarea class="form-control" name="message_<?= $lang_code; ?>"
                                              id="message_<?= $lang_code; ?>"
                                              required><?= isset($messages) && isset($messages[$lang_code]) ? $messages[$lang_code] : '' ?></textarea>
                                </div>
                            <?php } ?>
                            <div class="mb-3">
                                <label for="message">
                                    Message for other languages:
                                </label>
                                <textarea class="form-control" name="message" id="message"
                                          required><?= isset($message) ? $message : '' ?></textarea>
                            </div>
                            <div>
                                Hint: use <b>\n</b> as new line symbol
                            </div>
                        </div>
                        <div class="card-footer">
                            <input type="submit" name="submit" class="btn btn-primary" value="Send">
                            <input type="submit" name="submit" class="btn btn-secondary" value="Test">
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="mt-2 text-end">
            &copy; UR5WKM 2022
        </div>
    </div>
<?php } else { ?>
    <div class="container">
        <div class="row mt-5">
            <div class="col mb-5 mb-md-0">
                <div class="card">
                    <form action="<?= "panel.php"; ?>" method="post">
                        <div class="card-header">
                            Please log:
                        </div>
                        <div class="card-body">
                            <input type="hidden" name="action" value="login">
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    Password:
                                </label>
                                <input type="password" class="form-control" name="password" value="" required>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="text-center">
                                <input type="submit" class="btn btn-primary" value="Sing in">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-A3rJD856KowSb7dwlZdYEkO39Gagi7vIsF0jrRAoQmDKKtQBHUuLZ9AsSv4jD4Xa"
        crossorigin="anonymous"></script>
</body>
</html>
<?php

if (isset($_SESSION['result']))
    unset($_SESSION['result']);

if (isset($_SESSION['message']))
    unset($_SESSION['message']);

?>
