<?php
session_start();
require_once __DIR__ . "/db.php";

$pdo = db();

function require_login() {
  if (!isset($_SESSION["user_id"])) {
    json_response(["ok" => false, "error" => "Not logged in"], 401);
  }
}

/**
 * Normalize string for comparison:
 * - trim
 * - lowercase (mb if available)
 * - remove accents (iconv if available)
 * - keep a-z0-9 spaces
 * - collapse spaces
 */
function normalize($s) {
  $s = trim((string)$s);

  if (function_exists("mb_strtolower")) {
    $s = mb_strtolower($s, "UTF-8");
  } else {
    $s = strtolower($s);
  }

  if (function_exists("iconv")) {
    $tmp = @iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s);
    if ($tmp !== false) $s = $tmp;
  }

  $s = preg_replace("/[^a-z0-9 ]+/", " ", $s);
  $s = preg_replace("/\s+/", " ", $s);
  return trim($s);
}

$action = $_GET["action"] ?? "";
require_login();

/* -------------------- START -------------------- */
if ($action === "start") {
  $stmt = $pdo->prepare("INSERT INTO games (user_id, score) VALUES (:uid, 0) RETURNING id");
  $stmt->execute([":uid" => (int)$_SESSION["user_id"]]);
  $gameId = (int)$stmt->fetchColumn();

  $_SESSION["game_id"] = $gameId;
  $_SESSION["score"] = 0;
  $_SESSION["lives"] = 3;
  $_SESSION["used_country_ids"] = [];
  unset($_SESSION["current_country_id"]);

  json_response(["ok" => true, "game_id" => $gameId, "score" => 0, "lives" => 3]);
}

/* -------------------- NEXT -------------------- */
if ($action === "next") {
  $gameId = (int)($_SESSION["game_id"] ?? 0);
  if ($gameId <= 0) json_response(["ok" => false, "error" => "No active game"], 400);

  $used = $_SESSION["used_country_ids"] ?? [];
  if (!is_array($used)) $used = [];

  if (count($used) > 0) {
    $placeholders = [];
    $params = [];
    foreach ($used as $i => $id) {
      $ph = ":c" . $i;
      $placeholders[] = $ph;
      $params[$ph] = (int)$id;
    }
    $sql = "SELECT id, flag_url
            FROM countries
            WHERE id NOT IN (" . implode(",", $placeholders) . ")
            ORDER BY RANDOM()
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  } else {
    $stmt = $pdo->query("SELECT id, flag_url FROM countries ORDER BY RANDOM() LIMIT 1");
  }

  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    json_response(["ok" => true, "done" => true, "message" => "No more countries"]);
  }

  $countryId = (int)$row["id"];
  $flagUrl = $row["flag_url"];

  $_SESSION["current_country_id"] = $countryId;

  json_response([
    "ok" => true,
    "done" => false,
    "country_id" => $countryId,
    "flag_url" => $flagUrl,
    "lives" => (int)($_SESSION["lives"] ?? 3),
    "score" => (int)($_SESSION["score"] ?? 0)
  ]);
}

/* -------------------- GUESS -------------------- */
if ($action === "guess") {
  $gameId = (int)($_SESSION["game_id"] ?? 0);
  if ($gameId <= 0) json_response(["ok" => false, "error" => "No active game"], 400);

  // Read JSON body
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    json_response(["ok" => false, "error" => "Invalid JSON body"], 400);
  }

  $answer = (string)($data["answer"] ?? "");
  $countryId = (int)($data["country_id"] ?? 0);

  if ($countryId <= 0) {
    json_response(["ok" => false, "error" => "Missing country_id"], 400);
  }

  // Must guess the country that was served by "next"
  $current = (int)($_SESSION["current_country_id"] ?? 0);
  if ($current <= 0) {
    json_response(["ok" => false, "error" => "No country to guess (call next first)"], 400);
  }
  if ($countryId !== $current) {
    json_response(["ok" => false, "error" => "Invalid country_id for current question"], 400);
  }

  // Fetch correct names
  $stmt = $pdo->prepare("SELECT name_fr, name_en FROM countries WHERE id = :id");
  $stmt->execute([":id" => $countryId]);
  $country = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$country) {
    json_response(["ok" => false, "error" => "Country not found"], 404);
  }

  $userAnswer = normalize($answer);
  $fr = normalize($country["name_fr"] ?? "");
  $en = normalize($country["name_en"] ?? "");

  $isCorrect = ($userAnswer !== "" && ($userAnswer === $fr || $userAnswer === $en));

  // Mark country as used
  $used = $_SESSION["used_country_ids"] ?? [];
  if (!is_array($used)) $used = [];
  if (!in_array($countryId, $used, true)) {
    $used[] = $countryId;
    $_SESSION["used_country_ids"] = $used;
  }

  // Update score if correct
  if ($isCorrect) {
    $_SESSION["score"] = (int)($_SESSION["score"] ?? 0) + 1;
    $stmt = $pdo->prepare("UPDATE games SET score = :s WHERE id = :g");
    $stmt->execute([":s" => (int)$_SESSION["score"], ":g" => $gameId]);
  }

  // Lives system
  $lives = (int)($_SESSION["lives"] ?? 3);
  if (!$isCorrect) {
    $lives--;
    $_SESSION["lives"] = $lives;
  }
  $gameOver = ($lives <= 0);

  // Save guess (optional table)
  try {
    $stmt = $pdo->prepare("
      INSERT INTO guesses (game_id, country_id, answer, is_correct)
      VALUES (:g, :c, :a, :ok)
    ");
    $stmt->execute([
      ":g" => $gameId,
      ":c" => $countryId,
      ":a" => $answer,
      ":ok" => $isCorrect
    ]);
  } catch (Exception $e) {
    // ignore if table doesn't exist
  }

  // Consume the current question (forces calling next again)
  unset($_SESSION["current_country_id"]);

  // If game over: close game + clear active session
  if ($gameOver) {
    $stmt = $pdo->prepare("UPDATE games SET ended_at = NOW() WHERE id = :g");
    $stmt->execute([":g" => $gameId]);

    unset($_SESSION["game_id"]);
    // on garde score/lives si tu veux les afficher côté front, sinon tu peux aussi les unset
  }

  json_response([
    "ok" => true,
    "correct" => $isCorrect,
    "score" => (int)($_SESSION["score"] ?? 0),
    "lives" => max(0, (int)($_SESSION["lives"] ?? 0)),
    "game_over" => $gameOver,
    "expected" => [
      "fr" => $country["name_fr"] ?? null,
      "en" => $country["name_en"] ?? null
    ]
  ]);
}

/* -------------------- END -------------------- */
if ($action === "end") {
  $gameId = (int)($_SESSION["game_id"] ?? 0);
  if ($gameId > 0) {
    $stmt = $pdo->prepare("UPDATE games SET ended_at = NOW() WHERE id = :g");
    $stmt->execute([":g" => $gameId]);
  }

  unset(
    $_SESSION["current_country_id"],
    $_SESSION["game_id"],
    $_SESSION["score"],
    $_SESSION["lives"],
    $_SESSION["used_country_ids"]
  );

  json_response(["ok" => true]);
}

json_response(["ok" => false, "error" => "Unknown action"], 404);
