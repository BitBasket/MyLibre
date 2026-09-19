#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 FILE.csv" >&2
    exit 1
fi

CSV_FILE="$1"
SCRIPT="bin/find-threshold-streaks.php"

if [[ ! -x "$SCRIPT" ]]; then
    echo "Script not executable: $SCRIPT" >&2
    exit 1
fi

if [[ ! -f "$CSV_FILE" ]]; then
    echo "CSV file not found: $CSV_FILE" >&2
    exit 1
fi

echo "## Clinical Threshold Overview"
echo

php "$SCRIPT" "$CSV_FILE" \
    --above=180 \
    --above=250 \
    --below=70 \
    --max=180 \
    --max=200 \
    --range=70-180 \
    --min=30 \
    --limit=5

echo
echo "## Strict Recovery Streaks"
echo

php "$SCRIPT" "$CSV_FILE" \
    --max=180 \
    --max=160 \
    --max=150 \
    --min=30 \
    --limit=5

echo
echo "## Clinical Thresholds Allowing 15-Minute Excursions"
echo

php "$SCRIPT" "$CSV_FILE" \
    --range=70-180 \
    --max=180 \
    --above=180 \
    --above=250 \
    --allow=15 \
    --min=60 \
    --limit=5

echo
echo "## Extremely Tight ±5 mg/dL Glucose Bands"
echo

php "$SCRIPT" "$CSV_FILE" \
    --band=100:5 \
    --band=120:5 \
    --band=140:5 \
    --band=150:5 \
    --band=160:5 \
    --band=180:5 \
    --band=200:5 \
    --min=30 \
    --limit=5

echo
echo "## Sustained ±10 and ±20 mg/dL Glucose Bands"
echo

php "$SCRIPT" "$CSV_FILE" \
    --band=120:10 \
    --band=140:10 \
    --band=160:10 \
    --band=180:10 \
    --band=200:10 \
    --band=120:20 \
    --band=140:20 \
    --band=160:20 \
    --band=180:20 \
    --band=200:20 \
    --min=60 \
    --limit=5

echo
echo "## Hyperglycemia-to-Control Transition"
echo

php "$SCRIPT" "$CSV_FILE" \
    --above=180 \
    --above=200 \
    --above=250 \
    --above=300 \
    --max=180 \
    --max=160 \
    --range=70-180 \
    --min=1 \
    --limit=5


