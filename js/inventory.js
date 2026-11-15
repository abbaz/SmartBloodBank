// js/inventory.js - Blood Unit Inventory and Expiry Management UI Logic

document.addEventListener("DOMContentLoaded", () => {
  if (!requireStaffOrAdmin()) return;

  const tableBody = document.getElementById("inventoryTableBody");
  const statusFilter = document.getElementById("statusFilter");
  const groupFilter = document.getElementById("groupFilter");
  const nearExpiryCheckbox = document.getElementById("nearExpiryCheckbox");
  const refreshBtn = document.getElementById("refreshInventoryBtn");

  // KPI elements
  const statTotal = document.getElementById("statTotal");
  const statAvailable = document.getElementById("statAvailable");
  const statQuarantined = document.getElementById("statQuarantined");
  const statNearExpiry = document.getElementById("statNearExpiry");
  const statExpired = document.getElementById("statExpired");

  // Edit Modal elements
  const editModal = document.getElementById("editUnitModal");
  const editForm = document.getElementById("editUnitForm");
  const editStatusMsg = document.getElementById("editUnitStatusMsg");
  const editUnitId = document.getElementById("editUnitId");
  const editUnitGroup = document.getElementById("editUnitGroup");
  const editUnitStatus = document.getElementById("editUnitStatus");
  const editUnitExpiry = document.getElementById("editUnitExpiry");

  let unitsList = [];

  function openModal(modal) {
    modal.hidden = false;
  }
  function closeModal(modal) {
    modal.hidden = true;
  }

  document.querySelectorAll("[data-close-modal]").forEach((btn) => {
    btn.addEventListener("click", () => closeModal(editModal));
  });

  // Fetch Inventory from MySQL
  async function loadInventory() {
    tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--muted);">Loading blood stock…</td></tr>`;

    const status = statusFilter.value;
    const group = groupFilter.value;
    const nearExpiry = nearExpiryCheckbox.checked;

    const params = new URLSearchParams();
    if (status) params.append("status", status);
    if (group) params.append("blood_group", group);
    if (nearExpiry) params.append("near_expiry", "1");

    try {
      const res = await fetch(`api/inventory.php?${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load inventory.");
      }

      unitsList = data.units || [];
      renderInventoryTable(unitsList);
      updateKPIs(data.stats || {});
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateKPIs(stats) {
    statTotal.textContent = stats.total || 0;
    statAvailable.textContent = stats.available || 0;
    statQuarantined.textContent = stats.quarantined || 0;
    statNearExpiry.textContent = stats.near_expiry || 0;
    statExpired.textContent = stats.expired || 0;
  }

  function renderInventoryTable(list) {
    if (list.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 28px; color: var(--muted);">No blood units found matching criteria. Click "+ Log New Donation" in Donors to add units.</td></tr>`;
      return;
    }

    tableBody.innerHTML = list
      .map((u) => {
        // Status Badge
        let statusBadge = `<span class="badge badge-quarantined">Quarantined</span>`;
        if (u.status === "AVAILABLE") {
          statusBadge = `<span class="badge badge-available">Available</span>`;
        } else if (u.status === "RESERVED") {
          statusBadge = `<span class="badge badge-reserved">Reserved</span>`;
        } else if (u.status === "ISSUED") {
          statusBadge = `<span class="badge badge-issued">Issued</span>`;
        } else if (u.status === "EXPIRED") {
          statusBadge = `<span class="badge badge-expired">Expired</span>`;
        }

        // Expiry Remaining Badge
        let remainingBadge = "";
        if (u.is_expired) {
          remainingBadge = `<span class="badge badge-expired">Expired (${Math.abs(u.days_to_expiry)}d ago)</span>`;
        } else if (u.is_near_expiry) {
          remainingBadge = `<span class="badge badge-quarantined" style="background:#fef0c7; color:#b54708;">⚠️ ${u.days_to_expiry} days left</span>`;
        } else {
          remainingBadge = `<span style="font-size: 0.88rem; color: var(--muted);">${u.days_to_expiry} days</span>`;
        }

        return `
          <tr>
            <td><strong>#${u.unit_id}</strong></td>
            <td><span class="blood-badge">${u.blood_group}</span></td>
            <td>
              <div><strong>${escapeHtml(u.donor_name)}</strong></div>
              ${u.donor_id ? `<small style="color:var(--muted)">Donor #${u.donor_id}</small>` : ""}
            </td>
            <td>${u.collection_date}</td>
            <td><strong>${u.expiry_date}</strong></td>
            <td>${remainingBadge}</td>
            <td>${statusBadge}</td>
            <td>
              <div class="actions-cell">
                <button class="btn btn-ghost btn-sm" data-action="edit-unit" data-id="${u.unit_id}">
                  Edit
                </button>
                ${
                  u.status !== "EXPIRED" && u.status !== "ISSUED"
                    ? `<button class="btn btn-ghost btn-sm" style="color: var(--danger)" data-action="discard-unit" data-id="${u.unit_id}">
                         Discard
                       </button>`
                    : ""
                }
                ${u.status !== "ISSUED" && !u.has_transaction
                  ? `<button class="btn btn-ghost btn-sm" style="color:var(--danger)" data-action="delete-unit" data-id="${u.unit_id}">Delete</button>`
                  : ""}
              </div>
            </td>
          </tr>
        `;
      })
      .join("");

    // Attach actions
    tableBody.querySelectorAll("[data-action='edit-unit']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        const unit = unitsList.find((item) => item.unit_id === id);
        if (!unit) return;
        openEditModal(unit);
      });
    });

    tableBody.querySelectorAll("[data-action='discard-unit']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        discardUnit(id);
      });
    });
    tableBody.querySelectorAll("[data-action='delete-unit']").forEach((btn) => {
      btn.addEventListener("click", () => deleteUnit(parseInt(btn.dataset.id, 10)));
    });
  }

  // Open Edit Modal
  function openEditModal(unit) {
    editUnitId.value = unit.unit_id;
    editUnitGroup.value = unit.blood_group;
    editUnitStatus.value = unit.status;
    editUnitExpiry.value = unit.expiry_date;
    editStatusMsg.className = "form-status";
    openModal(editModal);
  }

  // Submit Edit Form
  editForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const unitId = editUnitId.value;
    const status = editUnitStatus.value;
    const expiryDate = editUnitExpiry.value;

    try {
      const res = await fetch("api/inventory.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "update",
          unit_id: unitId,
          status,
          expiry_date: expiryDate,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to update unit.");
      }

      showStatus(editStatusMsg, "success", data.message);
      setTimeout(() => {
        closeModal(editModal);
        loadInventory();
      }, 700);
    } catch (err) {
      showStatus(editStatusMsg, "error", err.message);
    }
  });

  // Discard Unit
  async function discardUnit(id) {
    const reason = prompt("Enter reason for discarding unit (e.g. Near expiry, Bag defect, Clotted):", "Discarded by staff");
    if (reason === null) return;

    try {
      const res = await fetch("api/inventory.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "discard",
          unit_id: id,
          reason: reason || "Discarded by staff",
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to discard unit.");
      }

      loadInventory();
    } catch (err) {
      alert(err.message);
    }
  }

  async function deleteUnit(id) {
    if (!confirm(`Permanently delete inventory unit #${id}? It cannot be deleted if linked to an issued transaction. Its details will be retained in the audit trail.`)) return;
    const reason = prompt("Enter the reason for deletion:", "Duplicate or incorrect inventory entry");
    if (reason === null) return;
    try {
      const res = await fetch("api/inventory.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", unit_id: id, reason }),
      });
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || "Failed to delete unit.");
      loadInventory();
    } catch (err) {
      alert(err.message);
    }
  }

  // Event Listeners for Filters
  statusFilter.addEventListener("change", loadInventory);
  groupFilter.addEventListener("change", loadInventory);
  nearExpiryCheckbox.addEventListener("change", loadInventory);
  refreshBtn.addEventListener("click", loadInventory);

  // Initial Load
  loadInventory();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
