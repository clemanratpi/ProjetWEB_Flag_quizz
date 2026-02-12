<?php
session_start();
require_once __DIR__ . "/db.php";

$pdo = db();

$action = $_GET["action"] ?? "";

if ($action === "register") {
  $body = read_json_body();
  $username = trim($body["username"] ?? "");
  $password = $body["password"] ?? "";

  if ($username === "" || strlen($username) > 40 || $password === "") {
    json_response(["ok" => false, "error" => "Username/password invalid"], 400);
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  try {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :h) RETURNING id, username");
    $stmt->execute([":u" => $username, ":h" => $hash]);
    $user = $stmt->fetch();

    $_SESSION["user_id"] = (int)$user["id"];
    $_SESSION["username"] = $user["username"];

    json_response(["ok" => true, "user" => ["id" => (int)$user["id"], "username" => $user["username"]]]);
  } catch (PDOException $e) {
    json_response(["ok" => false, "error" => "Username already used"], 409);
  }
}

if ($action === "login") {
  $body = read_json_body();
  $username = trim($body["username"] ?? "");
  $password = $body["password"] ?? "";

  $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :u");
  $stmt->execute([":u" => $username]);
  $user = $stmt->fetch();

  if (!$user || !password_verify($password, $user["password_hash"])) {
    json_response(["ok" => false, "error" => "Bad credentials"], 401);
  }

  $_SESSION["user_id"] = (int)$user["id"];
  $_SESSION["username"] = $user["username"];

  json_response(["ok" => true, "user" => ["id" => (int)$user["id"], "username" => $user["username"]]]);
}

if ($action === "logout") {
  session_destroy();
  json_response(["ok" => true]);
}

if ($action === "me") {
  if (!isset($_SESSION["user_id"])) json_response(["ok" => false, "user" => null]);
  json_response(["ok" => true, "user" => ["id" => (int)$_SESSION["user_id"], "username" => $_SESSION["username"]]]);
}

json_response(["ok" => false, "error" => "Unknown action"], 404);
