<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";

if (!isset($_GET['key']) || $_GET['key'] !== getenv('API_KEY')) {
    http_response_code(403);
    exit;
}

$get = isset($_GET['get']) ? trim(filter_var($_GET['get'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;
switch ($get) {
    case 'history':
        $filter_from_date = NULL;
        $filter_from = isset($_GET['from']) ? trim(filter_var($_GET['from'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;
        if ($filter_from) {
            $from_timestamp = strtotime($filter_from);
            if ($from_timestamp && $from_timestamp >= strtotime("1 year ago"))
                $filter_from_date = date("Y-m-d H:i:s", $from_timestamp);
        }

        $filter_to_date = NULL;
        $filter_to = isset($_GET['to']) ? trim(filter_var($_GET['to'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;
        if ($filter_to) {
            $to_timestamp = strtotime($filter_to);
            if ($to_timestamp && $to_timestamp >= strtotime("1 year ago"))
                $filter_to_date = date("Y-m-d H:i:s", $to_timestamp);
        }

        $filter_call_sign = isset($_GET['call_sign']) ? trim(filter_var($_GET['call_sign'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;

        $balloons = array();

        $query = "SELECT
            `call_sign`
        FROM
            `history`
         " . ($filter_call_sign ? " AND `call_sign` = ? " : "") . "
        GROUP BY
            `call_sign`;";
        $call_signs_stmt = $db->prepare($query);
        if ($filter_call_sign) {
            $call_signs_stmt->bind_param('s', $filter_call_sign);
        }
        $call_signs_stmt->execute();
        $call_signs_result = $call_signs_stmt->get_result();
        while ($call_signs_row = $call_signs_result->fetch_object()) {
            $balloon = array(
                'call_sign' => $call_signs_row->call_sign,
                'history' => array()
            );

            $history_query = "SELECT
                `date`, `latitude`, `longitude`, `course`, `speed`, `altitude`, `daodatumbyte`, `comment`
            FROM
                `history`
            WHERE
                `call_sign` = ?
                " . ($filter_from_date && $filter_to_date ? " AND (`date` >= ? AND `date` <= ?) " : "") . "
                " . ($filter_from_date && !$filter_to_date ? " AND `date` >= ? " : "") . "
                " . (!$filter_from_date && $filter_to_date ? " AND `date` <= ? " : "") . "
            ORDER BY `date` ASC;";
            $history_stmt = $db->prepare($history_query);
            if ($filter_from_date && $filter_to_date)
                $history_stmt->bind_param('ss', $call_signs_row->call_sign, $filter_from_date, $filter_to_date);
            elseif ($filter_from_date && !$filter_to_date)
                $history_stmt->bind_param('s', $call_signs_row->call_sign, $filter_from_date);
            elseif (!$filter_from_date && $filter_to_date)
                $history_stmt->bind_param('s', $call_signs_row->call_sign, $filter_to_date);
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