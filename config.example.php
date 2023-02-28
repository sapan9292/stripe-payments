<?php

// DB CONNECTION START
$dbHost = "localhost";
$dbUsername = "";
$dbPassword = "";
$dbname = "";
$connection = new mysqli($dbHost, $dbUsername, $dbPassword, $dbname);
if ($connection->connect_error)
  die("Some Error Occured on our end, please try again in sometime.");
// DB CONNECTION START

// PAGE PATHS START
$host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")."://".$_SERVER['HTTP_HOST'];
$fullPagePath = $host.$_SERVER['REQUEST_URI'];
$pagePathNoParam = (isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : $_SERVER['SCRIPT_URL']);
$erpBasePath = $host.'/production';
$baseDir = "/stripe";
$basePath = $host.$baseDir;
$assetsPath = $basePath."/layouts/assets";
$scriptName = $_SERVER['SCRIPT_NAME'];
// PAGE PATHS END
?>