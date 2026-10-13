<?php
// Every deadline in the app (offers, contracts, stages, change requests, admin
// overrides) must be a real calendar date between today and today + 15 years.
// The fields themselves stay optional — empty means "no deadline".

const DEADLINE_MAX_YEARS = 15;

// Returns the normalized 'Y-m-d' string, or null for an empty value.
// Responds 422 and exits on anything else.
function validate_deadline($value, string $label = 'Термін'): ?string {
    if ($value === null || $value === '') return null;

    $value = (string) $value;
    $date  = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        http_response_code(422);
        echo json_encode(['error' => "$label: некоректна дата"]);
        exit;
    }

    $min = new DateTime('today');
    $max = (new DateTime('today'))->modify('+' . DEADLINE_MAX_YEARS . ' years');
    if ($date < $min || $date > $max) {
        http_response_code(422);
        echo json_encode(['error' => "$label має бути в діапазоні від {$min->format('d.m.Y')} до {$max->format('d.m.Y')}"]);
        exit;
    }

    return $value;
}
