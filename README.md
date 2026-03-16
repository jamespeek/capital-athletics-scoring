# Capital Athletics Scoring System

## Setup

Requires PHP 8+ and [Composer](https://getcomposer.org/).

### Composer

Run `composer install` to download the required dependencies.

### Environment file

Create `.env` file with following data:

`GOOGLE_SPREADSHEET_ID=xxx` Google Sheet ID (for scoring metadata)

### Google credentials

Create `.credentials.json` in the project root with credentials that can read the Google Sheet above.

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
* `verbose` Defaults to `true`. Use it to show the per-athlete working as well as the summary tables.
* `club` Defaults to `none`. Use it to filter results down to a single club, for example `Woden Athletics`.
 
## Scoring overview

The app reads all CSV files for the selected competition, normalises the meet and event names, and combines them into one result set.

The base score and excluded events are set in `appConfig()` in `utils.php`.

The remaining results are grouped by athlete and then by event. For each event:

* The app works out which meets offered that event.
* It finds the athlete's best raw score for that event across those meets.
* It calculates a participation factor based on how many times the athlete entered that event compared with how many times it was offered.
* The final event score is the best points score multiplied by the participation factor, rounded to a whole number.

The points score for each result is based on ACT record data in `data/reference/combined-act.csv` plus WMA adjustment tables for masters athletes:

* Under-35 athletes are scored against the next age group up.
* Athletes aged 35 and over are adjusted using WMA factors and then compared against Open records.
* Time events and field events are compared differently so that a stronger performance always produces a higher score.
* The score scale is based on the app config in `utils.php`.

Athlete totals are then calculated:

* In the full competition view for CA, only the athlete's top 4 event scores count.
* In club-filtered view, all scored events count.

Club totals are built from the summed athlete totals and then adjusted using:

* club participation factor: athletes entered divided by club size
* officials bonus: `20` points for each official recorded for each meet

The final club adjusted score is:

`club athlete total * participation factor + officials bonus`
