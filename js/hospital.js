// js/hospital.js - Hospital Requisition & Transfusion Dispatch UI Logic

document.addEventListener("DOMContentLoaded", () => {
  const session = getSession();
  if (!session) {
    window.location.replace("login.html");
    return;
  }

  const tableBody = document.getElementById("hospOrdersTableBody");
  const statusFilter = document.getElementById("hospStatusFilter");
  const refreshBtn = document.getElementById("refreshHospBtn");

  // KPI elements
  const kpiPending = document.getElementById("hospPendingCount");
  const kpiCritical = document.getElementById("hospCriticalCount");
  const kpiFulfilled = document.getElementById("hospFulfilledCount");
  const kpiTotalUnits = document.getElementById("hospTotalUnits");

  // Requisition Modal elements
  const reqModal = document.getElementById("requisitionModal");
  const reqForm = document.getElementById("requisitionForm");
  const reqStatusMsg = document.getElementById("requisitionStatusMsg");
  const openRequisitionBtn = document.getElementById("openRequisitionBtn");
  const reqPatientRef = document.getElementById("reqPatientRef");
  const reqIndication = document.getElementById("reqClinicalIndication");
  const reqQuantity = document.getElementById("reqQuantityInput");
  const reqGroup = document.getElementById("reqGroupSelect");
  const reqUrgency = document.getElementById("reqUrgencySelect");

  // Slip Modal elements
  const slipModal = document.getElementById("slipModal");
  const slipContent = document.getElementById("slipContent");

  let ordersList = [];

  function openModal(modal) {
    modal.hidden = false;
  }
  function closeModal(modal) {
    modal.hidden = true;
  }

  document.querySelectorAll("[data-close-modal]").forEach((btn) => {
    btn.addEventListener("click", () => {
      closeModal(reqModal);
      closeModal(slipModal);
    });
  });

  // Load Hospital Orders from api/hospital.php
  async function loadHospitalOrders() {
    tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--muted);">Loading hospital requisitions…</td></tr>`;

    const status = statusFilter.value;
    const params = new URLSearchParams();
    if (status) params.append("status", status);

    try {
      const res = await fetch(`api/hospital.php?action=my_requests&${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load hospital orders.");
      }

      ordersList = data.orders || [];
      renderOrdersTable(ordersList);
      updateKPIs(data.metrics || {});
    } catch (err) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateKPIs(metrics) {
    kpiPending.textContent = metrics.pending_count || 0;
    kpiCritical.textContent = metrics.critical_count || 0;
    kpiFulfilled.textContent = metrics.fulfilled_count || 0;
    kpiTotalUnits.textContent = metrics.total_units_received || 0;
  }

  function renderOrdersTable(list) {
    if (list.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 32px; color: var(--muted);">No blood orders recorded. Click "+ Order Blood Units" to submit a new clinical requisition.</td></tr>`;
      return;
    }

    tableBody.innerHTML = list
      .map((o) => {
        let urgencyBadge = `<span class="urgency-badge urgency-routine">Routine</span>`;
        if (o.urgency === "URGENT") {
          urgencyBadge = `<span class="urgency-badge urgency-urgent">Urgent</span>`;
        } else if (o.urgency === "CRITICAL") {
          urgencyBadge = `<span class="urgency-badge urgency-critical">⚡ Critical</span>`;
        }

        const isFulfilled = o.status === "FULFILLED";
        const partialCount = (o.allocated_units || []).length;
        const statusBadge = isFulfilled
          ? `<span class="badge badge-available">✓ Dispatched</span>`
          : `<span class="badge badge-quarantined">${partialCount ? `Partially dispatched (${partialCount}/${o.quantity})` : 'In Queue'}</span>`;

        const dispatchAction = isFulfilled || partialCount > 0
          ? `<button class="btn btn-primary btn-sm" data-action="view-slip" data-id="${o.request_id}">
               📄 Transfusion Slip (${o.allocated_units ? o.allocated_units.length : o.quantity} Units)
             </button>`
          : `<span style="font-size: 0.85rem; color: var(--muted);">Processing by Blood Bank…</span>`;

        return `
          <tr>
            <td><strong>#${o.request_id}</strong></td>
            <td>
              <div><strong>${escapeHtml(o.patient_ref)}</strong></div>
              <small style="color:var(--muted)">Dr. ${escapeHtml(o.requester_name)}</small>
            </td>
            <td><span class="blood-badge">${o.requested_blood_group}</span></td>
            <td><strong>${o.quantity} unit${o.quantity > 1 ? 's' : ''}</strong></td>
            <td>${urgencyBadge}</td>
            <td><small>${o.request_date}</small></td>
            <td>${statusBadge}</td>
            <td>${dispatchAction}</td>
          </tr>
        `;
      })
      .join("");

    tableBody.querySelectorAll("[data-action='view-slip']").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.dataset.id, 10);
        openTransfusionSlip(id);
      });
    });
  }

  // Open & Render Digital Transfusion Dispatch Slip
  async function openTransfusionSlip(requestId) {
    slipContent.innerHTML = `<div style="text-align: center; padding: 24px; color: var(--muted);">Generating certified transfusion dispatch slip…</div>`;
    openModal(slipModal);

    try {
      const res = await fetch(`api/hospital.php?action=transfusion_slip&request_id=${requestId}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load dispatch record.");
      }

      const o = data.order;
      const units = data.allocated_units || [];

      const unitsRows = units.length > 0
        ? units.map((u, i) => `
            <tr>
              <td><strong>#${i + 1}</strong></td>
              <td><strong>Bag #${u.unit_id}</strong></td>
              <td><span class="blood-badge">${u.blood_group}</span></td>
              <td>${u.collection_date || 'N/A'}</td>
              <td><strong>${u.expiry_date}</strong></td>
              <td><span class="badge badge-available">Issued / Verified</span></td>
            </tr>
          `).join("")
        : `<tr><td colspan="6" style="text-align:center; padding:12px;">Units allocated under Order #${o.request_id}</td></tr>`;

      slipContent.innerHTML = `
        <div style="font-size: 0.92rem; line-height: 1.6;">
          <!-- Slip Metadata Grid -->
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; background: #faf4ef; padding: 14px 18px; border-radius: 10px; margin-bottom: 16px;">
            <div>
              <small style="color:var(--muted); display:block;">Requisition ID</small>
              <strong>Order #${o.request_id}</strong>
            </div>
            <div>
              <small style="color:var(--muted); display:block;">Hospital / Ward</small>
              <strong>${escapeHtml(o.hospital_name)}</strong>
            </div>
            <div>
              <small style="color:var(--muted); display:block;">Patient / Clinical Ref</small>
              <strong>${escapeHtml(o.patient_ref)}</strong>
            </div>
            <div>
              <small style="color:var(--muted); display:block;">Recipient Group</small>
              <span class="blood-badge">${o.requested_blood_group}</span> (${o.quantity} Units)
            </div>
            <div>
              <small style="color:var(--muted); display:block;">Clinical Urgency</small>
              <strong>${o.urgency}</strong>
            </div>
            <div>
              <small style="color:var(--muted); display:block;">Dispatch Timestamp</small>
              <small>${data.dispatch_timestamp}</small>
            </div>
          </div>

          <!-- Allocated Units Table -->
          <h4 style="margin: 14px 0 8px; color: var(--wine-dark);">Dispatched Blood Unit Serial Numbers</h4>
          <table class="data-table" style="margin-bottom: 18px; font-size: 0.88rem;">
            <thead>
              <tr>
                <th>#</th>
                <th>Unit Serial</th>
                <th>Blood Group</th>
                <th>Collection Date</th>
                <th>Expiry Date</th>
                <th>Safety Status</th>
              </tr>
            </thead>
            <tbody>
              ${unitsRows}
            </tbody>
          </table>

          <!-- Mandatory Transfusion Safety Notice -->
          <div style="background: #fff3f3; border-left: 4px solid var(--danger); padding: 12px 16px; border-radius: 6px; font-size: 0.84rem; color: #841b14; margin-top: 10px;">
            <strong>MANDATORY PRE-TRANSFUSION SAFETY VERIFICATION:</strong><br/>
            Before intravenous transfusion, bedside cross-matching, dual-nurse verification of patient identity, and vital signs monitoring remain strictly mandatory under national transfusion standards.
          </div>
        </div>
      `;
    } catch (err) {
      slipContent.innerHTML = `<div style="color: var(--danger); padding: 20px; text-align: center;">${err.message}</div>`;
    }
  }

  // Open Requisition Modal
  openRequisitionBtn.addEventListener("click", () => {
    reqForm.reset();
    reqStatusMsg.className = "form-status";
    openModal(reqModal);
  });

  // Submit Requisition Form
  reqForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const patientRef = reqPatientRef.value.trim();
    const indication = reqIndication.value;
    const quantity = Number(reqQuantity.value);
    const bloodGroup = reqGroup.value;
    const urgency = reqUrgency.value;

    if (!patientRef || patientRef.length > 120 || !bloodGroup || !Number.isInteger(quantity) || quantity < 1 || quantity > 10) {
      showStatus(reqStatusMsg, "error", "Enter a patient or ward reference (up to 120 characters), select a blood group, and choose a whole-number quantity from 1 to 10.");
      return;
    }

    try {
      const res = await fetch("api/hospital.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "place_requisition",
          patient_ref: patientRef,
          clinical_indication: indication,
          blood_group: bloodGroup,
          quantity,
          urgency,
        }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to submit requisition.");
      }

      showStatus(reqStatusMsg, "success", data.message);
      setTimeout(() => {
        closeModal(reqModal);
        loadHospitalOrders();
      }, 900);
    } catch (err) {
      showStatus(reqStatusMsg, "error", err.message);
    }
  });

  statusFilter.addEventListener("change", loadHospitalOrders);
  refreshBtn.addEventListener("click", loadHospitalOrders);

  // Initial load
  loadHospitalOrders();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
