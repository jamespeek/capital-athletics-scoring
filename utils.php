<?php
require_once __DIR__ . '/vendor/autoload.php';

use Google\Service\Sheets as Google_Service_Sheets;

$client = new Google_Client();
$client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
$client->setAuthConfig(__DIR__ . '/.credentials.json');
$sheets = new Google_Service_Sheets($client);

$iaafData = json_decode(file_get_contents(dirname(__FILE__) . '/data/reference/iaaf.json'), true);
$wmaData = [
    'M' => json_decode(file_get_contents(dirname(__FILE__) . '/data/reference/wma-men.json'), true),
    'F' => json_decode(file_get_contents(dirname(__FILE__) . '/data/reference/wma-women.json'), true)
];
$juniorWrData = [
    'M' => json_decode(file_get_contents(dirname(__FILE__) . '/data/reference/wr-boys.json'), true),
    // 'F' => json_decode(file_get_contents(dirname(__FILE__) . '/data/reference/wr-girls.json'), true)
];

loadEnv();

function getData($filename, $comp) {
	$content = str_replace(["\r\n", "\r"], "\n", file_get_contents($filename));

    $meet = str_replace('/', ' ', basename($filename));
    $meet = str_replace('.csv', '', $meet);
    $meet = preg_replace('/([0-9]+)/', '#$1', $meet);
    $meet = strtoupper($comp) . ' ' . ucwords($meet);

	$handle = fopen('php://memory', 'r+');
	fwrite($handle, $content);
	rewind($handle);

	$header = fgetcsv($handle, null, ';');

	$data = [];
	while (($row = fgetcsv($handle, null, ';')) !== false) {
		if (empty(array_filter($row)) || $row[0] != 'E') continue; // skip empty lines or lines that don't start with E (event?)

		$result = [
            'meet' => $meet,
            'event' => normaliseEventName($row[4]),
            'result_str' => $row[10],
            'result_raw' => parseResult($row[10])[0],
            'result_units' => parseResult($row[10])[1],
            'wind' => (float)$row[12],
            'place' => (int)$row[14],
            'lastname' => preg_replace('/\s+[T0-9\(\)-]+/', '', $row[22]),
            'firstname' => $row[23],
            'gender' => $row[25],
            'dob' => strtotime($row[26]),
            'age' => (int)$row[29],
            'club' => str_replace(
                    ['Queanbeyan Lac', 'Woden Thunder Athletics', 'Tuggeranong Tornadoes Little A', 'Tuggeranong Tornadoes Lac', 'Tuggeranong Lac', 'Belconnen Lac', 'South Canberra-Tuggeranong', 'Act Para-Athletics Talent Squa', 'Gungahlin Lac', 'Gungahlin Athletics Club', 'Murrumbateman Lac', 'Jindabyne Lac', 'Calwell Little Aths', 'Weston Creek Lac', 'Lanyon Lac', 'Act Masters Athletics', 'Act Masters Athletics Club', 'Bega Valley Little Athletics C', 'North Canberra-Gungahlin', 'Cooma Athletics Incorporated'],
                    ['Queanbeyan Little Athletics', 'Woden Athletics', 'Tuggeranong Little Athletics', 'Tuggeranong Little Athletics', 'Tuggeranong Little Athletics', 'Belconnen Little Athletics', 'South Canberra Tuggeranong Athletics', 'ACT Para-Athletics Talent Squad', 'Gungahlin Athletics', 'Gungahlin Athletics', 'Murrumbateman Little Athletics', 'Jindabyne Little Athletics', 'Calwell Little Athletics', 'Weston Creek Little Athletics', 'Lanyon Little Athletics', 'ACT Masters Athletics', 'ACT Masters Athletics', 'Bega Valley Little Athletics', 'North Canberra-Gungahlin Athletics', '	Cooma Athletics'],
                    $row[28]
                ),
        ];

        if ($result['result_raw']) $data[] = $result;
	}

    // array_pop($data);

	fclose($handle);

	return $data;
}

function normaliseEventName ($eventName) {
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
        '/^800$/'     => '800m',
        '/^1000$/'    => '1000m',
        '/^1500$/'    => '1500m',
        '/^2000$/'    => '2000m',
        '/^3000$/'    => '3000m',
        '/^5000$/'    => '5000m',
        '/^1500W$/'  => '1500m Walk',
        '/^3000W$/'  => '3000m Walk',
        '/^5000W$/'  => '5000m Walk',
        '/^1MILE$/'  => '1 mile',
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

function parseResult ($result_str) {
    if (strstr($result_str, 'm')) {
        // distance in metres
        return [(float)$result_str, 'm'];
    } else if (strstr($result_str, ':')) {
        // time in minutes
        $parts = explode(':', $result_str);
        return [(int)$parts[0] * 60 + (float)$parts[1], 's'];
    } else if (strstr($result_str, 's') || strstr($result_str, '.')) {
        // time in seconds
        return [(float)$result_str, 's'];
    } else {
        return [null, null];
    }
}

function calcClubParticipationaFactor ($athletes, $size) {
    return number_format($athletes / $size, 3);
}

function calcClubAdjustedTotal ($score, $cpf, $officials) {
    return $score * $cpf + $officials;
}

function calcScore_old ($age, $gender, $event, $result, $units) {
    global $iaafData, $wmaData, $juniorWrData;

    if ($age >= 20 && $age < 35) {
        $age = 'Open';
    } else if ($age > 34) {
        // masters
        // we convert the masters time/distance into an open value
        $factor = $wmaData[$gender][$age][$event] ?? 1;

        $result = $result * $factor;

        $age = 'Open';
    } else {
        // U11-U20
        // if (isset($iaafData[$gender][$age][$event]) && isset($wmaData[$gender]['Standard'][$event]) && isset($juniorWrData[$gender][$age][$event])) {
        //     $tmp_result = $result * $wmaData[$gender]['Standard'][$event] / $juniorWrData[$gender][$age][$event];
        //     $tmp_vals = $iaafData[$gender]['Open'][$event] ?? null;

        //     if (in_array($event, ['Long Jump', 'Triple Jump', 'High Jump', 'Pole Vault'])) {
        //         $tmp_result *= 100; // into cm
        //     }

        //     if ($tmp_vals != null) {
        //         echo '<span style="background:pink">';
        //         echo $units == 'm'
        //             ? round($tmp_vals['a'] * pow($tmp_result - $tmp_vals['b'], $tmp_vals['c']))
        //             : round($tmp_vals['a'] * pow($tmp_vals['b'] - $tmp_result, $tmp_vals['c']));

        //         echo ' pts</span> ';
        //     }
        // }
    }

    // iaaf
    $vals = $iaafData[$gender][$age][$event] ?? null;

    if ($age < 20 && $vals == null) {
        // if we didn't find the abc factor values in the iaaf table for this age/event and the athlete is under 20
        // then look against the WR data and convert to an open value like we did for masters
        if (isset($wmaData[$gender]['Standard'][$event]) && isset($juniorWrData[$gender][$age][$event])) {
            $result = $result * $wmaData[$gender]['Standard'][$event] / $juniorWrData[$gender][$age][$event];
            $vals = $iaafData[$gender]['Open'][$event] ?? null;
        }
    }

    // if ($age < 17 && $vals == null) {
    //     // if the event doesn't exist in the age they are, check up one
    //     $vals = $iaafData[$gender][$age+1][$event] ?? null;
    // }

    // if ($age < 16 && $vals == null) {
    //     // if the event doesn't exist in the age they are, check up two
    //     $vals = $iaafData[$gender][$age+2][$event] ?? null;
    // }

    // if ($age < 18 && $vals == null) {
    //     // if the event doesn't exist in the age they are, check down one
    //     $vals = $iaafData[$gender][$age-1][$event] ?? null;
    // }

    if ($vals == null) return '?';

    if (in_array($event, ['Long Jump', 'Triple Jump', 'High Jump', 'Pole Vault'])) {
        $result *= 100; // into cm
    }

    return $units == 'm'
        ? round($vals['a'] * pow($result - $vals['b'], $vals['c']))
        : round($vals['a'] * pow($vals['b'] - $result, $vals['c']));
}

function loadEnv () {
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
    global $sheets;

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
    global $sheets;

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