document.addEventListener('DOMContentLoaded', () => {
  const session = getSession();
  if (!session) { window.location.replace('login.html'); return; }
  if (!['STAFF', 'ADMIN'].includes(session.role)) { window.location.replace('index.html'); return; }

  const banner = document.getElementById('donorWorkflowBanner');
  const profileList = document.getElementById('donorProfileApplications');
  const appointmentBody = document.getElementById('donorWorkflowAppointments');

  function showMessage(type, message) {
    banner.className = `form-banner form-banner--${type}`;
    banner.textContent = message;
    banner.hidden = false;
    banner.style.display = 'block';
  }

  function field(label, input) {
    const wrap = document.createElement('label');
    wrap.className = 'workflow-field';
    const caption = document.createElement('span');
    caption.textContent = label;
    wrap.append(caption, input);
    return wrap;
  }

  async function send(payload) {
    const res = await fetch('api/donor-workflow.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not save this review.');
    return data;
  }

  function renderProfiles(profiles) {
    profileList.replaceChildren();
    if (!profiles.length) {
      const empty = document.createElement('p');
      empty.textContent = 'There are no donor profiles awaiting review for this facility.';
      profileList.append(empty);
      return;
    }
    for (const profile of profiles) {
      const card = document.createElement('article');
      card.className = 'approval-item donor-review-item';
      const details = document.createElement('div');
      details.className = 'approval-item__details';
      const title = document.createElement('strong');
      title.textContent = `${profile.fullName} · ${profile.bloodGroup}`;
      const info = document.createElement('p');
      info.textContent = `@${profile.username} · ${profile.email} · ${profile.phone || 'No phone'} · submitted ${profile.submittedAt}`;
      const linkInput = document.createElement('input');
      linkInput.type = 'number'; linkInput.min = '1'; linkInput.placeholder = profile.linkedDonorId ? `Linked donor #${profile.linkedDonorId}` : 'Existing donor ID (optional)';
      linkInput.setAttribute('aria-label', `Existing donor ID for ${profile.username}`);
      details.append(title, info, field('Link existing donor record after identity check', linkInput));

      const actions = document.createElement('div');
      actions.className = 'approval-item__actions';
      const verify = document.createElement('button');
      verify.type = 'button'; verify.className = 'btn btn-primary'; verify.textContent = profile.linkedDonorId ? 'Reverify profile' : 'Verify and create donor';
      verify.addEventListener('click', async () => {
        const existing = linkInput.value.trim();
        if (!window.confirm(existing
          ? `Verify ${profile.fullName} and link donor record #${existing}? Confirm you checked the person's identity.`
          : `Verify ${profile.fullName} and create a donor record? Confirm you checked the person's identity.`)) return;
        verify.disabled = true;
        try {
          const data = await send({ action: 'review_profile', decision: 'VERIFY', user_id: profile.userId, existing_donor_id: existing || undefined });
          showMessage('success', data.message); await loadQueue();
        } catch (err) { showMessage('error', err.message); verify.disabled = false; }
      });
      const reject = document.createElement('button');
      reject.type = 'button'; reject.className = 'btn btn-ghost'; reject.textContent = 'Request correction';
      reject.addEventListener('click', async () => {
        if (!window.confirm(`Mark ${profile.fullName}'s donor profile as needing correction?`)) return;
        reject.disabled = true;
        try {
          const data = await send({ action: 'review_profile', decision: 'REJECT', user_id: profile.userId });
          showMessage('success', data.message); await loadQueue();
        } catch (err) { showMessage('error', err.message); reject.disabled = false; }
      });
      actions.append(verify, reject);
      card.append(details, actions);
      profileList.append(card);
    }
  }

  function renderAppointments(appointments) {
    appointmentBody.replaceChildren();
    if (!appointments.length) {
      const row = document.createElement('tr'); const cell = document.createElement('td');
      cell.colSpan = 6; cell.textContent = 'There are no pending or confirmed appointments.';
      row.append(cell); appointmentBody.append(row); return;
    }
    for (const appointment of appointments) {
      const row = document.createElement('tr');
      const donorCell = document.createElement('td');
      donorCell.textContent = `${appointment.donorName} (#${appointment.donorId}) · ${appointment.bloodGroup}\n${appointment.contact || ''}`;
      const requested = document.createElement('td');
      requested.textContent = `${appointment.requestedDate} ${appointment.requestedTime}`;
      const scheduleCell = document.createElement('td');
      const dateInput = document.createElement('input'); dateInput.type = 'date'; dateInput.value = appointment.scheduledDate || appointment.requestedDate; dateInput.setAttribute('aria-label', 'Scheduled date');
      const timeInput = document.createElement('input'); timeInput.type = 'time'; timeInput.value = appointment.scheduledTime || appointment.requestedTime; timeInput.setAttribute('aria-label', 'Scheduled time');
      scheduleCell.append(dateInput, timeInput);
      const status = document.createElement('td'); status.textContent = appointment.status;
      const noteCell = document.createElement('td');
      const note = document.createElement('input'); note.type = 'text'; note.maxLength = 300; note.value = appointment.staffNote || ''; note.placeholder = 'Private to staff'; note.setAttribute('aria-label', 'Private staff note');
      noteCell.append(note);
      const actionCell = document.createElement('td');
      const actions = document.createElement('div'); actions.className = 'workflow-actions';

      const act = async (decision, button) => {
        button.disabled = true;
        try {
          const data = await send({
            action: 'review_appointment', appointment_id: appointment.appointmentId, decision,
            scheduled_date: dateInput.value, scheduled_time: timeInput.value, staff_note: note.value.trim(),
          });
          showMessage('success', data.message); await loadQueue();
        } catch (err) { showMessage('error', err.message); button.disabled = false; }
      };
      const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'btn btn-primary btn-sm'; confirm.textContent = appointment.status === 'CONFIRMED' ? 'Reschedule' : 'Confirm';
      confirm.addEventListener('click', () => act('CONFIRM', confirm)); actions.append(confirm);
      const decline = document.createElement('button'); decline.type = 'button'; decline.className = 'btn btn-ghost btn-sm'; decline.textContent = 'Decline';
      decline.addEventListener('click', () => act('DECLINE', decline)); actions.append(decline);
      if (appointment.status === 'CONFIRMED') {
        const noShow = document.createElement('button'); noShow.type = 'button'; noShow.className = 'btn btn-ghost btn-sm'; noShow.textContent = 'No-show';
        noShow.addEventListener('click', () => act('NO_SHOW', noShow)); actions.append(noShow);

        const collection = document.createElement('div');
        collection.className = 'collection-entry';
        const shelfLife = document.createElement('input'); shelfLife.type = 'number'; shelfLife.min = '21'; shelfLife.max = '42'; shelfLife.step = '1'; shelfLife.value = '35'; shelfLife.setAttribute('aria-label', 'Shelf life in days');
        const record = document.createElement('button'); record.type = 'button'; record.className = 'btn btn-primary btn-sm'; record.textContent = 'Record collection';
        record.addEventListener('click', async () => {
          if (!window.confirm(`Record an actual collection from ${appointment.donorName}? This creates a QUARANTINED inventory unit and marks the appointment complete.`)) return;
          record.disabled = true;
          try {
            const now = new Date();
            const collectionDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
            const res = await fetch('api/inventory.php', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'log_donation', appointment_id: appointment.appointmentId, donor_id: appointment.donorId,
                collection_date: collectionDate, shelf_life_days: shelfLife.value, blood_group: appointment.bloodGroup,
              }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.message || 'Could not record the collection.');
            showMessage('success', `${data.message} Appointment completed.`);
            await loadQueue();
          } catch (err) { showMessage('error', err.message); record.disabled = false; }
        });
        collection.append(field('Shelf life (days)', shelfLife), record);
        actions.append(collection);
      }
      actionCell.append(actions);
      row.append(donorCell, requested, scheduleCell, status, noteCell, actionCell);
      appointmentBody.append(row);
    }
  }

  async function loadQueue() {
    profileList.textContent = 'Loading donor profiles…';
    appointmentBody.innerHTML = '<tr><td colspan="6">Loading appointments…</td></tr>';
    try {
      const res = await fetch('api/donor-workflow.php?action=profiles');
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || 'Could not load donor workflow.');
      renderProfiles(data.profiles || []);
      renderAppointments(data.appointments || []);
    } catch (err) { showMessage('error', err.message); }
  }

  document.getElementById('refreshDonorWorkflow').addEventListener('click', loadQueue);
  loadQueue();
});
