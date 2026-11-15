// js/auth.js - Authentication & Session handling connected to WAMP MySQL backend

const STORAGE_SESSION = "sbb_session";
const STORAGE_THEME = "sbb_theme";

function currentTheme() {
  const saved = localStorage.getItem(STORAGE_THEME);
  if (saved === "light" || saved === "dark") return saved;
  return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function applyTheme(theme, persist = false) {
  const safeTheme = theme === "dark" ? "dark" : "light";
  document.documentElement.dataset.theme = safeTheme;
  if (persist) localStorage.setItem(STORAGE_THEME, safeTheme);
  const toggle = document.getElementById("themeToggle");
  if (toggle) {
    toggle.setAttribute("aria-pressed", String(safeTheme === "dark"));
    toggle.setAttribute("aria-label", `Switch to ${safeTheme === "dark" ? "light" : "dark"} mode`);
    toggle.textContent = safeTheme === "dark" ? "☀️ Light mode" : "🌙 Dark mode";
  }
}

applyTheme(currentTheme());

/**
 * Check if page is loaded via file:// protocol and show a friendly helper banner.
 */
function checkProtocolWarning() {
  if (window.location.protocol === "file:") {
    if (!document.getElementById("protocolWarning")) {
      const banner = document.createElement("div");
      banner.id = "protocolWarning";
      banner.style.cssText =
        "background: #7a1f2d; color: #fff; padding: 10px 16px; text-align: center; font-size: 0.92rem; font-weight: 500; position: sticky; top: 0; z-index: 99999; box-shadow: 0 2px 8px rgba(0,0,0,0.2);";
      banner.innerHTML =
        "⚠️ <strong>WAMP Server Required:</strong> You opened this file directly (<code>file://</code>). To communicate with MySQL, open it via Apache: <a href='http://localhost/SmartBloodBank/' style='color:#ffe3a8; text-decoration:underline; font-weight:700;'>http://localhost/SmartBloodBank/</a>";
      document.body.prepend(banner);
    }
  }
}

/**
 * Retrieve current user session from sessionStorage.
 */
function getSession() {
  try {
    return JSON.parse(sessionStorage.getItem(STORAGE_SESSION) || "null");
  } catch {
    return null;
  }
}

/**
 * Save user session to sessionStorage.
 */
function setSession(user) {
  const safe = {
    userId: user.userId || user.user_id,
    username: user.username,
    email: user.email,
    role: user.role,
    fullName: user.fullName || user.full_name,
    institutionId: user.institutionId || user.institution_id,
    bloodGroup: user.bloodGroup || user.blood_group || "",
    mustChangePassword: Boolean(user.mustChangePassword || user.must_change_password),
  };
  sessionStorage.setItem(STORAGE_SESSION, JSON.stringify(safe));
}

/**
 * Clear user session from sessionStorage.
 */
function clearSession() {
  sessionStorage.removeItem(STORAGE_SESSION);
}

/**
 * Register a new user account via PHP API and MySQL database.
 */
async function registerUser(payload) {
  if (window.location.protocol === "file:") {
    throw new Error(
      "Cannot connect to MySQL via file://. Please open http://localhost/SmartBloodBank/register.html on WAMP."
    );
  }

  const res = await fetch("api/register.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(payload),
  });

  const data = await res.json().catch(() => ({}));

  if (!res.ok || !data.success) {
    throw new Error(data.message || `Registration failed (HTTP ${res.status}).`);
  }

  setSession(data.user);
  return data.user;
}

/**
 * Sign in user via PHP API and MySQL database.
 */
async function loginUser(identity, password) {
  if (window.location.protocol === "file:") {
    throw new Error(
      "Cannot connect to MySQL via file://. Please open http://localhost/SmartBloodBank/login.html on WAMP."
    );
  }

  const res = await fetch("api/login.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ identity, password }),
  });

  const data = await res.json().catch(() => ({}));

  if (!res.ok || !data.success) {
    throw new Error(data.message || `Sign in failed (HTTP ${res.status}).`);
  }

  setSession(data.user);
  return data.user;
}

/**
 * Log out user from both server session and client session.
 */
async function logoutUser() {
  try {
    if (window.location.protocol !== "file:") {
      await fetch("api/logout.php", { method: "POST" });
    }
  } catch (err) {
    console.warn("Logout request failed:", err);
  }
  clearSession();
  window.location.href = "index.html";
}

/**
 * Prevent logged-in users from visiting guest-only auth pages.
 */
function requireGuest() {
  if (getSession()) {
    window.location.replace("index.html");
  }
}

/**
 * Enforce staff/admin access for internal modules (Donors, Inventory).
 */
function requireStaffOrAdmin() {
  const session = getSession();
  if (!session) {
    window.location.replace("login.html");
    return false;
  }
  if (!["STAFF", "ADMIN", "LAB_TECHNICIAN"].includes(session.role)) {
    window.location.replace("index.html");
    return false;
  }
  return true;
}

/**
 * Render site navigation bar depending on user login state.
 */
function renderChrome() {
  const session = getSession();
  const nav = document.querySelector("[data-nav]");
  const year = document.querySelector("[data-year]");
  if (year) year.textContent = new Date().getFullYear();
  if (!nav) return;

  const page = document.body.dataset.page;
  const link = (href, key, label) =>
    `<a href="${href}" class="${page === key ? "active" : ""}">${label}</a>`;

  if (session) {
    if (session.mustChangePassword) {
      nav.innerHTML = `
        ${link("profile.html", "profile", "Change Temporary Password")}
        ${link("contact.html", "contact", "Contact")}
        <span class="user-chip">${session.fullName || session.username} · Password change required</span>
        <button class="btn btn-ghost" type="button" id="logoutBtn">Log out</button>
      `;
      document.getElementById("logoutBtn")?.addEventListener("click", logoutUser);
      return;
    }
    const roleLabel = (session.role || "USER").replaceAll("_", " ");
    const isStaffOrAdmin = ["STAFF", "ADMIN"].includes(session.role);
    const isLab = session.role === "LAB_TECHNICIAN";
    const isHospital = session.role === "HOSPITAL_REQUESTER";

    nav.innerHTML = `
      ${link("index.html", "home", "Home")}
      ${isStaffOrAdmin ? link("allocation.html", "allocation", "Allocation Engine") : ""}
      ${isStaffOrAdmin || isHospital ? link("hospital.html", "hospital", isHospital ? "Hospital Requisitions" : "Hospital Orders") : ""}
      ${isStaffOrAdmin || isLab ? link("lab.html", "lab", "Lab Clearance") : ""}
      ${isStaffOrAdmin ? link("donors.html", "donors", "Donors") : ""}
      ${isStaffOrAdmin || isLab ? link("inventory.html", "inventory", "Inventory") : ""}
      ${isStaffOrAdmin ? link("reports.html", "reports", "Analytics") : ""}
      ${isStaffOrAdmin ? link("audit.html", "audit", "Audit Trail") : ""}
      ${link("profile.html", "profile", "Profile & Settings")}
      ${isStaffOrAdmin ? link("ccp-simulation.html", "simulation", "Policy Simulation") : ""}
      ${link("contact.html", "contact", "Contact")}
      <span class="user-chip">${session.fullName || session.username} · ${roleLabel}</span>
      <button class="btn btn-ghost theme-toggle" type="button" id="themeToggle" aria-pressed="false">Dark mode</button>
      <button class="btn btn-ghost" type="button" id="logoutBtn">Log out</button>
    `;
    document.getElementById("logoutBtn")?.addEventListener("click", logoutUser);
  } else {
    nav.innerHTML = `
      ${link("index.html", "home", "Home")}
      ${link("contact.html", "contact", "Contact")}
      ${link("login.html", "login", "Sign in")}
      <a class="btn btn-primary" href="register.html">Register</a>
      <button class="btn btn-ghost theme-toggle" type="button" id="themeToggle" aria-pressed="false">Dark mode</button>
    `;
  }
  applyTheme(currentTheme());
}

/**
 * Sync client session with server session on page load.
 */
async function syncSessionWithServer() {
  if (window.location.protocol === "file:") return;
  try {
    const res = await fetch("api/session.php");
    if (res.ok) {
      const data = await res.json();
      if (data.authenticated && data.user) {
        setSession(data.user);
        renderChrome();
        if (data.user.mustChangePassword && document.body.dataset.page !== "profile") {
          window.location.replace("profile.html?password_reset=1");
        }
      } else if (!data.authenticated && getSession()) {
        clearSession();
        renderChrome();
      }
    }
  } catch (err) {
    // If backend is unreachable, don't crash
    console.warn("Could not sync session with backend:", err);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  applyTheme(currentTheme());
  document.addEventListener("click", (event) => {
    if (event.target.closest("#themeToggle")) {
      applyTheme(document.documentElement.dataset.theme === "dark" ? "light" : "dark", true);
    }
  });
  checkProtocolWarning();
  renderChrome();
  syncSessionWithServer();
});
