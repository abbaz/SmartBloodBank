// Reproducible FIFO / FEFO / weighted-priority comparison from proposal §7.
(() => {
  const groups = ["O_NEGATIVE", "O_POSITIVE", "A_NEGATIVE", "A_POSITIVE", "B_NEGATIVE", "B_POSITIVE", "AB_NEGATIVE", "AB_POSITIVE"];
  function seededRandom(seed) {
    let state = seed >>> 0;
    return () => ((state = (Math.imul(1664525, state) + 1013904223) >>> 0) / 4294967296);
  }

  function buildScenario(unitCount, requestCount, seed) {
    const random = seededRandom(seed);
    const units = Array.from({ length: unitCount }, (_, id) => {
      const collectionDay = Math.floor(random() * 60);
      return { id, group: groups[Math.floor(random() * groups.length)], collectionDay, expiryDay: collectionDay + 35, used: false };
    });
    const requests = Array.from({ length: requestCount }, (_, id) => {
      const urgencyRoll = random();
      return {
        id,
        day: Math.floor(random() * 60),
        group: groups[Math.floor(random() * groups.length)],
        quantity: random() < 0.18 ? 2 : 1,
        urgency: urgencyRoll < 0.12 ? "CRITICAL" : urgencyRoll < 0.38 ? "URGENT" : "ROUTINE",
      };
    }).sort((a, b) => a.day - b.day || a.id - b.id);
    return { units, requests };
  }

  function simulate(policy, scenario, policyConfig) {
    const units = scenario.units.map((unit) => ({ ...unit }));
    let fulfilled = 0, exactRequests = 0, issued = 0, universalSubstitutions = 0, violations = 0, elapsed = 0;

    for (const request of scenario.requests) {
      const started = performance.now();
      const accepted = policyConfig.compatibility[request.group];
      const candidates = units.filter((unit) => !unit.used && unit.collectionDay <= request.day && unit.expiryDay >= request.day && accepted.includes(unit.group));

      const score = (unit) => {
        if (policy === "FIFO") return [-unit.collectionDay, -unit.id];
        if (policy === "FEFO") return [-unit.expiryDay, -unit.collectionDay, -unit.id];
        const isExact = unit.group === request.group;
        const daysLeft = Math.max(0, unit.expiryDay - request.day);
        const shelfLife = policyConfig.reference_shelf_life_days;
        const expiryScore = Math.max(0, Math.min(1, (shelfLife - daysLeft) / shelfLife));
        const weights = policyConfig.urgency_weights[request.urgency] || policyConfig.urgency_weights.ROUTINE;
        const matchScore = isExact ? weights.match : weights.match * policyConfig.substitute_match_ratio;
        const scarcityPenalty = unit.group === "O_NEGATIVE" && request.group !== "O_NEGATIVE" ? weights.scarcity : 0;
        const composite = Math.round((matchScore + weights.expiry * expiryScore - scarcityPenalty) * 10) / 10;
        return [composite, -unit.expiryDay, -unit.id];
      };
      candidates.sort((a, b) => {
        const as = score(a), bs = score(b);
        for (let i = 0; i < Math.max(as.length, bs.length); i++) if ((bs[i] || 0) !== (as[i] || 0)) return (bs[i] || 0) - (as[i] || 0);
        return a.id - b.id;
      });
      const selected = candidates.slice(0, request.quantity);
      let allExact = selected.length === request.quantity;
      for (const unit of selected) {
        if (!accepted.includes(unit.group)) violations++;
        unit.used = true;
        issued++;
        if (unit.group !== request.group) allExact = false;
        if (unit.group === "O_NEGATIVE" && request.group !== "O_NEGATIVE") universalSubstitutions++;
      }
      if (selected.length === request.quantity) {
        fulfilled++;
        if (allExact) exactRequests++;
      }
      elapsed += performance.now() - started;
    }

    const expired = units.filter((unit) => !unit.used && unit.expiryDay < 60).length;
    return {
      policy,
      fulfilled,
      servedRate: scenario.requests.length ? fulfilled / scenario.requests.length * 100 : 0,
      expired,
      wastageRate: units.length ? expired / units.length * 100 : 0,
      exactRequestRate: scenario.requests.length ? exactRequests / scenario.requests.length * 100 : 0,
      universalSubstitutions,
      violations,
      avgMs: scenario.requests.length ? elapsed / scenario.requests.length : 0,
    };
  }

  function render(results, requestCount, unitCount, seed) {
    const body = document.getElementById("simulationResults");
    body.innerHTML = results.map((result) => `
      <tr>
        <td>${result.policy}</td>
        <td>${result.fulfilled}/${requestCount} (${result.servedRate.toFixed(1)}%)</td>
        <td>${result.expired}/${unitCount} (${result.wastageRate.toFixed(1)}%)</td>
        <td>${result.exactRequestRate.toFixed(1)}%</td>
        <td>${result.universalSubstitutions}</td>
        <td>${result.violations}</td>
        <td>${result.avgMs.toFixed(4)} ms</td>
      </tr>`).join("");
    document.getElementById("simulationStatus").textContent = `Completed: ${unitCount} donations and ${requestCount} requests over 60 simulated days (seed ${seed}). Re-run with the same seed to reproduce this scenario.`;
  }

  document.getElementById("simulationForm")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const unitCount = Math.min(2000, Math.max(50, Number(document.getElementById("unitCount").value) || 500));
    const requestCount = Math.min(1000, Math.max(25, Number(document.getElementById("requestCount").value) || 300));
    const seed = Math.min(999999, Math.max(1, Number(document.getElementById("scenarioSeed").value) || 13724));
    try {
      const response = await fetch("config/allocation-policy.json", { cache: "no-store" });
      if (!response.ok) throw new Error("Could not load the live allocation policy.");
      const policyConfig = await response.json();
      const scenario = buildScenario(unitCount, requestCount, seed);
      render(["FIFO", "FEFO", "WEIGHTED PRIORITY"].map((policy) => simulate(policy, scenario, policyConfig)), requestCount, unitCount, seed);
    } catch (error) {
      document.getElementById("simulationStatus").textContent = `Benchmark could not run: ${error.message}`;
    }
  });

  const session = typeof getSession === "function" ? getSession() : null;
  if (!session) window.location.replace("login.html");
  else if (!["STAFF", "ADMIN"].includes(session.role)) window.location.replace("index.html");
})();
