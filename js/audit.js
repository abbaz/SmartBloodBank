// js/audit.js - Regulatory Audit Trail & Compliance Ledger UI Logic

document.addEventListener("DOMContentLoaded", () => {
  if (!requireStaffOrAdmin()) return;

  const auditTableBody = document.getElementById("auditTableBody");
  const transfusionsTableBody = document.getElementById("transfusionsTableBody");
  const auditLogsPanel = document.getElementById("auditLogsPanel");
  const transfusionsPanel = document.getElementById("transfusionsPanel");
  const auditToolbar = document.getElementById("auditToolbar");

  const tabAuditLogsBtn = document.getElementById("tabAuditLogsBtn");
  const tabTransfusionsBtn = document.getElementById("tabTransfusionsBtn");

  const actionFilter = document.getElementById("actionTypeFilter");
  const dateFromFilter = document.getElementById("dateFromFilter");
  const dateToFilter = document.getElementById("dateToFilter");
  const searchInput = document.getElementById("auditSearch");
  const refreshBtn = document.getElementById("refreshAuditBtn");
  const exportCsvBtn = document.getElementById("exportAuditCsvBtn");
  const prevPageBtn = document.getElementById("auditPrevPage");
  const nextPageBtn = document.getElementById("auditNextPage");
  const pageSummary = document.getElementById("auditPageSummary");

  // KPI elements
  const totalAuditCount = document.getElementById("totalAuditCount");
  const allocAuditCount = document.getElementById("allocAuditCount");
  const labAuditCount = document.getElementById("labAuditCount");
  const securityAuditCount = document.getElementById("securityAuditCount");

  let auditLogsList = [];
  let currentPage = 1;
  let totalPages = 1;
  let currentTab = "audit"; // 'audit' or 'transfusions'

  // Tab Switching
  tabAuditLogsBtn.addEventListener("click", () => {
    currentTab = "audit";
    tabAuditLogsBtn.className = "btn btn-primary";
    tabTransfusionsBtn.className = "btn btn-ghost";
    auditLogsPanel.hidden = false;
    transfusionsPanel.hidden = true;
    auditToolbar.style.display = "flex";
    loadAuditLogs();
  });

  tabTransfusionsBtn.addEventListener("click", () => {
    currentTab = "transfusions";
    tabTransfusionsBtn.className = "btn btn-primary";
    tabAuditLogsBtn.className = "btn btn-ghost";
    auditLogsPanel.hidden = true;
    transfusionsPanel.hidden = false;
    auditToolbar.style.display = "none";
    loadTransfusionsLedger();
  });

  // Load Audit Logs from api/audit.php
  async function loadAuditLogs() {
    auditTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 24px; color: var(--muted);">Querying immutable compliance log…</td></tr>`;

    const action = actionFilter.value;
    const from = dateFromFilter.value;
    const to = dateToFilter.value;
    const q = searchInput.value.trim();

    const params = new URLSearchParams();
    if (action) params.append("action_type", action);
    if (from) params.append("date_from", from);
    if (to) params.append("date_to", to);
    if (q) params.append("q", q);
    params.append("page", currentPage);

    try {
      const res = await fetch(`api/audit.php?action=logs&${params.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load audit trail.");
      }

      auditLogsList = data.logs || [];
      currentPage = data.pagination?.page || 1;
      totalPages = data.pagination?.total_pages || 1;
      const total = data.pagination?.total || 0;
      const pageSize = data.pagination?.page_size || 100;
      const first = total ? (currentPage - 1) * pageSize + 1 : 0;
      const last = Math.min(currentPage * pageSize, total);
      pageSummary.textContent = `Showing ${first}–${last} of ${total} matching events`;
      prevPageBtn.disabled = currentPage <= 1;
      nextPageBtn.disabled = currentPage >= totalPages;
      renderAuditTable(auditLogsList);
      updateKPIs(data.metrics || {});
    } catch (err) {
      auditTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  function updateKPIs(metrics) {
    totalAuditCount.textContent = metrics.total_logs || 0;
    allocAuditCount.textContent = metrics.alloc_count || 0;
    labAuditCount.textContent = metrics.lab_count || 0;
    securityAuditCount.textContent = metrics.security_count || 0;
  }

  function renderAuditTable(list) {
    if (list.length === 0) {
      auditTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 28px; color: var(--muted);">No audit records matched your filter criteria.</td></tr>`;
      return;
    }

    auditTableBody.innerHTML = list
      .map((l) => {
        let badgeClass = "badge-issued";
        if (["REQUEST_FULFILLED", "LAB_UNIT_CLEARED"].includes(l.action)) {
          badgeClass = "badge-available";
        } else if (["LAB_UNIT_REJECTED", "UNIT_DISCARDED"].includes(l.action)) {
          badgeClass = "badge-expired";
        } else if (["DONATION_LOGGED", "BLOOD_REQUEST_CREATED"].includes(l.action)) {
          badgeClass = "badge-reserved";
        } else if (["DONOR_CREATED", "DONOR_UPDATED"].includes(l.action)) {
          badgeClass = "badge-quarantined";
        }

        const actionFormatted = l.action.replaceAll("_", " ");
        const refText = l.entity_id ? `Ref #${l.entity_id}` : `<span style="color:var(--muted)">—</span>`;

        return `
          <tr>
            <td><small style="font-family:monospace; font-size:0.84rem;">${escapeHtml(l.timestamp)}</small></td>
            <td><span class="badge ${badgeClass}">${escapeHtml(actionFormatted)}</span></td>
            <td><strong>${refText}</strong></td>
            <td>
              <div><strong>${escapeHtml(l.operator_name)}</strong></div>
              <small style="color:var(--muted);">${escapeHtml(l.operator_role)}</small>
            </td>
            <td>${escapeHtml(l.details)}</td>
          </tr>
        `;
      })
      .join("");
  }

  // Load Transfusion Issuance Ledger
  async function loadTransfusionsLedger() {
    transfusionsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--muted);">Loading transfusion ledger…</td></tr>`;

    try {
      const res = await fetch("api/audit.php?action=transfusions");
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load transfusion ledger.");
      }

      const list = data.transfusions || [];
      if (list.length === 0) {
        transfusionsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 28px; color: var(--muted);">No transfusion allocations committed yet.</td></tr>`;
        return;
      }

      transfusionsTableBody.innerHTML = list
        .map((t) => {
          let urgencyBadge = `<span class="urgency-badge urgency-routine">Routine</span>`;
          if (t.urgency === "URGENT") urgencyBadge = `<span class="urgency-badge urgency-urgent">Urgent</span>`;
          else if (t.urgency === "CRITICAL") urgencyBadge = `<span class="urgency-badge urgency-critical">Critical</span>`;

          return `
            <tr>
              <td><strong>Tx #${t.transaction_id}</strong></td>
              <td><small>${t.allocation_date}</small></td>
              <td>
                <strong>Bag #${t.unit_id}</strong>
                <span class="blood-badge" style="margin-left:4px;">${t.unit_blood_group}</span>
              </td>
              <td>
                Order #${t.request_id}
                <small style="color:var(--muted); display:block;">Target: ${t.requested_blood_group}</small>
              </td>
              <td>
                <div><strong>${escapeHtml(t.patient_ref)}</strong></div>
                ${urgencyBadge}
              </td>
              <td><strong>${escapeHtml(t.hospital_name)}</strong></td>
              <td>${escapeHtml(t.requester_name)}</td>
            </tr>
          `;
        })
        .join("");
    } catch (err) {
      transfusionsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 24px;">${err.message}</td></tr>`;
    }
  }

  // Export CSV Functionality
  exportCsvBtn.addEventListener("click", () => {
    if (auditLogsList.length === 0) {
      alert("No audit records to export.");
      return;
    }

    const csvCell = (value) => `"${String(value ?? "").replaceAll('"', '""')}"`;
    const headers = ["Log ID", "Timestamp", "Action", "Reference ID", "Operator", "Role", "Details"].map(csvCell);
    const rows = auditLogsList.map((l) => [
      l.log_id,
      l.timestamp,
      l.action,
      l.entity_id || "",
      l.operator_name || "",
      l.operator_role || "",
      l.details || "",
    ].map(csvCell));

    const csvContent = "\uFEFF" + [headers.join(","), ...rows.map((r) => r.join(","))].join("\r\n");
    const blobUrl = URL.createObjectURL(new Blob([csvContent], { type: "text/csv;charset=utf-8" }));
    const link = document.createElement("a");
    link.setAttribute("href", blobUrl);
    link.setAttribute("download", `SmartBloodBank_Audit_Trail_${new Date().toISOString().split("T")[0]}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(blobUrl);
  });

  // Filter Event Listeners with Debounce
  let searchTimer;
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { currentPage = 1; loadAuditLogs(); }, 300);
  });

  actionFilter.addEventListener("change", () => { currentPage = 1; loadAuditLogs(); });
  dateFromFilter.addEventListener("change", () => { currentPage = 1; loadAuditLogs(); });
  dateToFilter.addEventListener("change", () => { currentPage = 1; loadAuditLogs(); });
  refreshBtn.addEventListener("click", loadAuditLogs);
  prevPageBtn.addEventListener("click", () => { if (currentPage > 1) { currentPage--; loadAuditLogs(); } });
  nextPageBtn.addEventListener("click", () => { if (currentPage < totalPages) { currentPage++; loadAuditLogs(); } });

  // Initial Load
  loadAuditLogs();
});

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}
