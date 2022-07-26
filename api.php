<?php

include __DIR__ . DIRECTORY_SEPARATOR . "common.php";

if (getenv("BENCHMARK") === "TRUE") {
    $benchmark_start = microtime(TRUE);
    $benchmark_point = microtime(TRUE);
    $benchmarks = array();
}

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

        $filter_south_west_lat = isset($_GET['south_west_lat']) ? filter_var($_GET['south_west_lat'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : NULL;
        $filter_south_west_lng = isset($_GET['south_west_lng']) ? filter_var($_GET['south_west_lng'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : NULL;
        $filter_north_east_lat = isset($_GET['north_east_lat']) ? filter_var($_GET['north_east_lat'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : NULL;
        $filter_north_east_lng = isset($_GET['north_east_lng']) ? filter_var($_GET['north_east_lng'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : NULL;

        $filter_date_diff = NULL;
        $filter_precision = 2;
        if ($filter_from_date) {
            $filter_date_diff = ($filter_to_date ? strtotime($filter_to_date) : time()) - strtotime($filter_from_date);
            if ($filter_date_diff > 172800) {
                $filter_precision = 1; // Fewer points if timespan bigger than 2 days
            } elseif ($filter_date_diff > 432000) {
                $filter_precision = 0; // Even less points if timespan bigger than 5 days
            }
        }

        $filter_call_sign = isset($_GET['call_sign']) ? trim(filter_var($_GET['call_sign'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;

        $filter_only_last_point = isset($_GET['only_last_point']) ? filter_var($_GET['only_last_point'], FILTER_VALIDATE_BOOLEAN) : FALSE;

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['filters'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);
        }

        $balloons = array();
        $call_signs_where = array();
        $call_signs_params = array();
        if ($filter_call_sign) {
            $call_signs_where[] = "`call_sign` = ?";
            $call_signs_params[] = $filter_call_sign;
        }
        if ($filter_from_date && $filter_to_date) {
            $call_signs_where[] = "(`date` >= ? AND `date` <= ?)";
            $call_signs_params[] = $filter_from_date;
            $call_signs_params[] = $filter_to_date;
        } elseif ($filter_from_date && !$filter_to_date) {
            $call_signs_where[] = "`date` >= ?";
            $call_signs_params[] = $filter_from_date;
        } elseif (!$filter_from_date && $filter_to_date) {
            $call_signs_where[] = "`date` <= ?";
            $call_signs_params[] = $filter_to_date;
        }
        if ($filter_south_west_lat !== NULL && $filter_south_west_lng !== NULL && $filter_north_east_lat !== NULL && $filter_north_east_lng !== NULL) {
            $call_signs_where[] = "( `longitude` >= ? AND `longitude` <= ? AND `latitude` >= ? AND `latitude` <= ? )";
            $call_signs_params[] = $filter_south_west_lng;
            $call_signs_params[] = $filter_north_east_lng;
            $call_signs_params[] = $filter_south_west_lat;
            $call_signs_params[] = $filter_north_east_lat;
        }
        $call_signs_query = "SELECT
            `call_sign`
        FROM
            `history`
        " . (count($call_signs_where) > 0 ? "WHERE " . implode(" AND ", $call_signs_where) . " " : "") . "
        GROUP BY
            `call_sign`;";
        $call_signs_stmt = $db->prepare($call_signs_query);
        if (count($call_signs_params) > 0) {
            $call_signs_stmt->bind_param(str_repeat('s', count($call_signs_params)), ...$call_signs_params);
        }
        $call_signs_stmt->execute();
        $call_signs_result = $call_signs_stmt->get_result();

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['call_signs_query'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);

            $query_idx = 1;
        }

        while ($call_signs_row = $call_signs_result->fetch_object()) {
            $balloon_history = array();

            $history_where = array(
                "`call_sign` = ?"
            );
            $history_params = array(
                $call_signs_row->call_sign
            );
            if ($filter_from_date && $filter_to_date) {
                $history_where[] = "(`date` >= ? AND `date` <= ?)";
                $history_params[] = $filter_from_date;
                $history_params[] = $filter_to_date;
            } elseif ($filter_from_date && !$filter_to_date) {
                $history_where[] = "`date` >= ?";
                $history_params[] = $filter_from_date;
            } elseif (!$filter_from_date && $filter_to_date) {
                $history_where[] = "`date` <= ?";
                $history_params[] = $filter_to_date;
            }
            /*
            if ($filter_south_west_lat !== NULL && $filter_south_west_lng !== NULL && $filter_north_east_lat !== NULL && $filter_north_east_lng !== NULL) {
                $history_where[] = "( `longitude` >= ? AND `longitude` <= ? AND `latitude` >= ? AND `latitude` <= ? )";
                $history_params[] = $filter_south_west_lng;
                $history_params[] = $filter_north_east_lng;
                $history_params[] = $filter_south_west_lat;
                $history_params[] = $filter_north_east_lat;
            }
            */
            $history_query = "SELECT
                `date`,
                `latitude`,
                `longitude`,
                `speed`,
                `altitude`,
                `comment`
            FROM
                `history`
            " . (count($history_where) > 0 ? "WHERE " . implode(" AND ", $history_where) . " " : "") . "
            " . ($filter_only_last_point || ($filter_call_sign === NULL && (($filter_from_date === NULL && $filter_to_date === NULL) || ($filter_date_diff !== NULL && $filter_date_diff > 864000))) ? "ORDER BY `date` DESC LIMIT 1" : "ORDER BY `date` ASC") . "
            ;"; // Only last point if timespan more than 10 days
            $history_stmt = $db->prepare($history_query);
            if (count($history_params) > 0) {
                $history_stmt->bind_param(str_repeat('s', count($history_params)), ...$history_params);
            }
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();
            $previous_timestamp = NULL;
            $previous_latitude = NULL;
            $previous_longitude = NULL;
            while ($row = $history_result->fetch_object()) {
                if ($filter_call_sign === NULL && $previous_latitude !== NULL && $previous_longitude !== NULL && round($previous_latitude, $filter_precision) == round($row->latitude, $filter_precision) && round($previous_longitude, $filter_precision) == round($row->longitude, $filter_precision)) {
                    // Discard previous element of array, if it has same location as current
                    array_pop($balloon_history);
                }

                $timestamp = strtotime($row->date);

                if ($previous_timestamp !== NULL && $filter_call_sign === NULL && ($timestamp - $previous_timestamp) < 30 && round($previous_latitude, 0) != round($row->latitude, 0) && round($previous_longitude, 0) != round($row->longitude, 0)) {
                    $previous_timestamp = $timestamp;
                    $previous_latitude = $row->latitude;
                    $previous_longitude = $row->longitude;

                    continue;
                }

                $packet = array(
                    "t" => $timestamp,
                    "lat" => $row->latitude,
                    "lng" => $row->longitude,
                );
                if ($row->speed) $packet['s'] = $row->speed;
                if ($row->altitude) $packet['a'] = $row->altitude;
                if ($row->comment) $packet['c'] = $row->comment;

                $balloon_history[] = $packet;

                $previous_timestamp = $timestamp;
                $previous_latitude = $row->latitude;
                $previous_longitude = $row->longitude;
            }
            $history_result->close();

            $balloons[$call_signs_row->call_sign] = $balloon_history;
        }
        $call_signs_result->close();

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['history_queries'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);
        }

        $http_response_code = 200;
        $response = array(
            'data' => $balloons
        );
        break;
    default:
        $http_response_code = 400;
        $response = array();
}

if (getenv("BENCHMARK") === "TRUE") {
    $benchmarks['end'] = round(microtime(TRUE) - $benchmark_point, 5);
    $benchmarks['run'] = round(microtime(TRUE) - $benchmark_start, 5);
    $response = array_merge($response, array(
        'benchmarks' => $benchmarks
    ));
}

http_response_code($http_response_code);
header("Content-type:application/json");
echo json_encode($response);
exit;