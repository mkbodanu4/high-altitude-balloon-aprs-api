<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";

if (!isset($_GET['key']) || $_GET['key'] !== getenv('API_KEY')) {
    http_response_code(403);
    exit;
}

$get = trim(filter_var($_GET['get'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
switch ($get) {
    case 'history':
        $since_date = NULL;
        $since = trim(filter_var($_GET['since'], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        if ($since) {
            $since_timestamp = strtotime($since);
            if ($since_timestamp && $since_timestamp >= strtotime("1 year ago"))
                $since_date = date("Y-m-d H:i:s", $since_timestamp);
        }
        $balloons = array();

        $query = "SELECT `call_sign` FROM `history` GROUP BY `call_sign`;";
        $call_signs_stmt = $db->prepare($query);
        $call_signs_stmt->execute();
        $call_signs_result = $call_signs_stmt->get_result();
        while ($call_signs_row = $call_signs_result->fetch_object()) {
            $balloon = array(
                'call_sign' => $call_signs_row->call_sign,
                'history' => array()
            );

            $history_query = "SELECT `date`, `latitude`, `longitude`, `course`, `speed`, `altitude`, `daodatumbyte`, `comment` FROM `history` WHERE `call_sign` = ? " . ($since_date ? " AND `date` >= ? " : "") . " ORDER BY `date` ASC;";
            $history_stmt = $db->prepare($history_query);
            if ($since_date)
                $history_stmt->bind_param('ss', $call_signs_row->call_sign, $since_date);
            else
                $history_stmt->bind_param('s', $call_signs_row->call_sign);
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();
            while ($packet = $history_result->fetch_object()) {
                $balloon['history'][] = $packet;
            }
            $history_result->close();


            $balloons[] = $balloon;
        }
        $call_signs_result->close();

        $http_response_code = 200;
        $response = array(
            'data' => $balloons
        );
        break;
    default:
        $http_response_code = 400;
        $response = array();
}

http_response_code($http_response_code);
header("Content-type:application/json");
echo json_encode($response);
exit;