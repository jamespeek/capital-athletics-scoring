# Capital Athletics Scoring Systen

## Setup

Requires PHP 8.4+ and [Composer](https://getcomposer.org/).

### Composer

Run `composer install` to download the required dependencies.

### Environment file

Create `.env` file with following data:

`GOOGLE_SPREADSHEET_ID=xxx` Google Sheet ID (for scoring metadata)

### Data files

Note, data isn't included in the repository due to it including names and dates of birth, and also being time dependent.

CSV data from meet manager goes into `./data/{comp name}` e.g. `/data/hn`.

Number the CSV files sequentially (`1.csv`, `2.csv`, ...).

## Optional URL parameters

* `comp` Defaults to `hn`. Needs to match the data directory name above.
* `verbose` Defaults to `true`. Use to show all working.
* `club` Defaults to `none`. Use to filter data down to a club rather than all CA clubs, e.g. 'Woden Athletics'.
 
## Scoring overview

TODO