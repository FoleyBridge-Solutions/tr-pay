<?php
$employee = $data['employee'] ?? null;
if (! $employee) {
    // Handle the case where the employee data is not available
    echo '<p>Employee not found.</p>';

    return;
}
?>
<div class="card">
    <div class="card-header">
        <h5 class="card-title">Edit Employee</h5>
    </div>

    <div class="card-body">
        <form id="editEmployeeForm">
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($employee['first_name'].' '.$employee['last_name']) ?>" disabled>
            </div>
            <div class="mb-3">
                <label for="salary" class="form-label">Salary</label>
                <input type="number" class="form-control" id="salary" name="salary" value="<?= htmlspecialchars($employee['salary']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="hours" class="form-label">Contract Hours</label>
                <input type="number" class="form-control" id="hours" name="hours" value="<?= htmlspecialchars($employee['hours']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="benefits" class="form-label">Benefits</label>
                <select class="form-select" id="benefits" name="benefits">
                    <option value="0" <?= $employee['benefits'] == 0 ? 'selected' : '' ?>>None</option>
                    <option value="1" <?= $employee['benefits'] == 1 ? 'selected' : '' ?>>Health Insurance</option>
                    <option value="2" <?= $employee['benefits'] == 2 ? 'selected' : '' ?>>Retirement Plan</option>
                    <option value="3" <?= $employee['benefits'] == 3 ? 'selected' : '' ?>>Paid Time Off</option>
                </select>
            </div
        </form>
        <!-- back button -->
        <a href="?page=all_employees" class="btn btn-secondary">Back to Employee List</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editEmployeeForm');
    const employeeId = <?= json_encode($employee['staff_KEY']) ?>;

    form.addEventListener('blur', function(event) {
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'SELECT') {
            const fieldName = event.target.name;
            const fieldValue = event.target.value;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?endpoint=update_employee_field', true);
            xhr.setRequestHeader('Content-Type', 'application/json'); // Set content type to JSON

            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE && xhr.status === 200) {
                    console.log('Update successful');
                }
            };

            const data = {
                id: employeeId,
                field: fieldName,
                value: fieldValue
            };

            xhr.send(JSON.stringify(data)); // Send JSON data
        }
    }, true);
});
</script>