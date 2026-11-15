// js/donors.js - Donor Management and Donation Logging UI Logic

document.addEventListener("DOMContentLoaded", () => {
  if (!requireStaffOrAdmin()) return;

  const tableBody = document.getElementById("donorsTableBody");
  const searchInput = document.getElementById("donorSearch");
  const groupFilter = document.getElementById("groupFilter");
  const refreshBtn = document.getElementById("refreshDonorsBtn");

  const totalCountEl = document.getElementById("totalDonorsCount");
  const eligibleCountEl = document.getElementById("eligibleDonorsCount");
  const cooldownCountEl = document.getElementById("cooldownDonorsCount");

  // Donor Modal elements
  const donorModal = document.getElementById("donorModal");
  const donorForm = document.getElementById("donorForm");
  const donorFormStatus = document.getElementById("donorFormStatus");
  const donorModalTitle = document.getElementById("donorModalTitle");
  const openAddDonorBtn = document.getElementById("openAddDonorModal");

  // Donation Modal elements
  const donationModal = document.getElementById("donationModal");
  const donationForm = document.getElementById("donationForm");
  const donationFormStatus = document.getElementById("donationFormStatus");
  const donationDonorId = document.getElementById("donationDonorId");
  const donationDonorName = document.getElementById("donationDonorName");
  const donationBloodGroup = document.getElementById("donationBloodGroup");
  const collectionDateInput = document.getElementById("collectionDate");
  const shelfLifeInput = document.getElementById("shelfLife");
  const calcExpiryInput = document.getElementById("calcExpiryDate");

  let donorsList = [];

  // Helper: Open / Close Modals
  function openModal(modal) {
    modal.hidden = false;
  }
  function closeModal(modal) {
    modal.hidden = true;
  }

  document.querySelectorAll("[data-close-modal]").forEach((btn) => {
    btn.addEventListener("click", () => {
      closeModal(donorModal);
      closeModal(donationModal);
    });
  });

  // Calculate Expiry Date in donation modal
  function updateCalculatedExpiry() {
    const colVal = collectionDateInput.value;
    const days = parseInt(shelfLifeInput.value, 10) || 35;
    if (!colVal) {
      calcExpiryInput.value = "";
      return;
    }
    const d = new Date(colVal);
    d.setDate(d.getDate() + days);
    calcExpiryInput.value = d.toISOString().split("T")[0];
  }

  collectionDateInput.addEventListener("input", updateCalculatedExpiry);
  shelfLifeInput.addEventListener("input", updateCalculatedExpiry);

  // Load donors from MySQL via api/donors.php
  async function loadDonors() {
    tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--muted);">Loading donor records…</td></tr>`;
    const q = searchInput.value.trim();
    const group = groupFilter.value;
    const params = new URLSearchParams();
    if (q) params.append("q", q);
    if (group) params.append("blood_group", group);

    try {
      const res = await fetch(`api/donors.php?${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load donors.");
      }

      donorsList = data.donors || [];
      renderDonorsTable(donorsList);
      updateMetrics(donorsList);
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateMetrics(list) {
    let eligible = 0;
    let cooldown = 0;
    list.forEach((d) => {
      if (d.is_eligible) eligible++;
      else cooldown++;
    });
    totalCountEl.textContent = list.length;
    eligibleCountEl.textContent = eligible;
    cooldownCountEl.textContent = cooldown;
  }

  function renderDonorsTable(list) {
    if (list.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 28px; color: var(--muted);">No donors found matching your criteria. Click "+ Register New Donor" to add one.</td></tr>`;
      return;
    }

    tableBody.innerHTML = list
      .map((d) => {
        const eligibilityBadge = d.is_eligible
          ? `<span class="badge badge-eligible">✓ Eligible</span>`
          : `<span class="badge badge-cooldown" title="Next eligible: ${d.next_eligible_date}">⏳ Cooldown (${56 - (d.days_since || 0)}d left)</span>`;

        const lastDonationText = d.last_donation_date
          ? `${d.last_donation_date} <small style="color:var(--muted)">(${d.days_since}d ago)</small>`
          : `<span style="color:var(--muted)">None recorded</span>`;

        return `
          <tr>
            <td><strong>#${d.donor_id}</strong></td>
            <td><strong>${escapeHtml(d.name)}</strong></td>
            <td><span class="blood-badge">${d.blood_group}</span></td>
            <td>${escapeHtml(d.contact_details || "N/A")}</td>
            <td>${lastDonationText}</td>
            <td>${eligibilityBadge}</td>
            <td>
              <div class="actions-cell">
                <button class="btn btn-primary btn-sm" data-action="log-donation" data-id="${d.donor_id}">
                  + Log Donation
                </button>
                <button class="btn btn-ghost btn-sm" data-action="edit-donor" data-id="${d.donor_id}">
                  Edit
                </button>
                <button class="btn btn-ghost btn-sm" style="color: var(--danger)" data-action="delete-donor" data-id="${d.donor_id}">
                  Delete
                </button>
              </div>
            </td>
          </tr>
        `;
      })
      .join("");

    // Attach row button events
    tableBody.querySelectorAll("[data-action='log-donation']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        const donor = donorsList.find((item) => item.donor_id === id);
        if (!donor) return;
        openDonationModal(donor);
      });
    });

    tableBody.querySelectorAll("[data-action='edit-donor']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        const donor = donorsList.find((item) => item.donor_id === id);
        if (!donor) return;
        openEditDonorModal(donor);
      });
    });

    tableBody.querySelectorAll("[data-action='delete-donor']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        deleteDonor(id);
      });
    });
  }

  // Open Add Donor Modal
  openAddDonorBtn.addEventListener("click", () => {
    donorModalTitle.textContent = "Register New Donor";
    donorForm.reset();
    document.getElementById("donorId").value = "";
    donorFormStatus.className = "form-status";
    openModal(donorModal);
  });

  // Open Edit Donor Modal
  function openEditDonorModal(donor) {
    donorModalTitle.textContent = `Edit Donor #${donor.donor_id}`;
    document.getElementById("donorId").value = donor.donor_id;
    document.getElementById("donorName").value = donor.name;
    document.getElementById("donorBloodGroup").value = donor.blood_group;
    document.getElementById("donorContact").value = donor.contact_details || "";
    document.getElementById("donorLastDate").value = donor.last_donation_date || "";
    donorFormStatus.className = "form-status";
    openModal(donorModal);
  }

  // Save Donor (Create or Update)
  donorForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const donorId = document.getElementById("donorId").value;
    const name = document.getElementById("donorName").value.trim();
    const bloodGroup = document.getElementById("donorBloodGroup").value;
    const contact = document.getElementById("donorContact").value.trim();
    const lastDate = document.getElementById("donorLastDate").value;

    const contactClean = contact.replace(/[\s-]/g, "");
    const contactValid = /^(?:\+92|0)3[0-9]{9}$/.test(contactClean) || (contact.length <= 254 && PATTERNS.email.test(contact));
    const localToday = new Date();
    localToday.setMinutes(localToday.getMinutes() - localToday.getTimezoneOffset());
    if (!/^[A-Za-z][A-Za-z .'-]{1,99}$/.test(name) || !bloodGroup || !contactValid || (lastDate && (Number.isNaN(Date.parse(lastDate)) || lastDate > localToday.toISOString().slice(0, 10)))) {
      showStatus(donorFormStatus, "error", "Enter a valid donor name, blood group, phone or email, and an optional donation date no later than today.");
      return;
    }

    const payload = {
      action: donorId ? "update" : "create",
      donor_id: donorId,
      name,
      blood_group: bloodGroup,
      contact_details: contact,
      last_donation_date: lastDate,
    };

    try {
      const res = await fetch("api/donors.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to save donor.");
      }

      showStatus(donorFormStatus, "success", data.message);
      setTimeout(() => {
        closeModal(donorModal);
        loadDonors();
      }, 700);
    } catch (err) {
      showStatus(donorFormStatus, "error", err.message);
    }
  });

  // Delete Donor
  async function deleteDonor(id) {
    if (!confirm(`Are you sure you want to delete donor #${id}?`)) return;
    try {
      const res = await fetch("api/donors.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", donor_id: id }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || "Could not delete donor.");
      loadDonors();
    } catch (err) {
      alert(err.message);
    }
  }

  // Open Log Donation Modal
  function openDonationModal(donor) {
    donationForm.reset();
    donationFormStatus.className = "form-status";
    donationDonorId.value = donor.donor_id;
    donationDonorName.value = `${donor.name} (#${donor.donor_id})`;
    donationBloodGroup.value = donor.blood_group;

    // Set default collection date to today
    const todayStr = new Date().toISOString().split("T")[0];
    collectionDateInput.value = todayStr;
    shelfLifeInput.value = "35";
    updateCalculatedExpiry();

    if (!donor.is_eligible) {
      showStatus(
        donationFormStatus,
        "error",
        `Notice: This donor is within the 56-day cooldown period (Next recommended: ${donor.next_eligible_date}). Medical supervisor approval required.`
      );
    }

    openModal(donationModal);
  }

  // Submit Donation Form
  donationForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const donorId = donationDonorId.value;
    const collectionDate = collectionDateInput.value;
    const shelfLifeDays = shelfLifeInput.value;
    const bloodGroup = donationBloodGroup.value;

    if (!collectionDate) {
      showStatus(donationFormStatus, "error", "Collection date is required.");
      return;
    }

    try {
      const res = await fetch("api/inventory.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "log_donation",
          donor_id: donorId,
          blood_group: bloodGroup,
          collection_date: collectionDate,
          shelf_life_days: shelfLifeDays,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to log donation.");
      }

      showStatus(
        donationFormStatus,
        "success",
        `${data.message} Redirecting to inventory…`
      );

      setTimeout(() => {
        closeModal(donationModal);
        window.location.href = "inventory.html";
      }, 1000);
    } catch (err) {
      showStatus(donationFormStatus, "error", err.message);
    }
  });

  // Search & Filter event listeners with debounce
  let searchTimer;
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadDonors, 300);
  });
  groupFilter.addEventListener("change", loadDonors);
  refreshBtn.addEventListener("click", loadDonors);

  // Initial load
  loadDonors();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
