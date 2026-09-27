<?php
use Illuminate\Foundation\Application;use Illuminate\Http\Request;
define('LARAVEL_START',microtime(true));require dirname(__DIR__).'/vendor/autoload.php';$app=require_once dirname(__DIR__).'/bootstrap/app.php';$app->handleRequest(Request::capture());
