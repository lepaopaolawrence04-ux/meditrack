<?php
// Router for PHP's development server. Never serve database files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/style.css') { header('Content-Type: text/css'); readfile(__DIR__.'/style.css'); exit; }
if ($path !== '/' && $path !== '/index.php') { http_response_code(404); exit('Not found'); }
require __DIR__.'/index.php';
