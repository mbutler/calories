<?php
declare(strict_types=1);
require __DIR__ . '/../public/private/src/domain.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $work): bool
{
    try { $work(); return false; } catch (InvalidArgumentException) { return true; }
}
check(valid_date('2024-02-29') === '2024-02-29', 'Leap day should be accepted.');
foreach (['2025-02-29', '2026-13-01', '26-01-01', '2026-1-1', '0000-01-01', [], null] as $value) {
    check(rejects(fn() => valid_date($value)), 'Invalid date should be rejected.');
}
foreach ([-1, true, null, '', 'nan', '1e999', 1000001, []] as $value) {
    check(rejects(fn() => number_value($value, 'Test')), 'Invalid number should be rejected.');
}
check(rejects(fn() => number_value(0.0001, 'Quantity', 0.001)), 'Rounded zero quantities must be rejected.');
check(number_value('2.1256', 'Quantity') === 2.126, 'Use database precision for calculations.');
$food = ['name' => ' Test food ', 'basis_amount' => 100, 'basis_unit' => 'g', 'calories' => 120, 'protein' => 6, 'carbs' => 20, 'fat' => 2];
check(food_values($food)['name'] === 'Test food', 'Trim food names.');
check(rejects(fn() => food_values([...$food, 'fat' => null])), 'Unknown is not zero.');
check(rejects(fn() => food_values([...$food, 'basis_unit' => 'oz'])), 'Reject unsupported units.');
$entry = entry_response([...$food, 'id' => '1', 'quantity' => '150.000', 'request_key' => 'private']);
check($entry['totals']['calories'] === 180.0, 'Scale calories by quantity.');
check($entry['totals']['protein'] === 9.0, 'Scale macros by quantity.');
check(!isset($entry['request_key']), 'Do not return internal deduplication keys.');
echo "$checks domain checks passed.\n";
