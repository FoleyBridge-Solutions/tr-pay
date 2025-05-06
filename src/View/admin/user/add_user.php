<div class="card">
    <div class="card-header">
        <h5 class="card-title">Add User</h5>
    </div>

    <div class="card-body">
        <form id="addUserForm">
            <div class="mb-3">
                <label for="user_name" class="form-label">Username</label>
                <input type="text" class="form-control" id="user_name" name="user_name" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Add User</button>
        </form>
        <!-- back button -->
        <a href="?page=all_users" class="btn btn-secondary">Back to User List</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addUserForm');

    form.addEventListener('submit', function(event) {
        event.preventDefault();

        const userName = document.getElementById('user_name').value;
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '?endpoint=add_user', true);
        xhr.setRequestHeader('Content-Type', 'application/json'); // Set content type to JSON

        xhr.onreadystatechange = function() {
            if (xhr.readyState === XMLHttpRequest.DONE && xhr.status === 200) {
                console.log('User added successfully');
                window.location.href = '?page=admin_users'; // Redirect to user list
            }
        };

        const data = {
            user_name: userName,
            email: email,
            password: password
        };

        xhr.send(JSON.stringify(data)); // Send JSON data
    });
});
</script>