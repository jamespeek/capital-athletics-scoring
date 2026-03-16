<?php

$verbose = $_GET['verbose'] ?? true;
$clubFilter = $_GET['club'] ?? false;
$comp = $_GET['comp'] ?? 'ss';

$assetCssVersion = @filemtime(__DIR__ . '/assets/app.css') ?: time();
$assetJsVersion = @filemtime(__DIR__ . '/assets/app.js') ?: time();

include_once 'utils.php';
include_once 'scoring.php';
include_once 'render.php';

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
    $eventSummaries = [];
    $athlete_totals = [];

    // loop through the athletes events
    foreach ($athlete['events'] as $event => $results) {
        $eventSummary = buildAthleteEventSummary($athlete, $event, $results, $meetEventArray);
        $eventSummaries[] = $eventSummary;
        $athlete_totals[] = $eventSummary['final_score'];
    }

    if (!$clubFilter) {
        // for CA we only want the top 4 events
        rsort($athlete_totals);
        $athlete_totals = array_slice($athlete_totals, 0, 4);
    }

    // sum the athletes highest scores
    $athlete_total = array_sum($athlete_totals);

    echo renderAthleteSummary($athlete_name, $athlete, $eventSummaries, $athlete_totals, $athlete_total);

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

// Put the highest-scoring athletes at the top of the summary table.
$athletes = sortAthletesByScore($athletes);

ob_start();

if ($verbose) {
    echo $output;
}

echo renderAthleteScoresTable($athletes, $clubFilter);

// output club scores
if (!$clubFilter) {
    $clubs = buildClubSummaries($clubs, $clubsData, $meetEventArray);
    echo renderClubScoresTable($clubs);
}

$pageContent = ob_get_clean();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capital Athletics: Senior athletics scoring</title>
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
