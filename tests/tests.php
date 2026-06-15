<?php

function scoringTestSuite() {
    return [
        'ambiguous_dob_key_collapses_swapped_dates' => function () {
            assertSameValue(
                '2009-01-12-amb',
                buildAthleteDobIdentityKey('12/01/2009', null)
            );
            assertSameValue(
                buildAthleteDobIdentityKey('12/01/2009', null),
                buildAthleteDobIdentityKey('01/12/2009', null)
            );
        },
        'group_results_merges_same_name_across_clubs_when_dob_matches' => function () {
            $rows = [
                buildTestRow('Melody', 'Lisle', '11/16/2014', 'Ginninderra Athletics', '100m', 'SS #1'),
                buildTestRow('Melody', 'Lisle', '11/16/2014', 'Athletics New South Wales', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);

            assertCountValue(1, $athletes);
            $athlete = array_values($athletes)[0];
            assertSameValue(['Athletics New South Wales', 'Ginninderra Athletics'], $athlete['clubs']);
        },
        'group_results_splits_same_name_when_dob_is_genuinely_different' => function () {
            $rows = [
                buildTestRow('Joshua', 'Smith', '11/28/2008', 'New South Wales', '100m', 'SS #1'),
                buildTestRow('Joshua', 'Smith', '12/01/2009', 'Woden Athletics', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);

            assertCountValue(2, $athletes);
        },
        'club_scoring_uses_club_scoped_results_without_splitting_athlete_identity' => function () {
            $rows = [
                buildTestRow('Vicki', 'Townsend', '05/12/1964', 'ACT Masters Athletics', '100m', 'SS #1', 61, 'Female', 15.0),
                buildTestRow('Vicki', 'Townsend', '05/12/1964', 'Woden Athletics', '200m', 'SS #2', 61, 'Female', 31.0),
            ];

            $athletes = groupResultsByAthlete($rows);
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['100m']],
                ['name' => 'SS #2', 'events' => ['200m']],
            ];
            $clubsData = [
                'ACT Masters Athletics' => ['size' => 10, 'officials' => [0, 0]],
                'Woden Athletics' => ['size' => 10, 'officials' => [0, 0]],
            ];

            $summary = buildAthleteSummaries($athletes, $clubsData, $meetEventArray, false);

            assertCountValue(1, $summary['athletes']);
            assertCountValue(2, $summary['clubs']);

            $clubs = buildClubSummaries($summary['clubs'], $clubsData, $meetEventArray);
            assertCountValue(2, $clubs);

            foreach ($clubs as $clubName => $club) {
                assertCountValue(1, $club['athletes']);
                assertTrueValue($club['score'] > 0, "Expected positive club score for {$clubName}");
            }
        },
        'club_cpf_helpers_return_numeric_precision' => function () {
            $cpfOld = calcClubParticipationFactor(1, 3);
            $cpfNew = calcAverageAthleteMeetParticipationFactor([
                ['meet_pf' => 1 / 3],
                ['meet_pf' => 2 / 3],
            ]);

            assertTrueValue(is_float($cpfOld), 'Expected old CPF to be float');
            assertTrueValue(is_float($cpfNew), 'Expected new CPF to be float');
            assertSameValue(1 / 3, $cpfOld);
            assertSameValue(0.5, $cpfNew);
        },
        'meet_pf_keeps_attended_u20_open_champs_for_junior_athletes' => function () {
            $athlete = [
                'age' => 16,
                'events' => [
                    '800m' => [
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'SS #1', 16, 'Male', 130.0),
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'U20 & Opens Champs', 16, 'Male', 131.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(2, $meetPf['attended_meet_count']);
            assertSameValue(3, $meetPf['eligible_meet_count']);
            assertSameValue(2 / 3, $meetPf['meet_pf']);
        },
        'meet_pf_keeps_attended_u9_u18_champs_for_senior_athletes' => function () {
            $athlete = [
                'age' => 20,
                'events' => [
                    '800m' => [
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'SS #1', 20, 'Male', 130.0),
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'U9-U18 Champs', 20, 'Male', 131.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(2, $meetPf['attended_meet_count']);
            assertSameValue(3, $meetPf['eligible_meet_count']);
            assertSameValue(2 / 3, $meetPf['meet_pf']);
        },
        'meet_pf_excludes_unattended_u20_open_champs_for_juniors' => function () {
            $athlete = [
                'age' => 16,
                'events' => [
                    '800m' => [
                        buildTestRow('Junior', 'Runner', '11/28/2008', 'Woden Athletics', '800m', 'SS #1', 16, 'Male', 130.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(1, $meetPf['attended_meet_count']);
            assertSameValue(2, $meetPf['eligible_meet_count']);
            assertSameValue(0.5, $meetPf['meet_pf']);
        },
        'meet_pf_excludes_unattended_u9_u18_champs_for_seniors' => function () {
            $athlete = [
                'age' => 20,
                'events' => [
                    '800m' => [
                        buildTestRow('Senior', 'Runner', '08/05/2005', 'Woden Athletics', '800m', 'SS #1', 20, 'Male', 130.0),
                    ],
                ],
            ];
            $meetEventArray = [
                ['name' => 'SS #1', 'events' => ['800m']],
                ['name' => 'U20 & Opens Champs', 'events' => ['800m']],
                ['name' => 'U9-U18 Champs', 'events' => ['800m']],
            ];

            $meetPf = buildAthleteMeetParticipationData($athlete, $meetEventArray);

            assertSameValue(1, $meetPf['attended_meet_count']);
            assertSameValue(2, $meetPf['eligible_meet_count']);
            assertSameValue(0.5, $meetPf['meet_pf']);
        },
        'unknown_dob_warning_lists_affected_athletes' => function () {
            $rows = [
                buildTestRow('Alex', 'NoDob', '', 'Woden Athletics', '100m', 'SS #1'),
                buildTestRow('Jordan', 'KnownDob', '11/28/2008', 'Woden Athletics', '200m', 'SS #2'),
                buildTestRow('Alex', 'NoDob', '', 'ACT Masters Athletics', '200m', 'SS #3'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);
            $warningHtml = renderDataWarnings($warnings);

            assertSameValue(['Alex NoDob'], $warnings);
            assertContainsValue('Alex NoDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'invalid_dob_warning_lists_affected_athletes' => function () {
            $rows = [
                buildTestRow('Casey', 'BadDob', 'foo', 'Woden Athletics', '100m', 'SS #1'),
                buildTestRow('Jordan', 'KnownDob', '11/28/2008', 'Woden Athletics', '200m', 'SS #2'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);
            $warningHtml = renderDataWarnings($warnings);

            assertSameValue(['Casey BadDob'], $warnings);
            assertContainsValue('Casey BadDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'render_data_warnings_handles_empty_and_multiple_names' => function () {
            assertSameValue('', renderDataWarnings([]));

            $warningHtml = renderDataWarnings(['Alex NoDob', 'Casey BadDob']);
            assertContainsValue('Alex NoDob, Casey BadDob', $warningHtml);
            assertContainsValue('unknown or invalid DOB', $warningHtml);
        },
        'whitespace_only_dob_is_treated_as_unknown' => function () {
            $rows = [
                buildTestRow('Taylor', 'SpaceDob', ' ', 'Woden Athletics', '100m', 'SS #1'),
            ];

            $athletes = groupResultsByAthlete($rows);
            $warnings = collectUnknownDobAthleteNames($athletes);

            assertSameValue(['Taylor SpaceDob'], $warnings);
        },
        'render_athlete_scores_table_limits_to_top_twenty_and_adds_show_all_link' => function () {
            $athletes = [];

            for ($i = 1; $i <= 21; $i++) {
                $name = sprintf('Athlete %02d', $i);
                $athletes[$name] = [
                    'display_name' => $name,
                    'clubs' => ['Club ' . $i],
                    'club' => 'Club ' . $i,
                    'meet_pf' => 1,
                    'score' => 200 - $i,
                ];
            }

            $html = renderAthleteScoresTable($athletes, false, false, ['athletes' => '1']);

            assertContainsValue('Athlete 01', $html);
            assertContainsValue('Athlete 20', $html);
            assertNotContainsValue('Athlete 21', $html);
            assertContainsValue('<th style="width:50px"></th><th>Name</th>', $html);
            assertContainsValue('>1<', $html);
            assertContainsValue('>20<', $html);
            assertContainsValue('Show all', $html);
            assertContainsValue('?athletes=1&amp;all_athletes=1', $html);
            assertContainsValue('Meet PF', $html);
            assertContainsValue('Total athlete score after combining the counted event scores for this view.', $html);
        },
        'render_athlete_scores_table_uses_competition_places_for_tied_scores' => function () {
            $athletes = [
                'Alex Example' => [
                    'display_name' => 'Alex Example',
                    'clubs' => ['Woden Athletics'],
                    'club' => 'Woden Athletics',
                    'meet_pf' => 1,
                    'score' => 150,
                ],
                'Bailey Example' => [
                    'display_name' => 'Bailey Example',
                    'clubs' => ['Canberra Runners'],
                    'club' => 'Canberra Runners',
                    'meet_pf' => 0.9,
                    'score' => 140,
                ],
                'Casey Example' => [
                    'display_name' => 'Casey Example',
                    'clubs' => ['Canberra Runners'],
                    'club' => 'Canberra Runners',
                    'meet_pf' => 0.8,
                    'score' => 140,
                ],
                'Dakota Example' => [
                    'display_name' => 'Dakota Example',
                    'clubs' => ['Gungahlin Athletics'],
                    'club' => 'Gungahlin Athletics',
                    'meet_pf' => 0.7,
                    'score' => 130,
                ],
            ];

            $html = renderAthleteScoresTable($athletes, false, true, ['athletes' => '1']);

            assertContainsValue('>1<', $html);
            assertContainsValue('>2<', $html);
            assertContainsValue('>4<', $html);
            assertSameValue(2, substr_count($html, '<td style="text-align:right">2</td>'));
            assertNotContainsValue('<td style="text-align:right">3</td>', $html);
        },
        'render_athlete_scores_table_show_all_hides_link_and_omits_club_column_for_filtered_view' => function () {
            $athletes = [
                'Alex Example' => [
                    'display_name' => 'Alex Example',
                    'clubs' => ['Woden Athletics'],
                    'club' => 'Woden Athletics',
                    'meet_pf' => 0.5,
                    'score' => 123,
                ],
            ];

            $html = renderAthleteScoresTable($athletes, 'Woden Athletics', true, ['club' => 'Woden Athletics']);

            assertNotContainsValue('<th>Club</th>', $html);
            assertNotContainsValue('Show all', $html);
            assertContainsValue('0.50', $html);
            assertContainsValue('123', $html);
        },
        'render_club_scores_table_outputs_new_columns_and_tooltips' => function () {
            $clubs = [
                'Woden Athletics' => [
                    'cpf' => 0.625,
                    'score' => 200,
                    'adj' => 125,
                    'officials' => 40,
                    'total' => 165,
                ],
                'Canberra Runners' => [
                    'cpf' => 0.5,
                    'score' => 100,
                    'adj' => 50,
                    'officials' => 20,
                    'total' => 165,
                ],
                'Gungahlin Athletics' => [
                    'cpf' => 0.4,
                    'score' => 90,
                    'adj' => 36,
                    'officials' => 20,
                    'total' => 56,
                ],
            ];

            $html = renderClubScoresTable($clubs);

            assertContainsValue('>Club scores<', $html);
            assertContainsValue('<th style="width:50px"></th>', $html);
            assertContainsValue('Average athlete meet PF for the club', $html);
            assertContainsValue('Club adjusted score before officials: Score × CPF.', $html);
            assertContainsValue('Final club total: Adj + Officials.', $html);
            assertContainsValue('>1<', $html);
            assertContainsValue('>3<', $html);
            assertSameValue(2, substr_count($html, '<td style="text-align:right">1</td>'));
            assertNotContainsValue('<td style="text-align:right">2</td>', $html);
            assertContainsValue('0.625', $html);
            assertContainsValue('>125<', $html);
            assertContainsValue('>40<', $html);
            assertContainsValue('>165<', $html);
            assertNotContainsValue('CPF old', $html);
        },
        'render_view_toggles_disables_athletes_on_club_filter_and_preserves_non_default_query_params' => function () {
            $html = renderViewToggles(
                ['comp' => 'winter', 'records' => '1'],
                true,
                true,
                'Woden Athletics',
                ['ACT Masters Athletics', 'Woden Athletics']
            );

            assertContainsValue('name="comp" value="winter"', $html);
            assertContainsValue('name="records" value="1"', $html);
            assertContainsValue('name="athletes" value="1" checked disabled', $html);
            assertNotContainsValue('toggle-fallback', $html);
            assertContainsValue('<option value="Woden Athletics" selected>', $html);
        },
        'render_view_toggles_adds_athlete_fallback_when_no_club_filter' => function () {
            $html = renderViewToggles([], false, false, false, ['Woden Athletics']);

            assertContainsValue('name="athletes" value="0" class="toggle-fallback"', $html);
            assertContainsValue('Show athlete scores', $html);
            assertContainsValue('<option value="">All clubs</option>', $html);
            assertNotContainsValue('disabled', $html);
        },
        'local_ss_summary_snapshot_matches_expected_values' => function () {
            $files = loadCompetitionFiles('ss', false);
            $resultData = filterExcludedEvents(loadCompetitionResults($files, 'ss'));
            $meetEventArray = buildMeetEventArray($resultData);
            $athletes = groupResultsByAthlete($resultData);
            $summary = buildAthleteSummaries($athletes, [], $meetEventArray, false);
            $athletes = sortAthletesByScore($summary['athletes']);

            $snapshot = [
                'joshua_2008' => [
                    'score' => $athletes['Joshua Smith|2008-11-28']['score'] ?? null,
                    'meet_pf' => scoreFormatNumber((float)($athletes['Joshua Smith|2008-11-28']['meet_pf'] ?? 0), 6),
                    'attended' => $athletes['Joshua Smith|2008-11-28']['attended_meet_count'] ?? null,
                    'eligible' => $athletes['Joshua Smith|2008-11-28']['eligible_meet_count'] ?? null,
                ],
                'lucas_butler' => [
                    'score' => $athletes['Lucas Butler|2010-07-18']['score'] ?? null,
                    'meet_pf' => scoreFormatNumber((float)($athletes['Lucas Butler|2010-07-18']['meet_pf'] ?? 0), 6),
                    'attended' => $athletes['Lucas Butler|2010-07-18']['attended_meet_count'] ?? null,
                    'eligible' => $athletes['Lucas Butler|2010-07-18']['eligible_meet_count'] ?? null,
                ],
            ];

            assertSameValue([
                'joshua_2008' => [
                    'score' => 127.0,
                    'meet_pf' => '0.076923',
                    'attended' => 1,
                    'eligible' => 13,
                ],
                'lucas_butler' => [
                    'score' => 1403.0,
                    'meet_pf' => '0.769231',
                    'attended' => 10,
                    'eligible' => 13,
                ],
            ], $snapshot);
        },
        'local_ss_data_has_three_joshua_smith_identities_after_normalisation' => function () {
            $files = loadCompetitionFiles('ss', false);
            $resultData = loadCompetitionResults($files, 'ss');
            $resultData = filterExcludedEvents($resultData);
            $athletes = groupResultsByAthlete($resultData);

            $joshuaSmithKeys = array_values(array_filter(array_keys($athletes), function ($key) {
                return str_starts_with($key, 'Joshua Smith|');
            }));

            sort($joshuaSmithKeys);

            assertSameValue([
                'Joshua Smith|2002-05-08-amb',
                'Joshua Smith|2008-11-28',
                'Joshua Smith|2009-01-12-amb',
            ], $joshuaSmithKeys);
        },
    ];
}

function buildTestRow($first, $last, $dobRaw, $club, $event, $meet, $age = 16, $gender = 'Male', $resultRaw = 12.34) {
    $dobRaw = trim((string)$dobRaw);
    $dobTimestamp = $dobRaw !== '' ? strtotime($dobRaw) : false;

    return [
        'firstname' => $first,
        'lastname' => $last,
        'dob_raw' => $dobRaw,
        'dob' => $dobTimestamp !== false ? $dobTimestamp : null,
        'club' => $club,
        'event' => $event,
        'meet' => $meet,
        'age' => $age,
        'gender' => $gender,
        'result_raw' => $resultRaw,
        'result_str' => (string)$resultRaw,
        'is_para' => false,
        'meet_date' => null,
        'meet_date_ts' => null,
    ];
}

function assertSameValue($expected, $actual) {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertCountValue($expectedCount, $value) {
    $actualCount = count($value);
    if ($actualCount !== $expectedCount) {
        throw new RuntimeException("Expected count {$expectedCount}, got {$actualCount}");
    }
}

function assertTrueValue($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertContainsValue($needle, $haystack) {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function assertNotContainsValue($needle, $haystack) {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException('Did not expect to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}
