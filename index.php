<?php

$verbose = filter_var($_GET['verbose'] ?? false, FILTER_VALIDATE_BOOLEAN);
$clubFilter = $_GET['club'] ?? false;
$comp = $_GET['comp'] ?? 'ss';
$showAthletes = filter_var($_GET['athletes'] ?? true, FILTER_VALIDATE_BOOLEAN);
$showAllAthletes = filter_var($_GET['all_athletes'] ?? false, FILTER_VALIDATE_BOOLEAN);
$showPotentialRecords = filter_var($_GET['records'] ?? false, FILTER_VALIDATE_BOOLEAN);
$showAthletes = $clubFilter ? true : $showAthletes;

$assetCssVersion = @filemtime(__DIR__ . '/assets/app.css') ?: time();
$assetJsVersion = @filemtime(__DIR__ . '/assets/app.js') ?: time();

include_once 'utils.php';
include_once 'scoring.php';
include_once 'render.php';

// Pull in the club size and officials data we need for the final club scores.
$clubsData = loadClubData();
$clubNames = array_keys($clubsData);
natcasesort($clubNames);

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

$summaryData = buildAthleteSummaries($athletes, $clubsData, $meetEventArray, $clubFilter);
$athletes = $summaryData['athletes'];
$clubs = $summaryData['clubs'];
$potentialRecords = $summaryData['potential_records'];
$output = renderAthleteSummaries($summaryData['athlete_summaries']);

// Put the highest-scoring athletes at the top of the summary table.
$athletes = sortAthletesByScore($athletes);

$toggleQueryParams = [
];

if ($comp !== 'ss') {
    $toggleQueryParams['comp'] = $comp;
}

if ($showPotentialRecords) {
    $toggleQueryParams['records'] = '1';
}

ob_start();

echo '<form method="get" class="view-toggles">';
foreach ($toggleQueryParams as $key => $value) {
    if ($value === false || $value === null || $value === '') {
        continue;
    }

    echo '<input type="hidden" name="' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
}
echo '<label><input type="checkbox" name="verbose" value="1" onchange="this.form.requestSubmit()" ' . ($verbose ? 'checked' : '') . '> Verbose</label>';
if ($clubFilter) {
    echo '<label><input type="checkbox" name="athletes" value="1" checked disabled> Show athlete scores</label>';
} else {
    echo '<input type="hidden" name="athletes" value="0" class="toggle-fallback" data-checkbox-name="athletes" data-disable-when-unchecked="0">';
    echo '<label><input type="checkbox" name="athletes" value="1" onchange="this.form.requestSubmit()" ' . ($showAthletes ? 'checked' : '') . '> Show athlete scores</label>';
}
echo '<label><select name="club" onchange="this.form.requestSubmit()" data-empty-means-unset="1">';
echo '<option value="">All clubs</option>';
foreach ($clubNames as $clubName) {
    $selected = $clubFilter === $clubName ? ' selected' : '';
    echo '<option value="' . htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($clubName) . '</option>';
}
echo '</select></label>';
echo '</form>';

if ($verbose) {
    echo $output;
}

if ($showAthletes) {
    echo renderAthleteScoresTable($athletes, $clubFilter, $showAllAthletes);
}

// output club scores
if (!$clubFilter) {
    $clubs = buildClubSummaries($clubs, $clubsData, $meetEventArray);
    echo renderClubScoresTable($clubs);
}

if (!$clubFilter && $showPotentialRecords) {
    echo renderPotentialRecordsTable($potentialRecords);
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
