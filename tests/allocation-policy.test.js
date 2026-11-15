const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const config = JSON.parse(fs.readFileSync('config/allocation-policy.json', 'utf8'));
const groups = ['O_NEGATIVE', 'O_POSITIVE', 'A_NEGATIVE', 'A_POSITIVE', 'B_NEGATIVE', 'B_POSITIVE', 'AB_NEGATIVE', 'AB_POSITIVE'];
assert.deepEqual(Object.keys(config.compatibility).sort(), [...groups].sort(), 'compatibility matrix must cover all recipient groups');
const expectedCompatibility = {
  O_NEGATIVE: ['O_NEGATIVE'],
  O_POSITIVE: ['O_POSITIVE', 'O_NEGATIVE'],
  A_NEGATIVE: ['A_NEGATIVE', 'O_NEGATIVE'],
  A_POSITIVE: ['A_POSITIVE', 'A_NEGATIVE', 'O_POSITIVE', 'O_NEGATIVE'],
  B_NEGATIVE: ['B_NEGATIVE', 'O_NEGATIVE'],
  B_POSITIVE: ['B_POSITIVE', 'B_NEGATIVE', 'O_POSITIVE', 'O_NEGATIVE'],
  AB_NEGATIVE: ['AB_NEGATIVE', 'A_NEGATIVE', 'B_NEGATIVE', 'O_NEGATIVE'],
  AB_POSITIVE: ['AB_POSITIVE', 'AB_NEGATIVE', 'A_POSITIVE', 'A_NEGATIVE', 'B_POSITIVE', 'B_NEGATIVE', 'O_POSITIVE', 'O_NEGATIVE'],
};
for (const group of groups) {
  assert.deepEqual(config.compatibility[group], expectedCompatibility[group], `${group} compatibility must match the red-cell policy`);
}
assert.deepEqual(Object.keys(config.urgency_weights).sort(), ['CRITICAL', 'ROUTINE', 'URGENT']);

const ids = {
  simulationResults: { innerHTML: '' },
  simulationStatus: { textContent: '' },
  unitCount: { value: '500' },
  requestCount: { value: '300' },
  scenarioSeed: { value: '13724' },
};
let submit;
ids.simulationForm = { addEventListener: (name, callback) => { if (name === 'submit') submit = callback; } };
const document = { getElementById: (id) => ids[id] || null };
let timer = 0;
const context = {
  document,
  performance: { now: () => timer++ },
  fetch: async () => ({ ok: true, json: async () => config }),
  getSession: () => ({ role: 'STAFF' }),
  window: { location: { replace() {} } },
};
vm.runInNewContext(fs.readFileSync('js/ccp-simulation.js', 'utf8'), context);
assert.equal(typeof submit, 'function', 'benchmark submit handler should register');

async function runBenchmark() {
  await submit({ preventDefault() {} });
  return ids.simulationResults.innerHTML;
}

(async () => {
  const first = await runBenchmark();
  const second = await runBenchmark();
  const withoutTiming = (html) => html.replace(/<td>[0-9.]+ ms<\/td>/g, '<td>TIME</td>');
  assert.equal(withoutTiming(first), withoutTiming(second), 'same seed must produce repeatable outcome metrics');
  assert.equal((first.match(/<tr>/g) || []).length, 3, 'benchmark should report FIFO, FEFO, and weighted priority');
  assert.equal((first.match(/<td>0<\/td>/g) || []).length, 3, 'all strategies must have zero compatibility violations');
  console.log('Allocation policy and benchmark checks passed.');
})();
