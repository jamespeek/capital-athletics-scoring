<?php

$verbose = $_GET['verbose'] ?? true;
$clubFilter = $_GET['club'] ?? false;
$comp = $_GET['comp'] ?? 'ss';
$assetCssVersion = @filemtime(__DIR__ . '/assets/app.css') ?: time();
$assetJsVersion = @filemtime(__DIR__ . '/assets/app.js') ?: time();

include_once 'utils.php';
include_once 'scoring.php';

// Pull in the club size and officials data we need for the final club scores.
$clubsData = loadClubData();

// Find the CSV result files for this competition and put them in the right order.
$files = loadCompetitionFiles($comp, $clubFilter);

// Read every CSV file and turn it into one list of athlete results.
$resultData = loadCompetitionResults($files, $comp);

// Remove the events we do not want to include anywhere in the scoring.
$resultData = filterExcludedEvents($resultData);

// Build a simple list of which events were offered at each meet.
$meetEventArray = buildMeetEventArray($resultData);

// Keep only one club's results when a club filter has been chosen.
$resultData = filterResultsByClub($resultData, $clubFilter);

// Sort the results by athlete name so the output is grouped consistently.
usort($resultData, function($a, $b) {
    return strcasecmp($a['lastname'] . ' ' . $a['firstname'], $b['lastname'] . ' ' . $b['firstname']);
});

// Bundle each person's results together so we can score them event by event.
$athletes = groupResultsByAthlete($resultData);

// start writing to the buffer so we can conditionally show the output
ob_start();

$clubs = [];

// loop through the athletes
foreach ($athletes as $athlete_name => $athlete) {
    echo '<div class="athlete">';
    echo '<strong>' . $athlete_name . ' (' . $athlete['age'] . ')</strong><ul>';

    $athlete_totals = [];

    // loop through the athletes events
    foreach ($athlete['events'] as $event => $results) {
        // temp hack for non events
        if ($event == '10000') continue;

        $eventSummary = buildAthleteEventSummary($athlete, $event, $results, $meetEventArray);

        echo '<li>';
        echo $event;
        echo '<ul>';

        foreach ($eventSummary['meets'] as $meetSummary) {
            echo '<li>';
            echo $meetSummary['name'] . ': ';

            if ($meetSummary['score_data'] !== null) {
                $pointsScore = $meetSummary['score_data']['score'];

                echo $meetSummary['result_str'] . ' = ';
                echo ($pointsScore === null ? '?' : $pointsScore) . ' pts';
                echo renderScoreTooltipButton($meetSummary['score_data']['tooltip']);

                if ($meetSummary['has_missing_record']) {
                    echo ' <span style="margin-left:10px; background:gold; border-radius: 3px; padding: 1px 3px">No record available</span>';
                }

                if ($pointsScore !== null) {
                    echo ' [PF=' . number_format($meetSummary['participation_score'], 2) . ']';
                }
            }

            echo '</li>';
        }

        if ($eventSummary['best_score']) {
            echo '<div style="margin-top:5px"><strong>Best:</strong> ' . $eventSummary['best_score'] . ' &times; ' . number_format($eventSummary['participation_score'], 2) . ' = ' . $eventSummary['final_score'] . '</div>';
        }

        $athlete_totals[] = $eventSummary['final_score'];

        echo '</ul>';
        echo '</li>';
    }

    echo '</ul>';

    if (!$clubFilter) {
        // for CA we only want the top 4 events
        rsort($athlete_totals);
        $athlete_totals = array_slice($athlete_totals, 0, 4);
    }

    // sum the athletes highest scores
    $athlete_total = array_sum($athlete_totals);

    if ($athlete_total) {
        echo '<p>Total: ' . implode(' + ', $athlete_totals);
        echo ' = ' . number_format($athlete_total) . ' pts</p>';
    }

    echo '</div>';

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

// output athletes scores
uasort($athletes, function ($a, $b) {
    return $b['score'] <=> $a['score'];
});

ob_start();

if ($verbose) {
    echo $output;
}

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
            // for every officials per meet the club gets 20 pts
            $officialCount = $clubsData[$clubName]['officials'][$i] ?? 0;
            $clubs[$clubName]['officials'] += $officialCount * 20;
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

$pageContent = ob_get_clean();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CA: Senior athletics scoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/app.css?v=<?= $assetCssVersion ?>">
</head>
<body>

<div class="container">
    <?= $pageContent ?>
</div>

<script src="assets/app.js?v=<?= $assetJsVersion ?>"></script>
</body>
</html>
