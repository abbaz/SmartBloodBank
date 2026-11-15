// js/reports.js - Transfusion Analytics & Wastage Intelligence UI Logic (Chart.js Integration)

document.addEventListener("DOMContentLoaded", () => {
  if (!requireStaffOrAdmin()) return;

  const refreshBtn = document.getElementById("refreshReportsBtn");
  const rangePreset = document.getElementById("reportRangePreset");
  const customDates = document.getElementById("customReportDates");
  const startInput = document.getElementById("reportStartDate");
  const endInput = document.getElementById("reportEndDate");
  const applyPeriodBtn = document.getElementById("applyReportPeriodBtn");
  const reportPeriodLabel = document.getElementById("reportPeriodLabel");
  const monthlyTrendHint = document.getElementById("monthlyTrendHint");
  const kpiAvailable = document.getElementById("kpiAvailableUnits");
  const kpiEfficiency = document.getElementById("kpiEfficiencyRate");
  const kpiWastage = document.getElementById("kpiWastageRate");
  const kpiFulfillment = document.getElementById("kpiFulfillmentRate");
  const kpiNearExpiry = document.getElementById("kpiNearExpiry");
  const breakdownTableBody = document.getElementById("breakdownTableBody");

  // Keep references to Chart.js instances so we can destroy/recreate cleanly on refresh
  let chartGroup = null;
  let chartMonthly = null;
  let chartStatus = null;
  let chartUrgency = null;

  function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
  }

  function setPresetDates(preset) {
    const end = new Date();
    const start = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    if (preset === "30d") start.setDate(start.getDate() - 29);
    else if (preset === "90d") start.setDate(start.getDate() - 89);
    else if (preset === "ytd") start.setMonth(0, 1);
    else start.setDate(1), start.setMonth(start.getMonth() - 5);
    startInput.value = formatLocalDate(start);
    endInput.value = formatLocalDate(end);
  }

  async function loadReports() {
    const start = startInput.value;
    const end = endInput.value;
    if (!start || !end) {
      reportPeriodLabel.textContent = "Choose both dates.";
      return;
    }
    if (start > end) {
      reportPeriodLabel.textContent = "Start date must be on or before end date.";
      return;
    }
    try {
      const query = new URLSearchParams({ start_date: start, end_date: end });
      const res = await fetch(`api/reports.php?${query.toString()}`);
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || "Failed to load transfusion analytics.");
      }

      reportPeriodLabel.textContent = `${data.report_period.start_date} to ${data.report_period.end_date}`;
      monthlyTrendHint.textContent = `Monthly collections, transfusions, and expirations from ${data.report_period.start_date} through ${data.report_period.end_date}.`;
      updateKPIs(data);
      renderCharts(data);
      renderBreakdownTable(data.group_stock || {});
    } catch (err) {
      console.error("Analytics load error:", err);
      reportPeriodLabel.textContent = err.message || "Could not load this report period.";
      breakdownTableBody.innerHTML = `<tr><td colspan="7" role="alert" style="text-align:center;padding:20px;color:var(--danger)">${escapeHtml(err.message || "Could not load reports.")}</td></tr>`;
    }
  }

  function applyPreset(preset) {
    customDates.hidden = preset !== "custom";
    if (preset !== "custom") {
      setPresetDates(preset);
      loadReports();
    }
  }

  rangePreset?.addEventListener("change", () => applyPreset(rangePreset.value));
  startInput?.addEventListener("change", () => { rangePreset.value = "custom"; customDates.hidden = false; });
  endInput?.addEventListener("change", () => { rangePreset.value = "custom"; customDates.hidden = false; });
  applyPeriodBtn?.addEventListener("click", loadReports);

  function updateKPIs(data) {
    const wm = data.wastage_metrics || {};
    const dm = data.demand_metrics || {};

    let totalAv = 0;
    Object.values(data.group_stock || {}).forEach((g) => {
      totalAv += g.available || 0;
    });

    kpiAvailable.textContent = totalAv;
    kpiEfficiency.textContent = `${wm.efficiency_rate ?? 100}%`;
    kpiWastage.textContent = `${wm.wastage_rate ?? 0}%`;
    kpiFulfillment.textContent = `${dm.fulfillment_rate ?? 100}%`;
    kpiNearExpiry.textContent = data.near_expiry_count ?? 0;
  }

  function renderCharts(data) {
    if (typeof Chart === "undefined") {
      console.warn("Chart.js not loaded.");
      return;
    }

    // 1. Chart: Group Breakdown (Doughnut)
    const groupLabels = Object.keys(data.group_stock || {});
    const groupAvValues = groupLabels.map((g) => data.group_stock[g].available || 0);

    const ctxGroup = document.getElementById("groupChart")?.getContext("2d");
    if (ctxGroup) {
      if (chartGroup) chartGroup.destroy();
      chartGroup = new Chart(ctxGroup, {
        type: "doughnut",
        data: {
          labels: groupLabels,
          datasets: [
            {
              data: groupAvValues,
              backgroundColor: [
                "#8b1e2d", "#c45c6a", "#b8956a", "#2f6b5c",
                "#5c121c", "#e07a5f", "#3d5a80", "#98c1d9"
              ],
              borderWidth: 2,
              borderColor: "#fff"
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: "right" }
          }
        }
      });
    }

    // 2. Chart: Monthly Trends (Bar Chart)
    const trends = data.monthly_trends || [];
    const trendLabels = trends.map((t) => t.month);
    const trendColls = trends.map((t) => t.collections);
    const trendIssues = trends.map((t) => t.issuances);
    const trendExp = trends.map((t) => t.expired);

    const ctxMonthly = document.getElementById("monthlyChart")?.getContext("2d");
    if (ctxMonthly) {
      if (chartMonthly) chartMonthly.destroy();
      chartMonthly = new Chart(ctxMonthly, {
        type: "bar",
        data: {
          labels: trendLabels,
          datasets: [
            {
              label: "Collections",
              data: trendColls,
              backgroundColor: "rgba(47, 107, 92, 0.75)",
              borderColor: "#2f6b5c",
              borderWidth: 1
            },
            {
              label: "Transfusions",
              data: trendIssues,
              backgroundColor: "rgba(139, 30, 45, 0.75)",
              borderColor: "#8b1e2d",
              borderWidth: 1
            },
            {
              label: "Expired",
              data: trendExp,
              backgroundColor: "rgba(180, 35, 24, 0.5)",
              borderColor: "#b42318",
              borderWidth: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    }

    // 3. Chart: Unit Status Lifecycle (Bar)
    const stCounts = data.status_counts || {};
    const stLabels = ["Available", "Quarantined", "Reserved", "Issued", "Expired"];
    const stValues = [
      stCounts.AVAILABLE || 0,
      stCounts.QUARANTINED || 0,
      stCounts.RESERVED || 0,
      stCounts.ISSUED || 0,
      stCounts.EXPIRED || 0
    ];

    const ctxStatus = document.getElementById("statusChart")?.getContext("2d");
    if (ctxStatus) {
      if (chartStatus) chartStatus.destroy();
      chartStatus = new Chart(ctxStatus, {
        type: "bar",
        data: {
          labels: stLabels,
          datasets: [
            {
              data: stValues,
              backgroundColor: [
                "#2f6b5c", // Available (green)
                "#b8956a", // Quarantined (gold)
                "#0369a1", // Reserved (blue)
                "#6d575c", // Issued (gray)
                "#b42318"  // Expired (red)
              ],
              borderRadius: 8
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
          }
        }
      });
    }

    // 4. Chart: Urgency Demand (Pie)
    const ugCounts = data.demand_metrics?.urgency_counts || {};
    const ugLabels = ["Critical", "Urgent", "Routine"];
    const ugValues = [
      ugCounts.CRITICAL || 0,
      ugCounts.URGENT || 0,
      ugCounts.ROUTINE || 0
    ];

    const ctxUrgency = document.getElementById("urgencyChart")?.getContext("2d");
    if (ctxUrgency) {
      if (chartUrgency) chartUrgency.destroy();
      chartUrgency = new Chart(ctxUrgency, {
        type: "pie",
        data: {
          labels: ugLabels,
          datasets: [
            {
              data: ugValues,
              backgroundColor: ["#b42318", "#b54708", "#2f6b5c"],
              borderWidth: 2,
              borderColor: "#fff"
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: "right" }
          }
        }
      });
    }
  }

  function renderBreakdownTable(groupStock) {
    const keys = Object.keys(groupStock);
    if (keys.length === 0) {
      breakdownTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px;">No inventory records available.</td></tr>`;
      return;
    }

    breakdownTableBody.innerHTML = keys
      .map((g) => {
        const item = groupStock[g];
        const av = item.available || 0;
        const tot = item.total || 0;
        const issued = item.period_issued || 0;
        const expired = item.period_expired || 0;
        const handled = issued + expired;
        const near = item.near_expiry || 0;

        let statusTag = `<span class="badge badge-available">Optimal Stock</span>`;
        if (av === 0) {
          statusTag = `<span class="badge badge-expired">Critical Depletion (0)</span>`;
        } else if (av < 3) {
          statusTag = `<span class="badge badge-quarantined">Low Reserve (${av})</span>`;
        }

        return `
          <tr>
            <td><span class="blood-badge">${g}</span></td>
            <td><strong>${av} units</strong></td>
            <td>${tot} units</td>
            <td>${issued} units</td>
            <td>${expired} units${handled ? ` <small>(${(expired / handled * 100).toFixed(1)}%)</small>` : ""}</td>
            <td>${near} units</td>
            <td>${statusTag}</td>
          </tr>
        `;
      })
      .join("");
  }

  refreshBtn?.addEventListener("click", loadReports);

  // Initial load
  const today = formatLocalDate(new Date());
  startInput.max = today;
  endInput.max = today;
  setPresetDates("6m");
  loadReports();
});

function escapeHtml(value) {
  const node = document.createElement("span");
  node.textContent = value || "";
  return node.innerHTML;
}
