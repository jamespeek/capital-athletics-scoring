<?php

function renderScoreStatusLabel($status) {
    $labels = [
        'unavailable' => 'Score unavailable',
        'no_record' => 'No matching ACT record found',
        'invalid_record' => 'Matched record was invalid',
    ];

    return $labels[$status] ?? $status;
}

function formatCopiedResult($result) {
    return preg_replace('/\s*[a-zA-Z]+$/', '', trim((string)$result));
}

function buildScoreTooltipLines($scoreData) {
    $meta = $scoreData['meta'] ?? [];
    $input = $meta['input'] ?? [];
    $adjustment = $meta['adjustment'] ?? [];
    $lookup = $meta['lookup'] ?? [];
    $record = $meta['record'] ?? [];
    $display = $meta['display'] ?? [];
    $lines = [];
    $displayRawResult = $display['raw_result'] ?? $input['result'] ?? '';

    if (($input['result'] ?? '') !== '' && $displayRawResult !== ($input['result'] ?? '')) {
        $displayRawResult .= '*';
    }

    $lines[] = 'Input age: ' . ($input['age'] ?? '');
    $lines[] = 'Input gender: ' . ($input['gender'] ?? '');
    $lines[] = 'Input event: ' . ($input['event'] ?? '');
    $lines[] = 'Raw result: ' . $displayRawResult;

    if (($adjustment['wma_factor'] ?? null) !== null) {
        $lines[] = 'WMA factor: ' . ($display['wma_factor'] ?? '');
        $lines[] = 'Adjusted result: ' . ($display['adjusted_result'] ?? '');
        $lines[] = 'Lookup age: ' . ($display['lookup_age'] ?? '');
    }

    if (!empty($lookup['matched_age'])) {
        $lines[] = 'Matched age: ' . ($display['matched_age'] ?? '');
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

    if (!empty($scoreData['status']) && $scoreData['status'] !== 'ok') {
        $lines[] = 'Status: ' . renderScoreStatusLabel($scoreData['status']);
    }

    return $lines;
}

function renderScoreTooltipButton($scoreData) {
    $tooltipText = htmlspecialchars(implode("\n", buildScoreTooltipLines($scoreData)), ENT_QUOTES, 'UTF-8');

    return renderTooltipButton($tooltipText);
}

function renderTooltipButton($tooltipText) {
    return '<button type="button" class="act-score-btn" data-tip="' . $tooltipText . '">' .
        '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"><!-- Icon from Tabler Icons by Paweł Kuna - https://github.com/tabler/tabler-icons/blob/master/LICENSE --><path fill="currentColor" d="M12 2c5.523 0 10 4.477 10 10a10 10 0 0 1-19.995.324L2 12l.004-.28C2.152 6.327 6.57 2 12 2m0 9h-1l-.117.007a1 1 0 0 0 0 1.986L11 13v3l.007.117a1 1 0 0 0 .876.876L12 17h1l.117-.007a1 1 0 0 0 .876-.876L14 16l-.007-.117a1 1 0 0 0-.764-.857l-.112-.02L13 15v-3l-.007-.117a1 1 0 0 0-.876-.876zm.01-3l-.127.007a1 1 0 0 0 0 1.986L12 10l.127-.007a1 1 0 0 0 0-1.986z"/></svg>' .
        '</button>';
}

function renderTooltipTextTrigger($label, $tooltipText) {
    return '<span class="act-tooltip-trigger" data-tip="' . $tooltipText . '">' . htmlspecialchars($label) . '</span>';
}

function renderMissingRecordBadge() {
    return '<span class="missing-record-badge">No record available</span>';
}

function renderAthleteMeetSummary($meetSummary) {
    $hasScoredEvent = $meetSummary['score_data'] !== null;
    ob_start();
    echo '<li class="athlete-meet-summary' . ($hasScoredEvent ? ' has-scored-event' : '') . '">';
    echo htmlspecialchars($meetSummary['name']) . ': ';

    if ($meetSummary['score_data'] !== null) {
        $pointsScore = $meetSummary['score_data']['score'];

        echo htmlspecialchars($meetSummary['result_str']) . ' = ';
        echo ($pointsScore === null ? '?' : $pointsScore) . ' pts';
        echo renderScoreTooltipButton($meetSummary['score_data']);

        if ($meetSummary['has_missing_record']) {
            echo ' ' . renderMissingRecordBadge();
        }
    }

    echo ' [PF=' . number_format($meetSummary['participation_score'], 2) . ']';

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
        echo '<div class="event-best-score"><strong>Best:</strong> ';
        echo $eventSummary['best_score'] . ' &times; ' . number_format($eventSummary['participation_score'], 2);
        echo ' = ' . $eventSummary['final_score'] . '</div>';
    }

    echo '</ul>';
    echo '</li>';
    return ob_get_clean();
}

function renderAthleteSummaries($athleteSummaries) {
    ob_start();

    foreach ($athleteSummaries as $athleteSummary) {
        echo renderAthleteSummary(
            $athleteSummary['name'],
            $athleteSummary['athlete'],
            $athleteSummary['event_summaries'],
            $athleteSummary['totals'],
            $athleteSummary['total']
        );
    }

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
        echo ' = ' . number_format($athleteTotal) . ' pts';
        echo '<br>Meet PF: ' .
            htmlspecialchars((string)($athlete['attended_meet_count'] ?? 0)) .
            ' / ' .
            htmlspecialchars((string)($athlete['eligible_meet_count'] ?? 0)) .
            ' = ' .
            number_format((float)($athlete['meet_pf'] ?? 0), 2);
        echo '</p>';
    }

    echo '</div>';
    return ob_get_clean();
}

function renderAthleteScoresTable($athletes, $clubFilter, $showAllAthletes = false) {
    $meetPfTooltip = renderTooltipTextTrigger('Meet PF', htmlspecialchars('Athlete meet participation factor: distinct meets attended divided by total meets in the current competition view.', ENT_QUOTES, 'UTF-8'));
    $scoreTooltip = renderTooltipTextTrigger('Score', htmlspecialchars('Total athlete score after combining the counted event scores for this view.', ENT_QUOTES, 'UTF-8'));
    $visibleAthletes = [];

    foreach ($athletes as $athleteName => $athlete) {
        if ((float)$athlete['score'] === 0.0) {
            continue;
        }

        $visibleAthletes[$athleteName] = $athlete;
    }

    $athleteCount = count($visibleAthletes);

    if (!$showAllAthletes) {
        $visibleAthletes = array_slice($visibleAthletes, 0, 20, true);
    }

    $queryParams = $_GET;
    $queryParams['all_athletes'] = $showAllAthletes ? '0' : '1';
    $toggleLabel = $showAllAthletes ? 'Show top 20' : 'Show all';
    $toggleHref = '?' . http_build_query($queryParams);

    ob_start();
    echo '<h2>Athlete scores</h2>';
    echo '<table class="table table-bordered table-striped table-sm">';
    echo '<tr><th>Name</th>';
    if (!$clubFilter) {
        echo '<th>Club</th>';
    }
    echo '<th style="text-align:right">' . $meetPfTooltip . '</th>';
    echo '<th style="text-align:right">' . $scoreTooltip . '</th>';
    echo '</tr>';

    foreach ($visibleAthletes as $athleteName => $athlete) {
        $displayName = $athlete['display_name'] ?? $athleteName;
        echo '<tr>';
        echo '<td>' . htmlspecialchars($displayName) . '</td>';
        if (!$clubFilter) {
            echo '<td>' . htmlspecialchars(implode(', ', $athlete['clubs'] ?? [$athlete['club'] ?? ''])) . '</td>';
        }
        echo '<td style="text-align:right">' . number_format((float)($athlete['meet_pf'] ?? 0), 2) . '</td>';
        echo '<td style="text-align:right">' . number_format($athlete['score']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';

    if ($athleteCount > 20) {
        echo '<p><a href="' . htmlspecialchars($toggleHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($toggleLabel) . '</a></p>';
    }

    return ob_get_clean();
}

function renderClubScoresTable($clubs) {
    ob_start();
    $cpfTooltip = renderTooltipTextTrigger('CPF', htmlspecialchars('Average athlete meet PF for the club: distinct meets attended divided by total meets, averaged across the club\'s athletes.', ENT_QUOTES, 'UTF-8'));
    $scoreTooltip = renderTooltipTextTrigger('Score', htmlspecialchars('Sum of the included athlete scores for the club.', ENT_QUOTES, 'UTF-8'));
    $adjTooltip = renderTooltipTextTrigger('Adj', htmlspecialchars('Club adjusted score before officials: Score × CPF.', ENT_QUOTES, 'UTF-8'));
    $officialsTooltip = renderTooltipTextTrigger('Officials', htmlspecialchars('Officials bonus: 20 points for each official recorded for each meet.', ENT_QUOTES, 'UTF-8'));
    $totalTooltip = renderTooltipTextTrigger('Total', htmlspecialchars('Final club total: Adj + Officials.', ENT_QUOTES, 'UTF-8'));
    echo '<h2>Club scores</h2>';
    echo '<table class="table table-bordered table-striped table-sm">';
    echo '<tr><th>Club</th>';
    echo '<th style="width:70px;text-align:right">' . $cpfTooltip . '</th>';
    echo '<th style="width:70px;text-align:right">' . $scoreTooltip . '</th>';
    echo '<th style="width:70px;text-align:right">' . $adjTooltip . '</th>';
    echo '<th style="width:70px;text-align:right">' . $officialsTooltip . '</th>';
    echo '<th style="width:70px;text-align:right">' . $totalTooltip . '</th>';
    echo '</tr>';

    foreach ($clubs as $club => $clubObj) {
        if ((float)$clubObj['score'] === 0.0) continue;

        echo '<tr>';
        echo '<td>' . htmlspecialchars($club) . '</td>';
        echo '<td style="text-align:right">' . number_format((float)$clubObj['cpf'], 3) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['score']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['adj']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['officials']) . '</td>';
        echo '<td style="text-align:right">' . number_format($clubObj['total']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    return ob_get_clean();
}

function renderPotentialRecordsTable($potentialRecords) {
    if (!$potentialRecords) {
        return '';
    }

    $recordsBySource = [];

    foreach ($potentialRecords as $potentialRecord) {
        $source = (string)($potentialRecord['record_source'] ?? 'Unknown');
        $recordsBySource[$source][] = $potentialRecord;
    }

    ob_start();
    echo '<h2>Potential records</h2>';

    foreach ($recordsBySource as $source => $sourceRecords) {
        echo '<h3>' . htmlspecialchars($source) . '</h3>';
        echo '<table class="table table-bordered table-striped table-sm">';
        echo '<tr>';
        echo '<th>Athlete</th>';
        echo '<th>Age</th>';
        echo '<th></th>';
        echo '<th>Club</th>';
        echo '<th>Event</th>';
        echo '<th>Meet</th>';
        echo '<th>Result</th>';
        echo '<th>Weight</th>';
        echo '<th>Record</th>';
        echo '<th>Record holder</th>';
        echo '<th>Record age</th>';
        echo '</tr>';

        foreach ($sourceRecords as $potentialRecord) {
            $copyText = implode("\t", [
                $potentialRecord['athlete'],
                formatCopiedResult($potentialRecord['result']),
                $potentialRecord['club'] ?? '',
                $potentialRecord['meet_date_iso'] ?? '',
            ]);

            echo '<tr class="potential-record-row" data-copy="' . htmlspecialchars($copyText, ENT_QUOTES, 'UTF-8') . '">';
            echo '<td>' . htmlspecialchars($potentialRecord['athlete']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$potentialRecord['age']) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['gender'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['club'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars($potentialRecord['event']) . '</td>';
            echo '<td>' . htmlspecialchars($potentialRecord['meet']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$potentialRecord['result']) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['weight'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['record_result'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['record_name'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($potentialRecord['record_age'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }
    return ob_get_clean();
}
