<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corroboree Athletics - senior athletics scoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
</head>
<body>

<div class="container">
    
<?php

$verbose = $_GET['verbose'] ?? true;
$clubFilter = $_GET['club'] ?? false;
$comp = $_GET['comp'] ?? 'hn';

include_once 'utils.php';

$clubsData = loadClubData();

$files = glob(dirname(__FILE__) . '/data/' . $comp . '/*.csv');
natsort($files);

// load the data
$resultData = [];
foreach ($files as $file) {
    $resultData = array_merge($resultData, getData($file, $comp));
}

// create a lookup map of meets and the events offered
$meetEventMap = [];

foreach ($resultData as $entry) {
    $meet = $entry['meet'];
    $event = $entry['event'];
    
    // initialize array for meet if it doesn't exist
    if (!isset($meetEventMap[$meet])) {
        $meetEventMap[$meet] = [];
    }
    
    // add event if not already in the list
    if (!in_array($event, $meetEventMap[$meet])) {
        $meetEventMap[$meet][] = $event;
    }
}

// TODO rework this to use loadMeetData()
$meetEventArray = [];
foreach ($meetEventMap as $meet => $events) {
    $meetEventArray[] = [
        'name' => $meet,
        'events' => $events
    ];
}

// if this is just for Corroboree filter out the other results
if ($clubFilter) {
    $resultData = array_filter($resultData, function ($row) {
        global $clubFilter;
        return isset($row['club']) && $row['club'] == $clubFilter;
    });
}

// sort the data by name then meet
usort($resultData, function($a, $b) {
    return strcasecmp($a['lastname'] . ' ' . $a['firstname'], $b['lastname'] . ' ' . $b['firstname']);
    return strcasecmp($a['meet'], $b['meet']);
});

$athletes = [];

// group the data by athlete
foreach ($resultData as $row) {
    $key = $row['firstname'] . ' ' . $row['lastname'];

    $athletes[$key]['events'][$row['event']][] = $row;
    $athletes[$key]['club'] = $row['club'];
    $athletes[$key]['age'] = $row['age'];
}

// start writing to the buffer so we can conditionally show the output
ob_start();

$clubs = [];

// loop through the athletes
foreach ($athletes as $athlete_name => $athlete) {
    echo '<strong>' . $athlete_name . ' (' . $athlete['age'] . ')</strong><ul>';

    $athlete_totals = [];

    // loop through the athletes events
    foreach ($athlete['events'] as $event => $results) {
        echo '<li>';
        echo $event;
        echo '<ul>';

        $event_total = 0;

        $idx = 0;
        $offered = 0;
        $entered = 0;

        for ($idx; $idx < count($meetEventArray); $idx++) {
            if (!in_array($event, $meetEventArray[$idx]['events'])) continue;

            echo '<li>';

            $offered++;

            echo $meetEventArray[$idx]['name'] . ': ';

            foreach ($results as $result) {
                if ($result['result_raw'] && $result['meet'] == $meetEventArray[$idx]['name']) {
                    // get point score based on age, gender, event, result, type of event
                    $points_score = calcScore($result['age'], $result['gender'], $result['event'], $result['result_raw'], $result['result_units']);

                    echo $result['result_str'] . ' = ' . $points_score . ' pts';

                    if ($points_score != '?') {
                        // if the score is bigger than our running max then update it
                        if ($points_score > $event_total) $event_total = $points_score;
                    }

                    if ($points_score == '?') {
                        echo ' <span style="margin-left:10px; background:gold; border-radius: 3px; padding: 1px 3px">Missing event?</span>';
                    }

                    $entered++;
                    break;
                }
            }

            // calculate the participation score (how many times they've done the event divided by the times offered)
            $participation_score = $entered / $offered;
            
            echo ' [PF=' . number_format($participation_score, 2) . ']';

            echo '</li>';
        }

        echo '<div style="margin-top:5px"><strong>Best:</strong> ' . $event_total . ' &times; ' . number_format($participation_score, 2) . ' = ' . round($event_total * $participation_score) . '</div>';

        $athlete_totals[] = round($event_total * $participation_score);

        echo '</ul>';
        echo '</li>';
    }

    echo '</ul>';

    if (!$clubFilter) {
        // for ca we only want the top 4 events
        rsort($athlete_totals);
        $athlete_totals = array_slice($athlete_totals, 0, 4);
    }

    // sum the athletes highest scores (top 4 for ca)
    $athlete_total = array_sum($athlete_totals);
    echo '<p>Total: ' . implode(' + ', $athlete_totals);

    echo ' = ' . number_format($athlete_total) . ' pts</p>';

    // push into a table of athletes
    $athletes[$athlete_name]['score'] = $athlete_total;

    if (isset($clubsData[$athletes[$athlete_name]['club']])) {
        if (!isset($clubs[$athletes[$athlete_name]['club']])) {
            $clubs[$athletes[$athlete_name]['club']] = ['score' => 0, 'athletes' => []];
        }

        // push into a table of clubs (only ones we are interested in)
        $clubs[$athletes[$athlete_name]['club']]['score'] += $athlete_total;
        $clubs[$athletes[$athlete_name]['club']]['athletes'][] = $athlete_name;
    }
}

$output = ob_get_clean();

if ($verbose) {
    echo $output;
}

// output athletes scores
uasort($athletes, function ($a, $b) {
    return $b['score'] <=> $a['score'];
});

echo '<h2>Athlete scores</h2>';
echo '<table class="table table-bordered table-striped table-sm">';
echo '<tr><th>Name</th>';
if (!$clubFilter) echo '<th>Club</th>';
echo '<th style="text-align:right">Score</th>';
echo '</tr>';

foreach ($athletes as $athlete_name => $athlete) {
    if ($athlete['score'] == 0) continue;

    echo '<tr>';
    echo '<td>' . $athlete_name . '</td>';
    if (!$clubFilter) echo '<td>' . $athlete['club'] . '</td>';
    echo '<td style="text-align:right">' . number_format($athlete['score']) . '</td>';
    echo '</tr>';
}
echo '</table>';

// output club scores
if (!$clubFilter) {
    foreach ($clubs as $clubName => $clubObj) {
        $clubs[$clubName]['size'] = $clubsData[$clubName]['size'];
        
        $clubs[$clubName]['officials'] = 0;
        foreach ($meetEventArray as $i => $meet) {
            // for every volunteer per meet the club gets 20 pts
            $clubs[$clubName]['officials'] = $clubsData[$clubName]['volunteers'][$i] * 20;
        }

        $clubs[$clubName]['cpf'] = calcClubParticipationaFactor(count($clubObj['athletes']), $clubs[$clubName]['size']);
        $clubs[$clubName]['adj'] = calcClubAdjustedTotal($clubObj['score'], $clubs[$clubName]['cpf'], $clubs[$clubName]['officials']);
    }

    uasort($clubs, function ($a, $b) {
        return $b['adj'] <=> $a['adj'];
    });

    echo '<h2>Club scores</h2>';
    echo '<table class="table table-bordered table-striped table-sm">';

    echo '<tr><th>Club</th>';
    echo '<th style="width:70px;text-align:right">Size</th>';
    echo '<th style="width:70px;text-align:right">#</th>';
    echo '<th style="width:70px;text-align:right">CPF</th>';
    echo '<th style="width:70px;text-align:right">Score</th>';
    echo '<th style="width:70px;text-align:right">Officials</th>';
    echo '<th style="width:70px;text-align:right">Adj</th>';
    echo '</tr>';

    foreach ($clubs as $club => $clubObj) {
        if ($clubObj['score'] == 0) continue;

        echo '<tr>';
        echo '<td>' . $club . '</td>';
        echo '<td style="text-align:right">' . $clubObj['size'] . '</td>';
        echo '<td style="text-align:right">' . count($clubObj['athletes']) . '</td>';
        echo '<td style="text-align:right">' . $clubObj['cpf'] . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['score']) . '</td>';
        echo '<td style="text-align:right">' . $clubObj['officials'] . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['adj']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

?>

<div class="alert alert-warning">
    <h5>Notes</h5>
    <ul class="mb-0">
        <li>We are missing a few events like 200m Hurdles for some age groups (where we have 300m). I could make it look down an age for these?</li>
        <li>Also missing is 3000m and 1500m Walk for Masters (in both the WMA table and the scoring for open).</li>
        <li>Pole Vault and Hammer scoring is missing for juniors</li>
        <li>We don't have adjustment factor for U18-U20 so they are being scored against Open values</li>
        <li>We can only count the instances of events that had at least one result, so if no one ran then it's like it didn't happen, maybe that's ok.</li>
    </ul>
</div>

</div>
</body>
</html>
