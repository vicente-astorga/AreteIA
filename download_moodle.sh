#!/bin/bash
set -e

MOODLE_VERSION=${1:-"MOODLE_405_STABLE"} # Default to 4.5 Stable
TARGET_DIR="./src"

if [ -d "$TARGET_DIR/admin" ]; then
    echo "Moodle already seems to be present in $TARGET_DIR. Skipping download."
    exit 0
fi

echo "Downloading Moodle core ($MOODLE_VERSION)..."
tmp_dir=$(mktemp -d)
git clone --depth 1 -b "$MOODLE_VERSION" https://github.com/moodle/moodle.git "$tmp_dir"

echo "Moving Moodle to $TARGET_DIR..."
mkdir -p "$TARGET_DIR"
cp -R "$tmp_dir/." "$TARGET_DIR/"
rm -rf "$tmp_dir"

echo "Moodle core downloaded successfully to $TARGET_DIR."
