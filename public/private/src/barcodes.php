<?php
declare(strict_types=1);

function barcode_value(mixed $value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Enter the barcode digits printed on the package.');
    $code = preg_replace('/[\s-]+/', '', $value);
    if (!preg_match('/^(?:[0-9]{8}|[0-9]{12,14})$/', $code)) {
        throw new InvalidArgumentException('Enter an 8, 12, 13, or 14 digit product barcode.');
    }
    // Match OFF normalization so UPC-A and its zero-prefixed EAN resolve once.
    $digits = ltrim($code, '0');
    return str_pad($digits, strlen($digits) <= 8 ? 8 : 13, '0', STR_PAD_LEFT);
}

function off_number(mixed $value): ?float
{
    if (is_bool($value) || !is_numeric($value)) return null;
    $number = (float) $value;
    return is_finite($number) && $number >= 0 && $number <= 1000000 ? round($number, 3) : null;
}

function off_draft(array $product, string $barcode): array
{
    $nutrients = is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [];
    $fields = ['calories' => 'energy-kcal', 'protein' => 'proteins', 'carbs' => 'carbohydrates', 'fat' => 'fat'];
    $per100 = false;
    foreach ([...array_values($fields), 'energy-kj', 'energy'] as $field) {
        if (off_number($nutrients[$field . '_100g'] ?? null) !== null) $per100 = true;
    }
    $suffix = $per100 ? '_100g' : '_serving';
    $name = $product['product_name'] ?? '';
    $draft = ['barcode' => $barcode, 'name' => is_string($name) ? mb_substr(trim($name), 0, 160) : '',
        'basis_amount' => $per100 ? 100 : 1,
        // OFF uses the same _100g field for solids and liquids. Do not guess.
        'basis_unit' => $per100 ? '' : 'serving'];
    foreach ($fields as $key => $field) $draft[$key] = off_number($nutrients[$field . $suffix] ?? null);
    if ($draft['calories'] === null) {
        $kj = off_number($nutrients['energy-kj' . $suffix] ?? $nutrients['energy' . $suffix] ?? null);
        if ($kj !== null) $draft['calories'] = round($kj / 4.184, 3);
    }
    return $draft;
}

function off_fetch(string $barcode): ?array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Barcode lookup needs the PHP cURL extension on your server. You can enter the label manually.');
    $url = 'https://world.openfoodfacts.org/api/v3/product/' . $barcode . '.json?fields=product_name,nutriments';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Calories/1.0 (https://iws.mx/calories/)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($raw === false) throw new RuntimeException('Open Food Facts could not be reached. Try again or enter the label manually.');
    return off_response($status, $raw);
}

function off_response(int $status, string $raw): ?array
{
    if ($status === 404) return null;
    if ($status === 429) throw new RuntimeException('Open Food Facts is limiting requests. Wait a minute or enter the label manually.');
    if ($status !== 200) throw new RuntimeException('Open Food Facts is temporarily unavailable. Try again or enter the label manually.');
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('Open Food Facts returned unreadable data. Enter the label manually.'); }
    if (!is_array($data)) throw new RuntimeException('Open Food Facts returned unreadable data. Enter the label manually.');
    if (($data['status'] ?? null) === 0) return null;
    if (!is_array($data['product'] ?? null)) throw new RuntimeException('Open Food Facts returned no product details. Enter the label manually.');
    return $data['product'];
}
