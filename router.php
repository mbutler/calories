<?php
// Development router. In production Apache serves public/ using its .htaccess.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
// PHP's development server ignores .htaccess, so enforce the same private
// directory and dotfile restrictions here before serving any files.
if (preg_match('#(^|/)(private(?:/|$)|\.)#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/api.php';
    return true;
}
return false;
