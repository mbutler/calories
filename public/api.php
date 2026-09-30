<?php
declare(strict_types=1);
require __DIR__ . '/private/src/bootstrap.php';
require __DIR__ . '/private/src/barcodes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => $config['environment'] !== 'development',
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$localDevelopment = $config['environment'] === 'development'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$authenticated = $localDevelopment || ($_SESSION['authenticated'] ?? false);
$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
// Apache rewrites /calories/api/... to /calories/api.php. Remove that
// deployment prefix so the same endpoints work at / and any subdirectory.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if (str_ends_with($scriptName, '/api.php')) {
    $basePath = rtrim(dirname($scriptName), '/');
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
}

try {
    if ($path === '/api/session' && $method === 'GET') {
        respond(['authenticated' => $authenticated, 'csrf' => $_SESSION['csrf'],
            'today' => date('Y-m-d'), 'timezone' => $config['timezone'], 'local_development' => $localDevelopment]);
    }
    if ($method !== 'GET' && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        respond(['error' => 'Your session expired. Reload the page and try again.'], 403);
    }
    if ($path === '/api/login' && $method === 'POST') {
        $input = body();
        $password = $input['password'] ?? '';
        if (!$config['password_hash']) respond(['error' => 'Set an app password on the server before signing in.'], 503);
        if (!is_string($password) || strlen($password) > 1024 || !password_verify($password, $config['password_hash'])) {
            respond(['error' => 'That password is incorrect.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        respond(['ok' => true]);
    }
    if (!$authenticated) respond(['error' => 'Sign in to continue.'], 401);
    if ($path === '/api/logout' && $method === 'POST') {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }
    if ($path === '/api/foods/lookup' && $method === 'POST') {
        $barcode = barcode_value(body()['barcode'] ?? null);
        $food = query('SELECT * FROM foods WHERE barcode=?', [$barcode])->fetch();
        if ($food) respond(['cached' => true, 'food' => $food]);
        // Limit external requests in this single-user session; cached foods are unlimited.
        $recent = array_values(array_filter($_SESSION['barcode_requests'] ?? [], fn($time) => $time > time() - 60));
        if (count($recent) >= 12) respond(['error' => 'Please wait a minute before looking up another new barcode. You can still enter it manually.'], 429);
        $recent[] = time();
        $_SESSION['barcode_requests'] = $recent;
        try { $product = off_fetch($barcode); }
        catch (RuntimeException $error) { respond(['error' => $error->getMessage()], 502); }
        $_SESSION['barcode_sources'][$barcode] = $product === null ? 'manual' : 'openfoodfacts';
        $_SESSION['barcode_sources'] = array_slice($_SESSION['barcode_sources'], -20, null, true);
        respond(['cached' => false, 'found' => $product !== null,
            'food' => off_draft($product ?? [], $barcode)]);
    }
    if ($path === '/api/foods' && $method === 'GET') {
        $search = $_GET['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 160) throw new InvalidArgumentException('Search must be 160 characters or fewer.');
        // Escape LIKE wildcards so a search is literal; bind the entire pattern.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
        $rows = query("SELECT f.*, (SELECT MAX(l.id) FROM log_entries l WHERE l.food_id = f.id) AS last_logged
            FROM foods f WHERE f.name LIKE ? ESCAPE '!'
            ORDER BY last_logged DESC, f.id DESC LIMIT 50", [$pattern])->fetchAll();
        respond($rows);
    }
    if ($path === '/api/foods' && $method === 'POST') {
        $input = body();
        $values = food_values($input);
        $barcode = isset($input['barcode']) && $input['barcode'] !== '' ? barcode_value($input['barcode']) : null;
        $source = $barcode ? ($_SESSION['barcode_sources'][$barcode] ?? 'manual') : 'manual';
        try {
            query('INSERT INTO foods (name, basis_amount, basis_unit, calories, protein, carbs, fat, barcode, source, source_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...array_values($values), $barcode, $source, $source === 'openfoodfacts' ? $barcode : null]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            respond(['error' => 'This barcode is already saved. Close this form and look it up again to use or edit that food.'], 409);
        }
        respond(record('foods', (int) db()->lastInsertId()), 201);
    }
    if (preg_match('#^/api/foods/([1-9][0-9]*)$#', $path, $match) && $method === 'PUT') {
        $id = (int) $match[1];
        $existing = record('foods', $id);
        $input = body();
        $values = food_values($input);
        $barcode = array_key_exists('barcode', $input) ? ($input['barcode'] === '' ? null : barcode_value($input['barcode'])) : $existing['barcode'];
        try {
            query('UPDATE foods SET name=?, basis_amount=?, basis_unit=?, calories=?, protein=?, carbs=?, fat=?, barcode=? WHERE id=?', [...array_values($values), $barcode, $id]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            respond(['error' => 'That barcode belongs to another saved food.'], 409);
        }
        respond(record('foods', $id));
    }
    if ($path === '/api/logs' && $method === 'GET') {
        $date = valid_date($_GET['date'] ?? date('Y-m-d'));
        $entries = array_map('entry_response', query('SELECT * FROM log_entries WHERE logged_date=? ORDER BY id DESC', [$date])->fetchAll());
        $totals = array_fill_keys(NUTRIENTS, 0.0);
        foreach ($entries as $entry) foreach (NUTRIENTS as $key) $totals[$key] += $entry['totals'][$key];
        respond(['date' => $date, 'entries' => $entries, 'totals' => $totals]);
    }
    if ($path === '/api/logs' && $method === 'POST') {
        $input = body();
        $id = filter_var($input['food_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new InvalidArgumentException('Choose a food to log.');
        $date = valid_date($input['date'] ?? date('Y-m-d'));
        $quantity = number_value($input['quantity'] ?? null, 'Quantity', 0.001);
        $key = $input['request_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $key)) {
            throw new InvalidArgumentException('A valid request key is required.');
        }
        $food = record('foods', $id);
        try {
            query('INSERT INTO log_entries (food_id, logged_date, food_name, basis_amount, basis_unit, calories, protein, carbs, fat, quantity, request_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $date, $food['name'], $food['basis_amount'], $food['basis_unit'],
                    $food['calories'], $food['protein'], $food['carbs'], $food['fat'], $quantity, $key]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            $existing = query('SELECT * FROM log_entries WHERE request_key=?', [$key])->fetch();
            if (!$existing) throw $error;
            if ((int) $existing['food_id'] !== $id || $existing['logged_date'] !== $date || (float) $existing['quantity'] !== $quantity) {
                respond(['error' => 'This request was already used. Close this form and try again.'], 409);
            }
            respond(entry_response($existing));
        }
        respond(entry_response(record('log_entries', (int) db()->lastInsertId())), 201);
    }
    if (preg_match('#^/api/logs/([1-9][0-9]*)$#', $path, $match)) {
        $id = (int) $match[1];
        if ($method === 'PATCH') {
            $input = body();
            $quantity = number_value($input['quantity'] ?? null, 'Quantity', 0.001);
            record('log_entries', $id);
            query('UPDATE log_entries SET quantity=? WHERE id=?', [$quantity, $id]);
            respond(entry_response(record('log_entries', $id)));
        }
        if ($method === 'DELETE') {
            query('DELETE FROM log_entries WHERE id=?', [$id]);
            respond(['ok' => true]);
        }
    }
    if ($path === '/api/targets' && $method === 'GET') respond(record('targets', 1));
    if ($path === '/api/targets' && $method === 'PUT') {
        $input = body();
        $values = [];
        foreach (NUTRIENTS as $key) $values[] = number_value($input[$key] ?? null, ucfirst($key));
        query('UPDATE targets SET calories=?, protein=?, carbs=?, fat=? WHERE id=1', $values);
        respond(record('targets', 1));
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (InvalidArgumentException | JsonException $error) {
    respond(['error' => $error instanceof JsonException ? 'Send valid JSON.' : $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(['error' => 'The server could not complete that request. Please try again.'], 500);
}
