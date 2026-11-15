// js/profile.js - User profile, password change, admin alert thresholds

document.addEventListener('DOMContentLoaded', async () => {
  const session = getSession();
  if (!session) { window.location.replace('login.html'); return; }

  if (session.mustChangePassword) {
    const passwordCard = document.getElementById('passwordChangeSection');
    const notice = document.getElementById('forcedPasswordNotice');
    notice.hidden = false;
    notice.style.display = 'block';
    document.getElementById('oldPassword').placeholder = 'Enter the temporary password provided by your administrator';
    document.getElementById('oldPasswordLabel').textContent = 'Temporary Password';
    document.querySelectorAll('.profile-grid .profile-card').forEach((card) => {
      if (card !== passwordCard) card.hidden = true;
    });
    return;
  }

  loadProfile();
  const alertsCard = document.getElementById('stockAlertsCard');
  if (['STAFF', 'ADMIN', 'LAB_TECHNICIAN'].includes(session.role)) loadStockAlerts();
  else if (alertsCard) alertsCard.hidden = true;
  if (session.role === 'ADMIN') {
    document.getElementById('thresholdsSection').style.display = 'block';
    document.getElementById('provisionUserSection').style.display = 'block';
    document.getElementById('promoteAdminSection').style.display = 'block';
    document.getElementById('adminPasswordResetSection').style.display = 'block';
    loadThresholds();
  }
});

document.getElementById('provisionUserForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const payload = {
      fullName: document.getElementById('newUserName').value.trim(),
      username: document.getElementById('newUserUsername').value.trim(),
      email: document.getElementById('newUserEmail').value.trim(),
      phone: document.getElementById('newUserPhone').value.trim(),
      institutionId: document.getElementById('newUserInstitution').value.trim(),
      role: document.getElementById('newUserRole').value,
      bloodGroup: document.getElementById('newUserBloodGroup').value,
      password: document.getElementById('newUserPassword').value,
    };
    const provisionErrors = [
      validateFullName(payload.fullName), validateUsername(payload.username),
      validateEmail(payload.email), validateRequiredPhone(payload.phone),
      validateInstitution(payload.institutionId), validateBloodGroup(payload.bloodGroup),
      validatePassword(payload.password),
    ].filter(Boolean);
    if (provisionErrors.length) throw new Error(provisionErrors[0]);
    const res = await fetch('api/register.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not create account.');
    showBanner('provisionBanner', 'success', `Account created for ${payload.username} (${payload.role.replaceAll('_', ' ')}).`);
    form.reset();
  } catch (err) {
    showBanner('provisionBanner', 'error', err.message);
  } finally { button.disabled = false; }
});

document.getElementById('promoteAdminForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const identity = document.getElementById('promoteAdminIdentity').value.trim();
  if (!identity) return;
  if (!window.confirm(`Promote ${identity} to Admin for your facility? They will receive administrator access.`)) return;

  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const res = await fetch('api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'promote_to_admin', username_or_email: identity }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not promote this account.');
    showBanner('promoteAdminBanner', 'success', data.message);
    form.reset();
  } catch (err) {
    showBanner('promoteAdminBanner', 'error', err.message);
  } finally {
    button.disabled = false;
  }
});

document.getElementById('adminPasswordResetForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const identity = document.getElementById('resetUserIdentity').value.trim();
  if (!identity) return;
  if (!window.confirm(`Generate a temporary password for ${identity}? Their current password will stop working.`)) return;
  const button = form.querySelector('[type="submit"]');
  const result = document.getElementById('resetPasswordResult');
  const value = document.getElementById('temporaryPasswordValue');
  result.hidden = true;
  result.style.display = 'none';
  value.value = '';
  button.disabled = true;
  try {
    const res = await fetch('api/admin-password-reset.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username_or_email: identity }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Password reset failed.');
    document.getElementById('resetPasswordMessage').textContent = `${data.message} Account: ${data.username}.`;
    value.value = data.temporary_password;
    result.hidden = false;
    result.style.display = 'block';
    form.reset();
  } catch (err) {
    showBanner('adminPasswordResetBanner', 'error', err.message);
  } finally {
    button.disabled = false;
  }
});

document.getElementById('temporaryPasswordValue')?.addEventListener('focus', (event) => event.currentTarget.select());

// ─── Load & populate profile form ────────────────────────────────────────────
async function loadProfile() {
  try {
    const res = await fetch('api/profile.php?action=get_profile');
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const u = data.user;
    document.getElementById('profileName').value       = u.full_name || '';
    document.getElementById('profileEmail').value      = u.email || '';
    document.getElementById('profileUsername').value   = u.username || '';
    document.getElementById('profileRole').value       = (u.role || '').replaceAll('_', ' ');
    document.getElementById('profilePhone').value      = u.phone || '';
    document.getElementById('profileInstitution').value = u.institution_id || '';
    document.getElementById('profileBloodGroup').value = u.blood_group_display || '—';
    document.getElementById('profileSince').value      = u.created_at ? u.created_at.split(' ')[0] : '—';
  } catch (err) {
    showBanner('profileBanner', 'error', '⚠️ Could not load profile: ' + err.message);
  }
}

// ─── Save profile changes ─────────────────────────────────────────────────────
document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fullName = document.getElementById('profileName').value.trim();
  const phone = document.getElementById('profilePhone').value.trim();
  const nameError = !/^[A-Za-z][A-Za-z .'-]{1,59}$/.test(fullName);
  if (nameError || validateOptionalPhone(phone)) {
    showBanner('profileBanner', 'error', nameError ? 'Enter a name using 2-60 letters, spaces, apostrophes, periods, or hyphens.' : validateOptionalPhone(phone));
    return;
  }
  const btn = e.target.querySelector('[type="submit"]');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  try {
    const res = await fetch('api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:         'update_profile',
        full_name:      fullName,
        phone,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    // Update session display name
    const session = getSession();
    if (session) {
      session.fullName = document.getElementById('profileName').value.trim();
      setSession(session);
      renderChrome();
    }
    showBanner('profileBanner', 'success', '✅ ' + data.message);
  } catch (err) {
    showBanner('profileBanner', 'error', '❌ ' + err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save Changes';
  }
});

// ─── Change password ──────────────────────────────────────────────────────────
document.getElementById('passwordForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('[type="submit"]');
  const newPass = document.getElementById('newPassword').value;
  const confirm = document.getElementById('confirmPassword').value;
  const forcedChange = Boolean(getSession()?.mustChangePassword);

  if (newPass !== confirm) {
    showBanner('passwordBanner', 'error', '❌ New passwords do not match.');
    return;
  }
  if (forcedChange && newPass === document.getElementById('oldPassword').value) {
    showBanner('passwordBanner', 'error', 'Choose a new password different from the temporary password.');
    return;
  }
  const passwordError = validatePassword(newPass);
  if (passwordError) {
    showBanner('passwordBanner', 'error', passwordError);
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Updating…';

  try {
    const res = await fetch('api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action:           'change_password',
        old_password:     document.getElementById('oldPassword').value,
        new_password:     newPass,
        confirm_password: confirm,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const session = getSession();
    if (session) {
      session.mustChangePassword = false;
      setSession(session);
    }

    showBanner('passwordBanner', 'success', '✅ ' + data.message);
    e.target.reset();

    // Auto-logout after password change for security
    setTimeout(() => {
      showBanner('passwordBanner', 'info', '🔐 Signing out for security…');
      setTimeout(logoutUser, 1500);
    }, 2000);
  } catch (err) {
    showBanner('passwordBanner', 'error', '❌ ' + err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Update Password';
  }
});

// ─── Load alert thresholds (Admin) ───────────────────────────────────────────
async function loadThresholds() {
  try {
    const res = await fetch('api/profile.php?action=get_thresholds');
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    const t = data.thresholds;
    for (const [group, val] of Object.entries(t)) {
      const el = document.getElementById('thresh_' + group);
      if (el) el.value = val;
    }
  } catch (err) {
    showBanner('thresholdBanner', 'error', '⚠️ Could not load thresholds: ' + err.message);
  }
}

async function loadStockAlerts() {
  const container = document.getElementById('stockAlerts');
  try {
    const res = await fetch('api/profile.php?action=stock_alerts');
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not load alerts.');
    container.replaceChildren();
    if (!data.alerts.length) {
      container.textContent = 'No low-stock or near-expiry alerts right now.';
      return;
    }
    for (const alert of data.alerts) {
      const item = document.createElement('p');
      item.style.margin = '8px 0';
      item.textContent = alert.type === 'NEAR_EXPIRY'
        ? `${alert.current_stock} unit(s) expire within ${alert.days} days.`
        : `${alert.display}: ${alert.current_stock} available (alert threshold: ${alert.threshold}).`;
      container.append(item);
    }
  } catch (error) {
    container.textContent = `Could not load inventory alerts: ${error.message}`;
  }
}

// ─── Save thresholds (Admin) ──────────────────────────────────────────────────
document.getElementById('thresholdsForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = e.target.querySelector('[type="submit"]');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  const groups = ['A_POSITIVE','A_NEGATIVE','B_POSITIVE','B_NEGATIVE','O_POSITIVE','O_NEGATIVE','AB_POSITIVE','AB_NEGATIVE','NEAR_EXPIRY_DAYS'];
  const thresholds = {};
  for (const g of groups) {
    const el = document.getElementById('thresh_' + g);
    const value = el ? Number(el.value) : NaN;
    const min = g === 'NEAR_EXPIRY_DAYS' ? 1 : 0;
    const max = g === 'NEAR_EXPIRY_DAYS' ? 60 : 100;
    if (!Number.isInteger(value) || value < min || value > max) {
      showBanner('thresholdBanner', 'error', `${g.replaceAll('_', ' ')} must be a whole number from ${min} to ${max}.`);
      btn.disabled = false;
      btn.textContent = 'Save Thresholds';
      return;
    }
    thresholds[g] = value;
  }

  try {
    const res = await fetch('api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_thresholds', thresholds }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message);
    showBanner('thresholdBanner', 'success', '✅ ' + data.message);
  } catch (err) {
    showBanner('thresholdBanner', 'error', '❌ ' + err.message);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save Thresholds';
  }
});

// ─── Utility: show inline banner ─────────────────────────────────────────────
function showBanner(id, type, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = 'form-banner form-banner--' + type;
  el.textContent = msg;
  el.style.display = 'block';
  if (type === 'success') {
    setTimeout(() => { el.style.display = 'none'; }, 4000);
  }
}
