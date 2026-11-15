// js/allocation.js - Smart Allocation Decision Support Engine UI Logic

document.addEventListener("DOMContentLoaded", () => {
  const session = getSession();
  if (!session) {
    window.location.replace("login.html");
    return;
  }

  const tableBody = document.getElementById("requestsTableBody");
  const queueStatusFilter = document.getElementById("queueStatusFilter");
  const refreshQueueBtn = document.getElementById("refreshQueueBtn");
  const seedDemoStockBtn = document.getElementById("seedDemoStockBtn");

  // KPI elements
  const criticalCountEl = document.getElementById("criticalCount");
  const urgentCountEl = document.getElementById("urgentCount");
  const routineCountEl = document.getElementById("routineCount");
  const fulfilledCountEl = document.getElementById("fulfilledCount");

  // New Request Modal elements
  const newRequestModal = document.getElementById("newRequestModal");
  const newRequestForm = document.getElementById("newRequestForm");
  const newRequestStatus = document.getElementById("newRequestStatus");
  const openNewRequestBtn = document.getElementById("openNewRequestBtn");

  // Allocation Engine Modal elements
  const allocationModal = document.getElementById("allocationModal");
  const allocationSubtitle = document.getElementById("allocationModalSubtitle");
  const allocationStatusMsg = document.getElementById("allocationStatusMsg");
  const summaryGroup = document.getElementById("summaryGroup");
  const summaryQty = document.getElementById("summaryQty");
  const summaryUrgency = document.getElementById("summaryUrgency");
  const recommendationsContainer = document.getElementById("recommendationsContainer");
  const selectedUnitsCountEl = document.getElementById("selectedUnitsCount");
  const confirmAllocationBtn = document.getElementById("confirmAllocationBtn");

  let currentRequestId = null;
  let currentRequestQty = 1;
  let selectedUnitIds = new Set();
  let requestsList = [];

  function openModal(modal) {
    modal.hidden = false;
  }
  function closeModal(modal) {
    modal.hidden = true;
  }

  document.querySelectorAll("[data-close-modal]").forEach((btn) => {
    btn.addEventListener("click", () => {
      closeModal(newRequestModal);
      closeModal(allocationModal);
    });
  });

  // Load Requests Queue
  async function loadRequests() {
    tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--muted);">Loading clinical queue…</td></tr>`;

    const status = queueStatusFilter.value;
    const params = new URLSearchParams();
    if (status) params.append("status", status);

    try {
      const res = await fetch(`api/allocation.php?action=requests&${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load requests.");
      }

      requestsList = data.requests || [];
      renderRequestsTable(requestsList);
      updateKPIs(requestsList);
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateKPIs(list) {
    let crit = 0, urg = 0, rout = 0, fulf = 0;
    list.forEach((r) => {
      if (r.status === "FULFILLED") fulf++;
      else {
        if (r.urgency === "CRITICAL") crit++;
        else if (r.urgency === "URGENT") urg++;
        else rout++;
      }
    });
    criticalCountEl.textContent = crit;
    urgentCountEl.textContent = urg;
    routineCountEl.textContent = rout;
    fulfilledCountEl.textContent = fulf;
  }

  function renderRequestsTable(list) {
    if (list.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 28px; color: var(--muted);">No clinical requests found in this queue. Click "+ Place Clinical Request" to order units.</td></tr>`;
      return;
    }

    tableBody.innerHTML = list
      .map((r) => {
        let urgencyBadge = `<span class="urgency-badge urgency-routine">Routine</span>`;
        if (r.urgency === "URGENT") {
          urgencyBadge = `<span class="urgency-badge urgency-urgent">Urgent</span>`;
        } else if (r.urgency === "CRITICAL") {
          urgencyBadge = `<span class="urgency-badge urgency-critical">⚡ Critical</span>`;
        }

        const isPending = r.status === "PENDING";
        const statusBadge = isPending
          ? `<span class="badge badge-quarantined">Pending</span>`
          : `<span class="badge badge-available">Fulfilled</span>`;

        const actionBtn = isPending
          ? `<button class="btn btn-primary btn-sm" data-action="run-engine" data-id="${r.request_id}">
               ⚡ Run Allocation Engine
             </button>`
          : `<span style="font-size: 0.85rem; color: var(--sage); font-weight: 600;">✓ Units Issued</span>`;

        return `
          <tr>
            <td><strong>#${r.request_id}</strong></td>
            <td>
              <div><strong>${escapeHtml(r.requester_name)}</strong></div>
              <small style="color:var(--muted)">${escapeHtml(r.requester_institution)}</small>
            </td>
            <td><span class="blood-badge">${r.requested_blood_group}</span></td>
            <td><strong>${r.allocated_count ? `${r.allocated_count}/` : ''}${r.quantity} unit${r.quantity > 1 ? 's' : ''}</strong></td>
            <td>${urgencyBadge}</td>
            <td><small>${r.request_date}</small></td>
            <td>${statusBadge}</td>
            <td>${actionBtn}</td>
          </tr>
        `;
      })
      .join("");

    tableBody.querySelectorAll("[data-action='run-engine']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        openAllocationEngine(id);
      });
    });
  }

  // Open & Run the Smart Allocation Engine
  async function openAllocationEngine(requestId) {
    currentRequestId = requestId;
    selectedUnitIds.clear();
    allocationStatusMsg.className = "form-status";
    recommendationsContainer.innerHTML = `<div style="text-align: center; padding: 32px; color: var(--muted);">Running multi-factor scoring algorithm across live inventory…</div>`;
    openModal(allocationModal);

    try {
      const res = await fetch(`api/allocation.php?action=recommendations&request_id=${requestId}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to calculate recommendations.");
      }

      const req = data.request;
      currentRequestQty = req.quantity;

      allocationSubtitle.textContent = `Order #${req.request_id} · Needs ${req.quantity} unit(s) of ${req.requested_blood_group}`;
      summaryGroup.textContent = req.requested_blood_group;
      summaryQty.textContent = req.quantity;
      summaryUrgency.textContent = req.urgency;
      summaryUrgency.className = `urgency-badge urgency-${req.urgency.toLowerCase()}`;

      renderRecommendations(data.recommendations || []);
    } catch (err) {
      recommendationsContainer.innerHTML = `<div style="color: var(--danger); padding: 24px; text-align: center;">${err.message}</div>`;
    }
  }

  function renderRecommendations(candidates) {
    if (candidates.length === 0) {
      recommendationsContainer.innerHTML = `
        <div style="background: #fff3f3; border: 1px solid #ffd8d8; border-radius: 12px; padding: 24px; text-align: center;">
          <h3 style="color: var(--danger); margin: 0 0 8px;">No Compatible Units Available</h3>
          <p style="color: var(--muted); margin: 0; font-size: 0.92rem;">
            There are currently no compatible, lab-cleared (AVAILABLE), unexpired units in stock for this blood group.
            Please test quarantined units in Inventory or issue an urgent donor call.
          </p>
        </div>
      `;
      confirmAllocationBtn.disabled = true;
      selectedUnitsCountEl.textContent = "0";
      return;
    }

    selectedUnitIds.clear();

    recommendationsContainer.innerHTML = candidates
      .map((c) => {
        const isTop = c.rank === 1;
        const isChecked = c.recommended;
        if (isChecked) selectedUnitIds.add(c.unit_id);

        const matchTag = c.is_exact_match
          ? `<span class="badge badge-available">Exact Match (100% ABO/Rh)</span>`
          : `<span class="badge badge-reserved">Compatible Substitute</span>`;

        return `
          <div class="candidate-card ${isChecked ? 'selected' : ''}" data-unit-id="${c.unit_id}">
            <div class="candidate-rank ${isTop ? 'top-pick' : ''}" title="Rank #${c.rank}">
              #${c.rank}
            </div>
            <div class="candidate-details">
              <div class="candidate-title-row">
                <input type="checkbox" class="unit-select-checkbox" data-unit-id="${c.unit_id}" ${isChecked ? 'checked' : ''} />
                <strong>Unit #${c.unit_id}</strong>
                <span class="blood-badge">${c.blood_group}</span>
                ${matchTag}
                <span class="score-badge">Total Score: ${c.total_score}</span>
              </div>
              <div class="score-breakdown-row">
                <span><strong>Shelf Life:</strong> ${c.days_to_expiry}d remaining (Exp: ${c.expiry_date})</span>
                <span><strong>Match Score:</strong> +${c.match_score}</span>
                <span><strong>FEFO Score:</strong> +${c.fefo_score}</span>
                ${c.scarcity_penalty > 0 ? `<span style="color:var(--danger)"><strong>O- Penalty:</strong> -${c.scarcity_penalty}</span>` : ''}
              </div>
              <div class="clinical-reason">
                💡 ${escapeHtml(c.clinical_reason)}
              </div>
            </div>
          </div>
        `;
      })
      .join("");

    updateSelectedCounter();

    // Toggle card selection on click
    recommendationsContainer.querySelectorAll(".candidate-card").forEach((card) => {
      card.addEventListener("click", (e) => {
        if (e.target.type === "checkbox") return;
        const checkbox = card.querySelector(".unit-select-checkbox");
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event("change"));
      });
    });

    recommendationsContainer.querySelectorAll(".unit-select-checkbox").forEach((cb) => {
      cb.addEventListener("change", (e) => {
        const unitId = parseInt(cb.dataset.unitId, 10);
        const card = cb.closest(".candidate-card");
        if (cb.checked) {
          selectedUnitIds.add(unitId);
          card.classList.add("selected");
        } else {
          selectedUnitIds.delete(unitId);
          card.classList.remove("selected");
        }
        updateSelectedCounter();
      });
    });
  }

  function updateSelectedCounter() {
    const count = selectedUnitIds.size;
    selectedUnitsCountEl.textContent = count;
    confirmAllocationBtn.disabled = count === 0;

    if (count > currentRequestQty) {
      confirmAllocationBtn.textContent = `✓ Issue ${count} Units (${count - currentRequestQty} extra)`;
    } else {
      confirmAllocationBtn.textContent = `✓ Confirm Allocation & Issue (${count}/${currentRequestQty})`;
    }
  }

  // Confirm Allocation & Atomic Issue
  confirmAllocationBtn.addEventListener("click", async () => {
    if (selectedUnitIds.size === 0) return;

    if (
      !confirm(
        `Confirm allocation and issue of ${selectedUnitIds.size} unit(s) for Order #${currentRequestId}? Stock will be atomically deducted and logged.`
      )
    ) {
      return;
    }

    confirmAllocationBtn.disabled = true;
    confirmAllocationBtn.textContent = "Committing transaction…";

    try {
      const res = await fetch("api/allocation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "confirm_allocation",
          request_id: currentRequestId,
          unit_ids: Array.from(selectedUnitIds),
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Allocation failed.");
      }

      showStatus(allocationStatusMsg, "success", data.message);

      setTimeout(() => {
        closeModal(allocationModal);
        loadRequests();
      }, 1200);
    } catch (err) {
      showStatus(allocationStatusMsg, "error", err.message);
      confirmAllocationBtn.disabled = false;
      confirmAllocationBtn.textContent = "✓ Confirm Allocation & Issue";
    }
  });

  // Open New Request Modal
  openNewRequestBtn.addEventListener("click", () => {
    newRequestForm.reset();
    newRequestStatus.className = "form-status";
    openModal(newRequestModal);
  });

  // Submit New Request
  newRequestForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const bloodGroup = document.getElementById("reqBloodGroup").value;
    const quantity = parseInt(document.getElementById("reqQuantity").value, 10) || 1;
    const urgency = document.getElementById("reqUrgency").value;

    if (!bloodGroup) {
      showStatus(newRequestStatus, "error", "Please select a recipient blood group.");
      return;
    }

    try {
      const res = await fetch("api/allocation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "create_request",
          blood_group: bloodGroup,
          quantity,
          urgency,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to submit request.");
      }

      showStatus(newRequestStatus, "success", data.message);
      setTimeout(() => {
        closeModal(newRequestModal);
        loadRequests();
      }, 800);
    } catch (err) {
      showStatus(newRequestStatus, "error", err.message);
    }
  });

  // Seed Demo Stock Button (Pitch helper)
  seedDemoStockBtn.addEventListener("click", async () => {
    if (
      !confirm(
        "Generate 8 verified blood units across multiple blood groups with varying expiry countdowns for demonstration?"
      )
    ) {
      return;
    }

    seedDemoStockBtn.disabled = true;
    seedDemoStockBtn.textContent = "Seeding stock…";

    try {
      const res = await fetch("api/allocation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "seed_demo_stock" }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || "Could not seed stock.");
      alert(data.message);
      loadRequests();
    } catch (err) {
      alert(err.message);
    } finally {
      seedDemoStockBtn.disabled = false;
      seedDemoStockBtn.textContent = "⚡ Seed Demo Stock";
    }
  });

  queueStatusFilter.addEventListener("change", loadRequests);
  refreshQueueBtn.addEventListener("click", loadRequests);

  // Initial load
  loadRequests();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
