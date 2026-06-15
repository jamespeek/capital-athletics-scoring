<?php

// Use a sentinel for Open so it cannot be confused with a real age bucket like U20.
define('OPEN_AGE_SENTINEL', 999);

function calcScore($age, $gender, $eventName, $rawResult, $useOwnAgeLookup = false) {
    $records = scoreLoadRecords();
    $wmaData = scoreLoadWmaData();

    $ageRaw = trim((string)$age);
    $genderRaw = trim((string)$gender);
    $eventRaw = trim((string)$eventName);
    $resultRaw = trim((string)$rawResult);

    $actualAge = scoreNormaliseAge($ageRaw);
    $gender = scoreNormaliseGender($genderRaw);
    $event = scoreNormaliseEvent($eventRaw);
    $rawValue = is_numeric($rawResult) ? (float)$rawResult : scoreParseResult($resultRaw);
    $scoreData = [
        'score' => null,
        'status' => null,
        'potential_record' => null,
        'meta' => [
            'input' => [
                'age' => $ageRaw,
                'gender' => $genderRaw,
                'event' => $eventRaw,
                'result' => $resultRaw,
            ],
            'adjustment' => [
                'wma_factor' => null,
                'adjusted_result' => null,
                'percentage' => null,
            ],
            'lookup' => [
                'age' => null,
                'matched_age' => null,
            ],
            'record' => [
                'result' => null,
                'weight' => null,
                'name' => null,
                'source' => null,
            ],
            'display' => [
                'raw_result' => scoreFormatDisplayResult($eventRaw, $resultRaw, $rawValue),
                'wma_factor' => null,
                'adjusted_result' => null,
                'lookup_age' => null,
                'matched_age' => null,
            ],
        ],
    ];

    if (!$records || $actualAge === null || $gender === '' || $event === '' || $rawValue === null || $rawValue <= 0) {
        $scoreData['status'] = 'unavailable';
        return $scoreData;
    }

    $lookupAge = $actualAge;
    $factor = 1.0;
    $adjustedValue = $rawValue;
    $wmaEvent = $event;
    $recordEvent = $event;

    if (isMastersAge($actualAge)) {
        $wmaGender = $gender === 'Male' ? 'M' : 'F';
        $wmaEvent = scoreMastersWmaEventKey($event);
        $recordEvent = scoreMastersRecordEventKey($event, $gender);

        // Male masters aged 35-59 may run 2000m steeple, but the WMA/open-record comparison is against
        // the 3000m steeple event, so we project the 2000m time out to an equivalent 3000m time first,
        // with an extra 15 seconds added to account for the added endurance demand over the longer distance.
        if ($gender === 'Male' && $actualAge < 60 && $event === '2000m steeple') {
            $rawValue = ($rawValue * 1.5) + 15;
            $scoreData['meta']['display']['raw_result'] = scoreFormatDisplayResult('3000m steeple', (string)$rawValue, $rawValue);
        }

        $factor = (float)($wmaData[$wmaGender][$actualAge][$wmaEvent] ?? 1);
        $adjustedValue = $rawValue * $factor;
        $lookupAge = OPEN_AGE_SENTINEL; // compare masters to Open
    } elseif ($actualAge !== OPEN_AGE_SENTINEL) {
        // underage lookup is one year up
        if (!$useOwnAgeLookup) {
            $lookupAge++;
        }
    }

    $scoreData['meta']['adjustment']['wma_factor'] = isMastersAge($actualAge) ? $factor : null;
    $scoreData['meta']['adjustment']['adjusted_result'] = $adjustedValue;
    $scoreData['meta']['lookup']['age'] = $lookupAge;
    $scoreData['meta']['display']['wma_factor'] = isMastersAge($actualAge) ? scoreFormatNumber($factor, 5) : null;
    $scoreData['meta']['display']['adjusted_result'] = scoreFormatAdjustedDisplayResult($eventRaw, $adjustedValue);
    $scoreData['meta']['display']['lookup_age'] = scoreAgeLabel((string)$lookupAge);

    $record = scoreFindRecord($records, $lookupAge, $gender, $recordEvent);

    if (!$record) {
        $scoreData['status'] = 'no_record';
        return $scoreData;
    }

    $recordValue = $record['_result'] ?? null;
    $scoreData['meta']['lookup']['matched_age'] = $record['Age'] ?? null;
    $scoreData['meta']['record']['result'] = $record['Result'] ?? null;
    $scoreData['meta']['record']['weight'] = $record['Weight'] ?? null;
    $scoreData['meta']['record']['name'] = $record['Name'] ?? null;
    $scoreData['meta']['record']['source'] = $record['Source'] ?? null;
    $scoreData['meta']['display']['matched_age'] = scoreAgeLabel((string)($record['Age'] ?? ''));

    if (!$recordValue || $recordValue <= 0) {
        $scoreData['status'] = 'invalid_record';
        return $scoreData;
    }

    $timeEvent = scoreIsTimeEvent($event);

    if ($timeEvent) {
        $percentage = $recordValue / $adjustedValue;
    } else {
        $percentage = $adjustedValue / $recordValue;
    }

    $score = round($percentage * appConfig()['score_base'], 0);
    $scoreData['score'] = (float)$score;
    $scoreData['status'] = 'ok';
    $scoreData['meta']['adjustment']['percentage'] = $percentage;

    if ($percentage > 1) {
        $scoreData['potential_record'] = [
            'percentage' => $percentage,
            'record' => $scoreData['meta']['record'],
            'display' => $scoreData['meta']['display'],
        ];
    }

    return $scoreData;
}

function buildAthleteEventSummary($athlete, $eventName, $eventResults, $meetEvents) {
    $specialMeetNames = appConfig()['special_meet_names'];
    $meetSummaries = [];
    $bestScore = 0;
    $offered = 0;
    $entered = 0;
    $processedMeetNames = [];
    $calcParticipationScore = static function ($enteredCount, $offeredCount) {
        if ($offeredCount <= 0) {
            return 0;
        }

        return min($enteredCount, $offeredCount) / $offeredCount;
    };

    foreach ($meetEvents as $meet) {
        if (!in_array($eventName, $meet['events'])) {
            continue;
        }

        $meetName = $meet['name'];

        if ($meetName === $specialMeetNames['u9-18'] && $athlete['age'] > 17) {
            continue;
        }

        if ($meetName === $specialMeetNames['u20-open'] && $athlete['age'] < 18) {
            continue;
        }

        $offered++;
        $processedMeetNames[$meetName] = true;

        $meetSummary = [
            'name' => $meetName,
            'result_str' => null,
            'score_data' => null,
            'participation_score' => 0,
            'has_missing_record' => false,
        ];

        foreach ($eventResults as $eventResult) {
            if (!$eventResult['result_raw'] || $eventResult['meet'] !== $meetName) {
                continue;
            }

            // U9-U18 Champs results for U12 and below should be scored against their own age records,
            // rather than the usual "one age up" junior lookup.
            $useOwnAgeLookup = $meetName === $specialMeetNames['u9-18'] && $eventResult['age'] <= 12;

            $meetSummary['result_str'] = $eventResult['result_str'];
            $meetSummary['score_data'] = calcScore(
                $eventResult['age'],
                $eventResult['gender'],
                $eventResult['event'],
                $eventResult['result_raw'],
                $useOwnAgeLookup
            );
            $meetSummary['has_missing_record'] = $meetSummary['score_data']['status'] === 'no_record';

            if ($meetSummary['score_data']['score'] !== null && $meetSummary['score_data']['score'] > $bestScore) {
                $bestScore = $meetSummary['score_data']['score'];
            }

            $entered++;
            break;
        }

        $meetSummary['participation_score'] = $calcParticipationScore($entered, $offered);
        $meetSummaries[] = $meetSummary;
    }

    foreach ($eventResults as $eventResult) {
        $meetName = $eventResult['meet'] ?? null;

        if (!$eventResult['result_raw'] || $meetName === null || isset($processedMeetNames[$meetName])) {
            continue;
        }

        $processedMeetNames[$meetName] = true;

        $meetSummary = [
            'name' => $meetName,
            'result_str' => $eventResult['result_str'],
            'score_data' => null,
            'participation_score' => $offered > 0 ? $entered / $offered : 0,
            'has_missing_record' => false,
        ];

        $useOwnAgeLookup = $meetName === $specialMeetNames['u9-18'] && $eventResult['age'] <= 12;

        $meetSummary['score_data'] = calcScore(
            $eventResult['age'],
            $eventResult['gender'],
            $eventResult['event'],
            $eventResult['result_raw'],
            $useOwnAgeLookup
        );
        $meetSummary['has_missing_record'] = $meetSummary['score_data']['status'] === 'no_record';

        if ($meetSummary['score_data']['score'] !== null && $meetSummary['score_data']['score'] > $bestScore) {
            $bestScore = $meetSummary['score_data']['score'];
        }

        $offered++;
        $entered++;
        $meetSummary['participation_score'] = $calcParticipationScore($entered, $offered);
        $meetSummaries[] = $meetSummary;
    }

    $participationScore = $calcParticipationScore($entered, $offered);
    $finalScore = round($bestScore * $participationScore);

    return [
        'event' => $eventName,
        'meets' => $meetSummaries,
        'best_score' => $bestScore,
        'participation_score' => $participationScore,
        'final_score' => $finalScore,
    ];
}

function scoreLoadRecords() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $file = __DIR__ . '/data/reference/combined-act.csv';

    if (!is_file($file)) {
        return null;
    }

    $fh = fopen($file, 'r');

    $headers = fgetcsv($fh, null, ',', '"', '\\');
    if (!$headers) {
        return null;
    }

    $headers = array_map('scoreCleanHeader', $headers);

    $index = [];
    $ages = [];

    while (($row = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
        if (count($row) < count($headers)) {
            $row = array_pad($row, count($headers), '');
        }

        $r = array_combine($headers, $row);
        if (!$r) {
            continue;
        }

        $gender = scoreNormaliseGender($r['Gender'] ?? '');
        $event = scoreNormaliseEvent($r['Event'] ?? '');
        $age = scoreNormaliseAge($r['Age'] ?? '');
        $value = scoreParseResult($r['Result'] ?? '');

        if (!$gender || !$event || $age === null || !$value) {
            continue;
        }

        $r['_result'] = $value;

        $index[$gender][$event][$age][] = $r;
        $ages[$gender][$age] = true;
    }

    fclose($fh);

    foreach ($ages as $g => $set) {
        $a = array_keys($set);
        sort($a, SORT_NUMERIC);
        $ages[$g] = $a;
    }

    foreach ($index as $g => $events) {
        foreach ($events as $event => $ageRows) {
            $timeEvent = scoreIsTimeEvent($event);

            foreach ($ageRows as $age => $rows) {
                $best = null;

                foreach ($rows as $r) {
                    if (!$best) {
                        $best = $r;
                        continue;
                    }

                    if ($timeEvent) {
                        if ($r['_result'] < $best['_result']) $best = $r;
                    } else {
                        if ($r['_result'] > $best['_result']) $best = $r;
                    }
                }

                $index[$g][$event][$age] = $best;
            }
        }
    }

    $cache = [
        'index' => $index,
        'ages' => $ages,
    ];

    return $cache;
}

function scoreLoadWmaData() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'M' => json_decode(@file_get_contents(__DIR__ . '/data/reference/wma-men.json'), true),
        'F' => json_decode(@file_get_contents(__DIR__ . '/data/reference/wma-women.json'), true),
    ];

    if (!is_array($cache['M'])) {
        $cache['M'] = [];
    }

    if (!is_array($cache['F'])) {
        $cache['F'] = [];
    }

    return $cache;
}

function scoreFindRecord($records, $age, $gender, $event) {
    if (!isset($records['index'][$gender][$event])) {
        return null;
    }

    $ages = $records['ages'][$gender];

    foreach ($ages as $a) {
        if ($a < $age) {
            continue;
        }

        if (!empty($records['index'][$gender][$event][$a])) {
            return $records['index'][$gender][$event][$a];
        }
    }

    return null;
}

function scoreNormaliseAge($age) {
    $age = trim((string)$age);

    if ($age === '') {
        return null;
    }

    if (preg_match('/^U?(\d+)$/', $age, $m)) {
        return (int)$m[1];
    }

    if (strcasecmp($age, 'Open') === 0) {
        return OPEN_AGE_SENTINEL;
    }

    return null;
}

function isMastersAge($age) {
    return $age !== OPEN_AGE_SENTINEL && $age >= 35;
}

function scoreAgeLabel($age) {
    $age = (string)$age;

    if (strcasecmp($age, 'Open') === 0 || (int)$age === OPEN_AGE_SENTINEL) {
        return 'Open';
    }

    return 'U' . $age;
}

function scoreNormaliseGender($g) {
    $g = strtolower(trim((string)$g));

    if (in_array($g, ['m', 'male', 'men', 'boy', 'boys'], true)) {
        return 'Male';
    }

    if (in_array($g, ['f', 'female', 'women', 'girl', 'girls'], true)) {
        return 'Female';
    }

    return ucfirst($g);
}

function scoreNormaliseEvent($event) {
    $event = trim((string)$event);
    $event = preg_replace('/\s+/', ' ', $event);
    $event = preg_replace('/\s+\d+(?:\.\d+)?\s*(g|kg)$/i', '', $event);
    $event = strtolower(trim($event));

    return $event;
}

function scoreParseResult($r) {
    $r = trim((string)$r);

    if ($r === '') {
        return null;
    }

    if (strpos($r, ':') !== false) {
        $parts = explode(':', $r);

        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
    }

    $r = preg_replace('/[^0-9.]/', '', $r);

    return (float)$r;
}

function scoreIsTimeEvent($event) {
    $event = strtolower(trim((string)$event));

    if ($event === '') {
        return false;
    }

    if (str_contains($event, 'walk')) {
        return true;
    }

    if (str_contains($event, 'hurdle')) {
        return true;
    }

    if (str_contains($event, 'relay')) {
        return true;
    }

    if (str_contains($event, 'steeple')) {
        return true;
    }

    if (str_contains($event, 'mile')) {
        return true;
    }

    if (preg_match('/^\d+(?:\.\d+)?\s*m\b/', $event)) {
        return true;
    }

    if (preg_match('/^\d+(?:\.\d+)?\s*km\b/', $event)) {
        return true;
    }

    return false;
}

function scoreCleanHeader($h) {
    $h = trim((string)$h);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return $h;
}

function scoreFormatNumber($value, $decimals = 5) {
    $formatted = number_format((float)$value, $decimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function scoreFormatDisplayResult($eventName, $resultText, $rawValue = null) {
    $resultText = trim((string)$resultText);

    if ($resultText === '') {
        return '';
    }

    if (!scoreIsTimeEvent(scoreNormaliseEvent($eventName))) {
        return $resultText;
    }

    $rawValue = $rawValue ?? scoreParseResult($resultText);

    if ($rawValue === null || $rawValue < 60) {
        return $resultText;
    }

    $minutes = floor($rawValue / 60);
    $seconds = $rawValue - ($minutes * 60);

    return sprintf('%d:%05.2f', $minutes, $seconds);
}

function scoreFormatAdjustedDisplayResult($eventName, $adjustedValue) {
    if (!scoreIsTimeEvent(scoreNormaliseEvent($eventName))) {
        return scoreFormatNumber((float)$adjustedValue, 2);
    }

    if ($adjustedValue < 60) {
        return scoreFormatNumber((float)$adjustedValue, 2);
    }

    return scoreFormatDisplayResult($eventName, (string)$adjustedValue, (float)$adjustedValue);
}

function scoreMastersWmaEventKey($eventName) {
    if (preg_match('/^\d{2,3}m hurdles$/', $eventName)) {
        if (preg_match('/^(80|100|110)m hurdles$/', $eventName)) {
            return 'Short Hurdles';
        }

        if (preg_match('/^(200|300|400)m hurdles$/', $eventName)) {
            return 'Long Hurdles';
        }
    }

    if (preg_match('/^\d{4}m steeple$/', $eventName)) {
        return 'Steeple Chase';
    }

    return $eventName;
}

function scoreMastersRecordEventKey($eventName, $gender) {
    if (preg_match('/^(80|100|110)m hurdles$/', $eventName)) {
        return $gender === 'Male' ? '110m hurdles' : '100m hurdles';
    }

    if (preg_match('/^(200|300|400)m hurdles$/', $eventName)) {
        return '400m hurdles';
    }

    if (preg_match('/^\d{4}m steeple$/', $eventName)) {
        return '3000m steeple';
    }

    return $eventName;
}
