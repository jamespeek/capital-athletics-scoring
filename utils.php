<?php
require_once __DIR__ . '/vendor/autoload.php';

use Google\Service\Sheets as Google_Service_Sheets;

function getSheetsService() {
    static $sheets = null;

    if ($sheets !== null) {
        return $sheets;
    }

    loadEnv();

    $client = new Google_Client();
    $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
    $client->setAuthConfig(__DIR__ . '/.credentials.json');
    $sheets = new Google_Service_Sheets($client);

    return $sheets;
}

function appConfig() {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'score_base' => 800,
        'official_points' => 20,
        'excluded_events' => ['60m', '60m Hurdles', '70m', '300m', '600m', '1000m', '1 mile', '700m Walk', '1100m Walk', '2000m', '10000m'],
        'excluded_event_dates' => [
            [
                'event' => 'Pole Vault',
                'date' => '2026-03-26',
            ],
        ],
        'special_meet_names' => [
            'u20-open' => 'U20 & Opens Champs',
            'u9-18' => 'U9-U18 Champs',
        ],
        'club_name_map' => [
            'Queanbeyan Lac' => 'Queanbeyan Little Athletics',
            'Woden Thunder Athletics' => 'Woden Athletics',
            'Woden Athletics Club' => 'Woden Athletics',
            'Tuggeranong Tornadoes Little A' => 'Tuggeranong Little Athletics',
            'Tuggeranong Tornadoes Lac' => 'Tuggeranong Little Athletics',
            'Tuggeranong Lac' => 'Tuggeranong Little Athletics',
            'Belconnen Lac' => 'Belconnen Little Athletics',
            'South Canberra-Tuggeranong' => 'South Canberra Tuggeranong Athletics',
            'Act Para-Athletics Talent Squa' => 'ACT Para-Athletics Talent Squad',
            'Gungahlin Lac' => 'Gungahlin Athletics',
            'Gungahlin Athletics Club' => 'Gungahlin Athletics',
            'Murrumbateman Lac' => 'Murrumbateman Little Athletics',
            'Jindabyne Lac' => 'Jindabyne Little Athletics',
            'Calwell Little Aths' => 'Calwell Little Athletics',
            'Weston Creek Lac' => 'Weston Creek Little Athletics',
            'Lanyon Lac' => 'Lanyon Little Athletics',
            'Act Masters Athletics' => 'ACT Masters Athletics',
            'Act Masters Athletics Club' => 'ACT Masters Athletics',
            'Bega Valley Little Athletics C' => 'Bega Valley Little Athletics',
            'North Canberra-Gungahlin' => 'North Canberra-Gungahlin Athletics',
            'Cooma Athletics Incorporated' => 'Cooma Athletics',
            'Goulburn-Mulwaree' => 'Goulburn-Mulwaree Athletics',
            'New South Wales' => 'Athletics New South Wales',
            'Bega Valley Athletics Club' => 'Bega Valley Little Athletics',
            'Queensland' => 'Athletics Queensland',
            'Victoria' => 'Athletics Victoria',
            'Western Australia' => 'Athletics West',
        ],
    ];

    return $config;
}

function getData($filename, $comp) {
    $content = str_replace(["\r\n", "\r"], "\n", file_get_contents($filename));

    $meet = normaliseMeetName($filename, $comp);

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $header = fgetcsv($handle, null, ';', '"', '\\');
    $meetDate = null;
    $meetDateTimestamp = null;

    if (($header[0] ?? null) === 'H') {
        $meetDate = trim((string)($header[2] ?? ''));
        $meetDateTimestamp = $meetDate !== '' ? strtotime($meetDate) : null;
    }

    $data = [];
    while (($row = fgetcsv($handle, null, ';', '"', '\\')) !== false) {
        if (empty(array_filter($row)) || $row[0] !== 'E') continue; // skip empty lines or lines that don't start with E (event?)

        [$resultRaw, $resultUnits] = parseMeetResultData($row[10]);
        $rawLastName = (string)$row[22];
        $dobRaw = trim((string)$row[26]);
        $dobTimestamp = $dobRaw !== '' ? strtotime($dobRaw) : false;

        $result = [
            'meet' => $meet,
            'event' => normaliseEventName($row[4]),
            'result_str' => $row[10],
            'result_raw' => $resultRaw,
            'result_units' => $resultUnits,
            'wind' => (float)$row[12],
            'place' => (int)$row[14],
            'lastname' => normalisePersonNamePart(preg_replace('/\s+[T0-9\(\)-]+/', '', $rawLastName)),
            'firstname' => normalisePersonNamePart($row[23]),
            'gender' => $row[25],
            'dob_raw' => $dobRaw,
            'dob' => $dobTimestamp !== false ? $dobTimestamp : null,
            'age' => (int)$row[29],
            'club' => normaliseClubName($row[28]),
            'is_para' => preg_match('/\(\d+\)/', $rawLastName) === 1,
            'meet_date' => $meetDate,
            'meet_date_ts' => $meetDateTimestamp,
        ];

        if ($result['result_raw']) $data[] = $result;
    }

    // array_pop($data);

    fclose($handle);

    return $data;
}

function loadCompetitionFiles($comp, $clubFilter) {
    $files = glob(dirname(__FILE__) . '/data/' . $comp . '/*.csv');

    if ($clubFilter) {
        // Club-filtered view intentionally excludes champs files and only uses numbered meet files.
        $files = array_filter($files, function ($file) {
            return preg_match('/\/\d+\.csv$/', $file);
        });
    }

    natsort($files);

    return $files;
}

function loadCompetitionResults($files, $comp) {
    $resultData = [];

    foreach ($files as $file) {
        $resultData = array_merge($resultData, getData($file, $comp));
    }

    return $resultData;
}

function availableCompetitionKeys() {
    $paths = glob(dirname(__FILE__) . '/data/*', GLOB_ONLYDIR) ?: [];
    $keys = [];

    foreach ($paths as $path) {
        $basename = basename($path);

        if (preg_match('/^[a-z0-9_-]+$/i', $basename)) {
            $keys[$basename] = true;
        }
    }

    return array_keys($keys);
}

function normaliseCompetitionKey($comp) {
    $comp = trim((string)$comp);

    if ($comp === '') {
        return 'ss';
    }

    if (!preg_match('/^[a-z0-9_-]+$/i', $comp)) {
        return 'ss';
    }

    $availableComps = availableCompetitionKeys();

    if (in_array($comp, $availableComps, true)) {
        return $comp;
    }

    return 'ss';
}

function filterExcludedEvents($resultData) {
    $excludedEvents = appConfig()['excluded_events'];

    return array_filter($resultData, function ($row) use ($excludedEvents) {
        if (in_array($row['event'], $excludedEvents, true)) {
            return false;
        }

        return true;
    });
}

function normaliseMeetDate($meetDate, $meetDateTimestamp = null) {
    if (is_int($meetDateTimestamp) || is_float($meetDateTimestamp)) {
        return date('Y-m-d', (int)$meetDateTimestamp);
    }

    $meetDate = trim((string)$meetDate);
    if ($meetDate === '') {
        return null;
    }

    $parsedTimestamp = strtotime($meetDate);

    if ($parsedTimestamp === false) {
        return null;
    }

    return date('Y-m-d', $parsedTimestamp);
}

function buildMeetEventArray($resultData) {
    $meetEventMap = [];

    foreach ($resultData as $entry) {
        $meet = $entry['meet'];
        $event = $entry['event'];
        $meetDate = $entry['meet_date'] ?? null;
        $meetDateTimestamp = $entry['meet_date_ts'] ?? null;

        if (!isset($meetEventMap[$meet])) {
            $meetEventMap[$meet] = [];
        }

        if (isExcludedEventDate($event, $meetDate, $meetDateTimestamp)) {
            continue;
        }

        if (!in_array($event, $meetEventMap[$meet])) {
            $meetEventMap[$meet][] = $event;
        }
    }

    $meetEventArray = [];

    foreach ($meetEventMap as $meet => $events) {
        $meetEventArray[] = [
            'name' => $meet,
            'events' => $events,
        ];
    }

    return $meetEventArray;
}

function isExcludedEventDate($event, $meetDate, $meetDateTimestamp = null) {
    $excludedEventDates = appConfig()['excluded_event_dates'] ?? [];
    $normalisedMeetDate = normaliseMeetDate($meetDate, $meetDateTimestamp);

    foreach ($excludedEventDates as $excludedEventDate) {
        $excludedEvent = $excludedEventDate['event'] ?? null;
        $excludedDate = $excludedEventDate['date'] ?? null;

        if ($excludedEvent !== $event) {
            continue;
        }

        if ($excludedDate === $normalisedMeetDate) {
            return true;
        }
    }

    return false;
}

function filterResultsByClub($resultData, $clubFilter) {
    if (!$clubFilter) {
        return $resultData;
    }

    return array_filter($resultData, function ($row) use ($clubFilter) {
        return isset($row['club']) && $row['club'] === $clubFilter;
    });
}

function groupResultsByAthlete($resultData) {
    $athletes = [];

    foreach ($resultData as $row) {
        $displayName = trim($row['firstname'] . ' ' . $row['lastname']);
        $dobKey = buildAthleteDobIdentityKey($row['dob_raw'] ?? null, $row['dob'] ?? null);
        $key = implode('|', [$displayName, $dobKey]);

        $athletes[$key]['events'][$row['event']][] = $row;
        $athletes[$key]['display_name'] = $displayName;
        $athletes[$key]['identity_dob_key'] = $dobKey;
        $athletes[$key]['age'] = $row['age'];
        $athletes[$key]['dob'] = $row['dob'] ?? null;
        $athletes[$key]['dob_raw_values'][$row['dob_raw'] ?? ''] = true;
        $athletes[$key]['clubs'][$row['club']] = true;
        $athletes[$key]['is_para'] = $row['is_para'];
    }

    foreach ($athletes as $key => $athlete) {
        $clubNames = array_keys($athlete['clubs'] ?? []);
        natcasesort($clubNames);
        $athletes[$key]['clubs'] = array_values($clubNames);
        $athletes[$key]['club'] = $athletes[$key]['clubs'][0] ?? '';
        $athletes[$key]['dob_raw_values'] = array_values(array_filter(array_keys($athlete['dob_raw_values'] ?? [])));
    }

    return $athletes;
}

function buildAthleteDobIdentityKey($dobRaw, $dobTimestamp = null) {
    $dobRaw = trim((string)$dobRaw);

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dobRaw, $matches)) {
        $first = (int)$matches[1];
        $second = (int)$matches[2];
        $year = (int)$matches[3];

        if ($first >= 1 && $first <= 12 && $second >= 1 && $second <= 12) {
            $low = min($first, $second);
            $high = max($first, $second);
            return sprintf('%04d-%02d-%02d-amb', $year, $low, $high);
        }
    }

    if (is_int($dobTimestamp) || is_float($dobTimestamp) || (is_string($dobTimestamp) && is_numeric($dobTimestamp))) {
        return date('Y-m-d', (int)$dobTimestamp);
    }

    return 'unknown-dob';
}

function sortAthletesByScore($athletes) {
    uasort($athletes, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $athletes;
}

function athleteIsEligibleForMeet($athlete, $meetName) {
    $specialMeetNames = appConfig()['special_meet_names'];
    $age = (int)($athlete['age'] ?? 0);

    if ($meetName === $specialMeetNames['u9-18'] && $age > 17) {
        return false;
    }

    if ($meetName === $specialMeetNames['u20-open'] && $age < 18) {
        return false;
    }

    return true;
}

function buildAthleteMeetParticipationData($athlete, $meetEventArray) {
    $attendedMeetNames = [];

    foreach ($athlete['events'] as $eventResults) {
        foreach ($eventResults as $eventResult) {
            $meetName = $eventResult['meet'] ?? null;

            if (!$eventResult['result_raw'] || $meetName === null) {
                continue;
            }

            $attendedMeetNames[$meetName] = true;
        }
    }

    $eligibleMeetNames = [];

    foreach ($meetEventArray as $meet) {
        $meetName = $meet['name'] ?? null;

        if ($meetName === null) {
            continue;
        }

        if (isset($attendedMeetNames[$meetName]) || athleteIsEligibleForMeet($athlete, $meetName)) {
            $eligibleMeetNames[$meetName] = true;
        }
    }

    $eligibleMeetCount = count($eligibleMeetNames);
    $attendedMeetCount = count($attendedMeetNames);
    $meetPf = $eligibleMeetCount > 0 ? $attendedMeetCount / $eligibleMeetCount : 0;

    return [
        'attended_meet_count' => $attendedMeetCount,
        'eligible_meet_count' => $eligibleMeetCount,
        'meet_pf' => $meetPf,
    ];
}

function buildAthleteScoreBreakdown($athlete, $meetEventArray, $clubFilteredView = false) {
    $eventSummaries = [];
    $athleteTotals = [];

    foreach ($athlete['events'] as $eventName => $eventResults) {
        $eventSummary = buildAthleteEventSummary($athlete, $eventName, $eventResults, $meetEventArray);
        $eventSummaries[] = $eventSummary;
        $athleteTotals[] = $eventSummary['final_score'];
    }

    if (!$clubFilteredView) {
        rsort($athleteTotals);
        $athleteTotals = array_slice($athleteTotals, 0, 4);
    }

    return [
        'event_summaries' => $eventSummaries,
        'totals' => $athleteTotals,
        'total' => array_sum($athleteTotals),
    ];
}

function buildClubScopedAthlete($athlete, $clubName) {
    $clubAthlete = $athlete;
    $clubAthlete['events'] = [];
    $clubAthlete['clubs'] = [$clubName];
    $clubAthlete['club'] = $clubName;

    foreach ($athlete['events'] as $eventName => $eventResults) {
        $clubEventResults = array_values(array_filter($eventResults, function ($eventResult) use ($clubName) {
            return (($eventResult['club'] ?? null) === $clubName);
        }));

        if ($clubEventResults) {
            $clubAthlete['events'][$eventName] = $clubEventResults;
        }
    }

    return $clubAthlete;
}

function buildClubSummaries($clubs, $clubsData, $meetEventArray) {
    $officialPoints = appConfig()['official_points'];

    foreach ($clubs as $clubName => $clubObj) {
        $clubs[$clubName]['size'] = $clubsData[$clubName]['size'];
        $clubs[$clubName]['officials'] = 0;

        foreach ($meetEventArray as $i => $meet) {
            $officialCount = $clubsData[$clubName]['officials'][$i] ?? 0;
            $clubs[$clubName]['officials'] += $officialCount * $officialPoints;
        }

        $clubs[$clubName]['cpf_old'] = calcClubParticipationFactor(count($clubObj['athletes']), $clubs[$clubName]['size']);
        $clubs[$clubName]['cpf'] = calcAverageAthleteMeetParticipationFactor($clubObj['athletes']);
        $clubs[$clubName]['adj'] = calcClubScoreAdjustment($clubObj['score'], $clubs[$clubName]['cpf']);
        $clubs[$clubName]['total'] = calcClubAdjustedTotal($clubObj['score'], $clubs[$clubName]['cpf'], $clubs[$clubName]['officials']);
    }

    return sortClubsByTotalScore($clubs);
}

function buildAthleteSummaries($athletes, $clubsData, $meetEventArray, $clubFilter) {
    $athleteSummaries = [];
    $clubs = [];
    $potentialRecords = [];

    foreach ($athletes as $athleteKey => $athlete) {
        $athleteName = $athlete['display_name'] ?? $athleteKey;
        $meetParticipation = buildAthleteMeetParticipationData($athlete, $meetEventArray);
        $scoreBreakdown = buildAthleteScoreBreakdown($athlete, $meetEventArray, $clubFilter);
        $eventSummaries = $scoreBreakdown['event_summaries'];
        $athleteTotals = $scoreBreakdown['totals'];
        $athleteTotal = $scoreBreakdown['total'];

        foreach ($athlete['events'] as $eventName => $eventResults) {
            $eventSummary = null;

            foreach ($eventSummaries as $candidateSummary) {
                if (($candidateSummary['event'] ?? null) === $eventName) {
                    $eventSummary = $candidateSummary;
                    break;
                }
            }

            if ($eventSummary === null) {
                continue;
            }

            foreach ($eventSummary['meets'] as $meetSummary) {
                $scoreData = $meetSummary['score_data'] ?? null;
                $matchedEventResult = null;

                if ($scoreData === null || empty($scoreData['potential_record'])) {
                    continue;
                }

                if (!empty($athlete['is_para'])) {
                    continue;
                }

                foreach ($eventResults as $eventResult) {
                    if (($eventResult['meet'] ?? null) === $meetSummary['name']) {
                        $matchedEventResult = $eventResult;
                        break;
                    }
                }

                $matchedClub = $matchedEventResult['club'] ?? ($athlete['clubs'][0] ?? '');

                if (shouldIgnorePotentialRecord($matchedClub, $scoreData['meta']['record']['source'] ?? null)) {
                    continue;
                }

                $potentialRecords[] = [
                    'athlete' => $athleteName,
                    'age' => $athlete['age'],
                    'gender' => $matchedEventResult['gender'] ?? $eventResults[0]['gender'] ?? null,
                    'club' => $matchedClub,
                    'event' => $eventName,
                    'meet' => $meetSummary['name'],
                    'meet_date' => $matchedEventResult['meet_date'] ?? null,
                    'meet_date_ts' => $matchedEventResult['meet_date_ts'] ?? null,
                    'meet_date_iso' => !empty($matchedEventResult['meet_date_ts']) ? date('Y-m-d', (int)$matchedEventResult['meet_date_ts']) : '',
                    'result' => $meetSummary['result_str'],
                    'weight' => $scoreData['meta']['record']['weight'] ?? null,
                    'record_result' => $scoreData['meta']['record']['result'] ?? null,
                    'record_name' => $scoreData['meta']['record']['name'] ?? null,
                    'record_age' => $scoreData['meta']['display']['matched_age'] ?? null,
                    'record_source' => $scoreData['meta']['record']['source'] ?? null,
                    'percentage' => $scoreData['meta']['adjustment']['percentage'] ?? null,
                ];
            }
        }

        $athletes[$athleteKey]['score'] = $athleteTotal;
        $athletes[$athleteKey]['attended_meet_count'] = $meetParticipation['attended_meet_count'];
        $athletes[$athleteKey]['eligible_meet_count'] = $meetParticipation['eligible_meet_count'];
        $athletes[$athleteKey]['meet_pf'] = $meetParticipation['meet_pf'];
        $athleteSummaries[] = [
            'name' => $athleteName,
            'athlete' => $athletes[$athleteKey],
            'event_summaries' => $eventSummaries,
            'totals' => $athleteTotals,
            'total' => $athleteTotal,
        ];

        foreach ($athlete['clubs'] as $athleteClub) {
            if (!isset($clubsData[$athleteClub])) {
                continue;
            }

            $clubAthlete = buildClubScopedAthlete($athletes[$athleteKey], $athleteClub);
            if (empty($clubAthlete['events'])) {
                continue;
            }

            $clubScoreBreakdown = buildAthleteScoreBreakdown($clubAthlete, $meetEventArray, false);
            $clubMeetParticipation = buildAthleteMeetParticipationData($clubAthlete, $meetEventArray);

            if (!isset($clubs[$athleteClub])) {
                $clubs[$athleteClub] = [
                    'score' => 0,
                    'athletes' => [],
                ];
            }

            $clubAthlete['score'] = $clubScoreBreakdown['total'];
            $clubAthlete['attended_meet_count'] = $clubMeetParticipation['attended_meet_count'];
            $clubAthlete['eligible_meet_count'] = $clubMeetParticipation['eligible_meet_count'];
            $clubAthlete['meet_pf'] = $clubMeetParticipation['meet_pf'];

            $clubs[$athleteClub]['score'] += $clubScoreBreakdown['total'];
            $clubs[$athleteClub]['athletes'][] = $clubAthlete;
        }
    }

    usort($potentialRecords, function ($a, $b) {
        $sourceCompare = strcasecmp((string)($a['record_source'] ?? ''), (string)($b['record_source'] ?? ''));
        if ($sourceCompare !== 0) {
            return $sourceCompare;
        }

        $genderCompare = strcasecmp((string)($a['gender'] ?? ''), (string)($b['gender'] ?? ''));
        if ($genderCompare !== 0) {
            return $genderCompare;
        }

        $ageCompare = scorePotentialRecordAgeSortKey($a['record_age'] ?? null) <=> scorePotentialRecordAgeSortKey($b['record_age'] ?? null);
        if ($ageCompare !== 0) {
            return $ageCompare;
        }

        $eventCompare = strcasecmp((string)($a['event'] ?? ''), (string)($b['event'] ?? ''));
        if ($eventCompare !== 0) {
            return $eventCompare;
        }

        $resultCompare = ((float)($a['percentage'] ?? 0)) <=> ((float)($b['percentage'] ?? 0));
        if ($resultCompare !== 0) {
            return $resultCompare;
        }

        return strcasecmp((string)($a['athlete'] ?? ''), (string)($b['athlete'] ?? ''));
    });

    return [
        'athletes' => $athletes,
        'athlete_summaries' => $athleteSummaries,
        'clubs' => $clubs,
        'potential_records' => $potentialRecords,
    ];
}

function collectUnknownDobAthleteNames($athletes) {
    $names = [];

    foreach ($athletes as $athlete) {
        if (($athlete['identity_dob_key'] ?? null) !== 'unknown-dob') {
            continue;
        }

        $displayName = trim((string)($athlete['display_name'] ?? ''));
        if ($displayName === '') {
            continue;
        }

        $names[$displayName] = true;
    }

    $names = array_keys($names);
    natcasesort($names);

    return array_values($names);
}

function shouldIgnorePotentialRecord($clubName, $recordSource) {
    if ($recordSource !== 'act-best.csv') {
        return false;
    }

    $excludedOrigins = [
        'Victoria',
        'New South Wales',
        'Queensland',
        'South Australia',
        'Tasmania',
        'Western Australia',
        'Northern Territory',
        'International',
    ];

    foreach ($excludedOrigins as $origin) {
        if (stripos((string)$clubName, $origin) !== false) {
            return true;
        }
    }

    return false;
}

function scorePotentialRecordAgeSortKey($recordAge) {
    $recordAge = trim((string)$recordAge);

    if ($recordAge === '') {
        return 0;
    }

    if (strcasecmp($recordAge, 'Open') === 0) {
        return OPEN_AGE_SENTINEL;
    }

    if (preg_match('/^U(\d+)$/i', $recordAge, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function sortClubsByTotalScore($clubs) {
    uasort($clubs, function ($a, $b) {
        return $b['total'] <=> $a['total'];
    });

    return $clubs;
}

function normaliseMeetName($filename, $comp) {
    $basename = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $basenameWithoutPrefix = preg_replace('/^\d+[-_]?/', '', $basename);
    $specialMeetNames = appConfig()['special_meet_names'];

    if (isset($specialMeetNames[$basenameWithoutPrefix])) {
        return $specialMeetNames[$basenameWithoutPrefix];
    }

    $meet = str_replace(['/', '-'], ' ', $basename);
    $meet = preg_replace('/([0-9]+)/', '#$1', $meet);

    return strtoupper($comp) . ' ' . ucwords($meet);
}

function normaliseClubName($clubName) {
    $clubName = trim((string)$clubName);
    $clubNameMap = appConfig()['club_name_map'];

    return $clubNameMap[$clubName] ?? $clubName;
}

function normalisePersonNamePart($namePart) {
    $namePart = trim((string)$namePart);

    if ($namePart === '' || preg_match('/[a-z]/', $namePart)) {
        return $namePart;
    }

    return preg_replace_callback('/[A-Za-z]+(?:[\'-][A-Za-z]+)*/', function ($matches) {
        $segment = strtolower($matches[0]);

        return preg_replace_callback('/(^|[\'-])[a-z]/', function ($partMatches) {
            return strtoupper($partMatches[0]);
        }, $segment);
    }, $namePart);
}

function normaliseEventName($eventName) {
    $patterns = [
        '/^1500S$/'   => '1500m Steeple',
        '/^2000S$/'   => '2000m Steeple',
        '/^3000S$/'   => '3000m Steeple',
        '/^60H$/'     => '60m Hurdles',
        '/^80H$/'     => '80m Hurdles',
        '/^90H$/'     => '90m Hurdles',
        '/^100H$/'    => '100m Hurdles',
        '/^110H$/'    => '110m Hurdles',
        '/^200H$/'    => '200m Hurdles',
        '/^300H$/'    => '300m Hurdles',
        '/^400H$/'    => '400m Hurdles',
        '/^60$/'      => '60m',
        '/^70$/'      => '70m',
        '/^100$/'     => '100m',
        '/^200$/'     => '200m',
        '/^300$/'     => '300m',
        '/^400$/'     => '400m',
        '/^600$/'     => '600m',
        '/^800$/'     => '800m',
        '/^1000$/'    => '1000m',
        '/^1500$/'    => '1500m',
        '/^2000$/'    => '2000m',
        '/^3000$/'    => '3000m',
        '/^5000$/'    => '5000m',
        '/^10000$/'   => '10000m',
        '/^700W$/'    => '700m Walk',
        '/^1100W$/'   => '1100m Walk',
        '/^1500W$/'   => '1500m Walk',
        '/^3000W$/'   => '3000m Walk',
        '/^5000W$/'   => '5000m Walk',
        '/^1MILE$/'   => '1 mile',
        '/^LJ$/i'     => 'Long Jump',
        '/^TJ$/i'     => 'Triple Jump',
        '/^HJ$/i'     => 'High Jump',
        '/^PV$/i'     => 'Pole Vault',
        '/^SP$/i'     => 'Shot Put',
        '/^HT$/i'     => 'Hammer',
        '/^DT$/i'     => 'Discus',
        '/^JT$/i'     => 'Javelin',
    ];

    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $eventName)) {
            $eventName = $replacement;
            break;
        }
    }

    return $eventName;
}

function parseMeetResultData($result_str) {
    if (strstr($result_str, 'm')) {
        // distance in metres
        return [(float)$result_str, 'm'];
    } elseif (strstr($result_str, ':')) {
        // time in minutes
        $parts = explode(':', $result_str);
        return [(int)$parts[0] * 60 + (float)$parts[1], 's'];
    } elseif (strstr($result_str, 's') || strstr($result_str, '.')) {
        // time in seconds
        return [(float)$result_str, 's'];
    }

    return [null, null];
}

function calcClubParticipationFactor($athletes, $size) {
    if ($size <= 0) {
        return 0.0;
    }

    return $athletes / $size;
}

function calcAverageAthleteMeetParticipationFactor($athletes) {
    $athleteCount = count($athletes);

    if ($athleteCount <= 0) {
        return 0.0;
    }

    $meetPfTotal = 0;

    foreach ($athletes as $athlete) {
        $meetPfTotal += (float)($athlete['meet_pf'] ?? 0);
    }

    return $meetPfTotal / $athleteCount;
}

function calcClubScoreAdjustment($score, $cpf) {
    return $score * $cpf;
}

function calcClubAdjustedTotal($score, $cpf, $officials) {
    return calcClubScoreAdjustment($score, $cpf) + $officials;
}

function loadEnv() {
    $lines = file(dirname(__FILE__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        list($name, $value) = array_map('trim', explode('=', $line, 2));

        $value = trim($value, "\"'");

        $_ENV[$name] = $value;
    }
}

// load meet data from google sheet
function loadMeetData() {
    $sheets = getSheetsService();
    $response = $sheets->spreadsheets_values->get($_ENV['GOOGLE_SPREADSHEET_ID'], 'Meets');
    $values = $response->getValues();

    if (empty($values) || count($values) < 2) {
        return [];
    }

    // determine number of meets (based on header row)
    $header = $values[0];
    $numMeets = max(0, count($header) - 1);
    $meetData = array_fill(0, $numMeets, []);

    // fill per-meet structure
    for ($r = 1; $r < count($values); $r++) {
        $eventName = isset($values[$r][0]) ? trim((string)$values[$r][0]) : '';
        if ($eventName === '') {
            continue;
        }

        for ($c = 1; $c < count($values[$r]); $c++) {
            $val = strtolower(trim((string)$values[$r][$c]));
            $isTrue = in_array($val, ['true', '1', 'yes', 'y', 'x'], true);
            $meetData[$c - 1][$eventName] = $isTrue;
        }
    }

    return $meetData;
}

// load clubs data from google sheet
function loadClubData() {
    $sheets = getSheetsService();
    $response = $sheets->spreadsheets_values->get($_ENV['GOOGLE_SPREADSHEET_ID'], 'Clubs');
    $values = $response->getValues();

    if (empty($values) || count($values) < 2) {
        return []; // nothing or only header row
    }

    $clubData = [];

    // skip the first row (header)
    for ($r = 1; $r < count($values); $r++) {
        $row = $values[$r];

        // safely read columns with defaults for missing cells
        $clubName = isset($row[0]) ? trim((string)$row[0]) : '';
        if ($clubName === '') {
            continue; // skip rows without a club name
        }

        // column B: size (int)
        $sizeRaw = $row[1] ?? null;
        $size = is_numeric($sizeRaw) ? (int)$sizeRaw : 0;

        // columns C onwards: officials (ints)
        $officials = [];
        for ($c = 2; $c < count($row); $c++) {
            $val = $row[$c];
            if ($val === '' || $val === null) {
                // ignore blanks
                continue;
            }
            if (is_numeric($val)) {
                $officials[] = (int)$val;
            } else {
                $officials[] = 0;
            }
        }

        $clubData[$clubName] = [
            'size' => $size,
            'officials' => $officials,
        ];
    }

    return $clubData;
}
