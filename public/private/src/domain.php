<?php
declare(strict_types=1);

const NUTRIENTS = ['calories', 'protein', 'carbs', 'fat'];

function number_value(mixed $value, string $label, float $minimum = 0): float
{
    if (is_bool($value) || !is_numeric($value)) {
        throw new InvalidArgumentException("$label must be a number.");
    }
    $number = round((float) $value, 3);
    if (!is_finite($number) || $number < $minimum || $number > 1000000) {
        throw new InvalidArgumentException("$label must be between $minimum and 1,000,000.");
    }
    return $number;
}

function valid_date(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Choose a valid date.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
        throw new InvalidArgumentException('Choose a valid date.');
    }
    return $value;
}

function food_values(array $input): array
{
    $name = $input['name'] ?? null;
    if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 160) {
        throw new InvalidArgumentException('Food name must contain 1–160 characters.');
    }
    if (!in_array($input['basis_unit'] ?? null, ['g', 'ml', 'serving'], true)) {
        throw new InvalidArgumentException('Choose grams, milliliters, or servings.');
    }
    $values = [
        'name' => trim($name),
        'basis_amount' => number_value($input['basis_amount'] ?? null, 'Nutrition amount', 0.001),
        'basis_unit' => $input['basis_unit'],
    ];
    foreach (NUTRIENTS as $nutrient) {
        $values[$nutrient] = number_value($input[$nutrient] ?? null, ucfirst($nutrient));
    }
    return $values;
}

function entry_response(array $entry): array
{
    $entry['id'] = (int) $entry['id'];
    $entry['quantity'] = (float) $entry['quantity'];
    $entry['basis_amount'] = (float) $entry['basis_amount'];
    $entry['totals'] = [];
    foreach (NUTRIENTS as $nutrient) {
        $entry['totals'][$nutrient] = (float) $entry[$nutrient] * $entry['quantity'] / $entry['basis_amount'];
    }
    unset($entry['request_key']);
    return $entry;
}
