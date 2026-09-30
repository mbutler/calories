<?php
declare(strict_types=1);
require __DIR__ . '/../public/private/src/domain.php';
require __DIR__ . '/../public/private/src/barcodes.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
check(barcode_value('012345678905') === '0012345678905', 'Normalize UPC-A to EAN.');
check(barcode_value('0012345678905') === barcode_value('00 12345678905'), 'Preserve equivalent leading zeros.');
check(barcode_value('00012345678905') === '0012345678905', 'Normalize zero-prefixed GTIN.');
check(barcode_value('96385074') === '96385074', 'Preserve EAN-8.');
foreach (['abc', '123', 123456789012, 'https://example.com', null] as $value) {
    try { barcode_value($value); check(false, 'Reject malformed barcode.'); }
    catch (InvalidArgumentException) { check(true, 'Rejected malformed barcode.'); }
}
$product = ['product_name' => 'Test product', 'nutriments' => ['energy-kcal_100g' => 200, 'proteins_100g' => 12, 'carbohydrates_100g' => 20, 'fat_100g' => 8]];
$draft = off_draft($product, '0012345678905');
check($draft['calories'] === 200.0 && $draft['protein'] === 12.0, 'Read normalized nutrition.');
check($draft['basis_amount'] === 100 && $draft['basis_unit'] === '', 'Require unit confirmation for both solids and liquids.');
check(off_draft(['nutriments' => ['fat_100g' => 0]], '96385074')['fat'] === 0.0, 'Zero is valid nutrition.');
check(off_draft(['nutriments' => ['fat_100g' => 0]], '96385074')['protein'] === null, 'Missing is not zero.');
check(off_draft(['nutriments' => ['energy-kj_100g' => 418.4]], '96385074')['calories'] === 100.0, 'Convert kJ to kcal.');
check(off_draft(['nutriments' => ['energy-kcal_serving' => 90]], '96385074')['basis_unit'] === 'serving', 'Support serving-only nutrition.');
check(off_draft(['nutriments' => ['energy-kcal_prepared_100g' => 90]], '96385074')['calories'] === null, 'Do not mix prepared and as-sold values.');
check(off_draft(['product_name' => [], 'nutriments' => 'invalid'], '96385074')['name'] === '', 'Tolerate malformed upstream fields.');
foreach ([false, -1, 'unknown', 'INF', 1000001] as $value) check(off_number($value) === null, 'Invalid upstream values stay unknown.');
check(off_response(404, '{}') === null, 'A missing barcode can be entered manually.');
check(off_response(200, '{"status":0}') === null, 'Support legacy not-found response.');
check(off_response(200, json_encode(['status' => 'success', 'product' => $product])) === $product, 'Accept v3 product response.');
foreach ([[429, '{}'], [503, '{}'], [200, '<html>unavailable</html>'], [200, 'null'], [200, '{}']] as [$status, $raw]) {
    try { off_response($status, $raw); check(false, 'Upstream failure must be surfaced.'); }
    catch (RuntimeException) { check(true, 'Failure is actionable, not a zero-nutrition product.'); }
}
echo "$checks barcode checks passed.\n";
