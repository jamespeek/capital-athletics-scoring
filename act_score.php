<?php

/**
 * ACT Record Scoring Utility
 *
 * Usage:
 *   require_once __DIR__ . '/act_score.php';
 *   echo calcScore(17, 'Men', 'Pole Vault', 3.50);
 *
 * Behaviour:
 *   - Returns HTML containing the score and a small info tooltip button
 *   - If no suitable record is found it returns "?"
 *   - CSV parsed once per request and cached for speed
 *
 * CSV expected at:
 *   data/reference/combined-act.csv
 */

if (!function_exists('calcScore')) {

    $ACT_SCORE_CACHE = null;
    $ACT_SCORE_WMA_CACHE = null;

    function calcScore($age, $gender, $event, $result)
    {
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

        if (!$records || $actualAge === null || $gender === '' || $event === '' || $rawValue === null || $rawValue <= 0) {
            return '?';
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

        $record = actScoreFindRecord($records, $lookupAge, $gender, $event);

        if (!$record) {
            return '?';
        }

        $recordValue = $record['_result'] ?? null;

        if (!$recordValue || $recordValue <= 0) {
            return '?';
        }

        $timeEvent = actScoreIsTimeEvent($event);

        if ($timeEvent) {
            $percentage = $recordValue / $adjustedValue;
        } else {
            $percentage = $adjustedValue / $recordValue;
        }

        $score = round($percentage * 800, 0);

        static $assetsPrinted = false;

        if (!$assetsPrinted) {
            echo actScoreAssets();
            $assetsPrinted = true;
        }

        $tooltip = [];
        $tooltip[] = "Input age: {$ageRaw}";
        $tooltip[] = "Input gender: {$genderRaw}";
        $tooltip[] = "Input event: {$eventRaw}";
        $tooltip[] = "Raw result: {$resultRaw}";

        if ($actualAge >= 35 && $actualAge !== 999) {
            $tooltip[] = "WMA factor: " . actScoreFormatNumber($factor, 5);
            $tooltip[] = "Adjusted result: " . actScoreFormatNumber($adjustedValue, 5);
            $tooltip[] = "Lookup age: Open";
        }

        $tooltip[] = "Matched age: " . actScoreAgeLabel($record['Age'] ?? '');
        $tooltip[] = "Record: " . ($record['Result'] ?? '');

        if (!empty($record['Weight'])) {
            $tooltip[] = "Record weight: " . $record['Weight'];
        }

        $tooltip[] = "Athlete: " . ($record['Name'] ?? '');
        $tooltip[] = "Source: " . ($record['Source'] ?? '');
        $tooltip[] = "Percent of record: " . number_format($percentage * 100, 1) . "%";
        $tooltip[] = "Score: {$score}";

        $tooltipText = htmlspecialchars(implode("\n", $tooltip), ENT_QUOTES, 'UTF-8');

        echo '<button type="button" class="act-score-btn" data-tip="'.$tooltipText.'">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"><!-- Icon from Tabler Icons by Paweł Kuna - https://github.com/tabler/tabler-icons/blob/master/LICENSE --><path fill="currentColor" d="M12 2c5.523 0 10 4.477 10 10a10 10 0 0 1-19.995.324L2 12l.004-.28C2.152 6.327 6.57 2 12 2m0 9h-1l-.117.007a1 1 0 0 0 0 1.986L11 13v3l.007.117a1 1 0 0 0 .876.876L12 17h1l.117-.007a1 1 0 0 0 .876-.876L14 16l-.007-.117a1 1 0 0 0-.764-.857l-.112-.02L13 15v-3l-.007-.117a1 1 0 0 0-.876-.876zm.01-3l-.127.007a1 1 0 0 0 0 1.986L12 10l.127-.007a1 1 0 0 0 0-1.986z"/></svg>';
        echo '</button>';

        return (float)$score;
    }


    function actScoreAssets()
    {
        return <<<HTML
<style>
.act-score-btn {
    width:1.2rem;
    height:1.2rem;
    border:0;
    background:none;
    color:darkorange;
    cursor:pointer;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    bottom: -3px;
    position: relative;
    margin-right: 2px;
}

.act-score-btn svg {
    width:100%;
    height:100%;
}

.act-tooltip {
    position:fixed;
    background:#111;
    color:#fff;
    padding:6px 8px;
    border-radius:4px;
    font-size:12px;
    white-space:pre-line;
    max-width:280px;
    z-index:9999;
}
</style>
<script>
(function(){

let tooltip=null;

document.addEventListener("click",function(e){

let btn=e.target.closest(".act-score-btn");

if(btn){

e.stopPropagation();

if(tooltip){
tooltip.remove();
tooltip=null;
return;
}

tooltip=document.createElement("div");
tooltip.className="act-tooltip";
tooltip.textContent=btn.dataset.tip;

document.body.appendChild(tooltip);

let r = btn.getBoundingClientRect();
let tr = tooltip.getBoundingClientRect();

let left = r.left;
let top = r.bottom + 6;

if (left + tr.width > window.innerWidth - 8) {
    left = window.innerWidth - tr.width - 8;
}

if (left < 8) {
    left = 8;
}

if (top + tr.height > window.innerHeight - 8) {
    top = r.top - tr.height - 6;
}

if (top < 8) {
    top = 8;
}

tooltip.style.left = left + "px";
tooltip.style.top = top + "px";

return;
}

if(tooltip){
tooltip.remove();
tooltip=null;
}

});

})();
</script>
HTML;
    }


    function actScoreLoadRecords()
    {
        global $ACT_SCORE_CACHE;

        if ($ACT_SCORE_CACHE !== null) {
            return $ACT_SCORE_CACHE;
        }

        $file = __DIR__.'/data/reference/combined-act.csv';

        if (!is_file($file)) return null;

        $fh = fopen($file,'r');

        $headers = fgetcsv($fh);
        if (!$headers) return null;

        $headers = array_map('actScoreCleanHeader', $headers);

        $index = [];
        $ages = [];

        while (($row = fgetcsv($fh)) !== false) {

            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $r = array_combine($headers, $row);
            if (!$r) continue;

            $gender = actScoreNormalizeGender($r['Gender'] ?? '');
            $event = actScoreNormalizeEvent($r['Event'] ?? '');
            $age = actScoreNormalizeAge($r['Age'] ?? '');
            $value = actScoreParseResult($r['Result'] ?? '');

            if (!$gender || !$event || $age === null || !$value) continue;

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

        $ACT_SCORE_CACHE = [
            'index' => $index,
            'ages' => $ages
        ];

        return $ACT_SCORE_CACHE;
    }


    function actScoreLoadWmaData()
    {
        global $ACT_SCORE_WMA_CACHE;

        if ($ACT_SCORE_WMA_CACHE !== null) {
            return $ACT_SCORE_WMA_CACHE;
        }

        $ACT_SCORE_WMA_CACHE = [
            'M' => json_decode(@file_get_contents(__DIR__ . '/data/reference/wma-men.json'), true),
            'F' => json_decode(@file_get_contents(__DIR__ . '/data/reference/wma-women.json'), true)
        ];

        if (!is_array($ACT_SCORE_WMA_CACHE['M'])) {
            $ACT_SCORE_WMA_CACHE['M'] = [];
        }

        if (!is_array($ACT_SCORE_WMA_CACHE['F'])) {
            $ACT_SCORE_WMA_CACHE['F'] = [];
        }

        return $ACT_SCORE_WMA_CACHE;
    }


    function actScoreFindRecord($records, $age, $gender, $event)
    {
        if (!isset($records['index'][$gender][$event])) return null;

        $ages = $records['ages'][$gender];

        foreach ($ages as $a) {
            if ($a < $age) continue;

            if (!empty($records['index'][$gender][$event][$a])) {
                return $records['index'][$gender][$event][$a];
            }
        }

        return null;
    }


    function actScoreNormalizeAge($age)
    {
        $age = trim((string)$age);

        if ($age === '') return null;

        if (preg_match('/^U?(\d+)$/', $age, $m)) {
            return (int)$m[1];
        }

        if (strcasecmp($age, 'Open') === 0) {
            return 999;
        }

        return null;
    }


    function actScoreAgeLabel($age)
    {
        $age = (string)$age;

        if (strcasecmp($age, 'Open') === 0 || (int)$age === 999) {
            return 'Open';
        }

        return 'U'.$age;
    }


    function actScoreNormalizeGender($g)
    {
        $g = strtolower(trim((string)$g));

        if (in_array($g, ['m', 'male', 'men', 'boy', 'boys'], true)) {
            return 'Male';
        }

        if (in_array($g, ['f', 'female', 'women', 'girl', 'girls'], true)) {
            return 'Female';
        }

        return ucfirst($g);
    }


    function actScoreNormalizeEvent($event)
    {
        $event = trim((string)$event);
        $event = preg_replace('/\s+/', ' ', $event);
        $event = preg_replace('/\s+\d+(?:\.\d+)?\s*(g|kg)$/i', '', $event);
        $event = strtolower(trim($event));

        if ($event === 'mile') $event = '1 mile';
        if ($event === 'shot put') $event = 'shot put';

        return $event;
    }


    function actScoreParseResult($r)
    {
        $r = trim((string)$r);

        if ($r === '') return null;

        if (strpos($r, ':') !== false) {

            $parts = explode(':', $r);

            if (count($parts) == 2)
                return ($parts[0] * 60) + $parts[1];

            if (count($parts) == 3)
                return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        $r = preg_replace('/[^0-9.]/', '', $r);

        return (float)$r;
    }


    function actScoreIsTimeEvent($event)
    {
        $event = strtolower(trim((string)$event));

        if ($event === '') return false;

        if (str_contains($event, 'walk')) return true;
        if (str_contains($event, 'hurdle')) return true;
        if (str_contains($event, 'relay')) return true;
        if (str_contains($event, 'steeple')) return true;
        if (str_contains($event, 'mile')) return true;

        if (preg_match('/^\d+(?:\.\d+)?\s*m\b/', $event)) return true;
        if (preg_match('/^\d+(?:\.\d+)?\s*km\b/', $event)) return true;

        return false;
    }


    function actScoreCleanHeader($h)
    {
        $h = trim((string)$h);
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return $h;
    }


    function actScoreFormatNumber($value, $decimals = 5)
    {
        $formatted = number_format((float)$value, $decimals, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

}