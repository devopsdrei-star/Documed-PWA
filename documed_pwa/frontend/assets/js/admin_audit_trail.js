/**
 * Deprecated: Not currently referenced by any admin page. Kept for reference only.
 */

if (false) {
document.addEventListener('DOMContentLoaded', function() {
  fetch('../../backend/api/audit_trail.php?action=list')
    .then(res => res.json())
    .then(data => {
  const tbody = document.querySelector('#auditTrailTable tbody');
      tbody.innerHTML = '';
      if (data.logs && data.logs.length) {
        data.logs.forEach(log => {
          tbody.innerHTML += `
            <tr>
              <td>${log.admin_name || 'Admin #' + log.admin_id}</td>
              <td class="audit-action">${log.action}</td>
              <td class="audit-details">${log.details || ''}</td>
              <td class="audit-timestamp">${new Date(log.timestamp).toLocaleString()}</td>
            </tr>
          `;
        });
      } else {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;">No audit logs found.</td></tr>`;
      }
    })
    .catch(() => {
  const tbody = document.querySelector('#auditTrailTable tbody');
      tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#e11d48;">Error loading audit logs.</td></tr>`;
    });
});

function logAudit(action, details) {
  const adminId = localStorage.getItem('admin_id');
  if (!adminId) return;
  fetch('/DocMed/documed_pwa/backend/api/audit_trail.php?action=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `admin_id=${encodeURIComponent(adminId)}&action_txt=${encodeURIComponent(action)}&details=${encodeURIComponent(details)}`
  });
}
}