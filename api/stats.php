<?php
session_start();
require_once __DIR__ . "/db.php";

$pdo = db();

if (!isset($_SESSION["user_id"])) {
  json_response(["ok" => false, "error" => "Not logged in"], 401);
}

$uid = (int)$_SESSION["user_id"];

$stmt = $pdo->prepare("SELECT COALESCE(MAX(score),0) AS best FROM games WHERE user_id = :u");
$stmt->execute([":u" => $uid]);
$best = (int)$stmt->fetch()["best"];

$stmt = $pdo->prepare("SELECT id, score, started_at, ended_at FROM games WHERE user_id = :u ORDER BY started_at DESC LIMIT 10");
$stmt->execute([":u" => $uid]);
$history = $stmt->fetchAll();

json_response(["ok" => true, "best" => $best, "history" => $history, "user" => ["id" => $uid, "username" => $_SESSION["username"]]]);
