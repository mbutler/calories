<?php
// Creates and drops its own randomly named MySQL database; never uses app data.
declare(strict_types=1);
$root = dirname(__DIR__);
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4';
$user = getenv('TEST_MYSQL_USER') ?: 'root';
$password = getenv('TEST_MYSQL_PASSWORD') ?: '';
$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'calories_test_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$appDsn = $dsn . ';dbname=' . $database;
$pdo = new PDO($appDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$process = null;
$log = tempnam(sys_get_temp_dir(), 'calories-test-');
$testDirectory = sys_get_temp_dir() . '/calories-http-' . bin2hex(random_bytes(6));
mkdir($testDirectory, 0700);
symlink("$root/public", "$testDirectory/calories");
$cookie = '';
$csrf = '';
$checks = 0;
$failed = false;

function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function request(string $path, string $method = 'GET', ?array $data = null, bool $withCsrf = true): array {
    global $base, $cookie, $csrf;
    $handle = curl_init($base . '/api' . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . ($withCsrf ? $csrf : '')],
        CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$cookie) {
            if (stripos($header, 'Set-Cookie: ') === 0) $cookie = explode(';', trim(substr($header, 12)))[0];
            return strlen($header);
        }]);
    if ($data !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data));
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($raw === false) throw new RuntimeException(curl_error($handle));
    $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    return [$status, $result];
}
function launch(array $environment, string $mount = '', bool $disableCurl = false): void {
    global $process, $base, $log, $root, $testDirectory;
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address . $mount;
    // proc_open omits empty environment values on some PHP platforms. Apply
    // those in the test router so local config cannot supply a different value.
    $router = "<?php\n";
    foreach ($environment as $key => $value) {
        if ($value === '') $router .= 'putenv(' . var_export($key . '=', true) . ");\n";
    }
    if ($mount !== '') {
        $router .= 'if (preg_match("#(^|/)(private(?:/|$)|\\\\.)#i", rawurldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH)))) { return require '
            . var_export("$root/router.php", true) . '; }';
        $router .= 'if (str_starts_with($_SERVER["REQUEST_URI"], ' . var_export($mount . '/api/', true) . ')) {'
            . '$_SERVER["SCRIPT_NAME"] = ' . var_export($mount . '/api.php', true) . '; require '
            . var_export("$root/public/api.php", true) . '; return true; } return false;';
    } else {
        $router .= 'return require ' . var_export("$root/router.php", true) . ';';
    }
    file_put_contents("$testDirectory/router.php", $router);
    $documentRoot = $mount === '' ? "$root/public" : $testDirectory;
    $phpOptions = $disableCurl ? ['-d', 'disable_functions=curl_init'] : [];
    $process = proc_open([PHP_BINARY, ...$phpOptions, '-S', $address, '-t', $documentRoot, "$testDirectory/router.php"],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), $environment));
    if (!is_resource($process)) throw new RuntimeException('Could not start test server.');
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try { request('/session'); return; } catch (Throwable) {}
    }
    throw new RuntimeException('Test server did not start.');
}

function check_assets(): void {
    global $base, $root;
    foreach (['/' => 'index.html', '/assets/app.js' => 'assets/app.js', '/assets/style.css' => 'assets/style.css', '/icon.svg' => 'icon.svg'] as $path => $file) {
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $contents = curl_exec($handle);
        check(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200
            && $contents === file_get_contents("$root/public/$file"), "Serve $base$path from the public directory.");
    }
    foreach (['/private/config.php', '/private/config.example.php', '/private/src/bootstrap.php', '/private/src/domain.php', '/private/.htaccess', '/.htaccess', '/%70rivate/config.php'] as $path) {
        $handle = curl_init($base . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $contents = curl_exec($handle);
        check(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 403 && $contents === 'Forbidden', "Block direct access to $base$path.");
    }
}

try {
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $environment = ['APP_ENV' => 'development', 'DB_DSN' => $appDsn, 'DB_USER' => $user, 'DB_PASSWORD' => $password, 'APP_TIMEZONE' => 'America/Chicago'];
    launch($environment);
    check_assets();
    [$status, $session] = request('/session');
    $csrf = $session['csrf'];
    check($status === 200 && $session['authenticated'], 'Local session should authenticate.');
    check($session['today'] === (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('Y-m-d'), 'Use configured timezone.');
    $food = ['name' => 'Integration 100%_ test', 'basis_amount' => 100, 'basis_unit' => 'g', 'calories' => 200, 'protein' => 10, 'carbs' => 20, 'fat' => 5];
    check(request('/foods', 'POST', $food, false)[0] === 403, 'Writes require CSRF.');
    [$status, $saved] = request('/foods', 'POST', $food);
    check($status === 201, 'Create a food.');
    $id = (int) $saved['id'];
    check(count(request('/foods?q=100%25_')[1]) === 1, 'Search literal percent and underscore.');
    check(count(request('/foods?q=%27%20OR%201%3D1--')[1]) === 0, 'Treat SQL-looking search as text.');
    check(request('/foods', 'POST', [...$food, 'protein' => null])[0] === 422, 'Reject missing nutrients.');
    $payload = ['food_id' => $id, 'date' => '2026-09-28', 'quantity' => 150, 'request_key' => 'a2d530dd-68b9-4d3b-9c21-bd097bcfb91b'];
    [$status, $entry] = request('/logs', 'POST', $payload);
    check($status === 201 && $entry['totals']['calories'] === 300, 'Log scaled nutrition.');
    $entryId = (int) $entry['id'];
    check(request('/logs', 'POST', $payload)[1]['id'] === $entryId, 'Retry should return same entry.');
    check(request('/logs', 'POST', [...$payload, 'quantity' => 200])[0] === 409, 'Reject conflicting duplicate.');
    check(count(request('/logs?date=2026-09-28')[1]['entries']) === 1, 'Retries must not add rows.');
    check(request('/logs?date=2026-09-29')[1]['totals']['calories'] === 0, 'Dates are isolated.');
    check(request('/logs?date=2026-02-30')[0] === 422, 'Reject impossible dates.');
    request("/foods/$id", 'PUT', [...$food, 'name' => 'Changed food', 'calories' => 900, 'basis_unit' => 'ml']);
    $past = request('/logs?date=2026-09-28')[1];
    check($past['totals']['calories'] === 300 && $past['entries'][0]['food_name'] === $food['name'] && $past['entries'][0]['basis_unit'] === 'g', 'Food edits must not rewrite history.');
    $edited = request("/logs/$entryId", 'PATCH', ['quantity' => 75])[1];
    check($edited['totals']['calories'] === 150 && $edited['totals']['protein'] === 7.5, 'Quantity edits must use original nutrition.');
    check(request("/logs/$entryId", 'PATCH', ['quantity' => 0])[0] === 422, 'Reject zero quantity.');
    check(request('/logs', 'POST', [...$payload, 'request_key' => '18c180bb-2df0-4bfb-a2dc-4046164a1472', 'quantity' => 100])[1]['totals']['calories'] === 900, 'New logs use edited nutrition.');
    $targets = ['calories' => 2200, 'protein' => 150, 'carbs' => 240, 'fat' => 70];
    check(request('/targets', 'PUT', $targets)[0] === 200, 'Save targets.');
    check((float) request('/targets')[1]['protein'] === 150.0, 'Targets persist.');
    check(request('/targets', 'PUT', [...$targets, 'fat' => -1])[0] === 422, 'Reject negative targets.');
    check(request("/logs/$entryId", 'DELETE', [])[0] === 200, 'Delete an entry.');
    check(request('/logs?date=2026-09-28')[1]['totals']['calories'] === 900, 'Deletion updates totals.');
    $cookie = '';
    launch($environment, '/calories');
    check_assets();
    $session = request('/session')[1];
    $csrf = $session['csrf'];
    check($session['authenticated'] && request('/foods')[0] === 200, 'Subfolder API supports sessions and food search.');
    check(request('/targets', 'PUT', $targets)[0] === 200, 'Subfolder API supports writes.');
    // Cached barcode lookups are independent of the external food service.
    $barcodeFood = [...$food, 'name' => 'Barcode fixture', 'barcode' => '012345678905'];
    [$status, $barcodeSaved] = request('/foods', 'POST', $barcodeFood);
    check($status === 201 && $barcodeSaved['barcode'] === '0012345678905', 'Save normalized barcode with reviewed food.');
    check(request('/foods/lookup', 'POST', ['barcode' => '0012345678905'])[1]['cached'] === true, 'Equivalent barcode finds local food.');
    check(request('/foods/lookup', 'POST', ['barcode' => '012345678905'])[1]['food']['id'] === $barcodeSaved['id'], 'Repeat scan reuses the same food.');
    check(request('/foods', 'POST', $barcodeFood)[0] === 409, 'Duplicate food barcode gives useful conflict.');
    check(request('/foods/lookup', 'POST', ['barcode' => 'not a code'])[0] === 422, 'Reject invalid lookup without upstream request.');
    check(request('/foods/lookup', 'POST', ['barcode' => '012345678905'], false)[0] === 403, 'Barcode lookup requires CSRF.');
    check(request('/foods/' . $barcodeSaved['id'], 'PUT', [...$food, 'name' => 'Corrected barcode food'])[0] === 200, 'Editing a food without a barcode field preserves it.');
    check(request('/foods/lookup', 'POST', ['barcode' => '012345678905'])[1]['food']['name'] === 'Corrected barcode food', 'Future scans use local corrections.');
    $cookie = '';
    launch($environment, '/calories', true);
    $csrf = request('/session')[1]['csrf'];
    check(request('/foods/lookup', 'POST', ['barcode' => '012345678905'])[0] === 200, 'Cached foods still work without PHP cURL.');
    [$status, $failure] = request('/foods/lookup', 'POST', ['barcode' => '96385074']);
    check($status === 502 && str_contains($failure['error'], 'cURL'), 'Missing cURL gets an actionable error.');
    // Verify production auth using an explicit cookie header; TLS is handled by deployment.
    $cookie = '';
    launch([...$environment, 'APP_ENV' => 'production', 'APP_PASSWORD_HASH' => password_hash('test-only-password', PASSWORD_DEFAULT)]);
    $session = request('/session')[1];
    $csrf = $session['csrf'];
    check(!$session['authenticated'] && request('/foods')[0] === 401, 'Production must require authentication.');
    check(request('/login', 'POST', ['password' => 'wrong'])[0] === 401, 'Reject incorrect password.');
    check(request('/login', 'POST', ['password' => 'test-only-password'])[0] === 200, 'Accept configured password.');
    check(request('/foods')[0] === 200, 'Signed-in session can access foods.');
    check(request('/logout', 'POST', [])[0] === 200 && request('/foods')[0] === 401, 'Logout removes access.');
    echo "$checks MySQL/API checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n" . file_get_contents($log));
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $admin->exec("DROP DATABASE `$database`");
    unlink($log);
    unlink("$testDirectory/router.php");
    unlink("$testDirectory/calories");
    rmdir($testDirectory);
}
exit($failed ? 1 : 0);
