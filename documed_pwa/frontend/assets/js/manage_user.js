/**
 * Deprecated: Admin manage_user helper not currently included by any page.
 * File retained for reference only.
 */

if (false) {
 let users = [];
    function renderUsers(filter = '') {
      const tbody = document.querySelector('#usersTable tbody');
      tbody.innerHTML = '';
      users.filter(u => {
        const fullName = `${u.last_name}, ${u.first_name}${u.middle_initial ? ' ' + u.middle_initial + '.' : ''}`;
        return fullName.toLowerCase().includes(filter) || (u.email && u.email.toLowerCase().includes(filter)) || (u.student_faculty_id && u.student_faculty_id.toLowerCase().includes(filter));
      }).forEach(user => {
        const fullName = `${user.last_name}, ${user.first_name}${user.middle_initial ? ' ' + user.middle_initial + '.' : ''}`;
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${user.student_faculty_id}</td><td>${fullName}</td><td>${user.email}</td><td>${user.role || ''}</td>
          <td>
            <button class='btn' onclick='openEdit(${user.id}) style="background:#2563eb;color:#fff;padding:4px 12px;border:none;border-radius:6px;cursor:pointer;'>Edit</button>
            <button class='btn' onclick='deleteUser(${user.id}) style="background:#e11d48;color:#fff;padding:4px 12px;border:none;border-radius:6px;cursor:pointer;margin-left:4px;'>Delete</button>
          </td>`;
        tbody.appendChild(tr);
      });
    }
    function fetchUsers() {
      fetch('../../backend/api/manage_user.php?action=list')
        .then(res => res.json())
        .then(data => {
          if (data.success && data.users) {
            users = data.users;
            renderUsers(document.getElementById('searchUser').value.toLowerCase());
          }
        });
    }
    document.getElementById('searchUser').addEventListener('input', function() {
      renderUsers(this.value.toLowerCase());
    });
    fetchUsers();
    window.openEdit = function(id) {
      const user = users.find(u => u.id == id);
      if (!user) return;
      document.getElementById('editUserId').value = user.id;
      document.getElementById('editUserSchoolId').value = user.student_faculty_id;
      document.getElementById('editUserName').value = `${user.last_name}, ${user.first_name}${user.middle_initial ? ' ' + user.middle_initial + '.' : ''}`;
      document.getElementById('editUserEmail').value = user.email;
      document.getElementById('editUserRole').value = user.role || '';
      const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
      modal.show();
    }
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const id = document.getElementById('editUserId').value;
      const schoolId = document.getElementById('editUserSchoolId').value;
      const name = document.getElementById('editUserName').value;
      const email = document.getElementById('editUserEmail').value;
      const role = document.getElementById('editUserRole').value;
      // Split name into last_name, first_name, middle_initial
      let last_name = '', first_name = '', middle_initial = '';
      const nameParts = name.split(',');
      if (nameParts.length >= 2) {
        last_name = nameParts[0].trim();
        let firstAndMI = nameParts[1].trim().split(' ');
        first_name = firstAndMI[0] || '';
        if (firstAndMI.length > 1) {
          middle_initial = firstAndMI[1].replace('.', '').trim();
        }
      } else {
        last_name = name.trim();
      }
      fetch('../../backend/api/manage_user.php?action=update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&student_faculty_id=${encodeURIComponent(schoolId)}&last_name=${encodeURIComponent(last_name)}&first_name=${encodeURIComponent(first_name)}&middle_initial=${encodeURIComponent(middle_initial)}&email=${encodeURIComponent(email)}&role=${role}`
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          fetchUsers();
          bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
        }
      });
    });
    window.deleteUser = function(id) {
      if (!confirm('Are you sure you want to delete this user?')) return;
      fetch('../../backend/api/manage_user.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) fetchUsers();
      });
    }

    //   // Sidebar height reduction
    //         document.querySelector('.sidebar').style.height = '320px';

    //         // Search suggestion logic
    //         const searchInput = document.getElementById('searchUser');
    //         const suggestionsList = document.getElementById('searchSuggestions');
    //         let usersData = [];

    //         // Dummy fetch users (replace with actual AJAX if needed)
    //         function fetchUsers() {
    //             // Example static data, replace with real data
    //             usersData = [
    //                 { name: "Smith, John A.", email: "john.smith@example.com" },
    //                 { name: "Doe, Jane B.", email: "jane.doe@example.com" },
    //                 { name: "Brown, Charlie C.", email: "charlie.brown@example.com" }
    //             ];
    //         }
    //         fetchUsers();

    //         searchInput.addEventListener('input', function() {
    //             const query = this.value.trim().toLowerCase();
    //             suggestionsList.innerHTML = '';
    //             if (query.length === 0) {
    //                 suggestionsList.style.display = 'none';
    //                 return;
    //             }
    //             const matches = usersData.filter(u =>
    //                 u.name.toLowerCase().includes(query) ||
    //                 u.email.toLowerCase().includes(query)
    //             );
    //             if (matches.length > 0) {
    //                 matches.forEach(user => {
    //                     const li = document.createElement('li');
    //                     li.className = 'list-group-item list-group-item-action';
    //                     li.textContent = `${user.name} (${user.email})`;
    //                     li.onclick = () => {
    //                         searchInput.value = user.name;
    //                         suggestionsList.style.display = 'none';
    //                     };
    //                     suggestionsList.appendChild(li);
    //                 });
    //                 suggestionsList.style.display = 'block';
    //             } else {
    //                 suggestionsList.style.display = 'none';
    //             }
    //         });

    //         document.addEventListener('click', function(e) {
    //             if (!searchInput.contains(e.target) && !suggestionsList.contains(e.target)) {
    //                 suggestionsList.style.display = 'none';
    //             }
    //         });
}