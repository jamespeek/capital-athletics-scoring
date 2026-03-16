<?php

/**
 * ACT Record Scoring Utility
 *
 * Usage:
 *   require_once __DIR__ . '/act_score.php';
 *   $scoreData = calcScore(17, 'Men', 'Pole Vault', 3.50);
 *   echo $scoreData['score'];
 *
 * Behaviour:
 *   - Returns score data including the numeric score and supporting meta data
 *   - If no suitable record is found the score is null
 *   - CSV parsed once per request and cached for speed
 *
 * CSV expected at:
 *   data/reference/combined-act.csv
 */

function calcScore($age, $gender, $event, $result) {
    $records = actScoreLoadRecords();
    $wmaData = actScoreLoadWmaData();

    $ageRaw = trim((string)$age);
    $genderRaw = trim((string)$gender);
    $eventRaw = trim((string)$event);
    $resultRaw = trim((string)$result);

    $actualAge = actScoreNormalizeAge($ageRaw);
    $gender = actScoreNormalizeGender($genderRaw);
    $event = actScoreNormalizeEvent($eventRaw);
    $rawValue = is_numeric($result) ? (float)$result : actScoreParseResult($resultRaw);
    $scoreData = [
        'score' => null,
        'status' => null,
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
        ],
    ];

    if (!$records || $actualAge === null || $gender === '' || $event === '' || $rawValue === null || $rawValue <= 0) {
        $scoreData['status'] = 'Score unavailable';
        return $scoreData;
    }

    $lookupAge = $actualAge;
    $factor = 1.0;
    $adjustedValue = $rawValue;

    if ($actualAge !== 999 && $actualAge >= 35) {
        $wmaGender = $gender === 'Male' ? 'M' : 'F';
        $factor = (float)($wmaData[$wmaGender][$actualAge][$event] ?? 1);
        $adjustedValue = $rawValue * $factor;
        $lookupAge = 999; // compare masters to Open
    } elseif ($actualAge !== 999) {
        // underage lookup is one year up
        $lookupAge++;
    }

    $scoreData['meta']['adjustment']['wma_factor'] = $actualAge >= 35 && $actualAge !== 999 ? $factor : null;
    $scoreData['meta']['adjustment']['adjusted_result'] = $adjustedValue;
    $scoreData['meta']['lookup']['age'] = $lookupAge;

    $record = actScoreFindRecord($records, $lookupAge, $gender, $event);

    if (!$record) {
        $scoreData['status'] = 'No matching ACT record found';
        return $scoreData;
    }

    $recordValue = $record['_result'] ?? null;
    $scoreData['meta']['lookup']['matched_age'] = $record['Age'] ?? null;
    $scoreData['meta']['record']['result'] = $record['Result'] ?? null;
    $scoreData['meta']['record']['weight'] = $record['Weight'] ?? null;
    $scoreData['meta']['record']['name'] = $record['Name'] ?? null;
    $scoreData['meta']['record']['source'] = $record['Source'] ?? null;

    if (!$recordValue || $recordValue <= 0) {
        $scoreData['status'] = 'Matched record was invalid';
        return $scoreData;
    }

    $timeEvent = actScoreIsTimeEvent($event);

    if ($timeEvent) {
        $percentage = $recordValue / $adjustedValue;
    } else {
        $percentage = $adjustedValue / $recordValue;
    }

    $score = round($percentage * appConfig()['score_base'], 0);
    $scoreData['score'] = (float)$score;
    $scoreData['status'] = 'OK';
    $scoreData['meta']['adjustment']['percentage'] = $percentage;

    return $scoreData;
}

function buildAthleteEventSummary($athlete, $event, $results, $meetEventArray) {
    $specialMeetNames = appConfig()['special_meet_names'];
    $meetSummaries = [];
    $bestScore = 0;
    $offered = 0;
    $entered = 0;

    foreach ($meetEventArray as $meet) {
        if (!in_array($event, $meet['events'])) {
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

        $meetSummary = [
            'name' => $meetName,
            'result_str' => null,
            'score_data' => null,
            'participation_score' => 0,
            'has_missing_record' => false,
        ];

        foreach ($results as $result) {
            if (!$result['result_raw'] || $result['meet'] != $meetName) {
                continue;
            }

            $meetSummary['result_str'] = $result['result_str'];
            $meetSummary['score_data'] = calcScore($result['age'], $result['gender'], $result['event'], $result['result_raw']);
            $meetSummary['has_missing_record'] = $meetSummary['score_data']['score'] === null;

            if ($meetSummary['score_data']['score'] !== null && $meetSummary['score_data']['score'] > $bestScore) {
                $bestScore = $meetSummary['score_data']['score'];
            }

            $entered++;
            break;
        }

        $meetSummary['participation_score'] = $offered > 0 ? $entered / $offered : 0;
        $meetSummaries[] = $meetSummary;
    }

    $participationScore = $offered > 0 ? $entered / $offered : 0;
    $finalScore = round($bestScore * $participationScore);

    return [
        'event' => $event,
        'meets' => $meetSummaries,
        'best_score' => $bestScore,
        'participation_score' => $participationScore,
        'final_score' => $finalScore,
    ];
}

function actScoreLoadRecords() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $file = __DIR__ . '/data/reference/combined-act.csv';

    if (!is_file($file)) {
        return null;
    }

    $fh = fopen($file, 'r');

    $headers = fgetcsv($fh);
    if (!$headers) {
        return null;
    }

    $headers = array_map('actScoreCleanHeader', $headers);

    $index = [];
    $ages = [];

    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < count($headers)) {
            $row = array_pad($row, count($headers), '');
        }

        $r = array_combine($headers, $row);
        if (!$r) {
            continue;
        }

        $gender = actScoreNormalizeGender($r['Gender'] ?? '');
        $event = actScoreNormalizeEvent($r['Event'] ?? '');
        $age = actScoreNormalizeAge($r['Age'] ?? '');
        $value = actScoreParseResult($r['Result'] ?? '');

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
            $timeEvent = actScoreIsTimeEvent($event);

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

function actScoreLoadWmaData() {
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

function actScoreFindRecord($records, $age, $gender, $event) {
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

function actScoreNormalizeAge($age) {
    $age = trim((string)$age);

    if ($age === '') {
        return null;
    }

    if (preg_match('/^U?(\d+)$/', $age, $m)) {
        return (int)$m[1];
    }

    if (strcasecmp($age, 'Open') === 0) {
        return 999;
    }

    return null;
}

function actScoreAgeLabel($age) {
    $age = (string)$age;

    if (strcasecmp($age, 'Open') === 0 || (int)$age === 999) {
        return 'Open';
    }

    return 'U' . $age;
}

function actScoreNormalizeGender($g) {
    $g = strtolower(trim((string)$g));

    if (in_array($g, ['m', 'male', 'men', 'boy', 'boys'], true)) {
        return 'Male';
    }

    if (in_array($g, ['f', 'female', 'women', 'girl', 'girls'], true)) {
        return 'Female';
    }

    return ucfirst($g);
}

function actScoreNormalizeEvent($event) {
    $event = trim((string)$event);
    $event = preg_replace('/\s+/', ' ', $event);
    $event = preg_replace('/\s+\d+(?:\.\d+)?\s*(g|kg)$/i', '', $event);
    $event = strtolower(trim($event));

    return $event;
}

function actScoreParseResult($r) {
    $r = trim((string)$r);

    if ($r === '') {
        return null;
    }

    if (strpos($r, ':') !== false) {
        $parts = explode(':', $r);

        if (count($parts) == 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) == 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
    }

    $r = preg_replace('/[^0-9.]/', '', $r);

    return (float)$r;
}

function actScoreIsTimeEvent($event) {
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

function actScoreCleanHeader($h) {
    $h = trim((string)$h);
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return $h;
}

function actScoreFormatNumber($value, $decimals = 5) {
    $formatted = number_format((float)$value, $decimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}
