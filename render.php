<?php

function formatTooltipResult($event, $result) {
    $event = (string)$event;
    $result = trim((string)$result);

    if ($result === '') {
        return '';
    }

    if (!actScoreIsTimeEvent(actScoreNormalizeEvent($event))) {
        return $result;
    }

    $rawValue = actScoreParseResult($result);

    if ($rawValue === null || $rawValue < 60) {
        return $result;
    }

    $minutes = floor($rawValue / 60);
    $seconds = $rawValue - ($minutes * 60);

    return sprintf('%d:%05.2f', $minutes, $seconds);
}

function buildScoreTooltipLines($scoreData) {
    $meta = $scoreData['meta'] ?? [];
    $input = $meta['input'] ?? [];
    $adjustment = $meta['adjustment'] ?? [];
    $lookup = $meta['lookup'] ?? [];
    $record = $meta['record'] ?? [];
    $lines = [];
    $lines[] = 'Input age: ' . ($input['age'] ?? '');
    $lines[] = 'Input gender: ' . ($input['gender'] ?? '');
    $lines[] = 'Input event: ' . ($input['event'] ?? '');
    $lines[] = 'Raw result: ' . formatTooltipResult($input['event'] ?? '', $input['result'] ?? '');

    if (($adjustment['wma_factor'] ?? null) !== null) {
        $lines[] = 'WMA factor: ' . actScoreFormatNumber($adjustment['wma_factor'], 5);
        $lines[] = 'Adjusted result: ' . actScoreFormatNumber((float)$adjustment['adjusted_result'], 2);
        $lines[] = 'Lookup age: ' . ((int)$lookup['age'] === 999 ? 'Open' : actScoreAgeLabel((string)$lookup['age']));
    }

    if (!empty($lookup['matched_age'])) {
        $lines[] = 'Matched age: ' . actScoreAgeLabel($lookup['matched_age']);
    }

    if (!empty($record['result'])) {
        $lines[] = 'Record: ' . $record['result'];
    }

    if (!empty($record['weight'])) {
        $lines[] = 'Record weight: ' . $record['weight'];
    }

    if (!empty($record['name'])) {
        $lines[] = 'Athlete: ' . $record['name'];
    }

    if (!empty($record['source'])) {
        $lines[] = 'Source: ' . $record['source'];
    }

    if (($adjustment['percentage'] ?? null) !== null) {
        $lines[] = 'Percent of record: ' . number_format($adjustment['percentage'] * 100, 1) . '%';
    }

    if (($scoreData['score'] ?? null) !== null) {
        $lines[] = 'Score: ' . $scoreData['score'];
    }

    if (!empty($scoreData['status']) && $scoreData['status'] !== 'OK') {
        $lines[] = 'Status: ' . $scoreData['status'];
    }

    return $lines;
}

function renderScoreTooltipButton($scoreData) {
    $tooltipText = htmlspecialchars(implode("\n", buildScoreTooltipLines($scoreData)), ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="act-score-btn" data-tip="' . $tooltipText . '">' .
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"><!-- Icon from Tabler Icons by Paweł Kuna - https://github.com/tabler/tabler-icons/blob/master/LICENSE --><path fill="currentColor" d="M12 2c5.523 0 10 4.477 10 10a10 10 0 0 1-19.995.324L2 12l.004-.28C2.152 6.327 6.57 2 12 2m0 9h-1l-.117.007a1 1 0 0 0 0 1.986L11 13v3l.007.117a1 1 0 0 0 .876.876L12 17h1l.117-.007a1 1 0 0 0 .876-.876L14 16l-.007-.117a1 1 0 0 0-.764-.857l-.112-.02L13 15v-3l-.007-.117a1 1 0 0 0-.876-.876zm.01-3l-.127.007a1 1 0 0 0 0 1.986L12 10l.127-.007a1 1 0 0 0 0-1.986z"/></svg>' .
        '</button>';
}

function renderMissingRecordBadge() {
    return '<span class="missing-record-badge">No record available</span>';
}

function renderAthleteMeetSummary($meetSummary) {
    ob_start();
    echo '<li>';
    echo htmlspecialchars($meetSummary['name']) . ': ';

    if ($meetSummary['score_data'] !== null) {
        $pointsScore = $meetSummary['score_data']['score'];

        echo htmlspecialchars($meetSummary['result_str']) . ' = ';
        echo ($pointsScore === null ? '?' : $pointsScore) . ' pts';
        echo renderScoreTooltipButton($meetSummary['score_data']);

        if ($meetSummary['has_missing_record']) {
            echo ' ' . renderMissingRecordBadge();
        }

        if ($pointsScore !== null) {
            echo ' [PF=' . number_format($meetSummary['participation_score'], 2) . ']';
        }
    }

    echo '</li>';
    return ob_get_clean();
}

function renderAthleteEventSummary($eventSummary) {
    ob_start();
    echo '<li>';
    echo htmlspecialchars($eventSummary['event']);
    echo '<ul>';

    foreach ($eventSummary['meets'] as $meetSummary) {
        echo renderAthleteMeetSummary($meetSummary);
    }

    if ($eventSummary['best_score']) {
        echo '<div style="margin-top:5px"><strong>Best:</strong> ';
        echo $eventSummary['best_score'] . ' &times; ' . number_format($eventSummary['participation_score'], 2);
        echo ' = ' . $eventSummary['final_score'] . '</div>';
    }

    echo '</ul>';
    echo '</li>';
    return ob_get_clean();
}

function renderAthleteSummary($athleteName, $athlete, $eventSummaries, $athleteTotals, $athleteTotal) {
    ob_start();
    echo '<div class="athlete">';
    echo '<strong>' . htmlspecialchars($athleteName) . ' (' . htmlspecialchars((string)$athlete['age']) . ')</strong><ul>';

    foreach ($eventSummaries as $eventSummary) {
        echo renderAthleteEventSummary($eventSummary);
    }

    echo '</ul>';

    if ($athleteTotal) {
        echo '<p>Total: ' . implode(' + ', $athleteTotals);
        echo ' = ' . number_format($athleteTotal) . ' pts</p>';
    }

    echo '</div>';
    return ob_get_clean();
}

function renderAthleteScoresTable($athletes, $clubFilter) {
    ob_start();
    echo '<h2>Athlete scores</h2>';
    echo '<table class="table table-bordered table-striped table-sm">';
    echo '<tr><th>Name</th>';
    if (!$clubFilter) {
        echo '<th>Club</th>';
    }
    echo '<th style="text-align:right">Score</th>';
    echo '</tr>';

    foreach ($athletes as $athleteName => $athlete) {
        if ($athlete['score'] == 0) continue;

        echo '<tr>';
        echo '<td>' . htmlspecialchars($athleteName) . '</td>';
        if (!$clubFilter) {
            echo '<td>' . htmlspecialchars($athlete['club']) . '</td>';
        }
        echo '<td style="text-align:right">' . number_format($athlete['score']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    return ob_get_clean();
}

function renderClubScoresTable($clubs) {
    ob_start();
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
        echo '<td>' . htmlspecialchars($club) . '</td>';
        echo '<td style="text-align:right">' . htmlspecialchars((string)$clubObj['size']) . '</td>';
        echo '<td style="text-align:right">' . count($clubObj['athletes']) . '</td>';
        echo '<td style="text-align:right">' . htmlspecialchars((string)$clubObj['cpf']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['score']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['officials']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['adj']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    return ob_get_clean();
}
