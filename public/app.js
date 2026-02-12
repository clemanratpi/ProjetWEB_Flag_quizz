const $ = (id) => document.getElementById(id);

const authCard = $("authCard");
const gameCard = $("gameCard");
const statsCard = $("statsCard");

const authMsg = $("authMsg");
const gameMsg = $("gameMsg");

const me = $("me");
const scoreEl = $("score");
const bestEl = $("best");
const historyEl = $("history");

const flagImg = $("flag");
let currentCountryId = null;

function normalize($str) {
    $str = trim($str);
    $str = mb_strtolower($str, 'UTF-8');
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str); // enlève accents
    $str = preg_replace('/[^a-z ]/', '', $str);    // enlève caractères spéciaux
    $str = preg_replace('/\s+/', ' ', $str);       // espaces multiples
    return $str;
}

async function api(path, method = "GET", body = null) {
  const opts = { method, headers: {} };
  if (body) {
    opts.headers["Content-Type"] = "application/json";
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(path, opts);
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
}

async function refreshMe() {
  const r = await api("../api/auth.php?action=me");
  if (r.ok && r.data.ok && r.data.user) {
    me.textContent = `Connecté : ${r.data.user.username}`;
    $("btnLogout").classList.remove("hidden");
    gameCard.classList.remove("hidden");
    statsCard.classList.remove("hidden");
    await refreshStats();
  } else {
    me.textContent = "Non connecté";
    $("btnLogout").classList.add("hidden");
    gameCard.classList.add("hidden");
    statsCard.classList.add("hidden");
  }
}

async function refreshStats() {
  const r = await api("../api/stats.php");
  if (!r.ok) return;
  bestEl.textContent = r.data.best ?? 0;
  historyEl.innerHTML = "";
  (r.data.history || []).forEach(g => {
    const li = document.createElement("li");
    const ended = g.ended_at ? "terminée" : "en cours";
    li.textContent = `Score ${g.score} — ${ended} — ${g.started_at}`;
    historyEl.appendChild(li);
  });
}

async function startGame() {
  gameMsg.textContent = "";
  const r = await api("../api/game.php?action=start", "POST");
  if (!r.ok) {
    gameMsg.textContent = r.data.error || "Erreur start";
    return;
  }
  scoreEl.textContent = "0";
  await nextCountry();
}

async function nextCountry() {
  const r = await api("../api/game.php?action=next");
  if (!r.ok) {
    gameMsg.textContent = r.data.error || "Erreur next";
    return;
  }
  if (r.data.done) {
    gameMsg.textContent = "Plus de pays disponibles 😅";
    return;
  }
  currentCountryId = r.data.country_id;
  flagImg.src = r.data.flag_url;
  scoreEl.textContent = String(r.data.score ?? 0);
  $("answer").value = "";
  $("answer").focus();
}

async function guess() {
  const answer = $("answer").value.trim();
  if (!currentCountryId) return;

  const r = await api("../api/game.php?action=guess", "POST", {
    country_id: currentCountryId,
    answer
  });

  if (!r.ok) {
    gameMsg.textContent = r.data.error || "Erreur guess";
    return;
  }

  if (r.data.correct) {
    gameMsg.textContent = `✅ Correct ! (${r.data.expected.fr})`;
    scoreEl.textContent = String(r.data.score);
    await nextCountry();
    await refreshStats();
  } else {
    gameMsg.textContent = `❌ Faux. C'était : ${r.data.expected.fr} / ${r.data.expected.en}. Score final: ${r.data.score}`;
    currentCountryId = null;
    await refreshStats();
  }
}

async function endGame() {
  await api("../api/game.php?action=end", "POST");
  currentCountryId = null;
  gameMsg.textContent = "Partie arrêtée.";
  await refreshStats();
}

$("btnLogin").addEventListener("click", async () => {
  authMsg.textContent = "";
  const username = $("username").value.trim();
  const password = $("password").value;

  const r = await api("../api/auth.php?action=login", "POST", { username, password });
  if (!r.ok || !r.data.ok) {
    authMsg.textContent = r.data.error || "Erreur login";
    return;
  }
  authMsg.textContent = "Connecté ✅";
  await refreshMe();
});

$("btnRegister").addEventListener("click", async () => {
  authMsg.textContent = "";
  const username = $("username").value.trim();
  const password = $("password").value;

  const r = await api("../api/auth.php?action=register", "POST", { username, password });
  if (!r.ok || !r.data.ok) {
    authMsg.textContent = r.data.error || "Erreur register";
    return;
  }
  authMsg.textContent = "Compte créé ✅";
  await refreshMe();
});

$("btnLogout").addEventListener("click", async () => {
  await api("../api/auth.php?action=logout", "POST");
  authMsg.textContent = "Déconnecté.";
  await refreshMe();
});

$("btnStart").addEventListener("click", startGame);
$("btnGuess").addEventListener("click", guess);
$("btnEnd").addEventListener("click", endGame);
$("answer").addEventListener("keydown", (e) => {
  if (e.key === "Enter") guess();
});

refreshMe();
