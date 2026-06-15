# Capital Athletics Scoring System

## Setup

Requires PHP 8+ and [Composer](https://getcomposer.org/).

### Composer

Run `composer install` to download the required dependencies.

### Environment file

Create `.env` file with following data:

`GOOGLE_SPREADSHEET_ID=xxx` Google Sheet ID (for scoring metadata)


### Google credentials

Create `.credentials.json` in the project root with credentials that can read the Google Sheet which houses the comp metadata:

https://docs.google.com/spreadsheets/d/1TqaJoy5OCHdzD-nqdvmxRgUtg41Qk67aL3DrN4xza5A

### Data files

Note, data isn't included in the repository due to it including names and dates of birth, and also being season dependent.

CSV data from meet manager goes into `./data/{comp name}` e.g. `/data/ss`.

Number the CSV files sequentially (`1.csv`, `2.csv`, ...).

For champs files, keep the numeric prefix for sort order and suffix the file with either `u9-18` or `u20-open`, for example `8-u20-open.csv` or `9-u9-18.csv`.

## File responsibilities

* `index.php` handles request parameters, coordinates the scoring flow, and outputs the page shell.
* `utils.php` handles app config, CSV loading, meet and club normalisation, grouping, sorting, and club summary preparation.
* `scoring.php` handles ACT record lookup, WMA adjustments, points scoring, and event-level scoring logic.
* `render.php` handles HTML output and tooltip display formatting.

## Optional URL parameters

* `comp` Defaults to `ss`. It must match a directory inside `data`.
* `verbose` Defaults to `false`. Use it to show the per-athlete working as well as the summary tables.
* `athletes` Defaults to `true`. Use `athletes=false` to hide the athlete scores table in the full, unfiltered view.
* `all_athletes` Defaults to `false`. Use `all_athletes=true` to expand the athlete scores table beyond the default top 20 rows.
* `club` Defaults to `none`. Use it to filter results down to a single club, for example `Woden Athletics`.
* `records` Defaults to `false`. Use `records=true` to show the potential records table in the full, unfiltered view.
 
## Scoring overview

The app reads all CSV files for the selected competition, normalises the meet and event names, and combines them into one result set.

The base score, excluded events, and excluded event dates are set in `appConfig()` in `utils.php`.

The remaining results are grouped by athlete and then by event. For each event:

* The app works out which meets offered that event.
* It finds the athlete's best raw score for that event across those meets.
* It calculates a participation factor based on how many times the athlete entered that event compared with how many times it was offered.
* The final event score is the best points score multiplied by the participation factor, rounded to a whole number.

The points score for each result is based on ACT record data in `data/reference/combined-act.csv` plus WMA adjustment tables for masters athletes:

* Under-35 athletes are scored against the next age group up.
* Athletes aged 35 and over are adjusted using WMA factors and then compared against Open records.
* Time events and field events are compared differently so that a stronger performance always produces a higher score.
* The final points value is calculated by multiplying the performance ratio by the base score from `appConfig()` in `utils.php` (currently `800`) and rounding to a whole number.

Athlete totals are then calculated:

* In the full competition view for CA, only the athlete's top 4 event scores count.
* In club-filtered view, all scored events count.

Club totals are built from the summed athlete totals and then adjusted using:

* `CPF old`: athletes entered divided by club size
* `CPF`: the average club-specific athlete meet PF for the club
* officials bonus: `20` points for each official recorded for each meet

The club score columns are:

* `Adj`: `club athlete total * CPF`
* `Total`: `Adj + officials bonus`

Athletes also have a meet PF used for the club `CPF` calculation:

* meet PF: distinct meets attended divided by the total meets in the current competition view
* this meet PF is separate from the existing event-level PF used in athlete scoring
* athletes are grouped by display name plus a normalised DOB key, with ambiguous swapped month/day DOBs treated as the same identity

## UI behaviour

* The top control row lets users toggle `Verbose`, toggle `Show athlete scores`, and filter to a single club.
* In the full, unfiltered view, the athlete scores table shows the top 20 scored athletes by default and has a `Show all` link underneath.
* In club-filtered view, athlete scores are always shown and the club scores / potential records sections are hidden.

## Tests

Run the business-logic test suite with:

`php tests/run.php`

The tests use a lightweight native PHP runner in `tests/` and cover athlete identity grouping, DOB normalisation, club-scoped scoring, CPF calculations, and one integration check against the local `data/ss` dataset.

## Scoring exceptions

The scoring code also includes a few explicit business-rule overrides that sit on top of the general rules:

* Specific event/date exclusions can be added in `appConfig()['excluded_event_dates']`. These dates are treated as athlete-specific extra opportunities: athletes who did not contest them are not penalised in participation factor, while athletes who did contest them can still use the result for scoring and get the corresponding participation credit. This is useful when an event was run as an invitational.
* For `U9-U18 Champs`, athletes aged `12` and under are scored against their own age records instead of the usual "one age up" junior lookup.
* For masters hurdles, `80/100/110m Hurdles` are mapped through WMA as `Short Hurdles` and compared against Open `110m Hurdles` for men or Open `100m Hurdles` for women.
* For masters hurdles, `200/300/400m Hurdles` are mapped through WMA as `Long Hurdles` and compared against Open `400m Hurdles`.
* For masters steeple, `2000m/3000m Steeple` is mapped through WMA as `Steeple Chase` and compared against Open `3000m Steeple`.
* For male masters aged `35-59`, a `2000m Steeple` performance is projected to a `3000m` comparison time using `time * 1.5 + 15 seconds` before scoring.
