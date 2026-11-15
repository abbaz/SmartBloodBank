// js/pages.js - Page-specific form handling and dashboard logic connected to WAMP MySQL

document.addEventListener("DOMContentLoaded", () => {
  const page = document.body.dataset.page;

  // ----------------------------------------------------
  // REGISTER PAGE
  // ----------------------------------------------------
  if (page === "register") {
    requireGuest();
    const form = document.getElementById("registerForm");
    const status = document.getElementById("formStatus");
    const submitBtn = form?.querySelector('button[type="submit"]');
    const roleSelect = document.getElementById('role');

    const rules = {
      fullName: validateFullName,
      username: validateUsername,
      email: validateEmail,
      phone: validateRequiredPhone,
      institutionId: (value) => roleSelect.value === 'USER'
        ? (/^[1-9]\d{0,9}$/.test(value.trim()) ? '' : 'Enter a valid numeric blood bank facility ID.')
        : validateInstitution(value),
      role: (value) => ['USER', 'STAFF', 'HOSPITAL_REQUESTER', 'LAB_TECHNICIAN'].includes(value) ? '' : 'Select an available account role.',
      bloodGroup: validateBloodGroup,
      password: validatePassword,
      confirmPassword: (value) =>
        validateConfirmPassword(form.password.value, value),
      terms: validateTerms,
    };

    bindLiveValidation(form, rules);

    const institutionField = document.getElementById('institutionId').closest('.field');
    const institutionInput = document.getElementById('institutionId');
    const institutionLabel = document.getElementById('institutionLabel');
    const institutionHelp = document.getElementById('institutionHelp');
    const consentText = document.getElementById('registrationConsentText');
    const updateRoleFields = () => {
      const regularUser = roleSelect.value === 'USER';
      institutionField.hidden = false;
      institutionInput.required = true;
      institutionLabel.textContent = regularUser ? 'Preferred blood bank facility ID' : 'Institution ID';
      institutionInput.placeholder = regularUser ? '1' : 'e.g. FUSST-BB';
      institutionInput.value = regularUser && !/^[1-9]\d{0,9}$/.test(institutionInput.value) ? '1' : institutionInput.value;
      institutionHelp.textContent = regularUser
        ? 'The current WAMP installation uses facility ID 1. Your donor profile will be verified by staff.'
        : 'Use the exact ID assigned by your organization. Staff and Lab applications go to an Admin for that facility.';
      consentText.textContent = regularUser
        ? 'I consent to creating a donor profile and allow authorized blood bank staff to review it and contact me about donation appointments.'
        : 'I understand that access is role-limited and that donor information must stay private.';
    };
    roleSelect.addEventListener('change', updateRoleFields);
    updateRoleFields();

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!validateForm(form, rules)) {
        showStatus(status, "error", "Please correct the highlighted fields.");
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Creating account…";
      }

      try {
        const registration = await registerUser({
          fullName: form.fullName.value,
          username: form.username.value,
          email: form.email.value,
          phone: form.phone.value,
          institutionId: form.institutionId.value,
          role: roleSelect.value,
          bloodGroup: form.bloodGroup.value,
          donorConsent: roleSelect.value === 'USER' && form.terms.checked,
          password: form.password.value,
        });

        if (registration.approval_status === 'PENDING') {
          showStatus(status, "success", registration.message || "Your role application is waiting for Admin approval. You can sign in after it is approved.");
          form.reset();
          updateRoleFields();
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = "Create account"; }
        } else {
          showStatus(status, "success", registration.message || "Donor account created. Redirecting…");
          setTimeout(() => (window.location.href = "index.html"), 1000);
        }
      } catch (err) {
        showStatus(status, "error", err.message || "Failed to create account.");
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = "Create account";
        }
      }
    });
  }

  // ----------------------------------------------------
  // LOGIN PAGE
  // ----------------------------------------------------
  if (page === "login") {
    requireGuest();
    const form = document.getElementById("loginForm");
    const status = document.getElementById("formStatus");
    const submitBtn = form?.querySelector('button[type="submit"]');

    const rules = {
      identity: validateLoginIdentity,
      password: (value) => (value ? "" : "Password is required."),
    };

    bindLiveValidation(form, rules);

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!validateForm(form, rules)) {
        showStatus(status, "error", "Please complete both fields correctly.");
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Signing in…";
      }

      try {
        await loginUser(form.identity.value, form.password.value);
        showStatus(status, "success", "Signed in successfully! Redirecting…");
        setTimeout(() => (window.location.href = getSession()?.mustChangePassword ? "profile.html?password_reset=1" : "index.html"), 800);
      } catch (err) {
        showStatus(status, "error", err.message || "Sign in failed.");
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = "Sign in";
        }
      }
    });
  }

  // ----------------------------------------------------
  // CONTACT PAGE
  // ----------------------------------------------------
  if (page === "contact") {
    const form = document.getElementById("contactForm");
    const status = document.getElementById("formStatus");
    const submitBtn = form?.querySelector('button[type="submit"]');

    const session = getSession();
    if (session) {
      if (form.fullName && session.fullName) form.fullName.value = session.fullName;
      if (form.email && session.email) form.email.value = session.email;
      if (form.role && session.role) form.role.value = session.role;
    }

    const rules = {
      fullName: validateFullName,
      email: validateEmail,
      phone: validateOptionalPhone,
      role: validateRole,
      subject: validateSubject,
      message: validateMessage,
    };

    bindLiveValidation(form, rules);

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!validateForm(form, rules)) {
        showStatus(status, "error", "Please correct the highlighted fields.");
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Sending…";
      }

      try {
        if (window.location.protocol === "file:") {
          throw new Error("Cannot send to MySQL via file://. Please open http://localhost/SmartBloodBank/contact.html on WAMP.");
        }

        const res = await fetch("api/contact.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            fullName: form.fullName.value.trim(),
            email: form.email.value.trim(),
            phone: form.phone.value.trim(),
            role: form.role.value,
            subject: form.subject.value.trim(),
            message: form.message.value.trim(),
          }),
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
          throw new Error(data.message || `Failed to send message (HTTP ${res.status}).`);
        }

        form.reset();
        ["fullName", "email", "phone", "role", "subject", "message"].forEach((id) => {
          form[id]?.classList.remove("valid", "invalid");
        });

        showStatus(
          status,
          "success",
          data.message || "Message sent. The blood bank team will follow up using your email."
        );
      } catch (err) {
        showStatus(status, "error", err.message || "Failed to submit message.");
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = "Send message";
        }
      }
    });
  }

  // ----------------------------------------------------
  // HOME PAGE
  // ----------------------------------------------------
  if (page === "home") {
    const session = getSession();
    const guest = document.getElementById("guestHero");
    const dash = document.getElementById("signedInDash");
    const stockSection = document.getElementById("dashStockSection");
    const canReviewStock = session && ["STAFF", "ADMIN", "LAB_TECHNICIAN"].includes(session.role);
    if (stockSection) stockSection.hidden = !canReviewStock;

    if (session && guest && dash) {
      guest.hidden = true;
      dash.hidden = false;
      const welcomeName = document.getElementById("welcomeName");
      const welcomeRole = document.getElementById("welcomeRole");
      if (welcomeName) welcomeName.textContent = session.fullName || session.username;
      if (welcomeRole) welcomeRole.textContent = (session.role || "STAFF").replaceAll("_", " ");

      // Dynamic workspace kicker and actions based on role
      const kicker = dash.querySelector(".kicker");
      const actions = dash.querySelector(".hero-actions");
      const lede = dash.querySelector(".lede");

      if (session.role === "HOSPITAL_REQUESTER") {
        if (kicker) kicker.textContent = "Hospital Clinical Workspace";
        if (lede) lede.innerHTML = `Role: <strong>HOSPITAL REQUESTER</strong> (${session.institutionId || 'Partner Hospital'}). Place clinical blood requisitions, track real-time queue allocation, and view certified transfusion slips.`;
        if (actions) {
          actions.innerHTML = `
            <a class="btn btn-primary" href="hospital.html">+ Order Blood Units</a>
            <a class="btn btn-ghost" href="contact.html">📞 24/7 Transfusion Desk</a>
          `;
        }
      } else if (session.role === "LAB_TECHNICIAN") {
        if (kicker) kicker.textContent = "Laboratory Safety Workspace";
        if (lede) lede.innerHTML = `Role: <strong>LAB TECHNICIAN</strong>. Screen quarantined blood units for transfusion-transmissible infections and authorize safety clearance into inventory.`;
        if (actions) {
          actions.innerHTML = `
            <a class="btn btn-primary" href="lab.html">🔬 Safety Clearance Queue</a>
            <a class="btn btn-ghost" href="inventory.html">📦 Blood Inventory</a>
          `;
        }
      } else if (session.role === "USER") {
        if (kicker) kicker.textContent = "Donor Workspace";
        if (lede) lede.textContent = "Manage your donor profile, request an appointment, and view your own donation history. Staff verifies donor profiles and confirms appointments.";
        if (actions) actions.innerHTML = `<a class="btn btn-primary" href="donor-portal.html">Open Donor Portal</a><a class="btn btn-ghost" href="contact.html">Contact the blood bank</a>`;
      } else {
        if (kicker) kicker.textContent = "Blood Bank Staff Workspace";
        if (actions) {
          actions.innerHTML = `
            <a class="btn btn-primary" href="allocation.html">⚡ Smart Allocation Engine</a>
            <a class="btn btn-ghost" href="hospital.html">🏥 Hospital Orders</a>
            <a class="btn btn-ghost" href="lab.html">🔬 Lab Clearance</a>
            <a class="btn btn-ghost" href="donors.html">🩸 Manage Donors</a>
            <a class="btn btn-ghost" href="inventory.html">📦 Blood Inventory</a>
          `;
        }
      }
    }

    // Optional: Load live counts from MySQL database if on localhost
    if (session && session.role !== "USER" && window.location.protocol !== "file:") {
      fetch("api/stats.php")
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
          if (!data || !data.success) return;
          const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
          setText("dashUnits", data.units_in_stock);
          setText("dashNearExpiry", data.near_expiry);
          setText("dashPending", data.pending_requests);
          setText("dashDonors", data.total_donors || 0);

          if (canReviewStock && data.group_stock) {
            const grid = document.getElementById("dashBloodGroups");
            grid.replaceChildren();
            for (const [group, quantity] of Object.entries(data.group_stock)) {
              const cell = document.createElement("div");
              cell.className = "blood-cell";
              const label = document.createElement("div");
              label.className = "group";
              label.textContent = group;
              const count = document.createElement("strong");
              count.textContent = `${quantity} available`;
              cell.append(label, count);
              grid.append(cell);
            }
            setText("nearExpiryWindow", `Configured warning window: ${data.near_expiry_days} day(s).`);
            const tbody = document.getElementById("dashNearExpiryUnits");
            tbody.replaceChildren();
            if (!data.near_expiry_units.length) {
              const row = document.createElement("tr");
              const cell = document.createElement("td");
              cell.colSpan = 3;
              cell.textContent = "No units are within the near-expiry window.";
              row.append(cell);
              tbody.append(row);
            } else {
              for (const unit of data.near_expiry_units) {
                const row = document.createElement("tr");
                for (const value of [`#${unit.unit_id}`, unit.blood_group, unit.expiry_date]) {
                  const cell = document.createElement("td");
                  cell.textContent = value;
                  row.append(cell);
                }
                tbody.append(row);
              }
            }
          }
        })
        .catch(() => {
          // If offline or stats endpoint fails, keep original static figures
        });
    }
  }
});
