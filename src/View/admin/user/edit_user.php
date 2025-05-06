
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Edit User</h5>
    </div>

    <div class="card-body">
        <form id="editUserForm">
            <div class="mb-3">
                <label for="user_name" class="form-label">Username</label>
                <input type="text" class="form-control" id="user_name" name="user_name" value="<?= $user['name'] ?>" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= $user['email'] ?>" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
        </form>
        <!-- back button -->
        <a href="?page=all_users" class="btn btn-secondary">Back to User List</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editUserForm');
    const userId = <?= json_encode($user['user_id']) ?>; // Get the user ID from PHP
    form.addEventListener('blur', function(event) {
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'SELECT') {
            const fieldName = event.target.name;
            const fieldValue = event.target.value;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?endpoint=update_user_field', true);
            xhr.setRequestHeader('Content-Type', 'application/json'); // Set content type to JSON

            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE && xhr.status === 200) {
                    console.log('Update successful');
                }
            };

            const data = {
                id: userId,
                field: fieldName,
                value: fieldValue
            };

            xhr.send(JSON.stringify(data)); // Send JSON data
        }
    }, true);
});
</script>