document.addEventListener('DOMContentLoaded', () => {
  const session = getSession();
  if (!session) { window.location.replace('login.html'); return; }
  if (session.role !== 'USER') { window.location.replace('index.html'); return; }

  const banner = document.getElementById('donorPortalBanner');
  const onboardingCard = document.getElementById('donorOnboardingCard');
  const dashboard = document.getElementById('donorDashboard');
  const appointmentForm = document.getElementById('donorAppointmentForm');
  const dateInput = document.getElementById('requestedDate');
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  dateInput.min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;

  function showBanner(element, type, message) {
    element.className = `form-banner form-banner--${type}`;
    element.textContent = message;
    element.hidden = false;
    element.style.display = 'block';
  }

  function renderRows(target, rows, emptyText, cellsForRow, actionForRow = null) {
    target.replaceChildren();
    if (!rows.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = target.parentElement.closest('table').querySelectorAll('thead th').length;
      cell.textContent = emptyText;
      row.append(cell);
      target.append(row);
      return;
    }
    for (const item of rows) {
      const row = document.createElement('tr');
      for (const value of cellsForRow(item)) {
        const cell = document.createElement('td');
        cell.textContent = value || '—';
        row.append(cell);
      }
      if (actionForRow) {
        const cell = document.createElement('td');
        const button = actionForRow(item);
        if (button) cell.append(button); else cell.textContent = '—';
        row.append(cell);
      }
      target.append(row);
    }
  }

  async function loadDonorPortal() {
    try {
      const res = await fetch('api/donor-portal.php?action=dashboard');
      const data = await res.json();
      if (!res.ok || !data.success) throw new Error(data.message || 'Could not load donor portal.');
      if (!data.profile) {
        onboardingCard.hidden = false;
        dashboard.hidden = true;
        return;
      }
      onboardingCard.hidden = true;
      dashboard.hidden = false;
      const profile = data.profile;
      document.getElementById('donorName').value = profile.fullName || '';
      document.getElementById('donorEmail').value = profile.email || '';
      document.getElementById('donorPhone').value = profile.phone || '';
      document.getElementById('donorBloodGroup').value = profile.bloodGroup || 'O+';
      document.getElementById('donorFacility').value = String(profile.facilityId || '');
      const statusText = profile.status === 'VERIFIED'
        ? `Verified donor profile · preferred facility ${profile.facilityId}. Staff confirms eligibility at every collection.`
        : profile.status === 'REJECTED'
          ? 'Staff requested a profile correction. Update the details below and save to send it for review again.'
          : 'Your donor profile is waiting for staff verification. You can update your details, but appointment requests become available after verification.';
      showBanner(document.getElementById('donorProfileStatus'), profile.status === 'VERIFIED' ? 'success' : 'info', statusText);
      const verified = profile.status === 'VERIFIED';
      for (const control of appointmentForm.elements) control.disabled = !verified;
      document.getElementById('appointmentEligibility').textContent = verified
        ? 'A date estimate is informational only. Staff confirms eligibility at the appointment; your request does not create a donation.'
        : 'Staff verification is required before you can request an appointment.';
      renderRows(
        document.getElementById('donorAppointments'), data.appointments,
        'You have no appointment requests yet.',
        (item) => [
          `${item.requestedDate} ${item.requestedTime}`,
          item.scheduledDate ? `${item.scheduledDate} ${item.scheduledTime}` : 'Not scheduled',
          item.status.replaceAll('_', ' '),
        ],
        (item) => {
          if (!['PENDING', 'CONFIRMED'].includes(item.status)) return null;
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn-ghost btn-sm';
          button.textContent = 'Cancel';
          button.addEventListener('click', () => cancelAppointment(item.appointmentId, button));
          return button;
        }
      );
      renderRows(
        document.getElementById('donorHistory'), data.history,
        'No completed or in-process donations are linked to your account yet.',
        (item) => [item.date, item.bloodGroup, String(item.units), item.status]
      );
      const estimate = document.getElementById('eligibilityEstimate');
      estimate.textContent = profile.nextEligibleEstimate
        ? `Project cooldown estimate: ${profile.nextEligibleEstimate} based on the recorded donation date and 56-day rule. This is not medical clearance.`
        : 'No next-date estimate is available yet. Staff will confirm eligibility in person.';
    } catch (err) {
      showBanner(banner, 'error', err.message);
    }
  }

  async function postAction(payload) {
    const res = await fetch('api/donor-portal.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Request failed.');
    return data;
  }

  document.getElementById('donorOnboardingForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const data = await postAction({
        action: 'submit_profile',
        facility_id: document.getElementById('donorFacilityId').value,
        blood_group: document.getElementById('donorOnboardingGroup').value,
        consent: document.getElementById('donorConsent').checked,
      });
      showBanner(banner, 'success', data.message);
      await loadDonorPortal();
    } catch (err) { showBanner(banner, 'error', err.message); }
    finally { button.disabled = false; }
  });

  document.getElementById('donorProfileForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const data = await postAction({
        action: 'update_profile',
        full_name: document.getElementById('donorName').value.trim(),
        phone: document.getElementById('donorPhone').value.trim(),
        blood_group: document.getElementById('donorBloodGroup').value,
      });
      showBanner(banner, 'success', data.message);
      await loadDonorPortal();
    } catch (err) { showBanner(banner, 'error', err.message); }
    finally { button.disabled = false; }
  });

  appointmentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.currentTarget.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const data = await postAction({
        action: 'request_appointment',
        requested_date: dateInput.value,
        requested_time: document.getElementById('requestedTime').value,
      });
      showBanner(banner, 'success', data.message);
      appointmentForm.reset();
      dateInput.min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
      await loadDonorPortal();
    } catch (err) { showBanner(banner, 'error', err.message); }
    finally { button.disabled = false; }
  });

  async function cancelAppointment(appointmentId, button) {
    if (!window.confirm('Cancel this appointment request?')) return;
    button.disabled = true;
    try {
      const data = await postAction({ action: 'cancel_appointment', appointment_id: appointmentId });
      showBanner(banner, 'success', data.message);
      await loadDonorPortal();
    } catch (err) { showBanner(banner, 'error', err.message); button.disabled = false; }
  }

  loadDonorPortal();
});
