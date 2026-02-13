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
const livesEl = $("lives");

let currentCountryId = null;
let gameActive = false;

function renderLives(lives) {
  const n = Math.max(0, Number(lives ?? 0));
  if (livesEl) livesEl.textContent = "❤️".repeat(n) + "🖤".repeat(3 - n);
}

function setGuessEnabled(enabled) {
  $("btnGuess").disabled = !enabled;
  $("answer").disabled = !enabled;
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

// 🔥 Chemins absolus (évite les bugs ../api)
const API = {
  me: "/projet-web/api/auth.php?action=me",
  login: "/projet-web/api/auth.php?action=login",
  register: "/projet-web/api/auth.php?action=register",
  logout: "/projet-web/api/auth.php?action=logout",
  stats: "/projet-web/api/stats.php",
  start: "/projet-web/api/game.php?action=start",
  next: "/projet-web/api/game.php?action=next",
  guess: "/projet-web/api/game.php?action=guess",
  end: "/projet-web/api/game.php?action=end",
};

async function refreshMe() {
  const r = await api(API.me);
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
  const r = await api(API.stats);
  if (!r.ok) return;

  bestEl.textContent = r.data.best ?? 0;
  historyEl.innerHTML = "";
  (r.data.history || []).forEach((g) => {
    const li = document.createElement("li");
    const ended = g.ended_at ? "terminée" : "en cours";
    li.textContent = `Score ${g.score} — ${ended} — ${g.started_at}`;
    historyEl.appendChild(li);
  });
}

async function startGame() {
  gameMsg.textContent = "";
  setGuessEnabled(false);
  currentCountryId = null;

  const r = await api(API.start, "POST");
  if (!r.ok || !r.data.ok) {
    gameMsg.textContent = r.data.error || "Erreur start";
    return;
  }

  gameActive = true;
  scoreEl.textContent = String(r.data.score ?? 0);
  renderLives(r.data.lives ?? 3);

  await nextCountry();
}

async function nextCountry() {
  if (!gameActive) return;

  const r = await api(API.next);
  if (!r.ok || !r.data.ok) {
    gameMsg.textContent = r.data.error || "Erreur next";
    return;
  }

  renderLives(r.data.lives ?? 3);

  if (r.data.done) {
    gameMsg.textContent = "Plus de pays disponibles 😅";
    setGuessEnabled(false);
    return;
  }

  currentCountryId = r.data.country_id;
  flagImg.src = r.data.flag_url;

  scoreEl.textContent = String(r.data.score ?? 0);
  $("answer").value = "";
  $("answer").focus();
  setGuessEnabled(true);
}

async function guess() {
  if (!gameActive) return;

  const answer = $("answer").value.trim();
  if (!currentCountryId) return;

  // (option) éviter double-clic spam
  setGuessEnabled(false);

  const r = await api(API.guess, "POST", {
    country_id: currentCountryId,
    answer,
  });

  if (!r.ok || !r.data.ok) {
    gameMsg.textContent = r.data.error || `Erreur guess (HTTP ${r.status})`;
    setGuessEnabled(true);
    return;
  }

  renderLives(r.data.lives ?? 0);

  const fr = r.data?.expected?.fr ?? "?";
  const en = r.data?.expected?.en ?? "?";

  if (r.data.correct) {
    gameMsg.textContent = `✅ Correct ! (${fr})`;
    scoreEl.textContent = String(r.data.score ?? 0);
    await refreshStats();
    await nextCountry();
    return;
  }

  // Mauvaise réponse
  if (r.data.game_over) {
    gameMsg.textContent = `💀 Game Over ! C’était : ${fr} / ${en}. Score final: ${r.data.score ?? 0}`;
    currentCountryId = null;
    gameActive = false;
    await refreshStats();
    setGuessEnabled(false);
    return;
  }

  gameMsg.textContent = `❌ Faux ! C’était : ${fr} / ${en}. Il te reste ${r.data.lives} vie(s).`;
  await refreshStats();
  await nextCountry();
}

async function endGame() {
  await api(API.end, "POST");
  currentCountryId = null;
  gameActive = false;
  setGuessEnabled(false);
  gameMsg.textContent = "Partie arrêtée.";
  await refreshStats();
}

$("btnLogin").addEventListener("click", async () => {
  authMsg.textContent = "";
  const username = $("username").value.trim();
  const password = $("password").value;

  const r = await api(API.login, "POST", { username, password });
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

  const r = await api(API.register, "POST", { username, password });
  if (!r.ok || !r.data.ok) {
    authMsg.textContent = r.data.error || "Erreur register";
    return;
  }
  authMsg.textContent = "Compte créé ✅";
  await refreshMe();
});

$("btnLogout").addEventListener("click", async () => {
  await api(API.logout, "POST");
  authMsg.textContent = "Déconnecté.";
  await refreshMe();
});

$("btnStart").addEventListener("click", startGame);
$("btnGuess").addEventListener("click", guess);
$("btnEnd").addEventListener("click", endGame);

$("answer").addEventListener("keydown", (e) => {
  if (e.key === "Enter") guess();
});

// état initial
setGuessEnabled(false);
renderLives(0);
refreshMe();
