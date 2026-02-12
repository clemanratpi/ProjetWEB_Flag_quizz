<?php
require_once __DIR__ . "/constants.php";

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;

  $dsn = "pgsql:host=" . DB_SERVER . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  try {
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  } catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["error" => "DB connection failed", "details" => $e->getMessage()]);
    exit;
  }
}

function json_response($data, $code = 200) {
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json_body() {
  $raw = file_get_contents("php://input");
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
