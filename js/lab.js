// js/lab.js - Laboratory Testing & Clearance Workflow UI Logic

document.addEventListener("DOMContentLoaded", () => {
  if (!requireStaffOrAdmin()) return;

  const tableBody = document.getElementById("quarantineTableBody");
  const activityBody = document.getElementById("activityTableBody");
  const searchInput = document.getElementById("labSearch");
  const groupFilter = document.getElementById("labGroupFilter");
  const refreshBtn = document.getElementById("refreshLabBtn");

  // KPI elements
  const kpiQuarantine = document.getElementById("kpiQuarantine");
  const kpiAvailable = document.getElementById("kpiAvailable");
  const kpiClearedToday = document.getElementById("kpiClearedToday");
  const kpiRejected = document.getElementById("kpiRejected");

  // Clear Modal elements
  const clearModal = document.getElementById("clearModal");
  const clearForm = document.getElementById("clearForm");
  const clearStatusMsg = document.getElementById("clearStatusMsg");
  const clearUnitId = document.getElementById("clearUnitId");
  const clearUnitLabel = document.getElementById("clearUnitLabel");
  const clearDonorLabel = document.getElementById("clearDonorLabel");
  const clearBloodGroupBadge = document.getElementById("clearBloodGroupBadge");
  const clearNotesInput = document.getElementById("clearNotes");

  // Reject Modal elements
  const rejectModal = document.getElementById("rejectModal");
  const rejectForm = document.getElementById("rejectForm");
  const rejectStatusMsg = document.getElementById("rejectStatusMsg");
  const rejectUnitId = document.getElementById("rejectUnitId");
  const rejectSubtitle = document.getElementById("rejectModalSubtitle");
  const rejectReasonSelect = document.getElementById("rejectReason");
  const chkDisposalConfirm = document.getElementById("chkDisposalConfirm");

  let quarantineList = [];

  function openModal(modal) {
    modal.hidden = false;
  }
  function closeModal(modal) {
    modal.hidden = true;
  }

  document.querySelectorAll("[data-close-modal]").forEach((btn) => {
    btn.addEventListener("click", () => {
      closeModal(clearModal);
      closeModal(rejectModal);
    });
  });

  // Load Quarantine Queue & Metrics
  async function loadQuarantineQueue() {
    tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--muted);">Loading quarantined units…</td></tr>`;

    const q = searchInput.value.trim();
    const group = groupFilter.value;
    const params = new URLSearchParams();
    if (q) params.append("q", q);
    if (group) params.append("blood_group", group);

    try {
      const res = await fetch(`api/lab.php?action=quarantine_queue&${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load laboratory queue.");
      }

      quarantineList = data.quarantine_queue || [];
      renderQuarantineTable(quarantineList);
      updateKPIs(data.metrics || {});
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateKPIs(metrics) {
    kpiQuarantine.textContent = metrics.quarantine_count || 0;
    kpiAvailable.textContent = metrics.available_count || 0;
    kpiClearedToday.textContent = metrics.cleared_today || 0;
    kpiRejected.textContent = metrics.rejected_total || 0;
  }

  function renderQuarantineTable(list) {
    if (list.length === 0) {
      tableBody.innerHTML = `
        <tr>
          <td colspan="8" style="text-align: center; padding: 32px; color: var(--muted);">
            ✓ <strong>No blood units currently in quarantine.</strong><br/>
            All collected units have been cleared or discarded. Fresh donations logged in Donors will appear here automatically.
          </td>
        </tr>
      `;
      return;
    }

    tableBody.innerHTML = list
      .map((u) => {
        return `
          <tr>
            <td><strong>#${u.unit_id}</strong></td>
            <td><span class="blood-badge">${u.blood_group}</span></td>
            <td>
              <div><strong>${escapeHtml(u.donor_name)}</strong></div>
              ${u.donor_id ? `<small style="color:var(--muted)">Donor #${u.donor_id}</small>` : ""}
            </td>
            <td>${u.collection_date}</td>
            <td><strong>${u.days_in_quarantine} day${u.days_in_quarantine === 1 ? '' : 's'}</strong></td>
            <td>
              <span>${u.expiry_date}</span>
              <small style="color:var(--muted)">(${u.days_to_expiry}d left)</small>
            </td>
            <td><span class="badge badge-quarantined">Quarantined</span></td>
            <td>
              <div class="actions-cell">
                <button class="btn btn-primary btn-sm" data-action="clear-unit" data-id="${u.unit_id}">
                  🔬 Screen &amp; Clear
                </button>
                <button class="btn btn-ghost btn-sm" style="color: var(--danger)" data-action="reject-unit" data-id="${u.unit_id}">
                  ⚠️ Reject
                </button>
              </div>
            </td>
          </tr>
        `;
      })
      .join("");

    // Event listeners for action buttons
    tableBody.querySelectorAll("[data-action='clear-unit']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        const unit = quarantineList.find((item) => item.unit_id === id);
        if (!unit) return;
        openClearModal(unit);
      });
    });

    tableBody.querySelectorAll("[data-action='reject-unit']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        const unit = quarantineList.find((item) => item.unit_id === id);
        if (!unit) return;
        openRejectModal(unit);
      });
    });
  }

  // Load Recent Lab Activity Log
  async function loadRecentActivity() {
    try {
      const res = await fetch("api/lab.php?action=recent_activity");
      const data = await res.json();
      if (!res.ok || !data.success) return;

      const list = data.activity || [];
      if (list.length === 0) {
        activityBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--muted);">No laboratory screening events recorded yet.</td></tr>`;
        return;
      }

      activityBody.innerHTML = list
        .map((a) => {
          const isCleared = a.action === "LAB_UNIT_CLEARED";
          const badge = isCleared
            ? `<span class="badge badge-available">✓ Cleared</span>`
            : `<span class="badge badge-expired">⚠️ Rejected</span>`;

          return `
            <tr>
              <td><small>${a.timestamp}</small></td>
              <td>${badge}</td>
              <td><strong>#${a.unit_id || "N/A"}</strong></td>
              <td><strong>${escapeHtml(a.technician_name)}</strong></td>
              <td>${escapeHtml(a.details)}</td>
            </tr>
          `;
        })
        .join("");
    } catch (e) {
      // Ignore background activity load error
    }
  }

  // Open Clear Modal
  function openClearModal(unit) {
    clearUnitId.value = unit.unit_id;
    clearUnitLabel.textContent = `Unit #${unit.unit_id}`;
    clearDonorLabel.textContent = `Donor: ${unit.donor_name} (Collected: ${unit.collection_date})`;
    clearBloodGroupBadge.textContent = unit.blood_group;
    clearNotesInput.value = `All 5 TTI screens Non-Reactive. ABO/Rh (${unit.blood_group}) verified.`;
    clearStatusMsg.className = "form-status";
    openModal(clearModal);
  }

  // Submit Clearance Form
  clearForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const unitId = clearUnitId.value;
    const notes = clearNotesInput.value.trim();

    try {
      const res = await fetch("api/lab.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "clear_unit",
          unit_id: unitId,
          clearance_notes: notes,
          screen_hiv: document.getElementById("chkHiv").checked,
          screen_hbv: document.getElementById("chkHbv").checked,
          screen_hcv: document.getElementById("chkHcv").checked,
          screen_syphilis: document.getElementById("chkSyphilis").checked,
          screen_malaria: document.getElementById("chkMalaria").checked,
          verify_abo: document.getElementById("chkAbo").checked,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to clear blood unit.");
      }

      showStatus(clearStatusMsg, "success", data.message);

      setTimeout(() => {
        closeModal(clearModal);
        loadQuarantineQueue();
        loadRecentActivity();
      }, 900);
    } catch (err) {
      showStatus(clearStatusMsg, "error", err.message);
    }
  });

  // Open Reject Modal
  function openRejectModal(unit) {
    rejectUnitId.value = unit.unit_id;
    rejectSubtitle.textContent = `Unit #${unit.unit_id} (${unit.blood_group}) · Donor: ${unit.donor_name}`;
    chkDisposalConfirm.checked = false;
    rejectStatusMsg.className = "form-status";
    openModal(rejectModal);
  }

  // Submit Rejection Form
  rejectForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const unitId = rejectUnitId.value;
    const reason = rejectReasonSelect.value;

    if (!chkDisposalConfirm.checked) {
      showStatus(rejectStatusMsg, "error", "Please confirm the biohazard disposal protocol.");
      return;
    }

    try {
      const res = await fetch("api/lab.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "reject_unit",
          unit_id: unitId,
          rejection_reason: reason,
          disposal_confirmed: chkDisposalConfirm.checked,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to reject blood unit.");
      }

      showStatus(rejectStatusMsg, "success", data.message);

      setTimeout(() => {
        closeModal(rejectModal);
        loadQuarantineQueue();
        loadRecentActivity();
      }, 900);
    } catch (err) {
      showStatus(rejectStatusMsg, "error", err.message);
    }
  });

  // Filters with debounce
  let searchTimer;
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadQuarantineQueue, 300);
  });
  groupFilter.addEventListener("change", loadQuarantineQueue);
  refreshBtn.addEventListener("click", () => {
    loadQuarantineQueue();
    loadRecentActivity();
  });

  // Initial load
  loadQuarantineQueue();
  loadRecentActivity();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
